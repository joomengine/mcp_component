<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Router\SiteRouter;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use VDM\Component\JoomEngineMcp\Administrator\Extension\JoomEngineMcpComponent;
use VDM\Component\JoomEngineMcp\Administrator\Security\ApiCredential;
use VDM\Component\JoomEngineMcp\Administrator\Service\MVCFactory;
use VDM\Component\JoomEngineMcp\Administrator\Service\RuntimeFactory;

\defined('_JEXEC') or die;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload))
{
	throw new RuntimeException('The installed JoomEngine MCP runtime dependencies are missing. Install the built component package.');
}

require_once $autoload;

/**
 * Joomla-native component service provider; no request handlers use a container.
 *
 * @since 0.1.0
 */
return new class implements ServiceProviderInterface
{
	/** @inheritDoc */
	public function register(Container $container): void
	{
		$container->share(RuntimeFactory::class, static function (Container $container): RuntimeFactory
		{
			return new RuntimeFactory($container->get(DatabaseInterface::class), ComponentHelper::getParams('com_joomengine_mcp'),
				new ApiCredential(InputFilter::getInstance()),
				$container->has('joomengine.mcp.handler_providers') ? $container->get('joomengine.mcp.handler_providers') : []);
		});
		$container->share(MVCFactoryInterface::class, static function (Container $container): MVCFactoryInterface
		{
			$factory = new MVCFactory($container->get(RuntimeFactory::class));
			$factory->setFormFactory($container->get(FormFactoryInterface::class));
			$factory->setDispatcher($container->get(DispatcherInterface::class));
			$factory->setDatabase($container->get(DatabaseInterface::class));
			$factory->setSiteRouter($container->get(SiteRouter::class));
			$factory->setCacheControllerFactory($container->get(CacheControllerFactoryInterface::class));
			$factory->setUserFactory($container->get(UserFactoryInterface::class));
			$factory->setMailerFactory($container->get(MailerFactoryInterface::class));

			return $factory;
		});
		$container->registerServiceProvider(new ComponentDispatcherFactory('VDM\\Component\\JoomEngineMcp'));
		$container->share(ComponentInterface::class, static function (Container $container): ComponentInterface
		{
			$component = new JoomEngineMcpComponent($container->get(ComponentDispatcherFactoryInterface::class), $container->get(RuntimeFactory::class));
			$component->setMVCFactory($container->get(MVCFactoryInterface::class));

			return $component;
		});
	}
};
