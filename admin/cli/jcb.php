<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\ApiRouter;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Console\Loader\LoaderInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use VDM\Component\JoomEngineMcp\Administrator\Console\Bootstrap;
use VDM\Component\JoomEngineMcp\Administrator\Console\WorkerApplication;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\ApiRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandOutput;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\GeneratedApiInventory;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\InventoryTransport;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\RegistrationObserver;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\Worker;
use VDM\Component\JoomEngineMcp\Administrator\Security\ConsoleIdentity;
use VDM\Component\JoomEngineMcp\Administrator\Security\JoomlaPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Security\LocalPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit(1);
}

ini_set('display_errors', 'stderr');
// Native compiler/installer effects retain the hosting process's normal mask.
// MCP artifact storage applies its own explicit private directory/file modes.
$level = ob_get_level();
ob_start(static fn (string $output): string => '', 4096);
$json = null;

try
{
	$bytes = stream_get_contents(STDIN, 4194305);
	$request = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);

	if (strlen($bytes) > 4194304 || !is_array($request) || ($request['protocol'] ?? '') !== 'joomengine-worker/1'
		|| !in_array($request['operation'] ?? '', ['jcb.inventory', 'jcb.inspect', 'jcb.command'], true))
	{
		throw new RuntimeException('Invalid fixed JCB worker envelope.');
	}

	$root = dirname(__DIR__, 4);

	if (!is_file($root . '/configuration.php') || !is_file($root . '/includes/framework.php'))
	{
		throw new RuntimeException('This worker must run from the installed component.');
	}

	define('_JEXEC', 1);
	require dirname(__DIR__) . '/src/Console/Bootstrap.php';
	Bootstrap::initialize($root);
	define('JPATH_BASE', $root);
	require JPATH_BASE . '/includes/defines.php';
	require JPATH_BASE . '/includes/framework.php';
	require dirname(__DIR__) . '/autoload.php';
	$container = Factory::getContainer();
	$container->alias('session', 'session.cli')->alias(Session::class, 'session.cli')
		->alias(\Joomla\Session\Session::class, 'session.cli')->alias(SessionInterface::class, 'session.cli');
	$config = $container->get('config');
	$dispatcher = $container->get(DispatcherInterface::class);
	$language = $container->get(LanguageFactoryInterface::class)->createLanguage($config->get('language'), $config->get('debug_lang'));
	$consoleInput = new ArrayInput([]);
	$consoleInput->setInteractive(false);
	$app = new WorkerApplication($config, $dispatcher, $container, $language, $consoleInput, new CommandOutput());
	$app->setCommandLoader($container->get(LoaderInterface::class));
	$app->setLogger($container->get(LoggerInterface::class));
	$app->setSession($container->get(SessionInterface::class));
	$app->setUserFactory($container->get(UserFactoryInterface::class));
	$app->setDatabase($container->get(DatabaseInterface::class));
	Factory::$application = $app;
	$live = \Joomla\CMS\Uri\Uri::getInstance($app->get('live_site') ?: 'https://joomla.invalid/set/by/console/application');
	$_SERVER['HTTP_HOST'] = $live->toString(['host', 'port']);
	$_SERVER['REQUEST_URI'] = $live->getPath() ?: '/';
	$_SERVER['HTTPS'] = $live->getScheme() === 'https' ? 'on' : 'off';
	$app->createExtensionNamespaceMap();
	$authority = $request['authority'] ?? [];

	if (($authority['track'] ?? '') === 'api' && preg_match('/\Ajoomla:([1-9][0-9]*)\z/D', $authority['id'] ?? '', $matches) === 1)
	{
		$user = $container->get(UserFactoryInterface::class)->loadUserById((int) $matches[1]);
		$principal = new JoomlaPrincipal($user);
		$app->loadIdentity($user);

		$asset = $request['operation'] === 'jcb.inventory' ? 'com_joomengine_mcp' : 'com_componentbuilder';
		if (!$principal->authorise('mcp.access', 'com_joomengine_mcp') || !$principal->authorise('core.admin', $asset))
		{
			throw new OperationException('JCB_ACCESS_DENIED', 'The original Joomla user no longer authorizes JCB execution.');
		}
	}
	elseif (($authority['track'] ?? '') === 'cli')
	{
		$principal = new LocalPrincipal($app);

		if (($authority['id'] ?? '') !== $principal->getId())
		{
			throw new OperationException('JCB_ACCESS_DENIED', 'The worker owner differs from the approved local principal.');
		}

		$app->loadIdentity(new ConsoleIdentity($app, $container->get(DatabaseInterface::class)));
	}
	else
	{
		throw new OperationException('JCB_ACCESS_DENIED', 'A verified server-supplied principal is required.');
	}

	foreach (['behaviour', 'system', 'console'] as $group)
	{
		PluginHelper::importPlugin($group, null, true, $dispatcher);
	}

	$event = new ApplicationEvent(ApplicationEvents::BEFORE_EXECUTE, $app);
	$observer = new RegistrationObserver();
	$owners = null;

	if ($request['operation'] === 'jcb.inventory')
	{
		$owners = $observer->dispatch($dispatcher, $event, static fn (): array => $app->getAllCommands());
	}
	else
	{
		$dispatcher->dispatch(ApplicationEvents::BEFORE_EXECUTE, $event);
	}
	$worker = new Worker($app, $container->get(DatabaseInterface::class), $owners);

	if ($request['operation'] === 'jcb.inventory')
	{
		$commands = $worker->inventory();
		if (!$principal->isLocal() && CatalogueBuilder::hasCommandScope($commands)
			&& !$principal->authorise('core.admin', 'com_componentbuilder'))
		{
			throw new OperationException('JCB_CATALOGUE_DENIED', 'Native JCB administration permission is required to synchronize registered JCB commands.');
		}
		$api = $container->get(ApiApplication::class);
		$api->loadIdentity($app->getIdentity());
		Factory::$application = $api;

		try
		{
			$database = $container->get(DatabaseInterface::class);
			$query = $database->createQuery()->select($database->quoteName(['type', 'element', 'enabled']))
				->from($database->quoteName('#__extensions'))
				->where($database->quoteName('type') . ' = ' . $database->quote('component'));
			$coreComponents = array_map(static fn (array $extension): string => $extension[1], array_filter(
				ExtensionHelper::getCoreExtensions(), static fn (array $extension): bool => $extension[0] === 'component'));
			$components = GeneratedApiInventory::components($database->setQuery($query)->loadAssocList(), $coreComponents);
			$components = array_values(array_filter($components, static fn (string $component): bool =>
				$principal->isLocal() || $principal->authorise('core.admin', $component)));
			if (in_array('com_componentbuilder', $components, true))
			{
				$commands['component'] = 'com_componentbuilder';
			}
			$router = new ApiRouter($api);
			PluginHelper::importPlugin('webservices', null, true, $dispatcher);
			$owners = $observer->dispatch($dispatcher, new BeforeApiRouteEvent('onBeforeApiRoute', ['router' => $router, 'subject' => $api]),
				static fn (): array => $router->getRoutes());
			$inventory = (new ApiRegistry($router, $owners, $components))->inventory();
			$result = ['commands' => $commands, 'api' => GeneratedApiInventory::enrich($inventory,
				JPATH_ADMINISTRATOR . '/components', JPATH_ROOT . '/api/components')];
		}
		finally
		{
			Factory::$application = $app;
		}
	}
	elseif ($request['operation'] === 'jcb.inspect')
	{
		$result = $worker->inspect((array) ($request['configuration'] ?? []));
	}
	else
	{
		$result = $worker->execute((array) ($request['prepared'] ?? []), $principal);
	}

	$result = ['protocol' => 'joomengine-worker/1'] + $result;
	if ($request['operation'] === 'jcb.inventory' && isset($request['inventory_format']))
	{
		if ($request['inventory_format'] !== InventoryTransport::FORMAT)
		{
			throw new OperationException('JCB_INVENTORY_INVALID', 'The requested native inventory encoding is unsupported.');
		}
		$result = InventoryTransport::pack($result);
		$json = Json::encode($result, InventoryTransport::MAX_WIRE_BYTES);
	}
}
catch (Throwable $error)
{
	$json = null;
	$result = ['protocol' => 'joomengine-worker/1', 'error' => $error instanceof OperationException
		? $error->toArray() : ['code' => 'JCB_WORKER_FAILED', 'message' => 'The native JCB worker could not complete this request.']];
}

while (ob_get_level() > $level)
{
	ob_end_clean();
}

$json ??= json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
for ($offset = 0, $length = strlen($json); $offset < $length; $offset += $written)
{
	$written = fwrite(STDOUT, substr($json, $offset));

	if ($written === false || $written === 0)
	{
		exit(1);
	}
}
