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
	 * Inventory reviewed component routes after native webservices registration.
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
	/** @var string[] Explicit installed component scope; Joomla core uses its existing catalogue. @since 0.1.2 */
	private array $components;

	/** @param ApiRouter $router Router after onBeforeApiRoute. @param ?array $owners Observed native registration provenance. @param string[] $components Reviewed installed components. @since 0.1.0 */
	public function __construct(ApiRouter $router, ?array $owners = null, array $components = ['com_componentbuilder'])
	{
		$this->router = $router;
		$this->owners = $owners;
		$this->components = array_values(array_unique($components));

		foreach ($this->components as $component)
		{
			if (!is_string($component) || preg_match('/\Acom_[A-Za-z][A-Za-z0-9_]*\z/D', $component) !== 1)
			{
				throw new OperationException('JCB_INVENTORY_INVALID', 'The installed API component scope contains an invalid extension name.');
			}
		}
		sort($this->components, SORT_STRING);
	}

	/** @return array Exact registered methods, paths, defaults and variables. @since 0.1.0 */
	public function inventory(): array
	{
		$routes = [];
		$unsupported = [];
		$unsupportedComponents = [];
		$registered = [];

		foreach ($this->router->getRoutes() as $route)
		{
			$defaults = $route->getDefaults();

			$component = $defaults['component'] ?? '';

			if (!in_array($component, $this->components, true))
			{
				continue;
			}

			$path = '/' . ltrim($route->getPattern(), '/');
			$controller = $route->getController();

			if (!is_string($controller) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\.[a-zA-Z][a-zA-Z0-9_]*\z/D', $controller) !== 1
				|| preg_match('/\A\/v[1-9][0-9]*(?:\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*))+\/?\z/D', $path) !== 1)
			{
				$unsupported[$path] = 'A specialized controller or route requires a reviewed adapter.';
				$unsupportedComponents[$path] = $component;
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
						$unsupportedComponents[$key] = $component;
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
					$unsupportedComponents[$key] = $component;
					continue;
				}

				$routes[$key] = $definition;
			}
		}

		ksort($routes, SORT_STRING);
		ksort($unsupported, SORT_STRING);
		ksort($unsupportedComponents, SORT_STRING);

		return ['routes' => array_values($routes), 'unsupported' => $unsupported,
			'components' => $this->components, 'unsupported_components' => $unsupportedComponents,
			'fingerprint' => hash('sha256', Json::canonical(['routes' => $routes, 'unsupported' => $unsupported,
				'components' => $this->components, 'unsupported_components' => $unsupportedComponents]))];
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
		if (!is_string($route['method'] ?? null) || !is_string($route['controller'] ?? null)
			|| preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\.[a-zA-Z][a-zA-Z0-9_]*\z/D', $route['controller']) !== 1
			|| !is_string($route['route'] ?? null)
			|| preg_match('/\A\/v[1-9][0-9]*(?:\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*))+\/?\z/D', $route['route']) !== 1
			|| !is_array($route['variables'] ?? null) || !array_is_list($route['variables'])
			|| !is_array($route['rules'] ?? null) || !is_array($route['defaults'] ?? null)
			|| !is_string($route['defaults']['component'] ?? null)
			|| preg_match('/\Acom_[A-Za-z][A-Za-z0-9_]*\z/D', $route['defaults']['component']) !== 1)
		{
			return 'The native route contract lacks valid method, controller, component or path metadata.';
		}

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
			if (!is_string($variable) || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $variable) !== 1)
			{
				return 'A native route variable must be a literal named identifier.';
			}

			if (in_array($variable, ['data', 'offset', 'limit', 'filter', 'ordering', 'direction', 'etag', 'site'], true))
			{
				return 'A route variable collides with a reserved action input.';
			}

			if (self::parameterKind($route, $variable) === null)
			{
				return 'A specialized route-variable rule requires a reviewed encoder.';
			}
		}

		if (in_array($task, ['displayItem', 'edit', 'delete'], true) && self::identity($route) === null)
		{
			return 'Native item operations require a reviewed numeric id, GUID or registered unique-key route variable.';
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

	/** @param array $route Actual registration. @param string $variable Route variable. @return ?string Reviewed encoder kind. @since 0.1.2 */
	public static function parameterKind(array $route, string $variable): ?string
	{
		$rule = $route['rules'][$variable] ?? '';

		if (in_array($rule, ['(\\d+)', '\\d+', '[0-9]+', '([0-9]+)'], true))
		{
			return 'positive-integer';
		}

		if ($variable === 'guid' && in_array($rule, ['([0-9a-fA-F-]{36})', '[0-9a-fA-F-]{36}',
			'([0-9a-f-]{36})', '[0-9a-f-]{36}', '([0-9A-F-]{36})', '[0-9A-F-]{36}'], true))
		{
			return 'guid';
		}

		if ($variable !== 'id' && in_array($rule, ['([^/]+)', '[^/]+'], true))
		{
			return 'unique-key';
		}

		return in_array($rule, ['', '[A-Za-z0-9][A-Za-z0-9._-]*'], true) ? 'adapter-id' : null;
	}

	/**
	 * Resolve only identities declared by the actual item route; no alias is invented.
	 *
	 * @param array $route Native item registration.
	 * @return ?array Variable, stored field and typed encoder, or null.
	 * @since 0.1.2
	 */
	public static function identity(array $route): ?array
	{
		$identity = null;

		foreach ($route['variables'] as $variable)
		{
			$kind = self::parameterKind($route, $variable);

			if (($variable === 'id' && $kind === 'positive-integer') || ($variable === 'guid' && $kind === 'guid')
				|| ($kind === 'unique-key' && str_ends_with(rtrim($route['route'], '/'), '/' . $variable . '/:' . $variable)))
			{
				if ($identity !== null)
				{
					return null;
				}

				$identity = ['name' => $variable, 'field' => $variable, 'kind' => $kind];
			}
		}

		return $identity;
	}

}
