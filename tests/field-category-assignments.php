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
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\CoreEntityDefinition;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Service\ApiWriteVerification;
use VDM\Component\JoomEngineMcp\Administrator\Service\FieldCategoryAssignments;
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

/** Recording Joomla API double; persistence assertions here are not live Joomla evidence. */
$http = new class implements ClientInterface
{
	/** @var array<int,array<string,mixed>> Fake resources. */
	public array $items = [];
	/** @var int Read count. */
	public int $reads = 0;
	/** @var int Write count. */
	public int $writes = 0;
	/** @var array Most recent mutation body. */
	public array $body = [];
	/** @inheritDoc */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$id = (int) basename($request->getUri()->getPath());
		if ($request->getMethod() === 'GET')
		{
			$this->reads++;
			return isset($this->items[$id]) ? new Response(200, [], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $this->items[$id]]])) : new Response(404);
		}
		$this->writes++;
		$data = Json::decode((string) $request->getBody());
		$this->body = $data;
		if ($request->getMethod() === 'POST')
		{
			$id = count($this->items) + 1;
		}
		$this->items[$id] = array_replace($this->items[$id] ?? [], $data);
		$ids = empty($data['only_use_in_subform']) ? array_map('intval', $data['assigned_cat_ids'] ?? []) : [-1];
		$this->items[$id]['assigned_cat_ids'] = in_array(-1, $ids, true) ? ['-1']
			: (array_map('strval', array_values(array_unique(array_filter($ids)))) ?: [0]);
		return new Response(200, [], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $this->items[$id]]]));
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
$principal = new Principal('joomla:17', 'api', [1]);
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings, static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
$now = 1900000000;
$clock = static function () use (&$now): int
{
	return $now;
};
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
$builder = new ApiRequestBuilder();
$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder);
$input = ['data' => ['title' => 'New field', 'type' => 'text']];
$reject(static fn () => $executor->plan('fields.content-articles.create', $input, Json::uuid()), 'PERMISSION_REQUIRED');
$check($http->writes === 0, 'Field normalization bypassed the explicit write grant.');
$permission = $permissions->request(['toolsets' => ['structure.write', 'users.admin'], 'duration' => '30-minutes', 'reason' => 'Native field category assignment regression']);
$permissions->approve($permission['requestId'], $permission['acknowledgement']);
foreach (FieldCategoryAssignments::CONTEXTS as $base => $contract)
{
	$create = $base . '.create';
	$update = $base . '.update';
	$reject(static fn () => $builder->build(['id' => 41, 'data' => ['title' => 'Unknown assignments']], $catalogue->action($update)['binding']['configuration']), 'PREFLIGHT_FAILED');
	foreach ([[8], [12, 8], [0], [], [-1], [0, 8], [-1, 8], [8, 8]] as $selection)
	{
		$data = ['title' => 'Assigned field', 'type' => 'text', 'assigned_cat_ids' => $selection];
		$writes = $http->writes;
		$preview = $executor->plan($create, ['data' => $data], Json::uuid(), true);
		$check($preview['dryRun'] && $http->writes === $writes, $create . ' preview wrote data.');
		$plan = $executor->plan($create, ['data' => $data], Json::uuid());
		$result = $executor->apply($plan['confirmationToken']);
		$id = count($http->items);
		$check($http->body['assigned_cat_ids'] === $selection, $create . ' rewrote approved category input.');
		$check($result['verification']['status'] === 'verified' && in_array('assigned_cat_ids', $result['verification']['matchedFields'], true),
			$create . ' failed native category membership verification.');
		$check($executor->apply($plan['confirmationToken'])['idempotentReplay'] && $http->writes === $writes + 1, $create . ' replay created a duplicate.');
		$before = $http->items[$id]['assigned_cat_ids'];
		$plan = $executor->plan($update, ['id' => $id, 'data' => ['title' => 'Renamed']], Json::uuid());
		$executor->apply($plan['confirmationToken']);
		$check($http->body['assigned_cat_ids'] === $before && $http->items[$id]['assigned_cat_ids'] === $before, $update . ' lost omitted category assignments.');
		foreach ([[29], [0], [], [-1]] as $changed)
		{
			$plan = $executor->plan($update, ['id' => $id, 'data' => ['assigned_cat_ids' => $changed]], Json::uuid());
			$result = $executor->apply($plan['confirmationToken']);
			$check($http->body['assigned_cat_ids'] === $changed && $result['verification']['status'] === 'verified', $update . ' failed an explicit selection.');
		}
	}
	$plan = $executor->plan($create, ['data' => ['title' => 'Subform child', 'type' => 'text', 'assigned_cat_ids' => [8], 'only_use_in_subform' => 1]], Json::uuid());
	$result = $executor->apply($plan['confirmationToken']);
	$check(in_array('assigned_cat_ids', $result['verification']['matchedFields'], true) && $http->items[count($http->items)]['assigned_cat_ids'] === ['-1'],
		$create . ' failed the approved subform-only None contract.');
	foreach ([null, 8, '8', ['8'], [true], [-2], [2147483648], ['bad' => 8], array_fill(0, 1001, 8)] as $bad)
	{
		$reject(static fn () => $executor->plan($create, ['data' => ['title' => 'Invalid', 'assigned_cat_ids' => $bad]], Json::uuid()), 'INVALID_INPUT');
	}
	$write = $catalogue->action($create);
	$read = $catalogue->action($base . '.get');
	foreach ([[8, 9], [true], '8', (object) ['0' => 8], [-1, 8], null] as $bad)
	{
		$check(ApiWriteVerification::compare($write, $read, 'assigned_cat_ids', [8], $bad) === false, $base . ' accepted mismatched categories.');
	}
	$check(ApiWriteVerification::compare($write, $read, 'assigned_cat_ids', [8], [-1]) === false, $base . ' inferred a subform flag from observed None.');
	$changed = $write;
	$changed['binding']['configuration']['route'] = '/v1/custom';
	$check(ApiWriteVerification::compare($changed, $read, 'assigned_cat_ids', [8], ['8']) === null, 'A customized route inherited membership semantics.');
}

$bindingRows = array_column($seed['binding'], null, 'name');
$schemaRows = array_column($seed['schema'], null, 'id');
foreach (FieldCategoryAssignments::CONTEXTS as $base => $contract)
{
	$model = new class
	{
		public array $saved = [];
		public array $stored = ['id' => 41, 'title' => 'Existing', 'default_value' => '', 'assigned_cat_ids' => ['8']];
		public function setState(string $key, mixed $value): void {}
		public function getState(string $key, int $default = 0): int { return 41; }
		public function getItem(int $id): object { return (object) $this->stored; }
		public function save(array $data): bool
		{
			$this->saved = $data;
			$ids = empty($data['only_use_in_subform']) ? array_map('intval', $data['assigned_cat_ids'] ?? []) : [-1];
			$this->stored = ['id' => 41] + $data;
			$this->stored['assigned_cat_ids'] = in_array(-1, $ids, true) ? ['-1'] : (array_map('strval', array_values(array_unique(array_filter($ids)))) ?: [0]);
			return true;
		}
	};
	$models = new class ($model) implements ModelProviderInterface
	{
		private object $model;
		public function __construct(object $model) { $this->model = $model; }
		public function administrator(string $component, string $modelName): object { return $this->model; }
	};
	$createNative = static fn (array $binding): CoreEntityAction => new CoreEntityAction(
		new CoreEntityDefinition(...$binding['configuration']['entity']), $binding['configuration']['operation'], $models);
	foreach (['create', 'update'] as $operation)
	{
		$model->stored['only_use_in_subform'] = 0;
		$binding = $bindingRows[$base . '.' . $operation . '.cli'];
		$binding['configuration'] = Json::decode($binding['configuration']);
		$action = $createNative($binding);
		$readBinding = $bindingRows[$base . '.get.cli'];
		$readBinding['configuration'] = Json::decode($readBinding['configuration']);
		$write = ['action' => ['name' => $base . '.' . $operation], 'binding' => $binding];
		$read = ['action' => ['name' => $base . '.get'], 'binding' => $readBinding];
		foreach ([[8], [29], [0], [], [-1], [0, 8], [-1, 8]] as $selection)
		{
			$input = ['data' => ['title' => 'Native field', 'assigned_cat_ids' => $selection]];
			if ($operation === 'update') { $input['id'] = 41; }
			$schemas->input($input, $schemaRows[$binding['input_schema_id']]['document']);
			$action->execute($input);
			$result = $action->execute($input + ['dryRun' => false, '_edgeConfirmed' => true]);
			$check($model->saved['assigned_cat_ids'] === $selection
				&& ApiWriteVerification::compare($write, $read, 'assigned_cat_ids', $selection, $result['item']['assigned_cat_ids']) === true,
				$base . ' native ' . $operation . ' did not save/read categories.');
		}
		$input = ['data' => ['title' => 'Subform child', 'assigned_cat_ids' => [8], 'only_use_in_subform' => 1]];
		if ($operation === 'update') { $input['id'] = 41; }
		$result = $action->execute($input + ['dryRun' => false, '_edgeConfirmed' => true]);
		$check(ApiWriteVerification::compare($write, $read, 'assigned_cat_ids', [8], $result['item']['assigned_cat_ids'], $input['data']) === true, $base . ' native subform selection is not verified.');
	}
	$model->stored['assigned_cat_ids'] = ['12', '8'];
	unset($model->stored['only_use_in_subform']);
	$binding = $bindingRows[$base . '.update.cli'];
	$binding['configuration'] = Json::decode($binding['configuration']);
	$createNative($binding)->execute(['id' => 41, 'data' => ['title' => 'Renamed'], 'dryRun' => false, '_edgeConfirmed' => true]);
	$check($model->saved['assigned_cat_ids'] === ['12', '8'], $base . ' native update lost omitted assignments.');
}

// Reconstruct the legacy input-schema graph using retained immutable contracts.
$legacy = ['source' => '2cff50f4f6b440da3c684f9995a77efad32e1a36', 'runtimeSource' => hash('sha256', 'legacy-category-contract'), 'entities' => $seed];
$upstream = Json::decode(file_get_contents(dirname(__DIR__) . '/data/upstream-contracts.json'));
$native = Json::decode(file_get_contents(dirname(__DIR__) . '/data/upstream-native.json'));
$originalApi = array_column($upstream['catalog']['api']['writeActions'], null, 'id');
$originalNative = [];
foreach ($native['actions'] as $action) { $originalNative[$action['descriptor']['name']] = $action; }
foreach ($legacy['entities'] as &$rows)
{
	foreach ($rows as &$row) { if (strlen($row['seed_revision']) === 64) { $row['seed_revision'] = $legacy['runtimeSource']; } }
	unset($row);
}
unset($rows);
foreach ($legacy['entities']['binding'] as &$binding)
{
	if (preg_match('/\A(.+)\.(create|update)\.(api|cli)\z/D', $binding['name'], $parts) !== 1 || !isset(FieldCategoryAssignments::CONTEXTS[$parts[1]])) { continue; }
	$name = $parts[1] . '.' . $parts[2];
	$document = $parts[3] === 'api' ? $originalApi[$name]['inputSchema'] : $originalNative[$name]['descriptor']['inputSchema'];
	foreach ($seed['schema'] as $schema)
	{
		if (Json::canonical(Json::decode($schema['document'])) === Json::canonical($document)) { $binding['input_schema_id'] = $schema['id']; break; }
	}
	$config = Json::decode($binding['configuration']);
	if ($parts[3] === 'cli') { $config = $originalNative[$name]['configuration']; }
	else { $config['preserve_fields'] = []; }
	$binding['configuration'] = Json::encode($config);
}
unset($binding);
foreach (['managed', 'customized-binding', 'customized-schema'] as $case)
{
	$installed = new MemoryStore();
	$updater = new SeedUpdater($installed);
	$updater->apply($legacy);
	$old = $installed->one('binding', ['name' => 'fields.content-articles.create.api']);
	if ($case === 'customized-binding') { $installed->update('binding', ['customized' => 1], ['id' => $old['id']]); }
	if ($case === 'customized-schema') { $installed->update('schema', ['customized' => 1], ['id' => $old['input_schema_id']]); }
	$updater->apply(['source' => $legacy['source'], 'runtimeSource' => hash_file('sha256', dirname(__DIR__) . '/data/runtime-tools.json'), 'entities' => $seed]);
	$current = $installed->one('binding', ['name' => $old['name']]);
	$document = Json::decode($installed->one('schema', ['id' => $current['input_schema_id']])['document']);
	$check(isset($document['properties']['data']['properties']['assigned_cat_ids']) === ($case === 'managed'), 'Seed upgrade lost ownership for ' . $case . '.');
}
echo Json::encode(['checks' => $checks, 'fieldCategoryAssignments' => 'passed with recording API/native model doubles', 'liveJoomla' => 'not implied by this recording suite']) . PHP_EOL;
