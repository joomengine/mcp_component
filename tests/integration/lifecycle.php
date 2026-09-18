<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;

require __DIR__ . '/bootstrap.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$factory = $app->bootComponent('com_joomengine_mcp')->getMVCFactory();
$store = new JoomlaStore($db);
$phase = $argv[1] ?? '';
$file = getenv('MCP_TEST_LIFECYCLE_FILE');
if (!is_string($file) || $file === '')
{
	throw new RuntimeException('A private lifecycle fixture file is required.');
}
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void
{
	if (!$ok)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . "\n";
};
if ($phase === 'prepare')
{
	$provider = $store->one('provider', ['name' => 'joomla.core']);
	$editor = $factory->createModel('Provider', 'Administrator', ['ignore_request' => true]);
	$editor->setCurrentUser($admin);
	$check($editor->save(['id' => (int) $provider['id'], 'version' => (int) $provider['version'], 'description' => 'Operator customization must survive package upgrade.']), 'Customize a shipped definition through native administration');
	$external = $provider;
	unset($external['id']);
	$external['name'] = 'fixture.external.provider';
	$external['title'] = 'Other extension seed ownership';
	$external['asset_id'] = 0;
	$external['seed_revision'] = str_repeat('a', 40);
	$external['customized'] = 0;
	$external['seed_hash'] = SeedUpdater::hash('provider', $external);
	$externalId = $store->insert('provider', $external);
	$component = new Extension($db);
	$check($component->load(['type' => 'component', 'element' => 'com_joomengine_mcp']), 'Installed component registry exists');
	$originalParams = $component->params;
	$params = new Registry($originalParams);
	$params->set('timeout', 31);
	$component->params = (string) $params;
	$check($component->store(), 'Store operator-owned component configuration');
	$plugin = new Extension($db);
	$check($plugin->load(['type' => 'plugin', 'folder' => 'webservices', 'element' => 'joomengine_mcp']), 'Owned routing plugin exists');
	$enabled = (int) $plugin->enabled;
	$plugin->enabled = 0;
	$check($plugin->store(), 'Operator disables the routing plugin before upgrade');
	$asset = new Asset($db);
	$check($asset->loadByName('com_joomengine_mcp'), 'Component ACL asset exists');
	$oldMask = umask(0077);
	try
	{
		$check(file_put_contents($file, json_encode(['provider' => $provider, 'externalId' => $externalId,
			'params' => $originalParams, 'pluginEnabled' => $enabled, 'assetRules' => $asset->rules], JSON_THROW_ON_ERROR)) !== false, 'Preserve private lifecycle fixture state');
	}
	finally
	{
		umask($oldMask);
	}
}
elseif ($phase === 'verify')
{
	$expected = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
	$provider = $store->one('provider', ['id' => (int) $expected['provider']['id']]);
	$check($provider['description'] === 'Operator customization must survive package upgrade.' && (int) $provider['customized'] === 1, 'Real package upgrade preserves modified shipped rows');
	$check((int) $store->one('provider', ['id' => (int) $expected['externalId']])['published'] === 1, 'Real package upgrade does not retire another extension provider');
	$component = new Extension($db);
	$component->load(['type' => 'component', 'element' => 'com_joomengine_mcp']);
	$check((int) (new Registry($component->params))->get('timeout') === 31, 'Real upgrade preserves component options');
	$plugin = new Extension($db);
	$plugin->load(['type' => 'plugin', 'folder' => 'webservices', 'element' => 'joomengine_mcp']);
	$check((int) $plugin->enabled === 0, 'Real upgrade preserves disabled plugin state');
	$asset = new Asset($db);
	$asset->loadByName('com_joomengine_mcp');
	$check($asset->rules === $expected['assetRules'], 'Real upgrade preserves native ACL rules');
	// Restore only this fixture's explicit changes, after asserting the upgrade result.
	$restored = $expected['provider'];
	unset($restored['id']);
	$store->update('provider', $restored, ['id' => (int) $expected['provider']['id']]);
	$store->remove('provider', ['id' => (int) $expected['externalId']]);
	$externalAsset = new Asset($db);
	if ($externalAsset->loadByName('com_joomengine_mcp.provider.' . (int) $expected['externalId']))
	{
		$check($externalAsset->delete(), 'Remove the temporary external-provider asset');
	}
	$component->params = $expected['params'];
	$plugin->enabled = $expected['pluginEnabled'];
	$check($component->store() && $plugin->store(), 'Restore tested operator settings');
	unlink($file);
}
elseif ($phase === 'uninstall')
{
	$component = new Extension($db);
	$check($component->load(['type' => 'component', 'element' => 'com_joomengine_mcp']), 'Resolve exact installed component for removal');
	$coreArticles = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__content'))->loadResult();
	$ownedEntities = array_merge(array_keys(Structure::definitions()), array_keys(Structure::state()));
	$ownedTables = [];
	foreach ($ownedEntities as $entity)
	{
		$ownedTables[$entity] = $db->replacePrefix(Structure::table($entity));
	}
	$installer = new Installer();
	$installer->setDatabase($db);
	$check($installer->uninstall('component', (int) $component->extension_id), 'Native Joomla component uninstall completes');
	$tables = $db->getTableList();
	foreach ($ownedTables as $entity => $table)
	{
		$check(!in_array($table, $tables, true), 'Uninstall removes owned ' . $entity . ' table');
	}
	$check(!(bool) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__assets') . ' WHERE name LIKE ' . $db->quote('com_joomengine_mcp%'))->loadResult(), 'Uninstall removes native component and row assets');
	$check(!(bool) $db->setQuery('SELECT extension_id FROM ' . $db->quoteName('#__extensions') . ' WHERE element = ' . $db->quote('joomengine_mcp') . ' AND folder = ' . $db->quote('webservices'))->loadResult(), 'Uninstall removes only the owned routing extension');
	$check(!is_dir(JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp') && !is_dir(JPATH_API . '/components/com_joomengine_mcp'), 'Uninstall removes administrator and API runtime');
	$check((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__content'))->loadResult() === $coreArticles, 'Removing MCP does not delete Joomla content');
}
else
{
	throw new RuntimeException('Use prepare, verify or uninstall against a disposable fixture.');
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'lifecycle' => $phase], JSON_THROW_ON_ERROR) . "\n";
