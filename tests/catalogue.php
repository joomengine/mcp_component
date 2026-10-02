<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\NativeFactory;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\NativeOperationsInterface;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;


$root = dirname(__DIR__);
require $root . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	$checks++;

	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};
$rejects = static function (callable $operation, string $code) use ($check): void
{
	try
	{
		$operation();
		throw new RuntimeException('Expected operation rejection: ' . $code);
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $code, 'Unexpected operation rejection: ' . $error->getIdentifier());
	}
};
$seed = Json::decode(file_get_contents($root . '/admin/data/catalogue-seed.json'), maximum: 16777216);
$source = Json::decode(file_get_contents($root . '/data/upstream-contracts.json'), maximum: 16777216);
$native = Json::decode(file_get_contents($root . '/data/upstream-native.json'), maximum: 16777216);
$runtime = Json::decode(file_get_contents($root . '/data/runtime-tools.json'), maximum: 16777216);
$entities = $seed['entities'];
$schemas = new SchemaValidator();

foreach ($entities as $entity => $rows)
{
	$ids = [];
	$names = [];
	$columns = Structure::columns($entity);

	foreach ($rows as $row)
	{
		$check(!isset($ids[$row['id']]) && !isset($names[$row['name']]), 'Duplicate stable seed key.');
		$ids[$row['id']] = true;
		$names[$row['name']] = true;
		$check(array_diff_key($row, $columns) === [], 'Unknown generated database column.');
		$check(in_array($row['seed_revision'], [$seed['source'], $seed['runtimeSource']], true), 'A seeded definition has no declared provenance.');

		foreach ($columns as $field => $type)
		{
			if ((str_starts_with($type, 'ref:') || str_starts_with($type, 'optional:')) && $row[$field] !== null)
			{
				$parent = substr($type, (int) strpos($type, ':') + 1);
				$check(in_array($row[$field], array_column($entities[$parent], 'id'), true), 'Dangling seed relationship.');
			}
		}

		if ($entity === 'schema')
		{
			$schemas->document($row['document']);
		}
	}
}

$expectedTools = array_merge(array_column($source['tools'], 'name'), array_column($runtime['tools'], 'name'));
$check(count($expectedTools) === count(array_unique($expectedTools)), 'Runtime declarations overlap the immutable upstream tool catalogue.');
$actualTools = array_column($entities['tool'], 'name');
sort($expectedTools);
sort($actualTools);
$check($expectedTools === $actualTools, 'The exact upstream plus component-runtime tool union was not preserved.');
$actualToolRows = array_column($entities['tool'], null, 'name');
foreach ($runtime['tools'] as $tool)
{
	$check(in_array($tool['handler'], ToolDispatcher::keys(), true), 'A runtime tool has no registered dispatcher.');
	$check($actualToolRows[$tool['name']]['handler'] === $tool['handler'], 'A runtime tool lost its declared handler.');
	$check($actualToolRows[$tool['name']]['seed_revision'] === hash_file('sha256', $root . '/data/runtime-tools.json'), 'Runtime tool provenance differs from its declaration.');
}

// Explicit input extensions upgrade shipped tools but preserve administrator
// references to the original schema, including deliberately narrower scopes.
foreach ($runtime['inputSchemaOverrides'] ?? [] as $override)
{
	$sourceTool = array_column($source['tools'], null, 'name')[$override['name']];
	$legacySchema = null;
	foreach ($entities['schema'] as $schema)
	{
		if (Json::canonical(Json::decode($schema['document'])) === Json::canonical($sourceTool['inputSchema']))
		{
			$legacySchema = $schema;
			break;
		}
	}
	$check($legacySchema !== null, 'An explicit schema extension removed the original administrator-referenced schema.');
	$legacySeed = $seed;
	foreach ($legacySeed['entities']['tool'] as &$tool)
	{
		if ($tool['name'] === $override['name'])
		{
			$tool['input_schema_id'] = $legacySchema['id'];
			$tool['seed_revision'] = $seed['source'];
		}
	}
	unset($tool);
	foreach ([false, true] as $customized)
	{
		$upgradeStore = new MemoryStore();
		$upgrade = new SeedUpdater($upgradeStore);
		$upgrade->apply($legacySeed);
		$before = $upgradeStore->one('tool', ['name' => $override['name']]);
		if ($customized)
		{
			$upgradeStore->update('tool', ['customized' => 1, 'title' => 'Operator-owned permission policy'], ['id' => $before['id']]);
		}
		$upgrade->apply($seed);
		$after = $upgradeStore->one('tool', ['id' => $before['id']]);
		$check(($after['input_schema_id'] === $before['input_schema_id']) === $customized
			&& (int) $upgradeStore->one('schema', ['id' => $after['input_schema_id']])['published'] === 1,
			'Schema extension upgrades untouched tools and preserves customized permission policy.');
	}
}

foreach (['mysql', 'postgresql'] as $driver)
{
	$install = file_get_contents($root . '/admin/sql/install.' . $driver . '.utf8.sql');
	$upgrade = file_get_contents($root . '/admin/sql/updates/' . $driver . '/0.1.1.sql');
	foreach (['job', 'artifact'] as $entity)
	{
		$quote = $driver === 'mysql' ? '`' : '"';
		$pattern = '/CREATE TABLE IF NOT EXISTS ' . preg_quote($quote . Structure::table($entity) . $quote, '/') . '.*?;/s';
		$check(preg_match($pattern, $install, $fresh) === 1 && preg_match($pattern, $upgrade, $migrated) === 1
			&& $fresh[0] === $migrated[0], 'Fresh and upgraded ' . $entity . ' tables differ on ' . $driver . '.');
	}
}
$expectedActions = array_values(array_unique(array_merge(
	array_column($source['catalog']['api']['readActions'], 'id'),
	array_column($source['catalog']['api']['writeActions'], 'id'),
	array_map(static fn (array $row): string => $row['descriptor']['name'], $native['actions'])
)));
$actualActions = array_column($entities['action'], 'name');
sort($expectedActions);
sort($actualActions);
$check($expectedActions === $actualActions, 'A source API or native action was lost.');

// A fresh install and repeated upgrades retain the declared source for each row.
$installed = new MemoryStore();
$updater = new SeedUpdater($installed);
$updater->apply($seed);
$runtimeName = $runtime['tools'][0]['name'];
$check($installed->one('tool', ['name' => $runtimeName])['seed_revision'] === $seed['runtimeSource'], 'Installer discarded component-runtime provenance.');
$check($updater->apply($seed)['updated'] === 0, 'An unchanged seeded graph must be idempotent.');
$nextSeed = $seed;
$nextSeed['runtimeSource'] = str_repeat('a', 64);
foreach ($nextSeed['entities'] as &$records)
{
	foreach ($records as &$record)
	{
		if ($record['seed_revision'] === $seed['runtimeSource'])
		{
			$record['seed_revision'] = $nextSeed['runtimeSource'];
		}
	}
	unset($record);
}
unset($records);
$check($updater->apply($nextSeed)['updated'] > 0
	&& $installed->one('tool', ['name' => $runtimeName])['seed_revision'] === $nextSeed['runtimeSource'], 'Runtime-only upgrades must advance their declared revision.');
$invalidSeed = $nextSeed;
$invalidSeed['entities']['tool'][0]['seed_revision'] = str_repeat('b', 64);
try
{
	$updater->apply($invalidSeed);
	$check(false, 'Undeclared seed provenance was accepted.');
}
catch (RuntimeException $error)
{
	$check($error->getMessage() === 'A shipped definition references undeclared provenance.', 'Unexpected provenance failure.');
}
$check($updater->apply($nextSeed)['updated'] === 0, 'Rejected provenance altered the installed graph.');

// Execution-track schema updates preserve customized bindings and original
// imported schemas, while untouched installed bindings adopt the new contract.
$sourceApi = array_column(array_merge($source['catalog']['api']['readActions'], $source['catalog']['api']['writeActions']), null, 'id');
foreach ($runtime['bindingInputSchemaOverrides'] ?? [] as $override)
{
	$actionName = substr($override['name'], 0, -4);
	$legacySchema = null;
	foreach ($entities['schema'] as $schema)
	{
		if (Json::canonical(Json::decode($schema['document'])) === Json::canonical($sourceApi[$actionName]['inputSchema']))
		{
			$legacySchema = $schema;
			break;
		}
	}
	$check($legacySchema !== null, 'A binding extension removed an administrator-referenced original schema.');
	$legacySeed = $seed;
	foreach ($legacySeed['entities']['binding'] as &$binding)
	{
		if ($binding['name'] === $override['name'])
		{
			$binding['input_schema_id'] = $legacySchema['id'];
			$binding['seed_revision'] = $seed['source'];
		}
	}
	unset($binding);
	foreach ([false, true] as $customized)
	{
		$upgradeStore = new MemoryStore();
		$upgrade = new SeedUpdater($upgradeStore);
		$upgrade->apply($legacySeed);
		$before = $upgradeStore->one('binding', ['name' => $override['name']]);
		if ($customized)
		{
			$upgradeStore->update('binding', ['customized' => 1], ['id' => $before['id']]);
		}
		$upgrade->apply($seed);
		$after = $upgradeStore->one('binding', ['name' => $override['name']]);
		$check($customized ? $after['input_schema_id'] === $before['input_schema_id']
			: $after['input_schema_id'] !== $before['input_schema_id'] && $after['seed_revision'] === $seed['runtimeSource'],
			'Binding schema upgrade did not respect administrator ownership.');
		$check($upgrade->apply($seed)['updated'] === 0, 'Binding schema upgrade is not idempotent.');
	}
}

// Runtime metadata and snapshot declarations carry their reason and provenance
// without changing the native public read/write interface or original gates.
$parity = Json::decode(file_get_contents($root . '/docs/migration/parity.json'), maximum: 16777216);
$actionRows = array_column($entities['action'], null, 'name');
$actionNames = array_column($entities['action'], 'name', 'id');
$bindingRows = array_column($entities['binding'], null, 'name');
$check($parity['runtimeSource'] === hash_file('sha256', $root . '/data/runtime-tools.json'), 'Parity lost the runtime declaration revision.');
$check($parity['sourceOnlyGates'] === $source['catalog']['api']['sourceOnlyBlockedActions']
	&& count($parity['sourceOnlyGates']) === 5, 'Runtime extensions changed the five original source gates.');

foreach ($entities['binding'] as $binding)
{
	$actionName = $actionNames[$binding['action_id']];
	$check((int) $binding['published'] === ($binding['track'] === 'api' && isset($parity['sourceOnlyGates'][$actionName]) ? 0 : 1),
		'Runtime extensions changed shipped binding callability.');
}

foreach ($runtime['actionMetadataOverrides'] ?? [] as $override)
{
	$row = $actionRows[$override['name']];
	$original = $sourceApi[$override['name']];
	unset($original['inputSchema'], $original['outputSchema']);
	$original += ['sourceGate' => $parity['sourceOnlyGates'][$override['name']] ?? null];
	$expected = array_replace($original, $override['metadata']);
	$check(Json::canonical(Json::decode($row['definition'])) === Json::canonical($expected)
		&& $row['description'] === $expected['description'], 'A metadata correction changed undeclared native action metadata.');
	$check($row['seed_revision'] === $seed['runtimeSource']
		&& $parity['runtimeActionMetadataOverrides'][$override['name']] === $override['reason'], 'Action metadata extension lost its source revision or reason.');
	$check($row['effect'] === ($original['method'] === 'GET' ? 'read' : 'write')
		&& $row['risk'] === $original['risk'] && $row['toolset'] === $original['toolset'], 'Metadata correction changed the public action authority contract.');
	$legacySeed = $seed;
	foreach ($legacySeed['entities']['action'] as &$action)
	{
		if ($action['name'] === $override['name'])
		{
			$action['description'] = $original['description'];
			$action['definition'] = Json::encode($original);
			$action['seed_revision'] = $seed['source'];
		}
	}
	unset($action);
	foreach (['untouched', 'explicit', 'implicit'] as $ownership)
	{
		$upgradeStore = new MemoryStore();
		$upgrade = new SeedUpdater($upgradeStore);
		$upgrade->apply($legacySeed);
		$before = $upgradeStore->one('action', ['name' => $override['name']]);
		if ($ownership !== 'untouched')
		{
			$upgradeStore->update('action', ['customized' => $ownership === 'explicit' ? 1 : 0,
				'description' => 'Operator-owned action description'], ['id' => $before['id']]);
			$before = $upgradeStore->one('action', ['name' => $override['name']]);
		}
		$upgrade->apply($seed);
		$after = $upgradeStore->one('action', ['name' => $override['name']]);
		$check($ownership === 'untouched' ? $after['definition'] === $row['definition']
			&& $after['description'] === $row['description'] && $after['seed_revision'] === $seed['runtimeSource'] : $after === $before,
			'Action metadata upgrade did not respect explicit or hash-detected administrator ownership.');
		$check($upgrade->apply($seed)['updated'] === 0, 'Action metadata upgrade is not idempotent.');
	}
}

$snapshotBindings = [];
foreach ($runtime['bindingConfigurationOverrides'] ?? [] as $override)
{
	$row = $bindingRows[$override['name']];
	$configuration = Json::decode($row['configuration']);
	$configurationObjects = json_decode($row['configuration'], false, 128, JSON_THROW_ON_ERROR);
	$check(array_intersect_key($configuration, $override['configuration']) === $override['configuration'], 'A binding lost its declared snapshot contract.');
	$check($configurationObjects->query_defaults instanceof stdClass, 'Binding contract extension collapsed an unchanged JSON object into a list.');
	$check($row['seed_revision'] === $seed['runtimeSource']
		&& $parity['runtimeBindingConfigurationOverrides'][$override['name']] === $override['reason'], 'Binding configuration extension lost its source revision or reason.');
	$legacySeed = $seed;
	foreach ($legacySeed['entities']['binding'] as &$binding)
	{
		if ($binding['name'] === $override['name'])
		{
			$binding['configuration'] = Json::encode(array_diff_key($configuration, $override['configuration']));
			$binding['seed_revision'] = $seed['source'];
		}
	}
	unset($binding);
	foreach (['untouched', 'explicit', 'implicit'] as $ownership)
	{
		$upgradeStore = new MemoryStore();
		$upgrade = new SeedUpdater($upgradeStore);
		$upgrade->apply($legacySeed);
		$before = $upgradeStore->one('binding', ['name' => $override['name']]);
		if ($ownership !== 'untouched')
		{
			$customConfiguration = Json::decode($before['configuration']);
			$customConfiguration['route'] = '/v1/custom-messages/:id';
			$upgradeStore->update('binding', ['customized' => $ownership === 'explicit' ? 1 : 0,
				'configuration' => Json::encode($customConfiguration)], ['id' => $before['id']]);
			$before = $upgradeStore->one('binding', ['name' => $override['name']]);
		}
		$upgrade->apply($seed);
		$after = $upgradeStore->one('binding', ['name' => $override['name']]);
		$check($ownership === 'untouched' ? $after['configuration'] === $row['configuration']
			&& $after['seed_revision'] === $seed['runtimeSource'] : $after === $before,
			'Snapshot contract upgrade did not respect explicit or hash-detected administrator ownership.');
		$check($upgrade->apply($seed)['updated'] === 0, 'Snapshot contract upgrade is not idempotent.');
	}
}

foreach ($entities['binding'] as $binding)
{
	$configuration = Json::decode($binding['configuration']);
	if (isset($configuration['snapshot_contract']))
	{
		$snapshotBindings[] = $binding['name'];
		$check($configuration['snapshot_contract'] === 'joomla.message-owned-record.v1'
			&& $binding['track'] === 'api' && $binding['handler'] === 'api.request', 'Unexpected native message snapshot marker.');
	}
}
sort($snapshotBindings);
$check($snapshotBindings === ['messages.messages.delete.api', 'messages.messages.get.api', 'messages.messages.update.api'],
	'The native-owned-record snapshot must be explicit on both message write bindings and their get binding only.');
$messageMetadata = Json::decode($actionRows['messages.messages.get']['definition']);
$check($messageMetadata['sideEffect'] === true && $actionRows['messages.messages.get']['effect'] === 'read'
	&& $sourceApi['messages.messages.get']['sideEffect'] === false, 'Message GET side-effect correction must preserve the immutable source and public read interface.');

// Exercise declaration rejection in an isolated source tree so invalid metadata
// cannot broaden execution contracts or overwrite the working installation seed.
$generatorRoot = sys_get_temp_dir() . '/mcp-catalogue-' . bin2hex(random_bytes(8));
$generatorFiles = ['tools/generate-catalogue.php', 'admin/src/Database/Structure.php',
	'data/upstream-contracts.json', 'data/upstream-native.json'];
$invalidDeclarations = [
	['actionMetadataOverrides', [['name' => 'missing.action']], 'Action metadata extensions'],
	['actionMetadataOverrides', [['reason' => '']], 'Action metadata extensions'],
	['actionMetadataOverrides', [['metadata' => ['effect' => 'write']]], 'Action metadata extensions'],
	['actionMetadataOverrides', [['metadata' => ['sideEffect' => 'true']]], 'Action metadata extensions'],
	['actionMetadataOverrides', [['description' => 'Undeclared top-level change']], 'Action metadata extensions'],
	['actionMetadataOverrides', [[], []], 'Action metadata extensions'],
	['bindingConfigurationOverrides', [['name' => 'missing.binding']], 'Binding configuration extensions'],
	['bindingConfigurationOverrides', [['reason' => '']], 'Binding configuration extensions'],
	['bindingConfigurationOverrides', [['configuration' => ['route' => '/v1/custom']]], 'Binding configuration extensions'],
	['bindingConfigurationOverrides', [['configuration' => ['snapshot_contract' => '../unreviewed']]], 'Binding configuration extensions'],
	['bindingConfigurationOverrides', [['configuration' => ['snapshot_contract' => ['nested']]]], 'Binding configuration extensions'],
	['bindingConfigurationOverrides', [['handler' => 'unreviewed.handler']], 'Binding configuration extensions'],
	['bindingConfigurationOverrides', [[], []], 'Binding configuration extensions'],
];
try
{
	foreach ($generatorFiles as $file)
	{
		if (!is_dir(dirname($generatorRoot . '/' . $file)))
		{
			mkdir(dirname($generatorRoot . '/' . $file), 0700, true);
		}
		copy($root . '/' . $file, $generatorRoot . '/' . $file);
	}
	foreach ($invalidDeclarations as [$collection, $changes, $expectedError])
	{
		$invalidRuntime = json_decode(file_get_contents($root . '/data/runtime-tools.json'), false, 128, JSON_THROW_ON_ERROR);
		$declaration = (array) $invalidRuntime->{$collection}[0];
		$invalidRuntime->{$collection} = array_map(static fn (array $change): object => (object) array_replace($declaration, $change), $changes);
		file_put_contents($generatorRoot . '/data/runtime-tools.json', Json::encode($invalidRuntime));
		$process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $generatorRoot . '/tools/generate-catalogue.php'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $generatorRoot);
		$check(is_resource($process), 'Could not start the isolated catalogue declaration check.');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$check(proc_close($process) !== 0 && str_contains($output, $expectedError), 'Invalid runtime declaration was not rejected by its bounded contract.');
	}
}
finally
{
	foreach (array_merge($generatorFiles, ['data/runtime-tools.json']) as $file)
	{
		if (is_file($generatorRoot . '/' . $file))
		{
			unlink($generatorRoot . '/' . $file);
		}
	}
	foreach (['tools', 'admin/src/Database', 'admin/src', 'admin', 'data', ''] as $directory)
	{
		if (is_dir($generatorRoot . '/' . $directory))
		{
			rmdir($generatorRoot . '/' . $directory);
		}
	}
}

$store = new MemoryStore($entities);
$principal = new Principal();
$settings = new Settings(['joomla_version' => '6.1.3']);
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
	static fn (string $extension): bool => in_array($extension, ['com_joomengine_mcp', 'com_content', 'webservices/content'], true),
	static fn (string $entity, string $handler): bool => $entity !== 'binding' || $handler === 'api.request');
$article = $catalogue->get('action', 'content.articles.get');
$check($catalogue->action('content.articles.get')['binding']['track'] === 'api', 'Wrong authority track selected.');
$rejects(static fn () => $catalogue->action('content.articles.get', 'cli'), 'TRANSPORT_UNAVAILABLE');
$store->update('action', ['access' => 9], ['id' => $article['id']]);
$catalogue->refresh();
$rejects(static fn () => $catalogue->get('action', 'content.articles.get'), 'DEFINITION_UNAVAILABLE');
$check(!in_array('joomla_content_article_get', array_column($catalogue->all('tool'), 'name'), true), 'A fixed tool leaked a hidden action.');
$store->update('action', ['access' => 1], ['id' => $article['id']]);
$catalogue->refresh();
$binding = $catalogue->action('content.articles.get')['binding'];
$store->update('schema', ['published' => 0], ['id' => $binding['input_schema_id']]);
$catalogue->refresh();
$rejects(static fn () => $catalogue->get('action', 'content.articles.get'), 'DEFINITION_UNAVAILABLE');
$store->update('schema', ['published' => 1], ['id' => $binding['input_schema_id']]);
$catalogue->refresh();
$principal->deny('mcp.execute', 'com_joomengine_mcp.provider.1');
$check($catalogue->all('action') === [], 'Provider asset denial did not propagate.');

$valid = $schemas->input([], '{"type":"object","properties":{"limit":{"type":"integer","default":20}},"additionalProperties":false}');
$check($valid === ['limit' => 20], 'Schema defaults were not applied deterministically.');
$rejects(static fn () => $schemas->input(['id' => '2'], '{"type":"object","properties":{"id":{"type":"integer"}}}'), 'INVALID_INPUT');
$rejects(static fn () => $schemas->document('{"type":"object","$ref":"https://example.invalid/schema"}'), 'INVALID_SCHEMA');
$rejects(static fn () => $schemas->document('{"type":"object","$filters":[{"func":"php"}]}'), 'INVALID_SCHEMA');
$rejects(static fn () => $schemas->input(['constructor' => 'x'], '{"type":"object"}'), 'INVALID_INPUT');
$check(Json::canonical(['b' => 2, 'a' => 1]) === Json::canonical(['a' => 1, 'b' => 2]), 'Map fingerprints depend on insertion order.');
$check(Json::canonical([]) !== Json::canonical(new stdClass()), 'Canonicalization collapsed arrays and objects.');
$envelope = new Envelope(str_repeat('installation-secret-', 3));
$cipher = $envelope->encrypt('sensitive input', 'plan:principal-a:record-a');
$check(!str_contains($cipher, 'sensitive input') && $envelope->decrypt($cipher, 'plan:principal-a:record-a') === 'sensitive input', 'Authenticated encryption round trip failed.');
$rejects(static fn () => $envelope->decrypt($cipher, 'plan:principal-b:record-a'), 'STATE_UNAVAILABLE');
$rejects(static fn () => $envelope->decrypt(substr($cipher, 0, -4) . 'AAAA', 'plan:principal-a:record-a'), 'STATE_UNAVAILABLE');

/** Native constructor parity uses real imported handlers, not execution mocks. */
$models = new class implements ModelProviderInterface
{
	/** @inheritDoc */
	public function administrator(string $component, string $modelName): object
	{
		throw new RuntimeException('Descriptor construction must not execute Joomla.');
	}
};
$operations = new class implements NativeOperationsInterface
{
	/** @inheritDoc */
	public function siteOfflineState(): bool
	{
		throw new RuntimeException('No native operation is permitted in this constructor test.');
	}

	/** @inheritDoc */
	public function setSiteOffline(bool $offline): int
	{
		throw new RuntimeException('No native operation is permitted in this constructor test.');
	}

	/** @inheritDoc */
	public function garbageCollectSessions(string $application): int
	{
		throw new RuntimeException('No native operation is permitted in this constructor test.');
	}

	/** @inheritDoc */
	public function garbageCollectSessionMetadata(): int
	{
		throw new RuntimeException('No native operation is permitted in this constructor test.');
	}

	/** @inheritDoc */
	public function setSchedulerTaskState(int $id, int $state): int
	{
		throw new RuntimeException('No native operation is permitted in this constructor test.');
	}

	/** @inheritDoc */
	public function runSchedulerTask(int $id): int
	{
		throw new RuntimeException('No native operation is permitted in this constructor test.');
	}
};
$factory = new NativeFactory($models, $operations, new stdClass());

foreach ($native['actions'] as $row)
{
	$descriptor = $factory->create(['handler' => $row['handler'], 'configuration' => $row['configuration']])->descriptor()->jsonSerialize();
	$expected = $row['descriptor'];

	// The imported PHP companion emitted [] for a known JSON Schema map.
	// Correct only that representation; list keywords/defaults remain unchanged.
	foreach (['inputSchema', 'outputSchema'] as $field)
	{
		if (($expected[$field]['properties'] ?? null) === [])
		{
			$expected[$field]['properties'] = (object) [];
		}
	}

	$check(Json::canonical($descriptor) === Json::canonical($expected), 'A database-constructed native descriptor differs from its immutable source, except corrected empty schema maps.');
}

echo Json::encode(['checks' => $checks, 'sourceParity' => 'all inventoried tools/actions and native constructor contracts', 'cataloguePolicy' => 'passed', 'encryption' => 'passed', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;

require __DIR__ . '/schema-upgrade.php';
