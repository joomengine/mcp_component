<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Build a disposable routing fixture with JCB's installed native compiler.
 * The supplied full API package has no historical linked routing plugin.
 * Its controllers supply the resource names, roles and record-key projection;
 * the native Resources, RecordId and Routes services supply all route code.
 */

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Router\ApiRouter;
use VDM\Joomla\Componentbuilder\Compiler\Factory as CompilerFactory;
use VDM\Plugin\Webservices\Mcpfulljcbapi\Extension\Mcpfulljcbapi;

require dirname(__DIR__) . '/integration/bootstrap.php';
$app->bootComponent('com_componentbuilder');

$authorityFile = dirname(__DIR__) . '/fixtures/jcb/native-routing-provenance.json';
$authority = json_decode(file_get_contents($authorityFile), true, 64, JSON_THROW_ON_ERROR);
$controllerRoot = JPATH_ROOT . '/api/components/com_componentbuilder/src/Controller';
$files = glob($controllerRoot . '/*Controller.php');
sort($files, SORT_STRING);
$require = static function (bool $ok, string $message): void
{
	if (!$ok)
	{
		throw new RuntimeException($message);
	}
};
$require(count($files) === $authority['controllers'], 'The supplied full API must contain exactly 104 controllers.');

/** Extract one method without evaluating supplied controller code. */
$method = static function (string $source, string $name): ?string
{
	$tokens = token_get_all($source);
	$count = count($tokens);

	for ($index = 0; $index < $count; $index++)
	{
		if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION)
		{
			continue;
		}

		$next = $index + 1;

		while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE)
		{
			$next++;
		}

		if (!is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING || $tokens[$next][1] !== $name)
		{
			continue;
		}

		while ($next < $count && $tokens[$next] !== '{')
		{
			$next++;
		}

		$body = '';
		$depth = 0;

		for (; $next < $count; $next++)
		{
			$token = $tokens[$next];
			$body .= is_array($token) ? $token[1] : $token;
			$depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

			if ($depth === 0)
			{
				return $body;
			}
		}
	}

	return null;
};

$controllers = [];
$digest = '';

foreach ($files as $file)
{
	$source = file_get_contents($file);
	$path = 'api/src/Controller/' . basename($file);
	$digest .= $path . "\0" . hash('sha256', $source) . "\n";
	$properties = [];

	foreach (['contentType', 'default_view'] as $property)
	{
		$pattern = '/protected\s+\$' . $property . "\s*=\s*'([a-z][a-z0-9_]*)'\s*;/";
		$require(preg_match_all($pattern, $source, $matches) === 1, $path . ' must have a literal ' . $property . '.');
		$properties[$property] = $matches[1][0];
	}

	$controllers[] = $properties + ['file' => $path, 'source' => $source,
		'record' => $method($source, 'getRecordId')];
}

$require(hash_equals($authority['controller_sha256'], hash('sha256', $digest)),
	'The installed API controller bytes must match the supplied package provenance.');
$pairs = [];
$guidItems = 0;

foreach ($controllers as $controller)
{
	if ($controller['record'] === null)
	{
		continue;
	}

	$list = $controller['contentType'];
	$single = $controller['default_view'];
	$require(!isset($pairs[$list]) && $single !== $list, 'Every item controller must have one distinct list resource.');
	$keys = [];

	if (preg_match('/foreach\s*\(\s*\[([^\]]*)\]\s+as\s+\$key\s*\)/', $controller['record'], $match) === 1)
	{
		$require(trim($match[1]) === "'guid'", 'The supplied item key projection must be GUID or primary ID only.');
		$keys = ['guid'];
		$guidItems++;
	}

	$pairs[$list] = ['single' => $single, 'list' => $list, 'keys' => $keys, 'item_controller' => $controller['file']];
}

$require(count($pairs) === $authority['administrator_pairs'] && $guidItems === $authority['guid_items'],
	'The supplied API must contain exactly 51 administrator pairs and 21 GUID item resources.');
$readonly = [];

foreach ($controllers as $controller)
{
	if ($controller['record'] !== null)
	{
		continue;
	}

	$name = $controller['contentType'];
	$require($controller['default_view'] === $name, 'A list controller must use its literal content type as its view.');
	$require($method($controller['source'], 'displayList') !== null, 'Every projected list must expose displayList.');

	if (isset($pairs[$name]))
	{
		$require(!isset($pairs[$name]['list_controller']), 'Each item must have exactly one list controller.');
		$pairs[$name]['list_controller'] = $controller['file'];
		continue;
	}

	$require(in_array($name, $authority['readonly_lists'], true) && !isset($readonly[$name]),
		'The only unpaired controllers must be the supplied compiler and extrusion lists.');
	$model = $method($controller['source'], 'getModel');
	$require(is_string($model) && preg_match("/parent::getModel\('" . ucfirst($name) . "',\s*'Administrator'/", $model) === 1,
		'The dynamic list must bind its actual administrator model.');

	foreach (['displayItem', 'add', 'edit', 'delete'] as $task)
	{
		$body = $method($controller['source'], $task);
		$require(is_string($body) && str_contains($body, 'throw new') && str_contains($body, ', 405)'),
			'The dynamic controller must reject every item and write task.');
	}

	$readonly[$name] = ['code' => $name, 'controller' => $controller['file']];
}

ksort($pairs, SORT_STRING);
ksort($readonly, SORT_STRING);
$require(array_keys($readonly) === $authority['readonly_lists'], 'Both supplied read-only resources must be present.');
$adminLinks = [];

foreach ($pairs as $pair)
{
	$require(isset($pair['list_controller']), 'Every administrator item must have its supplied list controller.');
	$fields = $pair['keys'] === [] ? [] : [['settings' => (object) [
		'xml' => '<field name="guid" type="text" />', 'name' => 'guid',
		'type_name' => 'text', 'datatype' => 'CHAR', 'indexes' => 1]]];
	$adminLinks[] = ['add_api' => 2, 'settings' => (object) [
		'name_single_code' => $pair['single'], 'name_list_code' => $pair['list'],
		'name_single' => $pair['single'], 'name_list' => $pair['list'], 'fields' => $fields]];
}

$dynamicLinks = [];

foreach ($readonly as $resource)
{
	$dynamicLinks[] = ['access' => 1, 'settings' => (object) [
		'code' => $resource['code'], 'main_get' => (object) ['gettype' => 2, 'main_source' => 1]]];
}

$temporary = null;
$zip = null;

try
{
	// Resolve only the renderer's native collaborators, never the full Compiler.
	$config = CompilerFactory::_('Config');
	$config->set('component_code_name', 'componentbuilder');
	$config->set('joomla_version', 6);
	$config->set('indentation_value', "\t");
	$config->set('debug_line_nr', false);
	$renderer = CompilerFactory::_('Architecture.Api.Plugin.Routes');
	$rendererFile = (new ReflectionClass($renderer))->getFileName();
	$require(realpath($rendererFile) === realpath(JPATH_ROOT . '/' . $authority['renderer']),
		'The renderer must be the installed native JCB Routes service.');
	$routeMethod = $renderer->getMethod($adminLinks, $dynamicLinks);
	$provenance = $authority + ['plugin' => 'plg_webservices_mcpfulljcbapi',
		'renderer_sha256' => hash_file('sha256', $rendererFile),
		'rendered_method_sha256' => hash('sha256', $routeMethod),
		'projected_pairs' => array_values($pairs), 'projected_readonly' => array_values($readonly)];
	$provenanceJson = json_encode($provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	$plugin = <<<'PHP'
<?php
/** Disposable compiler-rendered routing fixture; see native-routing-provenance.json. */
namespace VDM\Plugin\Webservices\Mcpfulljcbapi\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/** Register the supplied full JCB API through its native compiler route method. */
final class Mcpfulljcbapi extends CMSPlugin implements SubscriberInterface
{
	/** @return array Native Joomla event subscription. */
	public static function getSubscribedEvents(): array
	{
		return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
	}

PHP;
	$plugin .= "\t" . $routeMethod . "\n}\n";
	$provider = <<<'PHP'
<?php
/** Disposable native Joomla plugin composition. */
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use VDM\Plugin\Webservices\Mcpfulljcbapi\Extension\Mcpfulljcbapi;

\defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface
{
	/** @param Container $container Native plugin container. @return void */
	public function register(Container $container): void
	{
		$container->set(PluginInterface::class, $container->lazy(Mcpfulljcbapi::class, function (Container $container)
		{
			$plugin = new Mcpfulljcbapi((array) PluginHelper::getPlugin('webservices', 'mcpfulljcbapi'));
			$plugin->setApplication(Factory::getApplication());

			return $plugin;
		}));
	}
};
PHP;
	$manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<extension type="plugin" group="webservices" method="upgrade">
	<name>plg_webservices_mcpfulljcbapi</name>
	<author>MCP disposable acceptance fixture</author>
	<version>1.0.0</version>
	<description>Compiler-rendered routing fixture for the supplied JCB full API. Source authority and projection are recorded in native-routing-provenance.json; no historical linked routing plugin was supplied.</description>
	<namespace path="src">VDM\Plugin\Webservices\Mcpfulljcbapi</namespace>
	<files>
		<folder plugin="mcpfulljcbapi">services</folder>
		<folder>src</folder>
		<filename>native-routing-provenance.json</filename>
	</files>
</extension>
XML;
	$temporary = tempnam(sys_get_temp_dir(), 'mcp-full-api-');
	$require(is_string($temporary) && file_put_contents($temporary, $plugin) === strlen($plugin),
		'Unable to write the temporary native plugin proof.');
	require $temporary;
	$router = new ApiRouter($app);
	$nativePlugin = new Mcpfulljcbapi(['name' => 'mcpfulljcbapi', 'type' => 'webservices']);
	$nativePlugin->setApplication($app);
	$nativePlugin->onBeforeApiRoute(new BeforeApiRouteEvent('onBeforeApiRoute', ['subject' => $app, 'router' => $router]));
	$routes = [];
	$methods = [];

	foreach ($router->getRoutes() as $route)
	{
		$defaults = $route->getDefaults();
		$require(($defaults['component'] ?? '') === 'com_componentbuilder', 'Every native route must retain JCB ownership.');

		foreach ($route->getMethods() as $verb)
		{
			$key = $verb . ' ' . $route->getPattern();
			$require(!isset($routes[$key]), 'The native renderer must register unique method/path pairs.');
			$require($verb !== 'GET' || ($defaults['public'] ?? null) === false, 'All supplied API reads require native authentication.');
			$routes[$key] = ['method' => $verb, 'route' => $route->getPattern(), 'controller' => $route->getController(),
				'rules' => $route->getRules(), 'defaults' => $defaults];
			$methods[$verb] = ($methods[$verb] ?? 0) + 1;
		}
	}

	ksort($routes, SORT_STRING);
	ksort($methods, SORT_STRING);
	$expectedMethods = $authority['native_methods'];
	ksort($expectedMethods, SORT_STRING);
	$require(count($routes) === $authority['native_routes'] && $methods === $expectedMethods,
		'The real native plugin event must register exactly 320 routes with the supplied method counts.');
	$archive = '/tmp/mcp-full-api-routing.zip';
	$zip = new ZipArchive();
	$require($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unable to create the routing fixture ZIP.');
	$entries = ['mcpfulljcbapi.xml' => $manifest . "\n", 'native-routing-provenance.json' => $provenanceJson,
		'services/provider.php' => $provider . "\n", 'src/Extension/Mcpfulljcbapi.php' => $plugin];
	ksort($entries, SORT_STRING);

	foreach ($entries as $path => $content)
	{
		$require($zip->addFromString($path, $content) && $zip->setMtimeName($path, 1788307200),
			'Unable to add a deterministic routing fixture entry.');
	}

	$require($zip->close(), 'Unable to finish the routing fixture ZIP.');
	$zip = null;
	echo json_encode(['fixture' => $authority['fixture'], 'archive' => $archive,
		'archive_sha256' => hash_file('sha256', $archive), 'plugin' => 'plg_webservices_mcpfulljcbapi',
		'plugin_element' => 'mcpfulljcbapi', 'plugin_group' => 'webservices',
		'required_extensions' => ['webservices/mcpfulljcbapi'], 'controllers' => count($controllers),
		'administrator_pairs' => count($pairs), 'guid_items' => $guidItems, 'readonly_lists' => array_keys($readonly),
		'native_routes' => count($routes), 'native_methods' => $methods, 'provenance' => $provenance,
		'routes' => array_values($routes)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}
finally
{
	if ($zip !== null)
	{
		$zip->close();
	}

	if (is_string($temporary) && is_file($temporary))
	{
		unlink($temporary);
	}

	CompilerFactory::unset();
}
