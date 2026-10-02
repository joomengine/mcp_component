<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Materialize installed native contracts as an administrator-owned seed graph.
 * The generated graph is persisted once; runtime discovery reads database rows.
 *
 * @since 0.1.0
 */
final class CatalogueBuilder
{
	/**
	 * @param array $commands Native CommandRegistry inventory.
	 * @param array $api Native ApiRegistry inventory.
	 * @return array Portable complete graph for explicitly inventoried component providers.
	 * @since 0.1.0
	 */
	public function build(array $commands, array $api): array
	{
		$rows = array_fill_keys(array_keys(Structure::definitions()), []);
		$source = Json::canonicalHash(['commands' => $commands, 'api' => $api]);
		$add = static function (string $entity, array $record) use (&$rows): int
		{
			$id = count($rows[$entity]) + 1;
			$rows[$entity][] = ['id' => $id] + $record;
			return $id;
		};
		$routes = $api['routes'] ?? [];

		if (array_key_exists('components', $api))
		{
			foreach ($routes as $route)
			{
				if (!in_array($route['defaults']['component'] ?? '', $api['components'], true))
				{
					throw new OperationException('JCB_INVENTORY_INVALID', 'An API route is outside the explicitly synchronized component scope.');
				}
			}
		}

		$components = array_unique(array_merge($api['components'] ?? [], array_map(
			static fn (array $route): string => $route['defaults']['component'] ?? '', $routes)));
		sort($components, SORT_STRING);
		$provider = null;
		$output = null;
		$providers = [];
		$outputs = [];
		$jcbRoutes = array_filter($routes, static fn (array $route): bool => ($route['defaults']['component'] ?? '') === 'com_componentbuilder');

		// A partial component synchronization must not manage or retire JCB's
		// existing records unless that provider is part of the observed scope.
		if (!array_key_exists('components', $api) || in_array('com_componentbuilder', $components, true)
			|| self::hasCommandScope($commands))
		{
			$provider = $add('provider', ['name' => 'jcb.installed', 'title' => 'Joomla Component Builder',
				'extension' => 'com_componentbuilder', 'description' => 'Contracts observed in this installed JCB API and console registry.',
				'definition' => Json::encode(['minimumJoomla' => '6.1.0', 'maximumJoomlaExclusive' => '7.0.0',
					'inventory' => $source, 'commandCount' => count($commands['commands'] ?? []), 'routeCount' => count($jcbRoutes),
					'unsupportedCommands' => (object) ($commands['unsupported'] ?? []),
					'unsupportedRoutes' => (object) ($api['unsupported'] ?? [])])]);
		}

		$schema = static function (string $name, array $document, ?int $owner = null) use ($add, $provider): int
		{
			return $add('schema', ['provider_id' => $owner ?? $provider, 'name' => $name, 'title' => $name, 'document' => Json::encode($document)]);
		};
		if ($provider !== null)
		{
			$output = $schema('jcb.result', ['type' => 'object', 'additionalProperties' => true]);
			$providers['com_componentbuilder'] = $provider;
			$outputs['com_componentbuilder'] = $output;
		}

		foreach ($components as $component)
		{
			if (preg_match('/\Acom_[A-Za-z][A-Za-z0-9_]*\z/D', $component) !== 1)
			{
				throw new OperationException('JCB_INVENTORY_INVALID', 'An API provider lacks a valid native component owner.');
			}

			if ($component === 'com_componentbuilder')
			{
				continue;
			}

			$owned = array_filter($routes, static fn (array $route): bool => ($route['defaults']['component'] ?? '') === $component);
			$diagnostics = array_filter($api['unsupported'] ?? [], static fn (string $key): bool =>
				($api['unsupported_components'][$key] ?? null) === $component, ARRAY_FILTER_USE_KEY);
			$prefix = 'jcb.component.' . substr($component, 4);
			$providers[$component] = $add('provider', ['name' => $prefix . '.installed', 'title' => $component,
				'extension' => $component, 'description' => 'Native API contracts observed for this installed component.',
				'definition' => Json::encode(['minimumJoomla' => '6.1.0', 'maximumJoomlaExclusive' => '7.0.0',
					'inventory' => $source, 'routeCount' => count($owned), 'unsupportedRoutes' => (object) $diagnostics])]);
			$outputs[$component] = $schema($prefix . '.result', ['type' => 'object', 'additionalProperties' => true], $providers[$component]);
		}
		$permissions = [['action' => 'core.admin', 'asset' => 'com_componentbuilder']];

		foreach ($commands['commands'] ?? [] as $command)
		{
			$name = $command['name'] ?? '';

			if (preg_match('/\Acomponentbuilder:(compile|get|init|pull|push|reset):[a-z][a-z0-9_]*\z/D', $name) !== 1
				|| !is_string($command['fingerprint'] ?? null) || !is_string($command['implementation'] ?? null))
			{
				throw new OperationException('JCB_INVENTORY_INVALID', 'A registered JCB command lacks reviewed native provenance.');
			}

			$identity = 'jcb.' . str_replace(':', '.', substr($name, strlen('componentbuilder:')));
			$properties = [];

			foreach ((array) ($command['options'] ?? []) as $option => $mode)
			{
				$mode = (array) $mode;
				$properties[$option] = empty($mode['acceptsValue']) ? ['type' => 'boolean']
					: ['type' => empty($mode['valueRequired']) ? ['string', 'null'] : 'string', 'maxLength' => 1048576];
			}

			$input = $schema($identity . '.input', ['type' => 'object', 'properties' => (object) [
				'options' => ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false]], 'additionalProperties' => false]);
			$definition = ['required_permissions' => $permissions, 'required_extensions' => $command['required_extensions'] ?? [],
				'nativeCommand' => $name, 'aliases' => $command['aliases'] ?? []];
			$action = $add('action', ['provider_id' => $provider, 'name' => $identity, 'title' => $name,
				'description' => $command['description'] ?? $name, 'domain' => 'jcb', 'toolset' => 'jcb.execute',
				'effect' => 'write', 'risk' => 'high', 'input_schema_id' => $input, 'output_schema_id' => $output,
				'definition' => Json::encode($definition)]);
			$config = ['command' => $name, 'contract' => ['arguments' => $command['arguments'], 'options' => $command['options']],
				'implementation' => $command['implementation'], 'async' => true];

			foreach (['cli', 'api'] as $track)
			{
				$add('binding', ['provider_id' => $provider, 'action_id' => $action, 'name' => $identity . '.' . $track,
					'title' => $name . ' (' . $track . ')', 'input_schema_id' => $input, 'output_schema_id' => $output,
					'track' => $track, 'handler' => 'jcb.command', 'configuration' => Json::encode($config),
					'definition' => Json::encode($definition), 'params' => '{"async":true}']);
			}

			$add('target', ['provider_id' => $provider, 'name' => $name, 'title' => $name, 'command' => $name,
				'risk' => 'high', 'status' => 'confirmed-job', 'description' => $command['description'] ?? $name,
				'definition' => Json::encode($definition)]);
		}

		foreach ($commands['unsupported'] ?? [] as $name => $reason)
		{
			$add('target', ['provider_id' => $provider, 'name' => $name, 'title' => $name, 'command' => $name,
				'risk' => 'high', 'status' => 'unavailable', 'published' => 0,
				'description' => 'Registered by native JCB but unavailable to this reviewed adapter: ' . $reason,
				'definition' => Json::encode(['nativeCommand' => $name, 'unavailableReason' => $reason])]);
		}

		foreach ($routes as $route)
		{
			if (ApiRegistry::unsupportedReason($route) !== null)
			{
				throw new OperationException('JCB_INVENTORY_INVALID', 'An API route requires a reviewed specialized adapter.');
			}

			$method = $route['method'];
			$task = substr($route['controller'], strrpos($route['controller'], '.') + 1);
			$component = $route['defaults']['component'];
			$routeProvider = $providers[$component];
			$routeOutput = $outputs[$component];
			$name = self::routeName($route);
			$properties = [];
			$required = [];
			$parameters = [];

			foreach ($route['variables'] as $variable)
			{
				$kind = ApiRegistry::parameterKind($route, $variable);
				$properties[$variable] = match ($kind) {
					'positive-integer' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9007199254740991],
					'guid' => ['type' => 'string', 'minLength' => 36, 'maxLength' => 36,
						'pattern' => '^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$'],
					'unique-key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255,
						'pattern' => '^(?!\\.{1,2}$)[^/\\\\%?#\\x00-\\x1f\\x7f]+$'],
					default => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$'],
				};
				$required[] = $variable;
				$parameters[] = ['name' => $variable, 'kind' => $kind];
			}

			$list = $method === 'GET' && $task === 'displayList';
			$body = in_array($method, ['POST', 'PATCH', 'PUT'], true);

			if ($list)
			{
				$properties['offset'] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000];
				$properties['limit'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 500];
				$filterValue = ['type' => ['string', 'integer', 'number', 'boolean'], 'maxLength' => 2048];
				$properties['filter'] = ['type' => 'object', 'maxProperties' => 32,
					'propertyNames' => ['pattern' => '^[A-Za-z][A-Za-z0-9_]{0,63}$'],
					'additionalProperties' => ['anyOf' => [$filterValue, ['type' => 'array', 'minItems' => 1, 'maxItems' => 64, 'items' => $filterValue]]],
					'description' => 'Native scalar or multiselect filter values accepted by this installed controller; unrecognized filters may be ignored by Joomla.'];
				$properties['ordering'] = ['type' => 'string', 'maxLength' => 190, 'pattern' => '^[A-Za-z][A-Za-z0-9_.]*$'];
				$properties['direction'] = ['type' => 'string', 'enum' => ['asc', 'desc']];
			}

			if ($body)
			{
				$properties['data'] = $route['form_contract']['schema'] ?? ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => true];
				$required[] = 'data';
			}

			$input = $schema($name . '.input', ['type' => 'object', 'properties' => (object) $properties,
				'required' => $required, 'additionalProperties' => false], $routeProvider);
			$operation = match ($task) { 'displayList' => 'list', 'displayItem' => 'get', 'add' => 'create', 'edit' => 'update', 'delete' => 'delete', default => $task };
			$nativeRoute = $route;
			unset($nativeRoute['form_contract']);

			if (isset($route['form_contract']['fingerprint']))
			{
				$nativeRoute['form_contract_fingerprint'] = $route['form_contract']['fingerprint'];
			}

			$definition = ['nativeRoute' => $nativeRoute, 'required_extensions' => $route['required_extensions'] ?? [],
				'required_permissions' => [['action' => 'core.manage', 'asset' => $component]]];
			$domain = $component === 'com_componentbuilder' ? 'jcb' : 'component_api';
			$action = $add('action', ['provider_id' => $routeProvider, 'name' => $name, 'title' => $route['controller'] . ' (' . $method . ')',
				'description' => 'Installed component API: ' . $method . ' ' . $route['route'], 'domain' => $domain,
				'toolset' => $domain . ($method === 'GET' ? '.read' : '.write'), 'effect' => $method === 'GET' ? 'read' : 'write',
				'risk' => $method === 'GET' ? 'read' : ($method === 'DELETE' ? 'destructive' : 'write'),
				'input_schema_id' => $input, 'output_schema_id' => $routeOutput, 'definition' => Json::encode($definition)]);
			$config = ['method' => $method, 'route' => $route['route'], 'route_parameters' => $parameters,
				'paginated' => $list, 'body_policy' => $body ? 'required' : 'none', 'operation' => $operation,
				'body_defaults' => (object) ($body ? self::dataDefaults($route) : []),
				'query_defaults' => (object) self::dataDefaults($route), 'authentication' => 'joomla-api-token',
				'response_shape' => 'jsonapi', 'preserve_fields' => [], 'derived_fields' => []];
			$params = [];

			if (isset($route['form_contract']))
			{
				$config['api_form'] = $route['form_contract'];
			}

			if ($list)
			{
				$config['native_filter'] = true;
				$config['native_filter_arrays'] = true;
				$config['query_map'] = ['ordering' => 'list[ordering]', 'direction' => 'list[direction]'];
			}

			if (in_array($operation, ['create', 'update', 'delete'], true))
			{
				$read = self::readRoute($route, $routes, $operation);

				if ($read !== null)
				{
					$config['read_action'] = self::routeName($read);
					$identity = ApiRegistry::identity($read);
					$writeIdentity = ApiRegistry::identity($route) ?? $identity;
					$params['verification'] = ['read_action' => $config['read_action'], 'operation' => $operation,
						'primary_key' => $identity['field'], 'input_key' => $writeIdentity['name'],
						'read_input_key' => $identity['name'], 'identity_type' => match ($identity['kind']) {
							'positive-integer' => 'integer', 'guid' => 'guid', default => 'string',
						}, 'state_field' => 'state'];
				}
			}
			$add('binding', ['provider_id' => $routeProvider, 'action_id' => $action, 'name' => $name . '.api', 'title' => $name,
				'input_schema_id' => $input, 'output_schema_id' => $routeOutput, 'track' => 'api', 'handler' => 'api.request',
				'configuration' => Json::encode($config), 'definition' => Json::encode($definition), 'params' => Json::encode((object) $params)]);
		}

		foreach ($rows as $entity => &$records)
		{
			foreach ($records as &$record)
			{
				foreach (Structure::columns($entity) as $field => $type)
				{
					if (!array_key_exists($field, $record))
					{
						$record[$field] = match (true) {
							$type === 'date', $type === 'nullable_int', str_starts_with($type, 'optional:') => null,
							in_array($type, ['published', 'access', 'version'], true) => 1,
							in_array($type, ['int', 'bigint', 'pk'], true), str_starts_with($type, 'ref:') => 0,
							$type === 'json' => '{}', default => '',
						};
					}
				}

				$record['seed_revision'] = $source;
				$record['seed_hash'] = SeedUpdater::hash($entity, $record);
			}
			unset($record);
		}
		unset($records);

		return ['source' => $source, 'entities' => $rows];
	}

	/**
	 * Identify every command inventory that manages existing JCB provider records.
	 * Unsupported registrations and an authoritative empty scope can retire rows
	 * during synchronization, so they require the same native administration ACL.
	 *
	 * @param array $commands Observed native command inventory.
	 * @return bool Whether the graph manages JCB command ownership.
	 * @since 1.0.6
	 */
	public static function hasCommandScope(array $commands): bool
	{
		return !empty($commands['commands']) || !empty($commands['unsupported'])
			|| ($commands['component'] ?? null) === 'com_componentbuilder';
	}

	/** @param array $route Native registered defaults. @return array Fixed controller input without router metadata. @since 0.1.1 */
	private static function dataDefaults(array $route): array
	{
		$defaults = $route['defaults'];
		unset($defaults['component'], $defaults['public'], $defaults['format']);

		foreach ($route['variables'] as $variable)
		{
			unset($defaults[$variable]);
		}

		return $defaults;
	}

	/** @param array $route Actual registered API method/path. @return string Stable route identity. @since 0.1.0 */
	private static function routeName(array $route): string
	{
		$component = $route['defaults']['component'];
		$prefix = $component === 'com_componentbuilder' ? 'jcb.api.' : 'jcb.api.' . substr($component, 4) . '.';

		return $prefix . strtolower($route['controller']) . '.' . strtolower($route['method'])
			. '.' . substr(hash('sha256', $route['route']), 0, 12);
	}

	/**
	 * Match a mutation to an observed item read of the same component and fixed scope.
	 *
	 * @param array $write Actual mutation route.
	 * @param array $routes Installed supported registrations.
	 * @param string $operation Create, update or delete.
	 * @return ?array Matching independent item read, preferring numeric create readback.
	 * @since 0.1.2
	 */
	private static function readRoute(array $write, array $routes, string $operation): ?array
	{
		$controller = substr($write['controller'], 0, (int) strrpos($write['controller'], '.'));
		$candidates = [];

		foreach ($routes as $read)
		{
			$identity = ApiRegistry::identity($read);

			if ($read['method'] !== 'GET' || $read['controller'] !== $controller . '.displayItem' || $identity === null
				|| ($read['defaults']['component'] ?? '') !== ($write['defaults']['component'] ?? '')
				|| Json::canonical(self::dataDefaults($read)) !== Json::canonical(self::dataDefaults($write)))
			{
				continue;
			}

			$path = rtrim($write['route'], '/');
			$expected = $operation === 'create' ? $path . ($identity['name'] === 'id' ? '/:id'
				: '/' . $identity['name'] . '/:' . $identity['name']) : $path;

			if (rtrim($read['route'], '/') === $expected)
			{
				$candidates[] = $read;
			}
		}

		usort($candidates, static function (array $left, array $right): int
		{
			$rank = ['positive-integer' => 0, 'guid' => 1, 'unique-key' => 2];
			$order = ($rank[ApiRegistry::identity($left)['kind']] ?? 3) <=> ($rank[ApiRegistry::identity($right)['kind']] ?? 3);

			return $order !== 0 ? $order : strcmp(self::routeName($left), self::routeName($right));
		});

		return $candidates[0] ?? null;
	}
}
