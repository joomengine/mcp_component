<?php
/**
 * @package    JoomEngine.Mcp
 * @created    02 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\InventoryTransport;
use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;
use VDM\Component\JoomEngineMcp\Administrator\Security\LocalPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/integration/bootstrap.php';
require dirname(__DIR__) . '/integration/HttpFixture.php';
$factory = $app->bootComponent('com_joomengine_mcp')->getMVCFactory();
$db = $container->get(DatabaseInterface::class);
$store = new JoomlaStore($db);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$phase = $argv[1] ?? '';
$started = hrtime(true);
$stateFile = '/tmp/mcp-full-api-catalogue.json';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
$hash = static fn (mixed $value): string => Json::canonicalHash($value);
$core = static function () use ($store, $hash): array
{
	$provider = $store->one('provider', ['name' => 'joomla.core']);
	if ($provider === null)
	{
		throw new RuntimeException('The native core catalogue provider is missing.');
	}
	$result = [];
	foreach (array_keys(Structure::definitions()) as $entity)
	{
		$records = $entity === 'provider' ? [$provider] : $store->find($entity, ['provider_id' => (int) $provider['id']], 10000);
		foreach ($records as $record)
		{
			$result[$entity . ':' . $record['id']] = $hash($record);
		}
	}
	ksort($result, SORT_STRING);
	return $result;
};
$saveState = static function (array $state) use ($stateFile): void
{
	$oldMask = umask(0077);
	try
	{
		if (file_put_contents($stateFile, Json::encode($state), LOCK_EX) === false)
		{
			throw new RuntimeException('The private full API fixture manifest could not be saved.');
		}
	}
	finally
	{
		umask($oldMask);
	}
};

if ($phase === 'prepare')
{
	$check(!is_file($stateFile), 'The full API acceptance starts without a stale fixture manifest');
	$pluginId = (int) $db->setQuery($db->createQuery()->select($db->quoteName('extension_id'))
		->from($db->quoteName('#__extensions'))->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
		->where($db->quoteName('folder') . ' = ' . $db->quote('webservices'))
		->where($db->quoteName('element') . ' = ' . $db->quote('mcpfulljcbapi')))->loadResult();
	$check($pluginId > 0 && is_file(JPATH_PLUGINS . '/webservices/mcpfulljcbapi/native-routing-provenance.json'),
		'Native Joomla installation owns the separate disposable compiler-rendered routing plugin');
	$pluginModel = $app->bootComponent('com_plugins')->getMVCFactory()->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
	$pluginModel->setCurrentUser($admin);
	$check($pluginModel->save(['extension_id' => $pluginId, 'enabled' => 1]),
		'Native plugin administration explicitly enables only the owned full API routing fixture');
	$archive = new ZipArchive();
	$check($archive->open('/tmp/mcp-full-api-componentbuilder.zip') === true
		&& hash_file('sha256', '/tmp/mcp-full-api-componentbuilder.zip') === 'a5870a1b162c9db1f8c23b87562389c46260838f471914132ef1d3d66fb8f55a',
		'The installed full component archive matches the exact supplied package');
	$controllers = 0;
	$installedSources = 0;
	for ($index = 0; $index < $archive->numFiles; $index++)
	{
		$name = $archive->getNameIndex($index);
		if (!is_string($name) || str_ends_with($name, '/'))
		{
			continue;
		}
		$relative = null;
		if (str_starts_with($name, 'api/src/'))
		{
			$relative = JPATH_ROOT . '/api/components/com_componentbuilder/' . substr($name, 4);
			$controllers += str_starts_with($name, 'api/src/Controller/') && str_ends_with($name, 'Controller.php') ? 1 : 0;
		}
		elseif (str_starts_with($name, 'admin/forms/') || str_starts_with($name, 'admin/src/Model/'))
		{
			$relative = JPATH_ADMINISTRATOR . '/components/com_componentbuilder/' . substr($name, 6);
		}
		if ($relative !== null)
		{
			$source = $archive->getFromIndex($index);
			$check(is_string($source) && is_file($relative) && hash_equals(hash('sha256', $source), hash_file('sha256', $relative)),
				'Native installer retains the supplied contract source ' . $name);
			$installedSources++;
		}
	}
	$archive->close();
	$check($controllers === 104, 'The supplied 102 CRUD and two read-only API controllers are actually installed');
	$principal = new LocalPrincipal($app);
	$packed = (new PhpProcess(PHP_BINARY, JPATH_COMPONENT . '/cli/jcb.php', JPATH_ROOT))->run([
		'protocol' => 'joomengine-worker/1', 'operation' => 'jcb.inventory', 'inventory_format' => InventoryTransport::FORMAT,
		'authority' => ['id' => $principal->getId(), 'track' => 'cli'],
	], 90, InventoryTransport::MAX_WIRE_BYTES, static fn (): bool => false);
	$check(!isset($packed['error']) && ($packed['inventory_format'] ?? '') === InventoryTransport::FORMAT,
		'The actual isolated inventory worker returns its opted-in compact full API contract');
	$compactBytes = strlen(Json::encode($packed));
	$inventory = InventoryTransport::unpack($packed);
	$expandedBytes = strlen(json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
	$check($expandedBytes > 8388608 && $compactBytes < 8388608,
		'Actual native commands and full form-backed API exceed the old 8 MiB envelope while compact IPC stays bounded');
	$legacyFailure = null;
	try
	{
		(new PhpProcess(PHP_BINARY, JPATH_COMPONENT . '/cli/jcb.php', JPATH_ROOT))->run([
			'protocol' => 'joomengine-worker/1', 'operation' => 'jcb.inventory',
			'authority' => ['id' => $principal->getId(), 'track' => 'cli'],
		], 90, 8388608, static fn (): bool => false);
	}
	catch (OperationException $error)
	{
		$legacyFailure = $error->getIdentifier();
	}
	$check(in_array($legacyFailure, ['WORKER_OUTPUT_LIMIT', 'WORKER_FAILED'], true),
		'The same installed full API worker demonstrably exceeds the unchanged legacy IPC limit without compact opt-in');
	$routes = array_values(array_filter($inventory['api']['routes'], static fn (array $route): bool =>
		($route['defaults']['component'] ?? '') === 'com_componentbuilder'));
	$forms = [];
	$contractRoutes = 0;
	$guidRoutes = 0;
	foreach ($routes as $route)
	{
		$check(in_array('webservices/mcpfulljcbapi', $route['required_extensions'] ?? [], true),
			'Actual native registration records the compiler-rendered fixture plugin owner');
		if (isset($route['form_contract']))
		{
			$forms[$route['form_contract']['provenance']['form']] = true;
			$contractRoutes++;
		}
		$guidRoutes += in_array('guid', $route['variables'], true) ? 1 : 0;
	}
	$check(count($routes) === 320 && $contractRoutes === 318 && count($forms) === 51 && $guidRoutes === 63,
		'Every supplied resource family and GUID registration reaches the actual worker with its complete native form');
	$seed = (new CatalogueBuilder())->build($inventory['commands'], $inventory['api']);
	$expected = [];
	foreach (['schema' => ['document'], 'action' => ['definition'], 'binding' => ['configuration', 'definition', 'params']] as $entity => $fields)
	{
		foreach ($seed['entities'][$entity] as $record)
		{
			foreach ($fields as $field)
			{
				$expected[$entity][$record['name']][$field] = hash('sha256', $record[$field]);
			}
		}
	}
	$state = ['revision' => $seed['source'], 'core' => $core(), 'expected' => $expected,
		'expandedBytes' => $expandedBytes, 'compactBytes' => $compactBytes, 'registeredRoutes' => count($routes),
		'forms' => count($forms), 'contractRoutes' => $contractRoutes, 'guidRoutes' => $guidRoutes,
		'nativeCommands' => count($inventory['commands']['commands']), 'installedSources' => $installedSources,
		'legacyWorkerFailure' => $legacyFailure];
	$saveState($state);
}
elseif (in_array($phase, ['verify', 'verify-rerun'], true))
{
	$state = Json::decode(file_get_contents($stateFile));
	$check($core() === $state['core'], 'Full API synchronization preserves every existing Joomla core definition byte for byte');
	$provider = $store->one('provider', ['name' => 'jcb.installed']);
	$definition = $provider === null ? [] : Json::decode($provider['definition']);
	$check(($definition['routeCount'] ?? 0) === 320 && ($definition['inventory'] ?? '') === $state['revision'],
		'The real joomla:mcp:jcb-sync command persists the complete full-size native inventory revision');
	$validator = new SchemaValidator();
	foreach ($state['expected'] as $entity => $records)
	{
		foreach ($records as $name => $fields)
		{
			$record = $store->one($entity, ['name' => $name]);
			$check($record !== null && (int) $record['published'] === 1, 'The full native graph publishes ' . $entity . ' ' . $name);
			foreach ($fields as $field => $fingerprint)
			{
				$check(hash_equals($fingerprint, hash('sha256', $record[$field])),
					'No schema, form, provenance or verification data is truncated: ' . $name . ' ' . $field);
			}
			if ($entity === 'schema')
			{
				$check(is_object($validator->document($record['document'])), 'Native full-package schema parses through the production validator: ' . $name);
			}
		}
	}
	$client = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
	try
	{
		$client->initialize();
		$tools = array_column($client->rpc('tools/list')['result']['tools'] ?? [], 'name');
		foreach (['joomla_actions_search', 'joomla_action_describe', 'joomla_action_read', 'joomla_action_write_plan', 'joomla_write_apply'] as $tool)
		{
			$check(in_array($tool, $tools, true), 'The actual authenticated MCP endpoint retains generic API tool ' . $tool);
		}
		$search = $client->tool('joomla_actions_search', ['text' => 'jcb.api.', 'domain' => 'jcb', 'includeWrites' => true]);
		$names = array_column($search['actions'] ?? [], 'id');
		$expectedNames = array_values(array_filter(array_keys($state['expected']['action']), static fn (string $name): bool => str_starts_with($name, 'jcb.api.')));
		sort($names, SORT_STRING);
		sort($expectedNames, SORT_STRING);
		$check($names === $expectedNames && count($names) === 320, 'All full native API registrations are discoverable through the authenticated MCP tools');
		$coreRead = $client->tool('joomla_action_read', ['action' => 'contacts.contacts.list', 'transport' => 'api', 'input' => ['offset' => 0, 'limit' => 1]]);
		$check(($coreRead['response']['status'] ?? null) === 200, 'Existing Joomla core API forwarding still works after both full-size synchronizations');
	}
	finally
	{
		$client->disconnect();
	}
	if ($phase === 'verify')
	{
		$action = $store->one('action', ['name' => $expectedNames[0]]);
		$editor = $factory->createModel('Action', 'Administrator', ['ignore_request' => true]);
		$editor->setCurrentUser($admin);
		$check($editor->save(['id' => (int) $action['id'], 'version' => (int) $action['version'], 'title' => 'Owned full API regression customization']),
			'Native administration customizes a freshly synchronized API action before rerunning the real command');
		$state['customization'] = $store->one('action', ['id' => (int) $action['id']]);
		$saveState($state);
	}
	else
	{
		$check($store->one('action', ['id' => (int) $state['customization']['id']]) === $state['customization'],
			'Repeated full native synchronization preserves the exact operator customization and revision');
		$check(unlink($stateFile), 'Remove the private full API fixture manifest; native component uninstall owns the disposable graph cleanup');
	}
}
else
{
	throw new RuntimeException('Use prepare, verify or verify-rerun in the disposable golden fixture.');
}

echo Json::encode(['checks' => $checks, 'phase' => $phase, 'registeredRoutes' => $state['registeredRoutes'],
	'nativeForms' => $state['forms'], 'formBackedRoutes' => $state['contractRoutes'], 'guidRoutes' => $state['guidRoutes'],
	'nativeCommands' => $state['nativeCommands'], 'expandedInventoryBytes' => $state['expandedBytes'],
	'compactInventoryBytes' => $state['compactBytes'], 'suppliedPackageSha256' => 'a5870a1b162c9db1f8c23b87562389c46260838f471914132ef1d3d66fb8f55a',
	'legacyWorkerFailure' => $state['legacyWorkerFailure'], 'elapsedSeconds' => round((hrtime(true) - $started) / 1000000000, 3),
	'fixturePeakMemoryBytes' => memory_get_peak_usage(true),
	'routingFixture' => 'Native JCB compiler-rendered registration; historical linked plugin was not supplied.', 'joomla' => JVERSION]) . PHP_EOL;
