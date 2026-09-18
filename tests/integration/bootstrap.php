<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Session\SessionInterface;

if (PHP_SAPI !== 'cli' || getenv('MCP_TEST_ALLOW_DESTRUCTIVE') !== '1')
{
	throw new RuntimeException('Integration tests require an explicitly disposable Joomla fixture.');
}

$joomla = realpath((string) getenv('JOOMLA_ROOT'));

if ($joomla === false || !is_file($joomla . '/.mcp-test-fixture') || !is_file($joomla . '/configuration.php'))
{
	throw new RuntimeException('JOOMLA_ROOT must contain an installed disposable Joomla fixture and .mcp-test-fixture marker.');
}

 define('_JEXEC', 1);
define('JPATH_BASE', $joomla);
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')->alias(SessionInterface::class, 'session.cli');
$app = $container->get(ConsoleApplication::class);
Factory::$application = $app;
// Match ConsoleApplication::execute() when booting native MVC services in this fixture.
// The normal Joomla CLI populates these before running any command.
$live = \Joomla\CMS\Uri\Uri::getInstance($app->get('live_site') ?: 'https://joomla.invalid/set/by/console/application');
$_SERVER['HTTP_HOST'] = $live->toString(['host', 'port']);
$_SERVER['REQUEST_URI'] = $live->getPath() ?: '/';
$_SERVER['HTTPS'] = $live->getScheme() === 'https' ? 'on' : 'off';
$app->createExtensionNamespaceMap();
define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp');
define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_COMPONENT);
