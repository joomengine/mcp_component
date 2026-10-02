<?php
/**
 * @package    JoomEngine.Mcp
 * @created    02 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\ApiRouter;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Installer\Assets;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\ApiRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueSynchronizer;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\GeneratedApiInventory;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\RegistrationObserver;
use VDM\Component\JoomEngineMcp\Administrator\Security\JoomlaPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
$factory = $app->bootComponent('com_joomengine_mcp')->getMVCFactory();
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$store = new JoomlaStore($db);
$principal = new JoomlaPrincipal($admin);
$client = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
$checks = 0;
$providerId = null;
$baseline = $store->find('provider', [], 1000);
$coreBinding = $store->one('binding', ['name' => 'contacts.contacts.list.api']);
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};

// This exercises the generic adapter with the actual installed Joomla contact
// plugin. It does not claim a generated JCB component or GUID CRUD endpoint.
$extensions = $db->setQuery($db->createQuery()->select($db->quoteName(['type', 'element', 'enabled']))
	->from($db->quoteName('#__extensions'))->where($db->quoteName('type') . ' = ' . $db->quote('component')))->loadAssocList();
$coreComponents = array_column(array_filter(ExtensionHelper::getCoreExtensions(),
	static fn (array $extension): bool => $extension[0] === 'component'), 1);
$automatic = GeneratedApiInventory::components($extensions, $coreComponents);
$check(!in_array('com_contact', $automatic, true) && !in_array('com_banners', $automatic, true)
	&& !in_array('com_content', $automatic, true),
	'Production generated-component selection excludes the native Joomla core component identities');
$check($coreBinding !== null, 'Existing Joomla contact API binding is available before the scoped test');
$check($store->one('provider', ['name' => 'jcb.component.contact.installed']) === null,
	'The disposable explicit contact-provider scope does not overwrite an existing integration');

try
{
	$api = $container->get(ApiApplication::class);
	$api->loadIdentity($admin);
	Factory::$application = $api;

	try
	{
		$dispatcher = $container->get(DispatcherInterface::class);
		$router = new ApiRouter($api);
		PluginHelper::importPlugin('webservices', null, true, $dispatcher);
		$owners = (new RegistrationObserver())->dispatch($dispatcher,
			new BeforeApiRouteEvent('onBeforeApiRoute', ['router' => $router, 'subject' => $api]), static fn (): array => $router->getRoutes());
		$inventory = (new ApiRegistry($router, $owners, ['com_contact']))->inventory();
		$inventory = GeneratedApiInventory::enrich($inventory, JPATH_ADMINISTRATOR . '/components', JPATH_ROOT . '/api/components');
	}
	finally
	{
		Factory::$application = $app;
	}

	$check($inventory['routes'] !== [], 'Actual installed contact webservices registration supplies the explicit generic inventory');
	$registered = [];

	foreach ($inventory['routes'] as $route)
	{
		$registered[$route['method'] . ' ' . $route['route']] = $route;
		$check($route['defaults']['component'] === 'com_contact' && $route['required_extensions'] !== [],
			'Native route ownership and plugin provenance remain attached to the generic contract');
	}

	$changes = (new CatalogueSynchronizer($store, [new Assets($db, $store), 'synchronize']))
		->synchronize(['commands' => [], 'unsupported' => []], $inventory, $principal);
	$provider = $store->one('provider', ['name' => 'jcb.component.contact.installed']);
	$providerId = $provider === null ? null : (int) $provider['id'];
	$check($providerId !== null && $changes['apiRoutes'] === count($registered),
		'Generic inventory persists its provider and complete observed route graph in Joomla');

	foreach ($baseline as $existing)
	{
		$check($store->one('provider', ['id' => (int) $existing['id']]) === $existing,
			'Partial component synchronization preserves existing provider ' . $existing['name']);
	}
	$check($store->one('binding', ['name' => 'contacts.contacts.list.api']) === $coreBinding,
		'Generic synchronization preserves the existing Joomla contact API binding');

	$read = null;
	foreach ($store->find('binding', ['provider_id' => $providerId], 1000) as $binding)
	{
		$config = Json::decode($binding['configuration']);
		$native = $registered[$config['method'] . ' ' . $config['route']] ?? null;
		$check($native !== null, 'Persisted generic binding refers to an actual installed method/path');
		if ($config['method'] === 'GET' && $config['route'] === '/v1/contacts')
		{
			$read = $store->one('action', ['id' => (int) $binding['action_id']]);
		}
	}
	$check($read !== null && $read['domain'] === 'component_api', 'Installed contact list resolves through the generic provider action');
	$client->initialize();
	$input = ['offset' => 0, 'limit' => 1, 'filter' => (object) ['search' => 'mcp-generic-no-match-' . bin2hex(random_bytes(6))]];
	$response = $client->tool('joomla_action_read', ['action' => $read['name'], 'transport' => 'api', 'input' => (object) $input]);
	$check(($response['response']['status'] ?? null) === 200 && is_array($response['response']['data'] ?? null),
		'Persisted generic action and nested filter execute the actual authenticated Joomla contact HTTP API');
	$core = $client->tool('joomla_action_read', ['action' => 'contacts.contacts.list', 'transport' => 'api', 'input' => ['offset' => 0, 'limit' => 1]]);
	$check(($core['response']['status'] ?? null) === 200, 'The existing Joomla MCP contact API action still executes after generic synchronization');
}
finally
{
	$client->disconnect();
	$ownedProvider = $store->one('provider', ['name' => 'jcb.component.contact.installed']);
	$providerId = $ownedProvider === null ? null : (int) $ownedProvider['id'];
	if ($providerId !== null)
	{
		foreach (array_reverse(array_keys(Structure::definitions())) as $entity)
		{
			$records = $entity === 'provider' ? [$store->one('provider', ['id' => $providerId])]
				: $store->find($entity, ['provider_id' => $providerId], 1000);
			foreach ($records as $record)
			{
				$model = $factory->createModel(ucfirst($entity), 'Administrator', ['ignore_request' => true]);
				$model->setCurrentUser($admin);
				$ids = [(int) $record['id']];
				$check($model->publish($ids, -2) && $model->delete($ids), 'Native cleanup removes the owned generic ' . $entity . ' definition');
			}
		}
		$check($store->one('provider', ['id' => $providerId]) === null, 'No explicitly scoped generic contact provider remains');
	}
}

echo Json::encode(['checks' => $checks, 'nativeRegisteredApiGenericAdapter' => true, 'persistedCatalogue' => true,
	'liveHttpRead' => true, 'generatedComponentGuidCrud' => 'not exercised', 'joomla' => JVERSION]) . PHP_EOL;
