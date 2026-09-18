<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Installer\InstallerScript;

\defined('_JEXEC') or die;

if (!class_exists(InstallerScript::class, false))
{
	require_once is_file(__DIR__ . '/admin/src/Installer/InstallerScript.php')
		? __DIR__ . '/admin/src/Installer/InstallerScript.php'
		: __DIR__ . '/src/Installer/InstallerScript.php';
}

/**
 * Native Joomla installer composition root for uploaded and installed contexts.
 *
 * @since 0.1.0
 */
return new class implements ServiceProviderInterface
{
	/** @inheritDoc */
	public function register(Container $container): void
	{
		$container->set(InstallerScriptInterface::class, static function (Container $container): InstallerScriptInterface
		{
			return new InstallerScript($container->get(DatabaseInterface::class), Factory::getApplication());
		});
	}
};
