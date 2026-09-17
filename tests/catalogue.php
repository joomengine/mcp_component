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
require $root . '/vendor/autoload.php';
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
$seed = Json::decode(file_get_contents($root . '/data/catalogue-seed.json'), maximum: 16777216);
$source = Json::decode(file_get_contents($root . '/data/upstream-contracts.json'), maximum: 16777216);
$native = Json::decode(file_get_contents($root . '/data/upstream-native.json'), maximum: 16777216);
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

$expectedTools = array_column($source['tools'], 'name');
$actualTools = array_column($entities['tool'], 'name');
sort($expectedTools);
sort($actualTools);
$check($expectedTools === $actualTools, 'Source MCP tool names were not preserved exactly.');
$expectedActions = array_values(array_unique(array_merge(
	array_column($source['catalog']['api']['readActions'], 'id'),
	array_column($source['catalog']['api']['writeActions'], 'id'),
	array_map(static fn (array $row): string => $row['descriptor']['name'], $native['actions'])
)));
$actualActions = array_column($entities['action'], 'name');
sort($expectedActions);
sort($actualActions);
$check($expectedActions === $actualActions, 'A source API or native action was lost.');

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
	$check($descriptor === $row['descriptor'], 'A database-constructed native descriptor differs from its immutable source.');
}

echo Json::encode(['checks' => $checks, 'sourceParity' => 'all inventoried tools/actions and native constructor contracts', 'cataloguePolicy' => 'passed', 'encryption' => 'passed', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
