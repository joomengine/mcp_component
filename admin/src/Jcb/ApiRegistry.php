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

	/** @param ApiRouter $router Router after onBeforeApiRoute. @since 0.1.0 */
	public function __construct(ApiRouter $router)
	{
		$this->router = $router;
	}

	/** @return array Exact registered methods, paths, defaults and variables. @since 0.1.0 */
	public function inventory(): array
	{
		$routes = [];
		$unsupported = [];

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
				if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true))
				{
					$unsupported[$method . ' ' . $path] = 'The method is not an MCP data operation.';
					continue;
				}

				$key = $method . ' ' . $path;
				$definition = ['method' => $method, 'route' => $path, 'controller' => $controller,
					'defaults' => $defaults, 'variables' => $route->getRouteVariables(), 'rules' => $route->getRules()];

				if (isset($routes[$key]) && Json::canonical($routes[$key]) !== Json::canonical($definition))
				{
					throw new OperationException('JCB_ROUTE_AMBIGUOUS', 'Two installed plugins register different JCB handlers for the same route.');
				}

				$routes[$key] = $definition;
			}
		}

		ksort($routes, SORT_STRING);
		ksort($unsupported, SORT_STRING);

		return ['routes' => array_values($routes), 'unsupported' => $unsupported,
			'fingerprint' => hash('sha256', Json::canonical($routes))];
	}
}
