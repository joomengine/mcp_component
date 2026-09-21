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
	 * @return array Portable complete graph for the JCB-owned providers only.
	 * @since 0.1.0
	 */
	public function build(array $commands, array $api): array
	{
		$rows = array_fill_keys(array_keys(Structure::definitions()), []);
		$source = hash('sha256', Json::canonical(['commands' => $commands, 'api' => $api]));
		$add = static function (string $entity, array $record) use (&$rows): int
		{
			$id = count($rows[$entity]) + 1;
			$rows[$entity][] = ['id' => $id] + $record;
			return $id;
		};
		$provider = $add('provider', ['name' => 'jcb.installed', 'title' => 'Joomla Component Builder',
			'extension' => 'com_componentbuilder', 'description' => 'Contracts observed in this installed JCB API and console registry.',
			'definition' => Json::encode(['minimumJoomla' => '6.1.0', 'maximumJoomlaExclusive' => '7.0.0',
				'inventory' => $source, 'commandCount' => count($commands['commands'] ?? []), 'routeCount' => count($api['routes'] ?? []),
				'unsupportedCommands' => (object) ($commands['unsupported'] ?? []),
				'unsupportedRoutes' => (object) ($api['unsupported'] ?? [])])]);
		$schema = static function (string $name, array $document) use ($add, $provider): int
		{
			return $add('schema', ['provider_id' => $provider, 'name' => $name, 'title' => $name, 'document' => Json::encode($document)]);
		};
		$output = $schema('jcb.result', ['type' => 'object', 'additionalProperties' => true]);
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
			$definition = ['required_permissions' => $permissions, 'nativeCommand' => $name, 'aliases' => $command['aliases'] ?? []];
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

		$routes = $api['routes'] ?? [];
		foreach ($routes as $route)
		{
			$method = $route['method'];
			$task = substr($route['controller'], strrpos($route['controller'], '.') + 1);
			$name = self::routeName($route);
			$properties = [];
			$required = [];
			$parameters = [];

			foreach ($route['variables'] as $variable)
			{
				$integer = in_array($route['rules'][$variable] ?? '', ['(\\d+)', '\\d+', '[0-9]+', '([0-9]+)'], true);
				$properties[$variable] = $integer ? ['type' => 'integer', 'minimum' => 1, 'maximum' => 9007199254740991]
					: ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$'];
				$required[] = $variable;
				$parameters[] = ['name' => $variable, 'kind' => $integer ? 'positive-integer' : 'adapter-id'];
			}

			$list = $method === 'GET' && $task === 'displayList';
			$body = in_array($method, ['POST', 'PATCH', 'PUT'], true);

			if ($list)
			{
				$properties['offset'] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000];
				$properties['limit'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 500];
			}

			if ($body)
			{
				$properties['data'] = ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => true];
				$required[] = 'data';
			}

			$input = $schema($name . '.input', ['type' => 'object', 'properties' => (object) $properties,
				'required' => $required, 'additionalProperties' => false]);
			$operation = match ($task) { 'displayList' => 'list', 'displayItem' => 'get', 'add' => 'create', 'edit' => 'update', 'delete' => 'delete', default => $task };
			$definition = ['nativeRoute' => $route, 'required_permissions' => [['action' => 'core.manage', 'asset' => 'com_componentbuilder']]];
			$action = $add('action', ['provider_id' => $provider, 'name' => $name, 'title' => $route['controller'] . ' (' . $method . ')',
				'description' => 'Installed JCB API: ' . $method . ' ' . $route['route'], 'domain' => 'jcb',
				'toolset' => $method === 'GET' ? 'jcb.read' : 'jcb.write', 'effect' => $method === 'GET' ? 'read' : 'write',
				'risk' => $method === 'GET' ? 'read' : ($method === 'DELETE' ? 'destructive' : 'write'),
				'input_schema_id' => $input, 'output_schema_id' => $output, 'definition' => Json::encode($definition)]);
			$config = ['method' => $method, 'route' => $route['route'], 'route_parameters' => $parameters,
				'paginated' => $list, 'body_policy' => $body ? 'required' : 'none', 'operation' => $operation,
				'body_defaults' => (object) [], 'query_defaults' => (object) [], 'authentication' => 'joomla-api-token',
				'response_shape' => 'jsonapi', 'preserve_fields' => [], 'derived_fields' => []];

			if (in_array($operation, ['create', 'update', 'delete'], true))
			{
				$controller = substr($route['controller'], 0, (int) strrpos($route['controller'], '.'));

				foreach ($routes as $read)
				{
					if ($read['method'] === 'GET' && $read['controller'] === $controller . '.displayItem'
						&& in_array('id', $read['variables'], true))
					{
						$config['read_action'] = self::routeName($read);
						break;
					}
				}
			}
			$add('binding', ['provider_id' => $provider, 'action_id' => $action, 'name' => $name . '.api', 'title' => $name,
				'input_schema_id' => $input, 'output_schema_id' => $output, 'track' => 'api', 'handler' => 'api.request',
				'configuration' => Json::encode($config), 'definition' => Json::encode($definition)]);
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

	/** @param array $route Actual registered API method/path. @return string Stable route identity. @since 0.1.0 */
	private static function routeName(array $route): string
	{
		return 'jcb.api.' . strtolower($route['controller']) . '.' . strtolower($route['method'])
			. '.' . substr(hash('sha256', $route['route']), 0, 12);
	}
}
