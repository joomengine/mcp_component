<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


$root = dirname(__DIR__);
require_once $root . '/admin/src/Database/Structure.php';
$upstream = json_decode(file_get_contents($root . '/data/upstream-contracts.json'), true, 64, JSON_THROW_ON_ERROR);
$native = json_decode(file_get_contents($root . '/data/upstream-native.json'), true, 64, JSON_THROW_ON_ERROR);
$commit = '2cff50f4f6b440da3c684f9995a77efad32e1a36';

if (($upstream['source']['commit'] ?? null) !== $commit || ($native['source'] ?? null) !== $commit)
{
	throw new RuntimeException('Catalogue generation requires the immutable reviewed source.');
}

$json = static function (mixed $value): string
{
	return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
};
$rows = array_fill_keys(array_keys(Structure::definitions()), []);
$rows['provider'][] = [
	'id' => 1, 'name' => 'joomla.core', 'title' => 'Joomla core and JoomEngine MCP',
	'extension' => 'com_joomengine_mcp',
	'description' => 'Reviewed Joomla API, local console and MCP definitions. Extend with separately owned provider records.',
	'definition' => $json(['sourceRepository' => 'joomengine/joomla-mcp', 'sourceCommit' => $commit, 'minimumJoomla' => '6.1.0', 'maximumJoomlaExclusive' => '7.0.0']),
];
$schemaIds = [];
$addSchema = static function (array $schema) use (&$schemaIds, &$rows, $json): int
{
	if (($schema['type'] ?? null) === 'object' && isset($schema['properties']) && $schema['properties'] === [])
	{
		$schema['properties'] = new stdClass();
	}

	$document = $json($schema);
	$hash = hash('sha256', $document);

	if (!isset($schemaIds[$hash]))
	{
		$id = count($rows['schema']) + 1;
		$schemaIds[$hash] = $id;
		$rows['schema'][] = ['id' => $id, 'provider_id' => 1, 'name' => 'schema.' . $hash, 'title' => 'Reusable schema ' . substr($hash, 0, 12), 'document' => $document];
	}

	return $schemaIds[$hash];
};
$api = [];

foreach (array_merge($upstream['catalog']['api']['readActions'], $upstream['catalog']['api']['writeActions']) as $descriptor)
{
	if (isset($api[$descriptor['id']]))
	{
		throw new RuntimeException('Duplicate source API action.');
	}

	$api[$descriptor['id']] = $descriptor;
}

$cli = [];

foreach ($native['actions'] as $entry)
{
	$cli[$entry['descriptor']['name']] = $entry;
}

$crud = [];

foreach ($upstream['crudBases'] as $base)
{
	$crud[$base['id']] = $base;
}

$gates = [];

foreach ($upstream['catalog']['api']['mutationContracts'] as $contract)
{
	if (($contract['status'] ?? '') === 'catalogued-only-source-gated')
	{
		$gates[$contract['id']] = $contract['reason'];
	}
}

foreach ($upstream['catalog']['api']['sourceOnlyBlockedActions'] as $gate)
{
	$gates[$gate['id']] = $gate['reason'];
}

$names = array_values(array_unique(array_merge(array_keys($api), array_keys($cli))));
sort($names, SORT_STRING);
$parity = [];

foreach ($names as $name)
{
	$http = $api[$name] ?? null;
	$local = $cli[$name] ?? null;
	$isWrite = $http !== null ? $http['effect'] === 'write' : $local['descriptor']['risk'] !== 'read';
	$domain = $http['domain'] ?? explode('.', $name)[0];
	$toolset = $http['toolset'] ?? ($domain === 'scheduler' || $domain === 'site' ? 'maintenance.write' : ($isWrite ? 'admin.write' : 'admin.read'));
	$actionId = count($rows['action']) + 1;
	$definition = $http ?? $local['descriptor'];
	unset($definition['inputSchema'], $definition['outputSchema'], $definition['driver']);
	$inputSchema = $http['inputSchema'] ?? $local['descriptor']['inputSchema'];
	$outputSchema = $local['descriptor']['outputSchema'] ?? null;
	$rows['action'][] = [
		'id' => $actionId, 'provider_id' => 1, 'name' => $name,
		'title' => ucwords(str_replace(['.', '_'], ' ', $name)),
		'description' => $http['description'] ?? $local['descriptor']['description'],
		'domain' => $domain, 'toolset' => $toolset, 'effect' => $isWrite ? 'write' : 'read',
		'risk' => $http['risk'] ?? $local['descriptor']['risk'],
		'input_schema_id' => $addSchema($inputSchema), 'output_schema_id' => null,
		'definition' => $json($definition),
	];
	$bindings = [];

	if ($http !== null)
	{
		$operation = $http['operation'] ?? substr($name, (int) strrpos($name, '.') + 1);
		$baseName = substr($name, 0, (int) strrpos($name, '.'));
		$base = $crud[$baseName] ?? null;
		$route = '/' . ltrim($http['routeTemplate'], '/');
		$config = [
			'method' => $http['method'], 'route' => $route,
			'route_parameters' => $http['routeParameters'], 'paginated' => $http['paginated'],
			'body_policy' => $http['bodyPolicy'] ?? 'none', 'operation' => $operation,
			'body_defaults' => [], 'query_defaults' => [], 'preserve_fields' => [], 'derived_fields' => [],
			'authentication' => $http['driver']['authentication'] ?? 'joomla-api-token',
			'response_shape' => $http['driver']['responseShape'] ?? 'json-api',
		];

		if ($base !== null)
		{
			$config['body_defaults'] = array_diff_key($base['controllerDefaults'] ?? [], ['component' => true]);

			if (isset($api[$baseName . '.get']))
			{
				$config['read_action'] = $baseName . '.get';
			}

			if (in_array($operation, ['create', 'update'], true) && in_array($baseName, ['menus.site.items', 'menus.administrator.items'], true))
			{
				$config['preserve_fields'] = ['menutype', 'type', 'parent_id', 'link', 'params'];
				$config['derived_fields'] = ['menu_request'];
			}

			if (in_array($operation, ['create', 'update'], true) && in_array($baseName, ['modules.site', 'modules.administrator'], true))
			{
				$config['preserve_fields'] = ['params', 'assigned'];
				$config['derived_fields'] = ['module_assignment'];
			}
		}

		if (in_array($name, ['menus.administrator.items.list', 'menus.administrator.items.get'], true))
		{
			$config['query_defaults'] = ['client_id' => 1];
		}

		if ($name === 'media.files.create')
		{
			$config['mutation_rule'] = 'media_create';
		}
		elseif ($name === 'media.files.update')
		{
			$config['mutation_rule'] = 'media_update';
		}
		elseif (str_starts_with($name, 'languages.overrides.') && str_ends_with($name, '.create'))
		{
			$config['mutation_rule'] = 'override_create';
		}

		if ($name === 'configuration.application.get')
		{
			$config['select_fields'] = ['sitename', 'offline', 'offline_message', 'display_offline_message', 'offline_image', 'access', 'list_limit', 'feed_limit', 'feed_email', 'MetaDesc', 'MetaKeys', 'MetaTitle', 'MetaAuthor', 'MetaVersion', 'robots', 'sef', 'sef_rewrite', 'sef_suffix', 'unicodeslugs', 'sitename_pagetitles', 'caching', 'cache_handler', 'cachetime', 'cache_platformprefix', 'offset', 'lifetime', 'session_handler', 'shared_session', 'force_ssl', 'gzip', 'error_reporting', 'debug'];
		}

		if (isset($gates[$name]))
		{
			$config['source_gate'] = $gates[$name];
		}

		$id = count($rows['binding']) + 1;
		$rows['binding'][] = [
			'id' => $id, 'provider_id' => 1, 'action_id' => $actionId, 'name' => $name . '.api',
			'title' => $name . ' / API', 'track' => 'api', 'handler' => 'api.request',
			'input_schema_id' => $addSchema($http['inputSchema']), 'output_schema_id' => null,
			'configuration' => $json($config),
			'definition' => $json(['source' => $http['source'], 'acl' => $http['acl'], 'required_extensions' => [$http['driver']['plugin'], $http['acl']['component']]]),
			'published' => isset($gates[$name]) ? 0 : 1,
		];
		$bindings[] = ['id' => $id, 'track' => 'api', 'sourceGate' => $gates[$name] ?? null];
	}

	if ($local !== null)
	{
		$id = count($rows['binding']) + 1;
		$nativeMetadata = $local['descriptor'];
		$component = $local['configuration']['entity']['component'] ?? $local['configuration']['component'] ?? null;
		$nativeMetadata['required_extensions'] = $component === null ? [] : [$component];
		$verification = [];

		if ($local['handler'] === 'native.core-entity' && !in_array($local['configuration']['operation'], ['get', 'list'], true))
		{
			$entity = $local['configuration']['entity'];
			$verification = ['read_action' => $entity['id'] . '.get', 'operation' => $local['configuration']['operation'],
				'primary_key' => $entity['primaryKey'], 'state_field' => $entity['stateField']];
		}

		unset($nativeMetadata['inputSchema'], $nativeMetadata['outputSchema']);
		$rows['binding'][] = [
			'id' => $id, 'provider_id' => 1, 'action_id' => $actionId, 'name' => $name . '.cli',
			'title' => $name . ' / CLI', 'track' => 'cli', 'handler' => $local['handler'],
			'input_schema_id' => $addSchema($local['descriptor']['inputSchema']),
			'output_schema_id' => $outputSchema === null ? null : $addSchema($outputSchema),
			'configuration' => $json((object) $local['configuration']), 'definition' => $json($nativeMetadata),
			'params' => $json(['verification' => (object) $verification]),
		];
		$bindings[] = ['id' => $id, 'track' => 'cli', 'primitive' => $local['handler']];
	}

	$parity[] = ['name' => $name, 'actionId' => $actionId, 'sourceApi' => $http !== null, 'sourceNative' => $local !== null, 'bindings' => $bindings, 'stage' => 'inventoried'];
}

$toolHandlers = [
	'joomla_sites_list' => ['site.list', []],
	'joomla_capabilities' => ['catalog.capabilities', []],
	'joomla_action_search' => ['catalog.search', []],
	'joomla_action_describe' => ['catalog.describe', []],
	'joomla_action_read' => ['action.read', []],
	'joomla_core_catalogue' => ['catalog.core', []],
	'joomla_content_articles_list' => ['action.read', ['action' => 'content.articles.list', 'input_from' => 'arguments', 'tracks' => ['api'], 'query_map' => ['search' => 'filter[search]', 'state' => 'filter[state]', 'featured' => 'filter[featured]', 'category' => 'filter[category]', 'tag' => 'filter[tag]', 'language' => 'filter[language]', 'ordering' => 'list[ordering]', 'direction' => 'list[direction]']]],
	'joomla_content_article_get' => ['action.read', ['action' => 'content.articles.get', 'input_from' => 'arguments']],
	'joomla_extensions_list' => ['action.read', ['action' => 'extensions.installed.list', 'input_from' => 'arguments', 'tracks' => ['api'], 'query_map' => ['core' => 'filter[core]', 'status' => 'filter[status]', 'type' => 'filter[type]']]],
	'joomla_config_application_safe' => ['action.safe_configuration', ['action' => 'configuration.application.get']],
	'joomla_permission_request' => ['permission.request', []],
	'joomla_permission_approve' => ['permission.approve', []],
	'joomla_permission_grants' => ['permission.list', []],
	'joomla_permission_revoke' => ['permission.revoke', []],
	'joomla_action_plan' => ['action.plan', []],
	'joomla_action_apply' => ['action.apply', []],
	'joomla_content_article_plan' => ['action.plan', ['family' => 'content.articles', 'entity' => true]],
	'joomla_content_article_apply' => ['action.apply', []],
	'joomla_cli_list' => ['console.list', ['tracks' => ['cli']]],
	'joomla_cli_help' => ['console.help', ['tracks' => ['cli']]],
	'joomla_cli_targets' => ['console.targets', []],
	'joomla_companion_capabilities' => ['console.capabilities', ['tracks' => ['cli']]],
	'joomla_companion_read' => ['action.read', ['tracks' => ['cli']]],
	'joomla_cli_inventory' => ['console.inventory', ['tracks' => ['cli']]],
];

foreach ($upstream['tools'] as $tool)
{
	if (!isset($toolHandlers[$tool['name']]))
	{
		throw new RuntimeException('An upstream MCP tool has no reviewed PHP operation mapping.');
	}

	[$handler, $configuration] = $toolHandlers[$tool['name']];
	$metadata = $tool;
	unset($metadata['inputSchema'], $metadata['outputSchema']);
	$rows['tool'][] = [
		'id' => count($rows['tool']) + 1, 'provider_id' => 1, 'name' => $tool['name'],
		'title' => $tool['title'] ?? ucwords(str_replace('_', ' ', $tool['name'])),
		'description' => $tool['description'] ?? '', 'handler' => $handler,
		'input_schema_id' => $addSchema($tool['inputSchema']),
		'output_schema_id' => isset($tool['outputSchema']) ? $addSchema($tool['outputSchema']) : null,
		'configuration' => $json((object) $configuration), 'definition' => $json($metadata),
	];
}

foreach ($upstream['resources'] as $resource)
{
	$rows['resource'][] = [
		'id' => count($rows['resource']) + 1, 'provider_id' => 1, 'name' => $resource['name'],
		'title' => $resource['title'] ?? $resource['name'], 'uri' => $resource['uri'],
		'mime_type' => $resource['mimeType'] ?? 'application/json', 'is_template' => 0,
		'description' => $resource['description'] ?? '', 'handler' => 'catalog.core',
		'configuration' => '{}', 'definition' => $json($resource),
	];
}

foreach ($upstream['catalog']['cli']['targets'] as $target)
{
	$rows['target'][] = [
		'id' => count($rows['target']) + 1, 'provider_id' => 1, 'name' => $target['command'],
		'title' => $target['command'], 'command' => $target['command'], 'risk' => $target['risk'],
		'status' => $target['status'], 'description' => $target['note'], 'definition' => $json($target),
	];
}

$defaults = static function (string $type): mixed
{
	return match (true)
	{
		$type === 'date', $type === 'nullable_int', str_starts_with($type, 'optional:') => null,
		in_array($type, ['published', 'access', 'version'], true) => 1,
		in_array($type, ['int', 'bigint', 'pk'], true), str_starts_with($type, 'ref:') => 0,
		$type === 'json' => '{}',
		default => '',
	};
};

foreach ($rows as $entity => &$records)
{
	foreach ($records as &$record)
	{
		$record += array_map($defaults, Structure::columns($entity));
		$record['seed_revision'] = $commit;
		$record['created'] = '2026-09-17 00:00:00';
		$owned = array_diff_key($record, array_flip(['id', 'asset_id', 'checked_out', 'checked_out_time', 'created', 'created_by', 'modified', 'modified_by', 'version', 'seed_revision', 'seed_hash', 'customized']));
		ksort($owned, SORT_STRING);
		$record['seed_hash'] = hash('sha256', $json($owned));
	}

	unset($record);
}

unset($records);
$allEntities = array_merge(array_keys(Structure::definitions()), array_keys(Structure::state()));

foreach (['mysql', 'postgresql'] as $driver)
{
	$quoteName = static function (string $name) use ($driver): string
	{
		return $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
	};
	$quote = static function (mixed $value) use ($driver): string
	{
		if ($value === null)
		{
			return 'NULL';
		}

		if (is_int($value))
		{
			return (string) $value;
		}

		$text = str_replace("'", "''", (string) $value);

		if ($driver === 'mysql')
		{
			$text = str_replace('\\', '\\\\', $text);
		}

		return "'" . $text . "'";
	};
	$ddlType = static function (string $type) use ($driver): string
	{
		return match (true)
		{
			$type === 'pk' => $driver === 'mysql' ? 'INT NOT NULL AUTO_INCREMENT' : 'SERIAL NOT NULL',
			$type === 'date' => $driver === 'mysql' ? 'DATETIME NULL DEFAULT NULL' : 'TIMESTAMP WITHOUT TIME ZONE NULL DEFAULT NULL',
			$type === 'nullable_int', str_starts_with($type, 'optional:') => 'INT NULL DEFAULT NULL',
			str_starts_with($type, 'ref:') => 'INT NOT NULL',
			in_array($type, ['published', 'access', 'version'], true) => 'INT NOT NULL DEFAULT 1',
			$type === 'int' => 'INT NOT NULL DEFAULT 0',
			$type === 'bigint' => 'BIGINT NOT NULL DEFAULT 0',
			in_array($type, ['text', 'json'], true) => $driver === 'mysql' ? 'MEDIUMTEXT NOT NULL' : 'TEXT NOT NULL',
			$type === 'uuid' => "VARCHAR(36) NOT NULL DEFAULT ''",
			$type === 'hash' => "VARCHAR(64) NOT NULL DEFAULT ''",
			$type === 'uri' => "VARCHAR(1024) NOT NULL DEFAULT ''",
			default => "VARCHAR(190) NOT NULL DEFAULT ''",
		};
	};
	$sql = "-- Generated installation schema and source-pinned data; runtime reads the database only.\n";

	foreach ($allEntities as $entity)
	{
		$table = Structure::table($entity);
		$columns = Structure::columns($entity);
		$parts = [];

		foreach ($columns as $column => $type)
		{
			$parts[] = '  ' . $quoteName($column) . ' ' . $ddlType($type);
		}

		$parts[] = '  PRIMARY KEY (' . $quoteName('id') . ')';

		if (isset(Structure::definitions()[$entity]))
		{
			$parts[] = '  UNIQUE (' . $quoteName('name') . ')';
		}

		if (isset($columns['uuid']))
		{
			$unique = $entity === 'session' ? ['principal_key', 'uuid'] : ['uuid'];
			$parts[] = '  UNIQUE (' . implode(', ', array_map($quoteName, $unique)) . ')';
		}

		if ($entity === 'lease')
		{
			$parts[] = '  UNIQUE (' . $quoteName('resource_key') . ')';
		}

		if ($entity === 'execution')
		{
			$parts[] = '  UNIQUE (' . $quoteName('principal_key') . ', ' . $quoteName('idempotency_key') . ')';
		}

		foreach ($columns as $column => $type)
		{
			if (str_starts_with($type, 'ref:') || str_starts_with($type, 'optional:'))
			{
				$parent = substr($type, (int) strpos($type, ':') + 1);
				$parts[] = '  FOREIGN KEY (' . $quoteName($column) . ') REFERENCES '
					. $quoteName(Structure::table($parent)) . ' (' . $quoteName('id') . ') ON DELETE RESTRICT';
			}
		}

		$sql .= 'CREATE TABLE IF NOT EXISTS ' . $quoteName($table) . " (\n" . implode(",\n", $parts) . "\n)";
		$sql .= $driver === 'mysql' ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;\n\n" : ";\n\n";

		foreach (['provider_id', 'action_id', 'principal_key', 'expires_at', 'published'] as $indexed)
		{
			if (isset($columns[$indexed]))
			{
				$sql .= 'CREATE INDEX ' . $quoteName('#__jemcp_' . $entity . '_' . $indexed)
					. ' ON ' . $quoteName($table) . ' (' . $quoteName($indexed) . ");\n";
			}
		}

		foreach ($rows[$entity] ?? [] as $record)
		{
			$sql .= 'INSERT INTO ' . $quoteName($table) . ' (' . implode(', ', array_map($quoteName, array_keys($record)))
				. ') VALUES (' . implode(', ', array_map($quote, array_values($record))) . ");\n";
		}

		if ($driver === 'postgresql' && !empty($rows[$entity]))
		{
			$sql .= 'SELECT setval(pg_get_serial_sequence(' . $quote($table) . ", 'id'), (SELECT MAX(\"id\") FROM " . $quoteName($table) . "), true);\n";
		}

		$sql .= "\n";
	}

	$uninstall = "-- Uninstall removes only JoomEngine MCP tables, in dependency order.\n";

	foreach (array_reverse($allEntities) as $entity)
	{
		$uninstall .= 'DROP TABLE IF EXISTS ' . $quoteName(Structure::table($entity)) . ";\n";
	}

	$directory = $root . '/admin/sql';

	if (!is_dir($directory . '/updates/' . $driver))
	{
		mkdir($directory . '/updates/' . $driver, 0775, true);
	}

	file_put_contents($directory . '/install.' . $driver . '.utf8.sql', $sql);
	file_put_contents($directory . '/uninstall.' . $driver . '.utf8.sql', $uninstall);
	file_put_contents($directory . '/updates/' . $driver . '/0.1.0.sql', "-- Initial schema version; installation supplies the reviewed seed graph.\n");
}

file_put_contents($root . '/data/catalogue-seed.json', json_encode(['source' => $commit, 'entities' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($root . '/docs/migration/parity.json', json_encode(['source' => $commit, 'tools' => array_column($upstream['tools'], 'name'), 'actions' => $parity, 'sourceOnlyGates' => $gates, 'counts' => array_map('count', $rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode(['seedCounts' => array_map('count', $rows), 'drivers' => ['mysql', 'postgresql'], 'liveEvidence' => 'not implied by generated definitions'], JSON_PRETTY_PRINT) . PHP_EOL;
