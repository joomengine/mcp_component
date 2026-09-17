<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use VDM\Plugin\Webservices\JoomEngineMcp\Extension\JoomEngineMcp;

\defined('_JEXEC') or die;

/**
 * Native Joomla provider for routing glue only.
 *
 * @since 0.1.0
 */
return new class implements ServiceProviderInterface
{
	/** @inheritDoc */
	public function register(Container $container): void
	{
		$container->set(PluginInterface::class, static function (Container $container): PluginInterface
		{
			$plugin = new JoomEngineMcp($container->get(DispatcherInterface::class), (array) PluginHelper::getPlugin('webservices', 'joomengine_mcp'));
			$plugin->setApplication(Factory::getApplication());

			return $plugin;
		});
	}
};
