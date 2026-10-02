<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Http\Client\HttpClient;
use Nyholm\Psr7\Response;
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
use VDM\Component\JoomEngineMcp\Administrator\Service\ApiWriteVerification;
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
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
$guid = '9cfb0aca-8c0f-4a04-b2b0-a369ed5c260e';
$contracts = ['fields' => [
	'published' => ['representation' => 'integer'],
	'options' => ['representation' => 'json', 'properties' => [
		'rows' => ['representation' => 'array', 'items' => ['representation' => 'object',
			'properties' => ['enabled' => ['representation' => 'integer']]]]]],
], 'request_only' => ['add_php']];

/** Independent responses exercise the complete approval, claim and settlement path. */
$fixture = static function (string $identity, array $record, array $mutation, bool $deleted = false, array $generation = []) use ($seed, $contracts): array
{
	$rows = $seed;
	$key = $identity === 'integer' ? 'id' : ($identity === 'guid' ? 'guid' : 'key');
	$kind = $identity === 'integer' ? 'positive-integer' : ($identity === 'guid' ? 'guid' : 'unique-key');
	$schemaIds = [];
	$actions = [];

	foreach ($rows['action'] as &$action)
	{
		if (str_starts_with($action['name'], 'content.categories.'))
		{
			$operation = substr($action['name'], strrpos($action['name'], '.') + 1);
			$action['name'] = 'generated.widgets.' . $operation;
			$actions[$action['id']] = $operation;
			$schemaIds[$action['input_schema_id']] = $operation;
		}
	}
	unset($action);
	$idSchema = $identity === 'integer' ? ['type' => 'integer', 'minimum' => 1]
		: ['type' => 'string', 'minLength' => 1, 'maxLength' => 255];

	foreach ($rows['schema'] as &$schema)
	{
		if (isset($schemaIds[$schema['id']]))
		{
			$operation = $schemaIds[$schema['id']];
			$properties = $operation === 'create' ? [] : [$key => $idSchema];
			$required = array_keys($properties);

			if (in_array($operation, ['create', 'update'], true))
			{
				$properties['data'] = ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => true];

				if ($operation === 'create' && isset($generation['guid']))
				{
					$properties['data']['properties'] = ['guid' => ['type' => 'string', 'format' => 'uuid']];
					$properties['data']['required'] = ['guid'];
				}
				$required[] = 'data';
			}
			$schema['document'] = Json::encode(['type' => 'object', 'properties' => (object) $properties,
				'required' => $required, 'additionalProperties' => false]);
		}
	}
	unset($schema);
	$readBindingId = null;

	foreach ($rows['binding'] as &$binding)
	{
		if (isset($actions[$binding['action_id']]) && $binding['track'] === 'api')
		{
			$operation = $actions[$binding['action_id']];
			$config = Json::decode($binding['configuration']);
			$config['route'] = '/v1/example/widgets' . ($operation === 'create' ? '' : '/:' . $key);
			$config['route_parameters'] = $operation === 'create' ? [] : [['name' => $key, 'kind' => $kind]];
			$config['body_defaults'] = (object) [];
			$config['query_defaults'] = (object) [];
			$config['preserve_fields'] = [];
			$config['read_action'] = 'generated.widgets.get';
			$config['api_form'] = ['verification' => $contracts, 'generation' => $generation];
			$binding['configuration'] = Json::encode($config);
			$binding['params'] = Json::encode(['verification' => ['operation' => $operation, 'read_action' => 'generated.widgets.get',
				'primary_key' => $key, 'input_key' => $key, 'read_input_key' => $key, 'identity_type' => $identity]]);
			$binding['definition'] = Json::encode(['nativeRoute' => ['route' => $config['route'],
				'controller' => 'widgets.' . ($operation === 'get' ? 'displayItem' : $operation), 'defaults' => ['component' => 'com_example']]]);

			if ($operation === 'get')
			{
				$readBindingId = $binding['id'];
			}
		}
	}
	unset($binding);
	$http = new class implements HttpClient
	{
		/** @var array Independent item read-back. */
		public array $record = [];
		/** @var array Accepted mutation item. */
		public array $mutation = [];
		/** @var bool Whether deletion makes the item unavailable. */
		public bool $deleted = false;
		/** @var int Native writes performed. */
		public int $writes = 0;
		/** @var array Captured requests without headers or credentials. */
		public array $requests = [];
		/** @var ?Closure Callback simulating a definition change during the native mutation. */
		public ?Closure $afterWrite = null;
		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$method = $request->getMethod();
			$this->requests[] = ['method' => $method, 'path' => $request->getUri()->getPath()];

			if ($method === 'GET' && $this->deleted && $this->writes > 0)
			{
				return new Response(404, ['Content-Type' => 'application/vnd.api+json'], Json::encode(['errors' => [['status' => '404']]]));
			}

			if ($method !== 'GET')
			{
				$this->writes++;

				if ($this->afterWrite !== null)
				{
					($this->afterWrite)();
				}
			}
			$item = $method === 'GET' ? $this->record : $this->mutation;

			return new Response($method === 'DELETE' ? 204 : 200, ['Content-Type' => 'application/vnd.api+json'],
				$method === 'DELETE' ? '' : Json::encode(['data' => ['id' => $item['id'] ?? '87', 'attributes' => $item]]));
		}
	};
	$http->record = $record;
	$http->mutation = $mutation;
	$http->deleted = $deleted;
	$store = new MemoryStore($rows);
	$principal = new Principal('joomla:17', 'api', [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $name): bool => true, static fn (string $entity, string $handler): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder);
	$request = $permissions->request(['toolsets' => ['structure.write'], 'duration' => '30-minutes', 'reason' => 'Generated API read-back contract tests']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);
	$dispatcher = new ToolDispatcher($catalogue, $executor, $permissions, $schemas, $principal, $settings);

	return compact('http', 'store', 'catalogue', 'executor', 'state', 'dispatcher', 'key', 'readBindingId');
};
$run = static function (array $fixture, string $operation, array $input, string $status) use ($check): array
{
	$plan = $fixture['executor']->plan('generated.widgets.' . $operation, $input, Json::uuid());
	$check($fixture['http']->writes === 0, 'Planning never performs a mutation.');
	$result = $fixture['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === $status, 'Unexpected generated verification: ' . Json::encode($result['verification']));
	$check($fixture['http']->writes === 1, 'Apply performs exactly one write.');
	$check(($fixture['store']->find('lease') === []) === ($status !== 'uncertain'), 'Uncertain generated writes retain their lease.');
	$count = count($fixture['http']->requests);
	$replay = $fixture['executor']->apply($plan['confirmationToken']);
	$check($replay['idempotentReplay'] && count($fixture['http']->requests) === $count, 'Reapplying never performs new read or mutation I/O.');

	return $result;
};

foreach (['integer' => 87, 'guid' => $guid, 'string' => '0012'] as $type => $identity)
{
	$key = $type === 'integer' ? 'id' : ($type === 'guid' ? 'guid' : 'key');
	$record = [$key => $identity, 'name' => 'Example', 'published' => '1'];
	$f = $fixture($type, $record, $record);
	$run($f, 'create', ['data' => ['name' => 'Example', 'published' => 1]], 'verified');
	$check(end($f['http']->requests)['path'] === '/api/index.php/v1/example/widgets/' . $identity, 'Created identity selects its exact declared read route.');
	$run($fixture($type, $record, $record), 'update', [$key => $identity, 'data' => ['published' => 1]], 'verified');
	$run($fixture($type, $record, [], true), 'delete', [$key => $identity], 'verified');
	$wrong = array_replace($record, [$key => $type === 'integer' ? 88 : ($type === 'guid' ? Json::uuid() : '12')]);
	$run($fixture($type, $wrong, $record), 'create', ['data' => ['name' => 'Example']], 'uncertain');
}
$run($fixture('guid', ['guid' => strtoupper($guid), 'name' => 'Example'], ['guid' => $guid]), 'update',
	['guid' => $guid, 'data' => ['name' => 'Example']], 'verified');
$f = $fixture('guid', ['guid' => $guid, 'name' => 'Example'], ['name' => 'Example']);
$run($f, 'create', ['data' => ['guid' => $guid, 'name' => 'Example']], 'verified');
$run($fixture('guid', ['name' => 'Example'], ['name' => 'Example']), 'create', ['data' => ['name' => 'Example']], 'uncertain');
$run($fixture('guid', ['guid' => $guid, 'name' => 'Example'], ['guid' => 'not-a-guid']), 'create', ['data' => ['name' => 'Example']], 'uncertain');
$f = $fixture('guid', ['guid' => $guid, 'name' => 'Example'], ['guid' => Json::uuid()]);
$run($f, 'update', ['guid' => $guid, 'data' => ['name' => 'Example']], 'uncertain');
$check(count(array_filter($f['http']->requests, static fn (array $request): bool => $request['method'] === 'GET')) === 2,
	'A mismatched mutation identity prevents a read of a different resource.');
$f = $fixture('guid', ['guid' => Json::uuid(), 'name' => 'Example'], ['guid' => $guid]);
try
{
	$f['executor']->plan('generated.widgets.update', ['guid' => $guid, 'data' => ['name' => 'Example']], Json::uuid());
	throw new RuntimeException('A snapshot of another resource must be rejected.');
}
catch (OperationException $error)
{
	$check($error->getIdentifier() === 'PRECONDITION_CHANGED' && $f['http']->writes === 0,
		'A mismatched GUID snapshot prevents creating an executable write plan.');
}
$f = $fixture('guid', ['guid' => $guid, 'name' => 'Example', 'add_php' => 1], ['guid' => $guid]);
$result = $run($f, 'create', ['data' => ['name' => 'Example', 'add_php' => 1, 'write_only' => 'inert fixture']], 'partial');
$check($result['verification']['requestOnlyFields'] === ['add_php'] && $result['verification']['unobservableFields'] === ['write_only']
	&& $result['verification']['matchedFields'] === ['name'], 'Request-only controls are distinct from matched and unobservable saved fields.');

$f = $fixture('guid', ['guid' => $guid, 'name' => 'Example'], ['guid' => $guid]);
$write = $f['catalogue']->action('generated.widgets.create');
$read = $f['catalogue']->action('generated.widgets.get');
$check(ApiWriteVerification::compare($write, $read, 'options', ['rows' => [['enabled' => 1]]], '{"rows":[{"enabled":"1"}]}') === true,
	'Declared recursive integer representations verify independently stored JSON.');
foreach ([
	['rows' => [['enabled' => '01']]], ['rows' => [['enabled' => true]]], ['rows' => [['enabled' => 1, 'additional' => 'value']]],
	['rows' => (object) ['0' => ['enabled' => 1]]], ['rows' => [['enabled' => 1], ['enabled' => 1]]],
] as $observed)
{
	$check(ApiWriteVerification::compare($write, $read, 'options', ['rows' => [['enabled' => 1]]], $observed) === false,
		'Declared child conversion never hides malformed values or changed shape.');
}
$check(ApiWriteVerification::compare($write, $read, 'untyped', ['value' => 1], ['value' => '1']) === false,
	'Undeclared nested numeric fields retain exact JSON comparison.');
$named = $write;
$named['binding']['configuration']['api_form']['verification']['fields']['named'] = ['representation' => 'json', 'ordered' => true,
	'additionalProperties' => ['representation' => 'object', 'properties' => ['enabled' => ['representation' => 'integer']]]];
$desired = ['row1' => ['enabled' => 1], 'row2' => ['enabled' => 0]];
$check(ApiWriteVerification::compare($named, $read, 'named', $desired, ['row1' => ['enabled' => '1'], 'row2' => ['enabled' => '0']]) === true
	&& ApiWriteVerification::compare($named, $read, 'named', $desired, ['row2' => ['enabled' => '0'], 'row1' => ['enabled' => '1']]) === false,
	'Declared ordered named subform rows retain their identity and order.');
$changed = $read;
$changed['binding']['configuration']['route'] = '/v1/other/widgets/:guid';
$check(ApiWriteVerification::compare($write, $changed, 'published', 1, '1') === null,
	'A customized foreign read does not inherit native form comparison contracts.');
$check(ApiWriteVerification::identity('0012', 'integer') === null && ApiWriteVerification::identity('0012', 'string') === '0012'
	&& ApiWriteVerification::identity('1e2', 'integer') === null && ApiWriteVerification::identity(true, 'integer') === null,
	'Identity comparison never invents numeric equivalence for typed string keys.');

$f = $fixture('guid', ['guid' => $guid, 'name' => 'Example'], ['guid' => $guid]);
$plan = $f['executor']->plan('generated.widgets.create', ['data' => ['name' => 'Example']], Json::uuid());
$binding = $f['store']->one('binding', ['id' => $f['readBindingId']]);
$config = Json::decode($binding['configuration']);
$config['route'] = '/v1/other/widgets/:guid';
$f['store']->update('binding', ['configuration' => Json::encode($config)], ['id' => $f['readBindingId']]);
try
{
	$f['executor']->apply($plan['confirmationToken']);
	throw new RuntimeException('A changed independent read must invalidate its plan.');
}
catch (OperationException $error)
{
	$check($error->getIdentifier() === 'PLAN_STALE' && $f['http']->writes === 0,
		'Changing the independent read after approval is rejected before mutation.');
}
$f = $fixture('guid', ['guid' => $guid, 'name' => 'Example'], ['guid' => $guid]);
$f['http']->afterWrite = static function () use ($f): void
{
	$binding = $f['store']->one('binding', ['id' => $f['readBindingId']]);
	$config = Json::decode($binding['configuration']);
	$config['route'] = '/v1/other/widgets/:guid';
	$f['store']->update('binding', ['configuration' => Json::encode($config)], ['id' => $f['readBindingId']]);
};
$run($f, 'create', ['data' => ['name' => 'Example']], 'uncertain');
$check(count($f['http']->requests) === 1, 'A changed verification definition after mutation is retained as uncertain without using the changed route.');

// Primary GUID generation is part of the approved input, never an apply-time effect.
$generation = ['guid' => ['kind' => 'guid', 'on' => 'create', 'format' => 'uuid-v4']];
$f = $fixture('guid', [], [], false, $generation);
$key = '4c6d8985-7dc1-7e6d-b197-c2f732f3559d';
$input = ['data' => ['name' => 'Example']];
$dry = $f['executor']->plan('generated.widgets.create', $input, $key, true);
$generated = $dry['operation']['generatedFields']['guid']['value'];
$check(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $generated) === 1
	&& $dry['operation']['generatedFields']['guid']['source'] === 'installed-native-form'
	&& $f['http']->writes === 0 && $f['store']->find('plan') === [], 'Dry planning discloses a v4 primary GUID without storing a plan or mutating.');
$first = $f['executor']->plan('generated.widgets.create', $input, $key);
$second = $f['executor']->plan('generated.widgets.create', $input, strtoupper($key));
$check($first['operation']['generatedFields'] === $second['operation']['generatedFields']
	&& $first['operation']['fingerprint'] === $second['operation']['fingerprint'],
	'Repeated planning with the same normalized operation key binds the same identity and fingerprint.');
$stored = $f['state']->resolve($first['confirmationToken']);
$check($stored['payload']['input']['data']['guid'] === $generated,
	'The generated primary GUID is frozen in the encrypted approved form input.');
$f['http']->record = $f['http']->mutation = ['guid' => $generated, 'name' => 'Example'];
$result = $f['executor']->apply($first['confirmationToken']);
$count = count($f['http']->requests);
$replay = $f['executor']->apply($second['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $result['verification']['id'] === $generated
	&& $replay['idempotentReplay'] && $f['http']->writes === 1 && count($f['http']->requests) === $count,
	'Two approved plans with the same generated identity execute once and replay the same independent verification.');
$other = $f['executor']->plan('generated.widgets.create', $input, Json::uuid(), true);
$check($other['operation']['generatedFields']['guid']['value'] !== $generated, 'A new operation key creates a different primary GUID.');
$explicit = $f['executor']->plan('generated.widgets.create', ['data' => ['name' => 'Example', 'guid' => $guid]], Json::uuid(), true);
$check(!isset($explicit['operation']['generatedFields']), 'An explicitly supplied primary GUID is preserved and never regenerated.');
$update = $f['executor']->plan('generated.widgets.update', ['guid' => $generated, 'data' => ['name' => 'Example']], Json::uuid(), true);
$check(!isset($update['operation']['generatedFields']), 'Updates never generate a new identity even when create generation metadata is present.');
$description = $f['executor']->describe('generated.widgets.create');
$check($description['nativeForm']['generation'] === $generation && isset($description['inputSchema']),
	'Generated action descriptions expose the installed native form and generation contract.');
$relations = $fixture('guid', ['guid' => $guid], ['guid' => $guid], false, ['parent_guid' => ['kind' => 'guid', 'on' => 'create', 'format' => 'uuid-v4']]);
$relation = $relations['executor']->plan('generated.widgets.create', ['data' => ['name' => 'Example']], Json::uuid(), true);
$check(!isset($relation['operation']['generatedFields']), 'Relationship GUID policies can never create or guess another resource identity.');

// A real wire JSON object remains an object through the generic tool schema.
$f = $fixture('guid', [], [], false, $generation);
$wire = Json::native(Json::decode(Json::encode(['action' => 'generated.widgets.create', 'transport' => 'api',
	'idempotencyKey' => Json::uuid(), 'input' => ['data' => new stdClass()]]), false));
$check($wire['input']['data'] instanceof stdClass, 'Empty wire data is retained as an object rather than a JSON list.');
$dry = $f['dispatcher']->call('joomla_action_write_plan', $wire + ['dryRun' => true]);
$generated = $dry['operation']['generatedFields']['guid']['value'];
$check($f['http']->writes === 0 && $f['store']->find('plan') === [],
	'An empty native form with only a required primary GUID is fully prepared through the tool schema without mutation.');
$plan = $f['dispatcher']->call('joomla_action_write_plan', $wire);
$stored = $f['state']->resolve($plan['confirmationToken']);
$check($stored['payload']['input']['data'] === ['guid' => $generated],
	'Empty-object tool planning freezes precisely the required generated GUID.');
$f['http']->record = $f['http']->mutation = ['guid' => $generated];
$result = $f['dispatcher']->call('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
$count = count($f['http']->requests);
$replay = $f['dispatcher']->call('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
$check($result['verification']['status'] === 'verified' && $result['verification']['matchedFields'] === ['guid']
	&& $replay['idempotentReplay'] && $f['http']->writes === 1 && count($f['http']->requests) === $count,
	'The empty-object wire tool plan applies once, independently verifies its GUID and replays without new I/O.');
echo Json::encode(['checks' => $checks, 'generatedApiVerification' => 'passed with recording API and transactional state doubles',
	'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
restore_error_handler();
