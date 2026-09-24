<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\CMS\Router\ApiRouter;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Inventory JCB routes after enabled webservices plugins register with Joomla.
 * Component defaults establish ownership; path names never imply ownership.
 *
 * @since 0.1.0
 */
final class ApiRegistry
{
	/** @var ApiRouter Registered native API router. @since 0.1.0 */
	private ApiRouter $router;
	/** @var ?array<int,string[]> Observed plugin provenance, required when supplied by the installed inventory. @since 0.1.1 */
	private ?array $owners;

	/** @param ApiRouter $router Router after onBeforeApiRoute. @param ?array $owners Observed native registration provenance. @since 0.1.0 */
	public function __construct(ApiRouter $router, ?array $owners = null)
	{
		$this->router = $router;
		$this->owners = $owners;
	}

	/** @return array Exact registered methods, paths, defaults and variables. @since 0.1.0 */
	public function inventory(): array
	{
		$routes = [];
		$unsupported = [];
		$registered = [];

		foreach ($this->router->getRoutes() as $route)
		{
			$defaults = $route->getDefaults();

			if (($defaults['component'] ?? '') !== 'com_componentbuilder')
			{
				continue;
			}

			$path = '/' . ltrim($route->getPattern(), '/');
			$controller = $route->getController();

			if (!is_string($controller) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\.[a-zA-Z][a-zA-Z0-9_]*\z/D', $controller) !== 1
				|| preg_match('/\A\/v[1-9][0-9]*(?:\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*))+\/?\z/D', $path) !== 1)
			{
				$unsupported[$path] = 'A specialized controller or route requires a reviewed adapter.';
				continue;
			}

			foreach ($route->getMethods() as $method)
			{
				$key = $method . ' ' . $path;
				$definition = ['method' => $method, 'route' => $path, 'controller' => $controller,
					'defaults' => $defaults, 'variables' => $route->getRouteVariables(), 'rules' => $route->getRules()];

				if ($this->owners !== null)
				{
					$definition['required_extensions'] = $this->owners[spl_object_id($route)] ?? [];

					if ($definition['required_extensions'] === [])
					{
						$unsupported[$key] = 'The native route has no observed owning plugin; synchronize through its registration event.';
						continue;
					}
				}

				if (isset($registered[$key]) && Json::canonical($registered[$key]) !== Json::canonical($definition))
				{
					throw new OperationException('JCB_ROUTE_AMBIGUOUS', 'Two installed plugins register different JCB handlers for the same route.');
				}

				$registered[$key] = $definition;
				$reason = self::unsupportedReason($definition);

				if ($reason !== null)
				{
					$unsupported[$key] = $reason;
					continue;
				}

				$routes[$key] = $definition;
			}
		}

		ksort($routes, SORT_STRING);
		ksort($unsupported, SORT_STRING);

		return ['routes' => array_values($routes), 'unsupported' => $unsupported,
			'fingerprint' => hash('sha256', Json::canonical(['routes' => $routes, 'unsupported' => $unsupported]))];
	}

	/**
	 * Review only source-backed Joomla CRUD contracts; method names do not imply safety.
	 *
	 * @param array $route An actual router registration.
	 * @return ?string Explicit diagnostic, or null for a supported contract.
	 * @since 0.1.1
	 */
	public static function unsupportedReason(array $route): ?string
	{
		$task = substr($route['controller'], strrpos($route['controller'], '.') + 1);
		$operations = ['GET' => ['displayList', 'displayItem'], 'POST' => ['add'],
			'PATCH' => ['edit'], 'PUT' => ['edit'], 'DELETE' => ['delete']];

		if (!in_array($task, $operations[$route['method']] ?? [], true))
		{
			return 'The native method/task pair requires a reviewed specialized adapter.';
		}

		$variables = $route['variables'];
		if (count($variables) !== count(array_unique($variables)))
		{
			return 'Repeated route variables require a reviewed adapter.';
		}

		foreach ($variables as $variable)
		{
			if (in_array($variable, ['data', 'offset', 'limit', 'filter', 'ordering', 'direction', 'etag', 'site'], true))
			{
				return 'A route variable collides with a reserved action input.';
			}

			$rule = $route['rules'][$variable] ?? '';
			if (!in_array($rule, ['', '(\\d+)', '\\d+', '[0-9]+', '([0-9]+)', '[A-Za-z0-9][A-Za-z0-9._-]*'], true))
			{
				return 'A specialized route-variable rule requires a reviewed encoder.';
			}
		}

		if (in_array($task, ['displayItem', 'edit', 'delete'], true)
			&& (!in_array('id', $variables, true)
				|| !in_array($route['rules']['id'] ?? '', ['(\\d+)', '\\d+', '[0-9]+', '([0-9]+)'], true)))
		{
			return 'Native item operations require an explicit numeric id route variable.';
		}

		foreach ($route['defaults'] as $name => $value)
		{
			if (in_array($name, ['component', 'public', 'format'], true))
			{
				continue;
			}

			if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $name) !== 1
				|| in_array($name, ['option', 'controller', 'task'], true)
				|| !is_scalar($value) || strlen((string) $value) > 2048)
			{
				return 'Specialized route defaults require a reviewed adapter.';
			}
		}

		return null;
	}

}
