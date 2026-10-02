<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\ApiRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;

require dirname(__DIR__) . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
};
$reject = static function (callable $operation, string $identifier) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $exception)
	{
		$check($exception->getIdentifier() === $identifier, 'Expected ' . $identifier . ', got ' . $exception->getIdentifier());
		return;
	}
	throw new RuntimeException('Expected rejection ' . $identifier);
};
$registration = static function (string $component, string $path, string $task, string $method, array $rules = []): array
{
	return ['method' => $method, 'route' => $path, 'controller' => 'record.' . $task,
		'variables' => array_keys($rules), 'rules' => $rules, 'defaults' => ['component' => $component, 'extension' => 'fixed-scope'],
		'required_extensions' => ['webservices/GeneratedFixture']];
};
$routes = [];
foreach (['com_componentbuilder', 'com_example', 'com_other'] as $component)
{
	$path = '/v1/' . substr($component, 4) . '/records';
	$routes[] = $registration($component, $path, 'add', 'POST');
	foreach (['id' => '(\\d+)', 'guid' => '([0-9a-fA-F-]{36})', 'external_key' => '([^/]+)'] as $variable => $rule)
	{
		$itemPath = $path . ($variable === 'id' ? '/:id' : '/' . $variable . '/:' . $variable);
		foreach (['GET' => 'displayItem', 'PATCH' => 'edit', 'DELETE' => 'delete'] as $method => $task)
		{
			$routes[] = $registration($component, $itemPath, $task, $method, [$variable => $rule]);
		}
	}
}
$form = ['schema' => ['type' => 'object', 'properties' => ['relation_guid' => ['type' => 'string',
	'pattern' => '^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$'], 'php_code' => ['type' => 'string']],
	'required' => ['relation_guid'], 'additionalProperties' => true],
	'fields' => ['relation_guid' => ['native_type' => 'text', 'validation' => 'guid']],
	'verification' => ['fields' => ['relation_guid' => ['representation' => 'string']], 'request_only' => ['not_required']],
	'provenance' => ['controller' => 'api/src/Controller/RecordController.php', 'form' => 'admin/forms/record.xml']];
foreach ($routes as &$route)
{
	if ($route['method'] === 'POST' && $route['defaults']['component'] === 'com_example')
	{
		$route['form_contract'] = $form;
	}
}
unset($route);
$builder = new CatalogueBuilder();
$seed = $builder->build(['commands' => [], 'unsupported' => []], ['routes' => $routes,
	'components' => ['com_componentbuilder', 'com_example', 'com_other']]);
$check(count($seed['entities']['provider']) === 3 && count($seed['entities']['action']) === 30,
	'Observed component contracts produce separate providers and one action per registered route.');
$schemas = [];
$validator = new SchemaValidator();
foreach ($seed['entities']['schema'] as $schema)
{
	$validator->document($schema['document']);
	$schemas[$schema['id']] = $schema['document'];
	$checks++;
}
$bindings = [];
foreach ($seed['entities']['binding'] as $binding)
{
	$config = Json::decode($binding['configuration']);
	$bindings[$config['method'] . ' ' . $config['route']] = $binding + ['config' => $config, 'verification' => Json::decode($binding['params'])['verification'] ?? []];
}
$guid = '62c69e4d-9b19-478a-a88a-6f00316ff1db';
$requestBuilder = new ApiRequestBuilder();
foreach (['componentbuilder', 'example', 'other'] as $code)
{
	$path = '/v1/' . $code . '/records';
	$create = $bindings['POST ' . $path];
	$numeric = $bindings['GET ' . $path . '/:id'];
	$check($create['config']['read_action'] . '.api' === $numeric['name']
		&& $create['verification']['primary_key'] === 'id' && $create['verification']['identity_type'] === 'integer',
		'Create readback selects its exact observed numeric item rather than an arbitrary GUID alias.');
	foreach (['guid' => [$guid, 'guid'], 'external_key' => ['0012 λ key', 'string']] as $key => [$value, $type])
	{
		$itemPath = $path . '/' . $key . '/:' . $key;
		$write = $bindings['PATCH ' . $itemPath];
		$read = $bindings['GET ' . $itemPath];
		$input = $validator->input([$key => $value, 'data' => ['php_code' => '<?php echo "literal source";']], $schemas[$write['input_schema_id']]);
		$request = $requestBuilder->build($input, $write['config']);
		$check($request['path'] === $path . '/' . $key . '/' . rawurlencode($value)
			&& $write['config']['read_action'] . '.api' === $read['name']
			&& $write['verification']['primary_key'] === $key && $write['verification']['read_input_key'] === $key
			&& $write['verification']['identity_type'] === $type && str_contains($request['body']['php_code'], '<?php'),
			'Typed aliases preserve native identifiers and inert source data with exact independent readback.');
	}
}
$create = $bindings['POST /v1/example/records'];
$check($create['config']['api_form'] === $form, 'Installed form descriptors and comparison rules remain bound to their native route.');
$reject(static fn () => $validator->input(['data' => ['php_code' => 'source']], $schemas[$create['input_schema_id']]), 'INVALID_INPUT');
$reject(static fn () => $validator->input(['data' => ['relation_guid' => 'invalid']], $schemas[$create['input_schema_id']]), 'INVALID_INPUT');
$valid = $validator->input(['data' => ['relation_guid' => $guid, 'php_code' => '<?php echo "inert";']], $schemas[$create['input_schema_id']]);
$check($requestBuilder->build($valid, $create['config'])['body']['relation_guid'] === $guid,
	'Actual native form validation reaches the complete generated action schema.');
$guidOnly = array_filter($routes, static fn (array $route): bool => $route['defaults']['component'] === 'com_other'
	&& ($route['variables'] === ['guid'] || $route['method'] === 'POST'));
$guidSeed = $builder->build(['commands' => []], ['routes' => array_values($guidOnly)]);
$guidCreate = array_values(array_filter($guidSeed['entities']['binding'], static fn (array $binding): bool => Json::decode($binding['configuration'])['method'] === 'POST'))[0];
$check(Json::decode($guidCreate['params'])['verification']['primary_key'] === 'guid'
	&& Json::decode($guidCreate['params'])['verification']['identity_type'] === 'guid',
	'A GUID-only native resource can verify creation without inventing a numeric route.');
$unreviewed = $registration('com_example', '/v1/example/records/guid/:guid', 'deleteByGuid', 'DELETE', ['guid' => '([0-9a-fA-F-]{36})']);
$check(ApiRegistry::unsupportedReason($unreviewed) !== null, 'An unregistered specialized task name never inherits native CRUD authority.');
$store = new MemoryStore();
(new SeedUpdater($store))->apply($seed);
$principal = new Principal();
$principal->deny('core.manage', 'com_example');
$enabled = ['com_componentbuilder' => true, 'com_example' => true, 'com_other' => true, 'webservices/GeneratedFixture' => true];
$catalogue = new Catalogue($store, new Authorizer(), $principal, $validator, new Settings(['joomla_version' => '6.1.3']),
	static function (string $extension) use (&$enabled): bool { return $enabled[$extension] ?? false; },
	static fn (string $entity, string $handler): bool => true);
$check(count($catalogue->all('action')) === 20, 'Denying one generated component hides only that component, without inheriting JCB access.');
$reject(static fn () => $catalogue->action(substr($create['name'], 0, -4)), 'DEFINITION_UNAVAILABLE');
$enabled['webservices/GeneratedFixture'] = false;
$catalogue->refresh();
$check($catalogue->all('action') === [], 'The observed webservices owner remains a required runtime extension for every generated action.');
$empty = $builder->build(['commands' => []], ['routes' => [], 'components' => ['com_example']]);
$check(count($empty['entities']['provider']) === 1 && $empty['entities']['provider'][0]['extension'] === 'com_example'
	&& $empty['entities']['action'] === [],
	'An explicit empty installed component scope retains provider identity and manufactures no routes.');
$jcb = $store->one('provider', ['name' => 'jcb.installed']);
$jcbActions = array_values(array_filter($store->find('action'), static fn (array $row): bool =>
	$row['provider_id'] === $jcb['id']));
$jcbBefore = Json::canonical($jcbActions);
$updated = (new SeedUpdater($store))->apply($empty);
$check($updated['retired'] > 0 && array_filter($store->find('action'), static fn (array $row): bool =>
	$row['provider_id'] === $create['provider_id'] && (int) $row['published'] === 1) === [],
	'Resync retires removed routes only for providers in the observed component scope.');
$jcbAfter = array_values(array_filter($store->find('action'), static fn (array $row): bool =>
	$row['provider_id'] === $jcb['id']));
$check(count($jcbAfter) === 10 && array_filter($jcbAfter, static fn (array $row): bool => (int) $row['published'] !== 1) === []
	&& Json::canonical($jcbAfter) === $jcbBefore && $store->one('provider', ['name' => 'jcb.installed']) === $jcb,
	'An example-only resync preserves every JCB action and its unselected provider unchanged.');
$commandScope = $builder->build(['commands' => [], 'component' => 'com_componentbuilder'], ['routes' => [], 'components' => []]);
$check(count($commandScope['entities']['provider']) === 1 && $commandScope['entities']['provider'][0]['extension'] === 'com_componentbuilder',
	'An explicit authoritative empty command scope can synchronize the JCB provider without inventing commands.');
$legacy = $builder->build(['commands' => []], ['routes' => []]);
$check(count($legacy['entities']['provider']) === 1 && $legacy['entities']['provider'][0]['extension'] === 'com_componentbuilder',
	'Legacy inventory without an explicit component scope retains its existing JCB synchronization behavior.');
$reject(static fn () => $builder->build(['commands' => []], ['routes' => [$routes[0]], 'components' => ['com_example']]),
	'JCB_INVENTORY_INVALID');

echo 'Generated API catalogue, identity and component authority contracts passed: ' . $checks . PHP_EOL;
