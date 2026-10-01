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
use VDM\Component\JoomEngineMcp\Administrator\Service\TemplateStyleInheritance;
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
$reject = static function (callable $operation, string $code) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $error->getIdentifier());
		return;
	}
	throw new RuntimeException('Expected ' . $code);
};
$node = static fn (int $id, string $template, int $client, ?array $xml = null): array => [
	'id' => (string) $id, 'type' => 'styles',
	'attributes' => ['template' => $template, 'client_id' => $client, 'title' => 'Existing style', 'xml' => $xml],
];
$child = ['name' => 'A readable child name', '@attributes' => ['type' => 'template', 'client' => 'site'], 'parent' => 'cassiopeia', 'inheritable' => '0'];
$check(TemplateStyleInheritance::manifest($child, 0) === ['parent' => 'cassiopeia', 'inheritable' => 0], 'Child metadata follows the installed manifest.');
$check(TemplateStyleInheritance::manifest(['name' => 'TPL_CASSIOPEIA', 'inheritable' => '1']) === ['parent' => '', 'inheritable' => 1], 'Inheritable parent metadata does not guess from its name.');
$check(TemplateStyleInheritance::manifest(['name' => 'Independent']) === ['parent' => '', 'inheritable' => 0], 'Absent optional elements have native installer defaults.');
$check(TemplateStyleInheritance::manifest(['name' => 'Empty nodes', 'parent' => [], 'inheritable' => []]) === ['parent' => '', 'inheritable' => 0], 'Empty SimpleXML objects preserve native defaults.');
$check(TemplateStyleInheritance::manifest(['name' => 'Empty object nodes', 'parent' => new stdClass(), 'inheritable' => new stdClass()]) === ['parent' => '', 'inheritable' => 0], 'Shape-preserving API decoding retains native empty SimpleXML element defaults.');
foreach ([null, [], 'xml', ['name' => 'Bad', 'parent' => '../other'], ['name' => 'Bad', 'parent' => null],
	['name' => 'Bad', 'inheritable' => 'true'], ['name' => 'Bad', 'inheritable' => 2], ['name' => 'Bad', 'parent' => ['first', 'second']],
	['name' => 'Bad', '@attributes' => ['client' => 'administrator']]] as $invalid)
{
	$reject(static fn () => TemplateStyleInheritance::manifest($invalid, 0), 'TEMPLATE_INHERITANCE_UNAVAILABLE');
}

// Real action, permission and plan lifecycle around a recording native-API double.
$http = new class implements ClientInterface
{
	/** @var array<int,array<string,mixed>> JSON:API style resources. */
	public array $items = [];
	/** @var array<int,array<string,mixed>> Recorded native requests without credentials. */
	public array $requests = [];
	/** @var bool Return a pagination link, even for a server-capped short page. */
	public bool $paginate = false;
	/** @var bool Return a repeated page to exercise the loop guard. */
	public bool $repeat = false;
	/** @var bool Deny native preflight reads. */
	public bool $deny = false;
	/** @inheritDoc */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$path = $request->getUri()->getPath();
		$method = $request->getMethod();
		parse_str($request->getUri()->getQuery(), $query);
		$this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => (string) $request->getBody()];
		if ($method === 'GET' && $this->deny)
		{
			return new Response(403);
		}
		$client = str_contains($path, '/administrator') ? 1 : 0;
		$id = (int) basename($path);
		if ($method === 'GET' && $id > 0)
		{
			return isset($this->items[$id]) ? new Response(200, [], Json::encode(['data' => $this->items[$id]])) : new Response(404);
		}
		if ($method === 'GET')
		{
			$items = array_values(array_filter($this->items, static fn (array $item): bool => (int) $item['attributes']['client_id'] === $client));
			$offset = $this->repeat ? 0 : (int) ($query['page']['offset'] ?? 0);
			$limit = $this->paginate ? 1 : 100;
			$page = array_slice($items, $offset, $limit);
			foreach ($page as &$item)
			{
				unset($item['attributes']['xml']);
			}
			unset($item);
			return new Response(200, [], Json::encode(['data' => $page, 'links' => ['next' => $offset + count($page) < count($items) ? 'https://untrusted.example/not-followed' : null]]));
		}
		if ($method !== 'POST')
		{
			throw new RuntimeException('Unexpected method.');
		}
		$data = Json::decode((string) $request->getBody());
		$id = 1000;
		$this->items[$id] = ['id' => (string) $id, 'attributes' => $data + ['xml' => ['name' => 'Persisted child', 'parent' => $data['parent'], 'inheritable' => (string) $data['inheritable']]]];
		return new Response(201, [], Json::encode(['data' => $this->items[$id]]));
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
$principal = new Principal('joomla:17', 'api', [1]);
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings, static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
$clock = static fn (): int => 1900000000;
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
$builder = new ApiRequestBuilder();
$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder);
$request = $permissions->request(['toolsets' => ['structure.write'], 'duration' => '30-minutes', 'reason' => 'Template style inheritance contract']);
$permissions->approve($request['requestId'], $request['acknowledgement']);
$input = ['data' => ['title' => 'New child style', 'template' => 'sample_child', 'params' => ['colorName' => 'colors_alternative']]];
$http->items = [1 => $node(1, 'cassiopeia', 0, ['name' => 'TPL_CASSIOPEIA', 'inheritable' => '1']), 2 => $node(2, 'sample_child', 0, $child)];
$http->paginate = true;
$plan = $executor->plan('templates.site-styles.create', $input, Json::uuid());
$check(count(array_filter($http->requests, static fn (array $request): bool => $request['method'] !== 'GET')) === 0, 'Planning performs reads only.');
$check($http->requests[1]['query']['page']['offset'] === '1', 'Server-capped pages follow bounded offsets, ignoring remote links.');
$result = $executor->apply($plan['confirmationToken']);
$post = array_values(array_filter($http->requests, static fn (array $request): bool => $request['method'] === 'POST'));
$body = Json::decode($post[0]['body']);
$check(count($post) === 1 && $body === $input['data'] + ['parent' => 'cassiopeia', 'inheritable' => 0, 'client_id' => 0], 'Only verified inheritance and fixed client are added to the submitted fields.');
$check($result['verification']['status'] === 'verified', 'The normal independent resource read-back remains active.');
$reject(static fn () => $executor->plan('templates.site-styles.create', ['data' => $input['data'] + ['parent' => 'caller_parent']], Json::uuid()), 'INVALID_INPUT');
$reject(static fn () => $builder->build(['data' => $input['data'] + ['inheritable' => 1]], $catalogue->action('templates.site-styles.create')['binding']['configuration'], ['parent' => 'cassiopeia', 'inheritable' => 0]), 'TEMPLATE_INHERITANCE_UNAVAILABLE');
$count = count($http->requests);
$check($executor->apply($plan['confirmationToken'])['idempotentReplay'] && count($http->requests) === $count, 'Replay never resolves or repeats a mutation.');
$http->items = [2 => $node(2, 'sample_child', 0, $child)];
$plan = $executor->plan('templates.site-styles.create', $input, Json::uuid());
$http->items[2]['attributes']['xml']['parent'] = 'other_parent';
$reject(static fn () => $executor->apply($plan['confirmationToken']), 'PRECONDITION_CHANGED');
$http->items = [];
$reject(static fn () => $executor->plan('templates.site-styles.create', $input, Json::uuid()), 'TEMPLATE_INHERITANCE_UNAVAILABLE');
$http->deny = true;
$reject(static fn () => $executor->plan('templates.site-styles.create', $input, Json::uuid()), 'JOOMLA_API_ERROR');
$http->deny = false;
$http->items = [2 => $node(2, 'sample_child', 0, $child), 3 => $node(3, 'sample_child', 0, $child + ['extra' => 'ignored'])];
$http->items[3]['attributes']['xml']['parent'] = 'conflicting_parent';
$reject(static fn () => $executor->plan('templates.site-styles.create', $input, Json::uuid()), 'TEMPLATE_INHERITANCE_UNAVAILABLE');
$http->repeat = true;
$reject(static fn () => $executor->plan('templates.site-styles.create', $input, Json::uuid()), 'TEMPLATE_INHERITANCE_UNAVAILABLE');
$http->repeat = false;
$http->items = [2 => $node(2, 'sample_child', 1, ['name' => 'Administrator child', 'parent' => 'atum', 'inheritable' => '0'])];
$plan = $executor->plan('templates.administrator-styles.create', $input, Json::uuid());
$executor->apply($plan['confirmationToken']);
$check($http->items[1000]['attributes']['parent'] === 'atum' && $http->items[1000]['attributes']['client_id'] === 1, 'Administrator creation retains its own client and parent.');
$principal->deny('mcp.execute');
$reject(static fn () => $executor->plan('templates.site-styles.create', $input, Json::uuid()), 'DEFINITION_UNAVAILABLE');
$reject(static fn () => $builder->build($input, $catalogue->action('templates.site-styles.create')['binding']['configuration']), 'TEMPLATE_INHERITANCE_UNAVAILABLE');

echo Json::encode(['checks' => $checks, 'templateInheritance' => 'passed with recording native API doubles', 'liveJoomla' => 'covered separately by installed template tests']) . PHP_EOL;
