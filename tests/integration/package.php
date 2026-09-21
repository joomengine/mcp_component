<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;

require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__, 2);
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$version = (string) simplexml_load_file($root . '/joomengine_mcp.xml')->version;
$path = $root . '/build/pkg_joomengine_mcp-' . $version . '.zip';
$stage = $root . '/build/package-install-test-' . bin2hex(random_bytes(6));
$zip = new ZipArchive();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}

	$checks++;
	echo 'PASS ' . $message . "\n";
};

$check($zip->open($path) === true, 'Open the built combined Joomla distribution');
$check($zip->extractTo($stage), 'Extract the actual package for native installation');
$zip->close();

try
{
	$installer = new Installer();
	$installer->setDatabase($db);
	$check($installer->install($stage), 'Native Joomla package installs component, routing and console extensions');
	$package = new Extension($db);
	$check($package->load(['type' => 'package', 'element' => 'pkg_joomengine_mcp']), 'Joomla registers the parent package');
	$component = new Extension($db);
	$check($component->load(['type' => 'component', 'element' => 'com_joomengine_mcp'])
		&& (int) $component->package_id === (int) $package->extension_id, 'Component belongs to the native package');
	$console = new Extension($db);
	$check($console->load(['type' => 'plugin', 'folder' => 'console', 'element' => 'joomengine_mcp'])
		&& (int) $console->package_id === (int) $package->extension_id, 'Console belongs to the native package');
	$webservices = new Extension($db);
	$check($webservices->load(['type' => 'plugin', 'folder' => 'webservices', 'element' => 'joomengine_mcp'])
		&& (new Registry($webservices->params))->get('joomengine_mcp_owner') === 'com_joomengine_mcp'
		&& (int) $webservices->package_id === 0, 'Component retains sole ownership of its webservices plugin');
	$check(is_file(JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/vendor/autoload.php')
		&& is_file(JPATH_PLUGINS . '/console/joomengine_mcp/src/Extension/JoomEngineMcpPlugin.php'),
		'Combined installation contains both server dependencies and console implementation');

	$console->enabled = 0;
	$check($console->store(), 'Operator can disable the console plugin');
	$installer = new Installer();
	$installer->setDatabase($db);
	$check($installer->install($stage), 'Native Joomla package upgrade completes');
	$console->load((int) $console->extension_id);
	$check((int) $console->enabled === 0 && (int) $console->package_id === (int) $package->extension_id,
		'Combined upgrade retains operator state and package ownership');

	$installer = new Installer();
	$installer->setDatabase($db);
	$check($installer->uninstall('package', (int) $package->extension_id), 'Native package uninstall completes');

	foreach ([['type' => 'package', 'element' => 'pkg_joomengine_mcp'],
		['type' => 'component', 'element' => 'com_joomengine_mcp'],
		['type' => 'plugin', 'folder' => 'console', 'element' => 'joomengine_mcp'],
		['type' => 'plugin', 'folder' => 'webservices', 'element' => 'joomengine_mcp']] as $identity)
	{
		$extension = new Extension($db);
		$check(!$extension->load($identity), 'Package removal cleans ' . ($identity['folder'] ?? $identity['type']));
	}

	$check(!is_dir(JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp')
		&& !is_dir(JPATH_PLUGINS . '/console/joomengine_mcp') && !is_dir(JPATH_PLUGINS . '/webservices/joomengine_mcp'),
		'Package uninstall removes all three owned runtime directories');
	echo json_encode(['checks' => $checks, 'nativePackageLifecycle' => 'installed, upgraded and uninstalled'], JSON_THROW_ON_ERROR) . "\n";
}
finally
{
	Folder::delete($stage);
}
