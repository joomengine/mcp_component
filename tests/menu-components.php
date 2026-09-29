<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiHandler;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\HandlerRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;


require dirname(__DIR__) . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never
{
	throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void
{
	$checks++;

	if (!$value)
	{
		throw new RuntimeException($message);
	}
};
$reject = static function (callable $call, string $code) use ($check): void
{
	try
	{
		$call();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $error->getIdentifier());
		return;
	}

	throw new RuntimeException('Expected rejection ' . $code);
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];

/** Fresh persisted approval state plus a Joomla-like stored/derived menu distinction. */
$fixture = static function () use ($seed): array
{
	$http = new class implements ClientInterface
	{
		/** @var array<int,array<string,mixed>> Raw table records, including genuinely stored component IDs. */
		public array $items = [];
		/** @var array<int,array<string,mixed>> Requests observed by the API double. */
		public array $requests = [];
		/** @var array<string,int> Site-owned native component identity lookup. */
		public array $components = ['com_content' => 41, 'com_contact' => 73];
		/** @var bool A successful-looking repair that does not persist its ID. */
		public bool $discardRepair = false;
		/** @var int Optional failure response for the corrective PATCH. */
		public int $repairFailure = 0;
		/** @var bool Initial POST persists but its HTTP response is lost. */
		public bool $initialFailure = false;
		/** @var bool Simulate collection verification being denied after mutation. */
		public bool $listDenied = false;
		/** @var ?int Simulate an incorrect item read's client. */
		public ?int $readClient = null;
		/** @var int Request-local revision used for ETag contracts. */
		private int $revision = 1;

		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$path = $request->getUri()->getPath();
			$id = ctype_digit(basename($path)) ? (int) basename($path) : null;
			$client = str_contains($path, '/administrator/') ? 1 : 0;
			parse_str($request->getUri()->getQuery(), $query);
			$data = $request->getMethod() === 'GET' ? null : Json::decode((string) $request->getBody());
			$this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'query' => $query, 'body' => $data,
				'key' => $request->getHeaderLine('Idempotency-Key'), 'etag' => $request->getHeaderLine('If-Match')];

			if ($request->getMethod() === 'GET' && $id === null)
			{
				if ($this->listDenied)
				{
					return new Response(403);
				}

				$items = array_values(array_filter($this->items, static fn (array $item): bool => $item['client_id'] === $client
					&& $item['menutype'] === ($query['filter']['menutype'] ?? '') && in_array($item['published'], [0, 1], true)));
				$offset = (int) ($query['page']['offset'] ?? 0);
				$limit = (int) ($query['page']['limit'] ?? 20);
				$page = array_slice($items, $offset, $limit);
				return new Response(200, [], Json::encode(['data' => array_map(static fn (array $item): array => ['id' => (string) $item['id'], 'attributes' => $item], $page),
					'meta' => ['total-pages' => (int) ceil(count($items) / $limit)]]));
			}

			if ($request->getMethod() === 'GET')
			{
				return isset($this->items[$id]) ? $this->itemResponse($id) : new Response(404);
			}

			$repair = ($data['component_id'] ?? 0) > 0;

			if ($repair && $this->repairFailure > 0)
			{
				return new Response($this->repairFailure);
			}

			if ($request->getMethod() === 'POST')
			{
				$id = max([0, ...array_keys($this->items)]) + 1;
			}

			if ($repair && $this->discardRepair)
			{
				unset($data['component_id']);
			}

			$this->items[$id] = array_replace($this->items[$id] ?? [], $data, ['id' => $id, 'client_id' => $client]);
			$this->revision++;

			return $this->initialFailure && $request->getMethod() === 'POST' ? new Response(500) : $this->itemResponse($id);
		}

		/** @param int $id Item identity. @return ResponseInterface The native GET masks broken stored IDs exactly as Joomla does. */
		private function itemResponse(int $id): ResponseInterface
		{
			$item = $this->items[$id];
			parse_str((string) parse_url($item['link'] ?? '', PHP_URL_QUERY), $query);

			if (($item['type'] ?? '') === 'component' && isset($this->components[$query['option'] ?? '']))
			{
				$item['component_id'] = $this->components[$query['option']];
			}

			if ($this->readClient !== null)
			{
				$item['client_id'] = $this->readClient;
			}

			return new Response(200, ['ETag' => '"menu-' . $this->revision . '"'], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $item]]));
		}
	};
	$store = new MemoryStore($seed);
	$principal = new Principal('joomla:17', 'api', [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php', 'max_list_limit' => 2]);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings, static fn (): bool => true, static fn (): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder);
	$request = $permissions->request(['toolsets' => ['structure.write'], 'duration' => '30-minutes', 'reason' => 'Native menu persistence contracts']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);

	return compact('http', 'store', 'catalogue', 'executor', 'state');
};
$data = ['title' => 'Menu contract', 'menutype' => 'fixture', 'type' => 'component', 'link' => 'index.php?option=com_content&view=featured',
	'parent_id' => 1, 'published' => 0, 'language' => '*', 'params' => []];
$writes = static fn (array $s): array => array_values(array_filter($s['http']->requests, static fn (array $r): bool => $r['method'] !== 'GET'));

foreach (['site' => 0, 'administrator' => 1] as $client => $clientId)
{
	$s = $fixture();
	$body = $data;
	$body['menutype'] = $client === 'site' ? 'fixture' : 'main';
	$dry = $s['executor']->plan('menus.' . $client . '-items.create', ['data' => $body], Json::uuid(), true);
	$check($writes($s) === [] && $s['store']->find('plan') === [] && isset($dry['operation']['menuComponent']['followUp']), 'Dry-run must disclose its repair without mutating or persisting a confirmation.');
	$key = Json::uuid();
	$plan = $s['executor']->plan('menus.' . $client . '-items.create', ['data' => $body], $key);
	$private = $s['state']->resolve($plan['confirmationToken']);
	$check($private['payload']['menu_component']['option'] === 'com_content' && isset($private['payload']['menu_component']['revisions']['updateAction']), 'Approved plan must bind target and correction definition.');
	$result = $s['executor']->apply($plan['confirmationToken']);
	$requests = $writes($s);
	$check(count($requests) === 2 && $requests[0]['method'] === 'POST' && $requests[0]['body']['component_id'] === 0
		&& $requests[1]['method'] === 'PATCH' && $requests[1]['body']['component_id'] === 41, 'Creation must initialize then persist the native component identity once.');
	$check($requests[0]['key'] === $key && $requests[1]['key'] !== $key && Json::requireUuid($requests[1]['key']) === $requests[1]['key'], 'Correction needs its own deterministic valid UUID.');
	$check($requests[1]['etag'] === '"menu-2"', 'Correction must use the fresh item ETag.');
	$check($result['verification']['status'] === 'verified' && $result['verification']['menuComponent']['storedComponentId'] === 41
		&& $s['http']->items[1]['component_id'] === 41, 'A derived GET must not substitute for actual stored-ID verification.');
	$repeat = $s['executor']->apply($plan['confirmationToken']);
	$check($repeat['idempotentReplay'] && count($writes($s)) === 2, 'Replay repeated the initial create or correction.');
	$plan = $s['executor']->plan('menus.' . $client . '-items.update', ['id' => 1, 'data' => ['link' => 'index.php?option=com_contact&view=categories']], Json::uuid());
	$result = $s['executor']->apply($plan['confirmationToken']);
	$requests = $writes($s);
	$check($requests[2]['body']['component_id'] === 0 && $requests[3]['body']['component_id'] === 73
		&& $s['http']->items[1]['component_id'] === 73 && $result['verification']['status'] === 'verified', 'Changing the component link reused the old derived ID.');
	$plan = $s['executor']->plan('menus.' . $client . '-items.update', ['id' => 1, 'data' => ['title' => 'Title-only update']], Json::uuid());
	$result = $s['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === 'verified' && $s['http']->items[1]['component_id'] === 73, 'Partial menu update did not preserve its effective component target.');
}

foreach ([['component_id' => 41], ['link' => 'index.php?option=com_content&option=com_contact'],
	['link' => 'index.php?option=com_content&option[]=com_contact'], ['link' => 'index.php?option%00x=com_content'],
	['link' => 'index.php?option=com_content&%20option=com_contact'], ['link' => 'index.php?option=com_content&x=1;option=com_contact'],
	['link' => 'https://outside.example/index.php?option=com_content']] as $invalid)
{
	$s = $fixture();
	$reject(static fn () => $s['executor']->plan('menus.site-items.create', ['data' => array_replace($data, $invalid)], Json::uuid()), 'INVALID_INPUT');
	$check($writes($s) === [] && $s['store']->find('plan') === [], 'Invalid component identity input passed planning.');
}

foreach ([['repairFailure', 412], ['repairFailure', 500], ['discardRepair', true], ['initialFailure', true], ['listDenied', true]] as [$mode, $value])
{
	$s = $fixture();
	$s['http']->{$mode} = $value;
	$key = Json::uuid();
	$plan = $s['executor']->plan('menus.site-items.create', ['data' => $data], $key);
	$result = $s['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === 'uncertain' && count($s['http']->items) === 1 && $s['store']->find('lease') !== [], 'Partial or ambiguous writes must retain durable uncertain state.');
	$check($mode === 'initialFailure' || ($result['mutation']['data']['data']['id'] ?? null) === '1', 'Post-mutation failure lost the created item identity.');
	$count = count($writes($s));
	$repeat = $s['executor']->apply($plan['confirmationToken']);
	$check($repeat['idempotentReplay'] && count($writes($s)) === $count && count($s['http']->items) === 1, 'An uncertain menu write repeated its creation.');
}

$s = $fixture();
$s['http']->components = [];
$plan = $s['executor']->plan('menus.site-items.create', ['data' => $data], Json::uuid());
$result = $s['executor']->apply($plan['confirmationToken']);
$check($result['verification']['status'] === 'uncertain' && count($writes($s)) === 1 && $s['http']->items[1]['component_id'] === 0, 'An unknown component must not repair using an old positive ID.');
$s = $fixture();
$s['http']->items[1] = $data + ['id' => 1, 'client_id' => 1, 'component_id' => 41];
$reject(static fn () => $s['executor']->plan('menus.site-items.update', ['id' => 1, 'data' => ['title' => 'Wrong client']], Json::uuid()), 'PRECONDITION_CHANGED');
$check($writes($s) === [], 'A menu item from the wrong client was mutated.');
$s = $fixture();
$body = array_replace($data, ['type' => 'url', 'link' => 'https://example.invalid/']);
$plan = $s['executor']->plan('menus.site-items.create', ['data' => $body], Json::uuid());
$result = $s['executor']->apply($plan['confirmationToken']);
$check(count($writes($s)) === 1 && $s['http']->items[1]['component_id'] === 0 && $result['verification']['menuComponent']['status'] === 'verified', 'Noncomponent menu entries must retain zero without a corrective PATCH.');
$s = $fixture();

foreach ([1, 2, 3] as $id)
{
	$s['http']->items[$id] = $data + ['id' => $id, 'client_id' => 0, 'component_id' => 41];
}

$plan = $s['executor']->plan('menus.site-items.create', ['data' => $data], Json::uuid());
$result = $s['executor']->apply($plan['confirmationToken']);
$pages = array_values(array_filter($s['http']->requests, static fn (array $r): bool => $r['method'] === 'GET' && str_ends_with($r['path'], '/items')));
$check($result['verification']['menuComponent']['id'] === 4 && count($pages) === 2
	&& $pages[1]['query']['page']['offset'] === '2' && $pages[1]['query']['filter']['menutype'] === 'fixture',
	'Collection verification must traverse bounded pages with the frozen native menu filter.');
$s = $fixture();
$plan = $s['executor']->plan('menus.site-items.create', ['data' => $data], Json::uuid());
$repairAction = $s['store']->one('action', ['name' => 'menus.site-items.update']);
$s['store']->update('action', ['published' => 0], ['id' => $repairAction['id']]);
$s['catalogue']->refresh();
$reject(static fn () => $s['executor']->apply($plan['confirmationToken']), 'DEFINITION_UNAVAILABLE');
$check($writes($s) === [], 'Revoked repair authority must prevent the initial mutation too.');

echo Json::encode(['checks' => $checks, 'menuComponentContracts' => 'passed with recording API and transactional memory doubles', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
