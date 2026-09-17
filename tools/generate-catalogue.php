<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


require_once dirname(__DIR__) . '/admin/src/Database/Structure.php';
$root = dirname(__DIR__);
$sourceFile = $root . '/data/upstream-contracts.json';
$nativeFile = $root . '/data/upstream-native.json';

if (!is_file($sourceFile) || !is_file($nativeFile))
{
	throw new RuntimeException('Import the pinned upstream contracts before generating installation data.');
}

$upstream = json_decode(file_get_contents($sourceFile), true, 128, JSON_THROW_ON_ERROR);
$native = json_decode(file_get_contents($nativeFile), true, 128, JSON_THROW_ON_ERROR);
$commit = '2cff50f4f6b440da3c684f9995a77efad32e1a36';

if (($upstream['source']['commit'] ?? null) !== $commit || ($native['source'] ?? null) !== $commit)
{
	throw new RuntimeException('Catalogue provenance does not match the reviewed migration source.');
}

$json = static function (mixed $value): string
{
	return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
};
$rows = array_fill_keys(array_keys(Structure::definitions()), []);
$rows['provider'][] = [
	'id' => 1, 'name' => 'joomla.core', 'title' => 'Joomla core and MCP',
	'extension' => 'com_joomengine_mcp',
	'description' => 'Reviewed Joomla API, local console and MCP definitions. Extend with separately owned provider records.',
	'definition' => $json(['source' => $upstream['source'], 'minimumJoomla' => '6.1.0', 'maximumJoomlaExclusive' => '7.0.0', 'toolsets' => $upstream['toolsets']]),
];
$schemaIds = [];
$addSchema = static function (array $schema) use (&$rows, &$schemaIds, $json): int
{
	// Preserve JSON Schema maps as objects even when the PHP decoder returned [].
	$normalise = static function (mixed $value, ?string $keyword = null) use (&$normalise): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		foreach ($value as $key => $child)
		{
			$value[$key] = $normalise($child, is_string($key) ? $key : null);
		}

		if ($value === [] && in_array($keyword, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true))
		{
			return new stdClass();
		}

		return $value;
	};
	$document = $json($normalise($schema));
	$hash = hash('sha256', $document);

	if (!isset($schemaIds[$hash]))
	{
		$id = count($schemaIds) + 1;
		$schemaIds[$hash] = $id;
		$rows['schema'][] = ['id' => $id, 'provider_id' => 1, 'name' => 'schema.' . $hash, 'title' => 'Schema ' . substr($hash, 0, 12), 'document' => $document];
	}

	return $schemaIds[$hash];
};
$genericOutput = $addSchema(['type' => 'object', 'additionalProperties' => true]);
$api = [];

foreach (array_merge($upstream['catalog']['api']['readActions'], $upstream['catalog']['api']['writeActions']) as $action)
{
	$api[$action['id']] = $action;
}

$nativeByName = [];

foreach ($native['actions'] as $action)
{
	$nativeByName[$action['descriptor']['name']] = $action;
}

$companion = [];

foreach ($upstream['catalog']['cli']['companion']['actions'] as $action)
{
	$companion[$action['id']] = $action;
}

$bases = [];

foreach ($upstream['crud'] as $base)
{
	$bases[$base['id']] = $base;
}

$names = array_values(array_unique(array_merge(array_keys($api), array_keys($nativeByName))));
sort($names, SORT_STRING);
$parity = [];
$actionIds = [];
$gates = $upstream['catalog']['api']['sourceOnlyBlockedActions'];

foreach ($names as $name)
{
	$http = $api[$name] ?? null;
	$local = $nativeByName[$name] ?? null;
	$descriptor = $http ?? ($companion[$name] ?? $local['descriptor']);
	$effect = ($http !== null ? $http['method'] !== 'GET' : $local['descriptor']['risk'] !== 'read') ? 'write' : 'read';
	$domain = $descriptor['domain'] ?? explode('.', $name)[0];
	$toolset = $descriptor['toolset'] ?? match ($domain)
	{
		'users' => $effect === 'write' ? 'users.admin' : 'users.read',
		'extensions' => $effect === 'write' ? 'extensions.admin' : 'extensions.read',
		'configuration' => $effect === 'write' ? 'configuration.write' : 'configuration.read',
		'content', 'banners', 'contacts', 'newsfeeds' => 'content.' . $effect,
		'menus', 'modules', 'tags', 'fields', 'templates', 'languages', 'redirects' => 'structure.' . $effect,
		default => $effect === 'write' ? 'maintenance.admin' : 'maintenance.read',
	};
	$schema = $http['inputSchema'] ?? $local['descriptor']['inputSchema'];
	$metadata = $descriptor;
	unset($metadata['inputSchema'], $metadata['outputSchema']);
	$id = count($rows['action']) + 1;
	$actionIds[$name] = $id;
	$rows['action'][] = [
		'id' => $id, 'provider_id' => 1, 'name' => $name,
		'title' => $descriptor['title'] ?? ucwords(str_replace(['.', '-'], ' ', $name)),
		'description' => $descriptor['description'], 'domain' => $domain, 'toolset' => $toolset,
		'effect' => $effect, 'risk' => $descriptor['risk'],
		'input_schema_id' => $addSchema($schema),
		'output_schema_id' => $local !== null && $http === null ? $addSchema($local['descriptor']['outputSchema']) : $genericOutput,
		'definition' => $json($metadata + ['sourceGate' => $gates[$name] ?? null]),
	];
	$mapped = ['action' => $name, 'actionId' => $id, 'api' => $http !== null, 'native' => $local !== null, 'stage' => 'inventoried'];

	if ($http !== null)
	{
		$baseId = substr($name, 0, (int) strrpos($name, '.'));
		$base = $bases[$baseId] ?? null;
		$fixed = array_diff_key($base['controllerDefaults'] ?? [], ['component' => true]);
		$config = [
			'method' => $http['method'], 'route' => '/' . $http['routeTemplate'],
			'route_parameters' => $http['routeParameters'], 'paginated' => $http['paginated'] ?? false,
			'body_policy' => $http['bodyPolicy'] ?? 'none', 'operation' => $http['operation'],
			'body_defaults' => $fixed, 'query_defaults' => $name === 'menus.administrator.list' ? $fixed : new stdClass(),
			'preserve_fields' => [], 'derived_fields' => [],
			'authentication' => $http['driver']['authentication'],
			'response_shape' => $http['driver']['responseShape'],
			'source_gate' => $gates[$name] ?? null,
		];

		if ($name === 'configuration.application.get')
		{
			$config['select_fields'] = [
				'sitename', 'offline', 'offline_message', 'display_offline_message', 'debug',
				'error_reporting', 'force_ssl', 'sef', 'sef_rewrite', 'sef_suffix', 'unicodeslugs',
				'feed_limit', 'feed_email', 'lifetime', 'session_handler', 'offset', 'mailonline',
				'mailfrom', 'fromname', 'gzip', 'list_limit',
			];
		}

		if (in_array($baseId, ['menus.site-items', 'menus.administrator-items'], true))
		{
			$config['preserve_fields'] = ['menutype', 'type', 'parent_id', 'link', 'params'];
			$config['derived_fields'] = ['menu_request'];
		}

		if (in_array($baseId, ['modules.site', 'modules.administrator'], true))
		{
			$config['preserve_fields'] = ['params', 'assigned'];
			$config['derived_fields'] = ['module_assignment'];
		}

		if ($base !== null && $effect === 'write')
		{
			$config['read_action'] = $baseId . '.get';
		}

		$config['mutation_rule'] = match (true)
		{
			$name === 'media.files.create' => 'media_create',
			$name === 'media.files.update' => 'media_update',
			str_starts_with($name, 'languages.overrides.') && str_ends_with($name, '.create') => 'override_create',
			default => null,
		};
		$rows['binding'][] = [
			'id' => count($rows['binding']) + 1, 'provider_id' => 1, 'action_id' => $id,
			'name' => $name . '.api', 'title' => $name . ' (API)', 'track' => 'api', 'handler' => 'api.request',
			'input_schema_id' => $addSchema($http['inputSchema']), 'output_schema_id' => $genericOutput,
			'configuration' => $json($config), 'definition' => $json(['source' => $http['source'], 'acl' => $http['acl'], 'required_extensions' => [$http['driver']['plugin'], $http['acl']['component']]]),
			'published' => isset($gates[$name]) ? 0 : 1,
		];
	}

	if ($local !== null)
	{
		$nativeMetadata = $local['descriptor'];
		$component = $local['configuration']['entity']['component'] ?? $local['configuration']['component'] ?? null;
		$nativeMetadata['required_extensions'] = $component === null ? [] : [$component];
		$verification = [];

		if ($local['handler'] === 'native.core-entity' && $local['configuration']['operation'] !== 'get' && $local['configuration']['operation'] !== 'list')
		{
			$entity = $local['configuration']['entity'];
			$verification = ['read_action' => $entity['id'] . '.get', 'operation' => $local['configuration']['operation'],
				'primary_key' => $entity['primaryKey'], 'state_field' => $entity['stateField']];
		}

		unset($nativeMetadata['inputSchema'], $nativeMetadata['outputSchema']);
		$rows['binding'][] = [
			'id' => count($rows['binding']) + 1, 'provider_id' => 1, 'action_id' => $id,
			'name' => $name . '.cli', 'title' => $name . ' (CLI)', 'track' => 'cli', 'handler' => $local['handler'],
			'input_schema_id' => $addSchema($local['descriptor']['inputSchema']),
			'output_schema_id' => $addSchema($local['descriptor']['outputSchema']),
			'configuration' => $json((object) $local['configuration']), 'definition' => $json($nativeMetadata),
			'params' => $json(['verification' => (object) $verification]),
		];
	}

	$parity[] = $mapped;
}

// This is the one-time migration mapping, not a runtime tool-name switch.
$toolHandlers = [
	'joomla_sites_list' => ['site.list', []],
	'joomla_capabilities' => ['catalog.capabilities', []],
	'joomla_actions_search' => ['catalog.search', []],
	'joomla_action_describe' => ['catalog.describe', []],
	'joomla_action_read' => ['action.read', []],
	'joomla_permission_request' => ['permission.request', []],
	'joomla_permission_approve' => ['permission.approve', []],
	'joomla_permissions_list' => ['permission.list', []],
	'joomla_permission_revoke' => ['permission.revoke', []],
	'joomla_action_write_plan' => ['action.plan', []],
	'joomla_content_articles_list' => ['action.read', ['action' => 'content.articles.list', 'input_from' => 'arguments', 'tracks' => ['api'], 'query_map' => ['search' => 'filter[search]', 'state' => 'filter[state]', 'featured' => 'filter[featured]', 'category' => 'filter[category]', 'tag' => 'filter[tag]', 'language' => 'filter[language]', 'ordering' => 'list[ordering]', 'direction' => 'list[direction]']]],
	'joomla_content_article_get' => ['action.read', ['action' => 'content.articles.get', 'input_from' => 'arguments']],
	'joomla_extensions_list' => ['action.read', ['action' => 'extensions.installed.list', 'input_from' => 'arguments', 'tracks' => ['api'], 'query_map' => ['core' => 'filter[core]', 'status' => 'filter[status]', 'type' => 'filter[type]']]],
	'joomla_application_config_get_safe' => ['action.safe_configuration', []],
	'joomla_cli_commands_list' => ['console.list', ['tracks' => ['cli']]],
	'joomla_cli_command_help' => ['console.help', ['tracks' => ['cli']]],
	'joomla_cli_targets' => ['console.targets', ['tracks' => ['cli']]],
	'joomla_companion_capabilities' => ['console.capabilities', ['tracks' => ['cli']]],
	'joomla_cli_inventory' => ['console.inventory', ['tracks' => ['cli']]],
	'joomla_companion_action_read' => ['action.read', ['tracks' => ['cli'], 'transport' => 'cli']],
	'joomla_content_article_create_plan' => ['action.plan', ['action' => 'content.articles.create', 'input_from' => 'arguments']],
	'joomla_content_article_update_plan' => ['action.plan', ['action' => 'content.articles.update', 'input_from' => 'arguments']],
	'joomla_content_article_delete_plan' => ['action.plan', ['action' => 'content.articles.delete', 'input_from' => 'arguments']],
	'joomla_write_apply' => ['action.apply', []],
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
