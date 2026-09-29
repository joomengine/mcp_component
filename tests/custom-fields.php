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
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
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
$check = static function (bool $condition, string $message) use (&$checks): void
{
	$checks++;

	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};
$reject = static function (callable $operation, string $identifier) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $identifier, 'Expected ' . $identifier . ', got ' . $error->getIdentifier());

		return;
	}

	throw new RuntimeException('Expected rejection ' . $identifier);
};
$field = static fn (string $name, array $overrides = []): array => [
	'id' => (string) (1 + hexdec(substr(hash('sha256', $name), 0, 6))), 'type' => 'fields', 'attributes' => array_replace([
		'name' => $name, 'type' => 'text', 'context' => 'com_content.article', 'state' => 1,
		'access' => 1, 'group_id' => 0,
	], $overrides),
];
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];

/** Compose isolated state and a recording API; these contracts are not live Joomla evidence. */
$fixture = static function (array $fields, string $track = 'api') use ($seed): array
{
	$http = new class implements ClientInterface
	{
		/** @var array<string,array<int,array<string,mixed>>> Native JSON:API field rows by route. */
		public array $fields = [];
		/** @var array<string,array<string,mixed>> Persisted fake resources by item route. */
		public array $items = [];
		/** @var array<int,array<string,mixed>> Observed mutations. */
		public array $writes = [];
		/** @var array<int,array<string,mixed>> Observed field discovery requests. */
		public array $discovery = [];
		/** @var int Resource read-back count. */
		public int $reads = 0;
		/** @var ?ResponseInterface Optional discovery error or malformed response. */
		public ?ResponseInterface $fieldResponse = null;
		/** @var ?string Field hidden by native read-back. */
		public ?string $hiddenField = null;
		/** @var ?string Field changed by native persistence. */
		public ?string $changedField = null;
		/** @var bool Simulate a server ignoring offsets. */
		public bool $repeatPage = false;
		/** @var int Next fake resource identifier. */
		private int $nextId = 1;

		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$path = $request->getUri()->getPath();
			$path = substr($path, strpos($path, '/v1/'));
			parse_str($request->getUri()->getQuery(), $query);

			if ($request->getMethod() === 'GET' && str_starts_with($path, '/v1/fields/'))
			{
				$this->discovery[] = ['path' => $path, 'query' => $query];

				if ($this->fieldResponse !== null)
				{
					return $this->fieldResponse;
				}

				$rows = $this->fields[$path] ?? [];
				$offset = (int) ($query['page']['offset'] ?? 0);
				$limit = (int) ($query['page']['limit'] ?? 20);
				$page = array_slice($rows, $this->repeatPage ? 0 : $offset, $limit);

				return new Response(200, [], Json::encode(['data' => $page, 'meta' => ['total-pages' => (int) ceil(count($rows) / $limit)],
					'links' => ['next' => $offset + $limit < count($rows) || $this->repeatPage ? 'https://joomla.example/unused-next-link' : null]]));
			}

			if ($request->getMethod() === 'GET')
			{
				$this->reads++;

				if (!isset($this->items[$path]))
				{
					return new Response(404);
				}

				$item = $this->items[$path];

				if ($this->hiddenField !== null)
				{
					unset($item[$this->hiddenField], $item['com_fields'][$this->hiddenField]);
				}

				return new Response(200, [], Json::encode(['data' => ['id' => (string) basename($path), 'attributes' => $item]]));
			}

			$data = Json::decode((string) $request->getBody());
			$this->writes[] = ['method' => $request->getMethod(), 'path' => $path, 'body' => $data];
			$id = $request->getMethod() === 'POST' ? $this->nextId++ : (int) basename($path);
			$itemPath = $request->getMethod() === 'POST' ? $path . '/' . $id : $path;
			$this->items[$itemPath] = array_replace($this->items[$itemPath] ?? [], $data);

			if ($this->changedField !== null)
			{
				$this->items[$itemPath][$this->changedField] = 'Changed by Joomla';
			}

			return new Response(200, [], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $this->items[$itemPath]]]));
		}
	};
	$http->fields = $fields;
	$store = new MemoryStore($seed);
	$principal = new Principal('joomla:17', $track, [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php', 'max_list_limit' => 2]);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]),
		$permissions, $state, $audit, $settings, $builder);
	$tools = new ToolDispatcher($catalogue, $executor, $permissions, $schemas, $principal, $settings);

	return compact('http', 'store', 'principal', 'settings', 'schemas', 'catalogue', 'permissions', 'state', 'executor', 'tools');
};
$approve = static function (array $services, string $scope = 'content.write'): void
{
	$request = $services['permissions']->request(['toolsets' => [$scope], 'duration' => '30-minutes', 'reason' => 'Custom field behavioural contracts']);
	$services['permissions']->approve($request['requestId'], $request['acknowledgement']);
};
$articleFields = ['/v1/fields/content/articles' => [
	$field('external-reference'), $field('audiences', ['type' => 'list']), $field('2026-reference'), $field('équipe'), $field('团队'), $field('123'), $field('0'),
	$field('unpublished-field', ['state' => 0]), $field('foreign-context', ['context' => 'com_contact.contact']),
	$field('subform-only-field', ['only_use_in_subform' => 1]),
	$field('private-field', ['access' => 7]), $field('private-group', ['group_id' => 3, 'group_state' => 1, 'group_access' => 7]),
	$field('unpublished-group', ['group_id' => 4, 'group_state' => 0, 'group_access' => 1]),
	$field('visible-group', ['group_id' => 5, 'group_state' => 1, 'group_access' => 1]),
]];

$s = $fixture($articleFields);
$dry = $s['executor']->plan('content.articles.create', ['data' => ['title' => 'Core fields only', 'catid' => 2]], Json::uuid(), true);
$check($dry['dryRun'] && $s['http']->discovery === [] && $s['http']->writes === [], 'Core-only writes must not depend on field discovery.');
$description = $s['tools']->call('joomla_action_describe', ['action' => 'content.articles.create'])['action'];
$names = array_column($description['customFields']['fields'], 'name');
sort($names);
$check($names === ['2026-reference', 'audiences', 'external-reference', 'visible-group', 'équipe', '团队'], 'Discovery exposed an unpublished, foreign-context or inaccessible field/group, or rejected numeric-leading/Unicode field names.');
$check($description['customFields']['context'] === 'com_content.article'
	&& $description['customFields']['sourceAction'] === 'fields.content-articles.list', 'Action description lost the native field context and source.');
$properties = $description['inputSchema']['properties']['data']['properties'];
$check(isset($properties['external-reference'], $properties['audiences'], $properties['com_fields'])
	&& $description['inputSchema']['properties']['data']['additionalProperties'] === false, 'Runtime schemas must extend the field allowlist without accepting arbitrary keys.');
$check(count($s['http']->discovery) >= 4 && $s['http']->discovery[1]['query']['page']['offset'] === '2', 'Field discovery did not traverse bounded native pagination.');
$check($s['http']->writes === [], 'Describing custom fields performed a write.');
$dry = $s['executor']->plan('content.articles.create', ['data' => ['title' => 'Custom field preview', 'catid' => 2,
	'com_fields' => ['external-reference' => 'preview-value']]], Json::uuid(), true);
$check($dry['dryRun'] && isset($dry['operation']['customFields']) && $s['store']->find('plan') === [] && $s['http']->writes === [],
	'A custom field dry-run must validate and describe input without approval state or mutation.');

$approve($s);
$data = ['title' => 'Custom field article', 'catid' => 2, 'external-reference' => 'reference-secret', 'audiences' => ['members', 'partners'],
	'2026-reference' => 'numeric-leading', 'équipe' => 'Unicode Latin', '团队' => 'Unicode Han'];
$plan = $s['tools']->call('joomla_action_write_plan', ['action' => 'content.articles.create', 'input' => ['data' => $data], 'idempotencyKey' => Json::uuid()]);
$check(!str_contains(Json::encode($plan), 'reference-secret'), 'A confirmation preview disclosed custom field values.');
$check($s['http']->writes === [] && $plan['operation']['customFields']['context'] === 'com_content.article', 'Planning must publish field metadata without mutation.');
$privatePlan = $s['state']->resolve($plan['confirmationToken']);
$check(isset($privatePlan['payload']['custom_fields']) && !str_contains($privatePlan['input_cipher'], 'reference-secret'), 'Resolved field metadata and values must be bound inside encrypted plan state.');
$discoveryCount = count($s['http']->discovery);
$s['http']->fieldResponse = new Response(503);
$result = $s['executor']->apply($plan['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $s['http']->writes[0]['body'] === $data, 'Flat API custom field values were lost, changed or left unverified.');
$check(count($s['http']->discovery) === $discoveryCount, 'Applying a frozen plan rediscovered mutable field definitions.');
$repeat = $s['executor']->apply($plan['confirmationToken']);
$check($repeat['idempotentReplay'] && count($s['http']->writes) === 1, 'Custom field confirmation replay repeated a mutation.');

$s = $fixture($articleFields);
$approve($s);
$plan = $s['tools']->call('joomla_content_article_create_plan', ['data' => [
	'title' => 'Typed tool alias', 'catid' => 2, 'com_fields' => ['external-reference' => 'typed-value', 'audiences' => []],
], 'idempotencyKey' => Json::uuid()]);
$result = $s['executor']->apply($plan['confirmationToken']);
$check($s['http']->writes[0]['body'] === ['title' => 'Typed tool alias', 'catid' => 2, 'external-reference' => 'typed-value', 'audiences' => []]
	&& $result['verification']['status'] === 'verified', 'The typed article tool must retain, normalize and verify com_fields input.');
$plan = $s['tools']->call('joomla_content_article_update_plan', ['id' => 1, 'data' => ['com_fields' => ['external-reference' => 'updated-value']], 'idempotencyKey' => Json::uuid()]);
$result = $s['executor']->apply($plan['confirmationToken']);
$check($s['http']->writes[1]['body']['external-reference'] === 'updated-value'
	&& $result['verification']['status'] === 'verified', 'Typed partial updates dropped a custom field.');
$reject(static fn () => $s['tools']->call('joomla_content_article_create_plan', ['data' => ['title' => 123, 'catid' => 2,
	'external-reference' => 'value'], 'idempotencyKey' => Json::uuid()]), 'INVALID_INPUT');

foreach ([
	['unknown-field' => 'value'], ['private-field' => 'value'], ['foreign-context' => 'value'],
	['external-reference' => null], ['com_fields' => ['external-reference' => null]],
	['com_fields' => []], ['com_fields' => 'invalid'], ['com_fields' => ['value']], ['com_fields' => ['unknown-field' => 'value']],
	['external-reference' => 'value', 'com_fields' => ['external-reference' => 'duplicate']], ['com_fields' => ['title' => 'core collision']],
	['com_fields' => ['123' => 'numeric-only']], ['com_fields' => ['0' => 'numeric-only']],
] as $invalid)
{
	$writes = count($s['http']->writes);
	$plans = count($s['store']->find('plan'));
	$reject(static fn () => $s['executor']->plan('content.articles.create', ['data' => ['title' => 'Rejected article', 'catid' => 2] + $invalid], Json::uuid()), 'INVALID_INPUT');
	$check(count($s['http']->writes) === $writes && count($s['store']->find('plan')) === $plans, 'Invalid custom data wrote a resource or persisted an executable plan.');
}

foreach ([
	['content.categories', 'com_content.categories', '/v1/fields/content/categories', 'structure.write', ['title' => 'Article category'], true],
	['contacts.contacts', 'com_contact.contact', '/v1/fields/contacts/contact', 'content.write', ['name' => 'Contact'], false],
	['users.users', 'com_users.user', '/v1/fields/users', 'users.admin', ['name' => 'User', 'username' => 'custom-fields-user', 'email' => 'test@example.invalid'], false],
] as [$entity, $context, $route, $scope, $core, $nested])
{
	$s = $fixture([$route => [$field('external-reference', ['context' => $context])]]);
	$approve($s, $scope);
	$plan = $s['executor']->plan($entity . '.create', ['data' => $core + ['external-reference' => 'entity-value']], Json::uuid());
	$check($plan['operation']['customFields']['context'] === $context, 'The runtime selected the wrong entity custom field context.');
	$result = $s['executor']->apply($plan['confirmationToken']);
	$body = $s['http']->writes[0]['body'];
	$check(($nested ? ($body['com_fields']['external-reference'] ?? null) : ($body['external-reference'] ?? null)) === 'entity-value'
		&& ($nested ? !isset($body['external-reference']) : !isset($body['com_fields'])), 'The Joomla entity API received an incorrect custom field wire shape.');
	$check($result['verification']['status'] === 'verified', 'Native custom field read-back was not verified for ' . $entity . '.');

	if ($nested)
	{
		$plan = $s['executor']->plan($entity . '.create', ['data' => $core + ['com_fields' => ['external-reference' => 'nested-alias']]], Json::uuid());
		$result = $s['executor']->apply($plan['confirmationToken']);
		$check($s['http']->writes[1]['body']['com_fields'] === ['external-reference' => 'nested-alias']
			&& $result['verification']['status'] === 'verified', 'Category com_fields aliases must retain the native nested wire shape.');
	}
}

foreach (['hiddenField' => 'partial', 'changedField' => 'uncertain'] as $mode => $expected)
{
	$s = $fixture($articleFields);
	$approve($s);
	$s['http']->{$mode} = 'external-reference';
	$plan = $s['executor']->plan('content.articles.create', ['data' => ['title' => 'Native filtering', 'catid' => 2, 'external-reference' => 'requested']], Json::uuid());
	$result = $s['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === $expected, 'A hidden or changed custom field was falsely reported as verified.');
}

foreach ([
	[new Response(403, [], '{"errors":[{"title":"Permission denied"}]}'), 'CUSTOM_FIELDS_UNAVAILABLE'],
	[new Response(200, [], '{"data":{"not":"a collection"}}'), 'CUSTOM_FIELDS_UNAVAILABLE'],
	[new Response(200, [], '{"data":[]}'), 'INVALID_INPUT'],
] as [$response, $expected])
{
	$s = $fixture($articleFields);
	$s['http']->fieldResponse = $response;
	$reject(static fn () => $s['executor']->plan('content.articles.create', ['data' => ['title' => 'Unavailable fields', 'catid' => 2, 'external-reference' => 'value']], Json::uuid()), $expected);
	$check($s['http']->writes === [] && $s['store']->find('plan') === [], 'Failed discovery must not permit mutation or confirmation.');
}
$s = $fixture($articleFields);
$s['http']->repeatPage = true;
$reject(static fn () => $s['executor']->describe('content.articles.create'), 'CUSTOM_FIELDS_UNAVAILABLE');
$check(count($s['http']->discovery) <= 100 && $s['http']->writes === [], 'Unbounded or repeated discovery pages escaped the fail-closed limit.');
$s = $fixture(['/v1/fields/content/articles' => [$field('title')]]);
$reject(static fn () => $s['executor']->describe('content.articles.create'), 'CUSTOM_FIELDS_UNAVAILABLE');
$check($s['http']->writes === [], 'A discovered field name collision must not replace the core form contract.');
foreach ([null, 'invalid', -1, false] as $group)
{
	$s = $fixture(['/v1/fields/content/articles' => [$field('external-reference', ['group_id' => $group])]]);
	$reject(static fn () => $s['executor']->describe('content.articles.create'), 'CUSTOM_FIELDS_UNAVAILABLE');
}
$s = $fixture($articleFields);
$source = $s['store']->one('action', ['name' => 'fields.content-articles.list']);
$s['store']->update('action', ['published' => 0], ['id' => $source['id']]);
$s['catalogue']->refresh();
$resolved = $s['catalogue']->action('content.articles.create');
$staticSchema = Json::decode($s['catalogue']->schema((int) $resolved['binding']['input_schema_id']));
$description = $s['executor']->describe('content.articles.create');
$check($description['inputSchema'] === $staticSchema && $description['customFields']['status'] === 'unavailable'
	&& $description['customFields']['fields'] === [], 'Unavailable field discovery must retain the unchanged core action schema and report its unavailable status.');
$reject(static fn () => $s['executor']->plan('content.articles.create', ['data' => ['title' => 'Unauthorized discovery', 'catid' => 2,
	'external-reference' => 'value']], Json::uuid()), 'CUSTOM_FIELDS_UNAVAILABLE');
$check($s['http']->discovery === [], 'Unpublished field discovery actions must remain behind catalogue authorization.');
$s = $fixture($articleFields, 'cli');
$description = $s['executor']->describe('content.articles.create');
$check(!isset($description['customFields']) && $s['http']->discovery === [], 'API field discovery must not widen the native console model schema.');

echo Json::encode(['checks' => $checks, 'customFieldContracts' => 'passed with recording API and transactional memory doubles', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
