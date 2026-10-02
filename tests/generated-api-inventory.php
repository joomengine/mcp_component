<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueSynchronizer;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\GeneratedApiInventory;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
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
$extensions = [
	['type' => 'component', 'element' => 'com_componentbuilder', 'enabled' => 1, 'protected' => 0, 'locked' => 0],
	['type' => 'component', 'element' => 'com_example', 'enabled' => '1', 'protected' => '0', 'locked' => '0'],
	['type' => 'component', 'element' => 'com_content', 'enabled' => 1, 'protected' => 1, 'locked' => 1],
	['type' => 'component', 'element' => 'com_contact', 'enabled' => 1, 'protected' => 0, 'locked' => 1],
	['type' => 'component', 'element' => 'com_banners', 'enabled' => '1', 'protected' => '0', 'locked' => '1'],
	['type' => 'component', 'element' => 'com_joomengine_mcp', 'enabled' => 1, 'protected' => 0, 'locked' => 0],
	['type' => 'component', 'element' => 'com_disabled', 'enabled' => 0, 'protected' => 0, 'locked' => 0],
	['type' => 'component', 'element' => 'com_unknown', 'protected' => 0, 'locked' => 0],
	['type' => 'plugin', 'element' => 'com_plugin', 'enabled' => 1, 'protected' => 0, 'locked' => 0],
	['type' => 'component', 'element' => 'com_../escape', 'enabled' => 1, 'protected' => 0, 'locked' => 0],
];
$coreComponents = ['com_content', 'com_contact', 'com_banners'];
$check(GeneratedApiInventory::components($extensions, $coreComponents) === ['com_componentbuilder', 'com_example'],
	'Native core identity excludes Joomla core, disabled, malformed and MCP components.');
$check(GeneratedApiInventory::components(array_reverse($extensions), $coreComponents) === GeneratedApiInventory::components($extensions, $coreComponents),
	'Component scope is stable independently of native registry row order.');
$check(GeneratedApiInventory::components([['type' => 'component', 'element' => 'com_example', 'enabled' => 1]], $coreComponents) === ['com_example'],
	'A compiled component API can be inventoried without JCB being installed.');
$check(GeneratedApiInventory::components([
	['type' => 'component', 'element' => 'com_contact', 'enabled' => 1, 'protected' => 0, 'locked' => 1],
	['type' => 'component', 'element' => 'com_banners', 'enabled' => 1, 'protected' => 0, 'locked' => 1],
], $coreComponents) === [], 'Native Joomla core identities never acquire duplicate generated API providers even when protected is zero.');
$check(GeneratedApiInventory::components([
	['type' => 'component', 'element' => 'com_locked_custom', 'enabled' => 1, 'protected' => 0, 'locked' => 1],
	['type' => 'component', 'element' => 'com_protected_custom', 'enabled' => 1, 'protected' => 1, 'locked' => 1],
], $coreComponents) === ['com_locked_custom', 'com_protected_custom'],
	'Native uninstall protection and locking do not exclude API-enabled third-party components.');
$temporary = sys_get_temp_dir() . '/mcp-installed-inventory-' . bin2hex(random_bytes(8));
$write = static function (string $path, string $source) use ($temporary): void
{
	$absolute = $temporary . '/' . $path;
	if (!is_dir(dirname($absolute)))
	{
		mkdir(dirname($absolute), 0700, true);
	}
	file_put_contents($absolute, $source);
};
$remove = static function (string $path) use (&$remove): void
{
	if (is_dir($path) && !is_link($path))
	{
		foreach (new DirectoryIterator($path) as $entry)
		{
			if (!$entry->isDot())
			{
				$remove($entry->getPathname());
			}
		}
		rmdir($path);
	}
	elseif (file_exists($path) || is_link($path))
	{
		unlink($path);
	}
};
try
{
	$write('api/com_example/src/Controller/RecordController.php', '<?php class RecordController extends ApiController { public function getModel($name = "", $prefix = "", $config = []) { return parent::getModel("record", "Administrator", $config); } }');
	$write('administrator/com_example/src/Model/RecordModel.php', '<?php class RecordModel extends AdminModel { public function getForm($data = [], $loadData = true) { return $this->loadForm("com_example.record", "record", []); } }');
	$write('administrator/com_example/forms/record.xml', '<form><fieldset name="record"><field name="title" type="text" required="true" /><field name="active" type="radio" filter="INT" default="0"><option value="0">No</option><option value="1">Yes</option></field></fieldset></form>');
	$route = ['method' => 'POST', 'route' => '/v1/example/records', 'controller' => 'record.add',
		'defaults' => ['component' => 'com_example'], 'variables' => [], 'rules' => [],
		'required_extensions' => ['webservices/ExampleRoutes']];
	$inventory = ['routes' => [$route], 'unsupported' => [], 'components' => ['com_example']];
	$enriched = GeneratedApiInventory::enrich($inventory, $temporary . '/administrator', $temporary . '/api');
	$contract = $enriched['routes'][0]['form_contract'] ?? null;
	$check($contract !== null && $contract['provenance']['model'] === 'record'
		&& isset($contract['fields']['title'], $contract['fields']['active']),
		'Production parent-directory inventory resolves the exact route owner component form.');
	$check(isset($contract['schema']['required']) && in_array('title', $contract['schema']['required'], true),
		'Source-backed native create requirements reach synchronized metadata.');
	$check(($contract['fields']['active']['default'] ?? null) === '0'
		&& !array_key_exists('default', (array) $contract['schema']['properties']['active']),
		'Native XML defaults remain descriptive metadata instead of implicit request mutations.');
	$validator = new SchemaValidator();
	$validated = $validator->input(['title' => 'literal source'], Json::encode($contract['schema']));
	$check($validated === ['title' => 'literal source'], 'Native schema validation does not insert omitted form fields.');
	$check($enriched['fingerprint'] === GeneratedApiInventory::enrich($inventory, $temporary . '/administrator', $temporary . '/api')['fingerprint'],
		'Unchanged installed native sources produce a stable inventory fingerprint.');
	$other = $route;
	$other['defaults']['component'] = 'com_other';
	$other['route'] = '/v1/other/records';
	$missing = GeneratedApiInventory::enrich($inventory + [], $temporary . '/missing', $temporary . '/api');
	$check(isset($missing['routes'][0]['form_contract_status']) && !isset($missing['routes'][0]['form_contract']),
		'Unavailable native form metadata is explicitly disclosed without pretending field validation coverage.');
	$isolated = GeneratedApiInventory::enrich(['routes' => [$other], 'components' => ['com_other']], $temporary . '/administrator', $temporary . '/api');
	$check(!isset($isolated['routes'][0]['form_contract']), 'Another route owner cannot borrow a sibling component form.');
	$write('administrator/com_example/forms/record.xml', '<!DOCTYPE form [<!ENTITY external SYSTEM "file:///etc/passwd">]><form><field name="title" type="text" default="&external;" /></form>');
	$invalid = GeneratedApiInventory::enrich($inventory, $temporary . '/administrator', $temporary . '/api');
	$check($invalid['routes'] === [] && isset($invalid['unsupported']['POST /v1/example/records'])
		&& $invalid['unsupported_components']['POST /v1/example/records'] === 'com_example'
		&& $invalid['fingerprint'] !== $enriched['fingerprint'],
		'Unsafe bound form sources remove executable coverage and retain explicit owner diagnostics.');
	$store = new MemoryStore();
	$assets = 0;
	$synchronizer = new CatalogueSynchronizer($store, static function () use (&$assets): void { $assets++; });
	$principal = new Principal();
	$principal->deny('core.admin', 'com_componentbuilder');
	$result = $synchronizer->synchronize(['commands' => []], ['routes' => [], 'components' => ['com_example']], $principal);
	$check($result['apiRoutes'] === 0 && $assets === 1, 'Custom component synchronization does not require permission to an absent or unselected JCB.');
	$jcbRoute = $route;
	$jcbRoute['defaults']['component'] = 'com_componentbuilder';
	$jcbRoute['route'] = '/v1/componentbuilder/records';
	$jcbSeed = (new CatalogueBuilder())->build(['commands' => []], ['routes' => [$jcbRoute], 'components' => ['com_componentbuilder']]);
	(new SeedUpdater($store))->apply($jcbSeed);
	$snapshot = static function () use ($store): string
	{
		$entities = [];
		foreach (array_keys(Structure::definitions()) as $entity)
		{
			$entities[$entity] = $store->find($entity);
		}
		return Json::canonical($entities);
	};
	$commandBefore = $snapshot();
	foreach ([['commands' => [], 'unsupported' => ['componentbuilder:fixture:unreviewed' => 'Requires a reviewed adapter.']],
		['commands' => [], 'component' => 'com_componentbuilder']] as $commands)
	{
		try
		{
			$synchronizer->synchronize($commands, ['routes' => [], 'components' => ['com_example']], $principal);
			$check(false, 'Command provider synchronization without JCB administration must be rejected.');
		}
		catch (OperationException $error)
		{
			$check($error->getIdentifier() === 'JCB_CATALOGUE_DENIED' && $assets === 1 && $snapshot() === $commandBefore,
				'Unsupported-only and authoritative-empty command scopes require JCB administration before every catalogue mutation.');
		}
	}
	$jcbProvider = $store->one('provider', ['name' => 'jcb.installed']);
	$jcbActions = $store->find('action', ['provider_id' => $jcbProvider['id']]);
	$check(count($jcbActions) === 1 && (int) $jcbActions[0]['published'] === 1,
		'Rejected unsupported-only synchronization cannot retire existing JCB actions.');
	$before = Json::canonical($store->find('provider'));
	$principal->deny('core.admin', 'com_example');
	try
	{
		$synchronizer->synchronize(['commands' => []], ['routes' => [], 'components' => ['com_example']], $principal);
		$check(false, 'Selected component administration denial must reject synchronization.');
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === 'JCB_CATALOGUE_DENIED' && $assets === 1
			&& Json::canonical($store->find('provider')) === $before,
			'Native component administration denial rejects synchronization before any catalogue mutation.');
	}
}
finally
{
	$remove($temporary);
}

echo 'Installed generated API inventory and form ownership contracts passed: ' . $checks . PHP_EOL;
