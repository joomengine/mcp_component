<?php
/**
 * @package    JoomEngine.Mcp
 * @created    12 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Handler\NativeFactory;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\CoreEntityDefinition;
use VDM\Component\JoomEngineMcp\Administrator\Service\LanguageAssociations;
use VDM\Component\JoomEngineMcp\Administrator\Service\NativeMenuAssociations;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaNativeOperations;

require __DIR__ . '/fixtures/native-menu-database.php';
require dirname(__DIR__) . '/admin/autoload.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
};
$seed = json_decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'), true, 128, JSON_THROW_ON_ERROR);
$binding = array_values(array_filter($seed['entities']['binding'], static fn (array $row): bool => $row['name'] === 'menus.site-items.update.cli'))[0];
$configuration = json_decode($binding['configuration'], true, 128, JSON_THROW_ON_ERROR);
$entity = new CoreEntityDefinition(...$configuration['entity']);
$check(in_array('associations', $entity->writeFields, true) && in_array('associations', $entity->readFields, true), 'Seeded native menu contract exposes association input and output.');
$model = new class
{
	/** @var array Actual persisted representation. */
	public array $stored = ['id' => 71, 'title' => 'Original', 'client_id' => 0, 'language' => 'en-GB', 'associations' => ['fr-FR' => 72]];
	/** @var array Joomla helper snapshot intentionally remains stale for the process. */
	public array $cached = ['fr-FR' => 72];
	/** @var int Actual save count. */
	public int $writes = 0;
	/** @var bool Whether native multilingual association support is enabled. */
	public bool $enabled = true;
	/** @return object Joomla's DatabaseAwareTrait intentionally exposes only a protected accessor. */
	protected function getDatabase(): object
	{
		throw new RuntimeException('A handler must not access the native model protected database method.');
	}
	/** @inheritDoc */
	public function setState(string $name, mixed $value): void
	{
	}
	/** @return object Native item carrying an intentionally stale association helper result. */
	public function getItem(int $id): object
	{
		$item = $this->stored;
		if ($this->enabled)
		{
			$item['associations'] = $this->cached;
		}
		else
		{
			unset($item['associations']);
		}
		return (object) $item;
	}
	/** @return bool Persist with the same omission-clears semantics as Joomla's menu model. */
	public function save(array $data): bool
	{
		$this->writes++;
		$this->stored = array_replace($this->stored, $data);
		if ($this->enabled)
		{
			$this->stored['associations'] = $data['associations'] ?? [];
		}
		return true;
	}
	/** @return int Saved item identifier. */
	public function getState(string $name, int $default = 0): int
	{
		return 71;
	}
};
$provider = new class($model) implements ModelProviderInterface
{
	/** @var object Fixed recording model. */
	private object $model;
	/** @param object $model Fixed recording model. */
	public function __construct(object $model)
	{
		$this->model = $model;
	}
	/** @inheritDoc */
	public function administrator(string $component, string $modelName): object
	{
		return $this->model;
	}
};
$reads = 0;
$reader = static function (object $actualModel, int $id) use ($model, $check, &$reads): array
{
	$check($actualModel === $model && $id === 71, 'Fresh association reads retain native model and item identity.');
	$reads++;
	return $model->stored['associations'];
};
$action = new CoreEntityAction($entity, 'update', $provider, $reader);
$check($action->descriptor()->inputSchema['properties']['data']['properties']['associations'] === LanguageAssociations::schema(), 'Native discovery carries the bounded object schema.');
foreach ([
	['title' => 'Preserved title'],
	['associations' => (object) ['de-DE' => 73]],
	['title' => 'Preserve replacement'],
	['associations' => (object) []],
	['title' => 'Preserve explicit clear'],
] as $data)
{
	$before = $model->stored['associations'];
	$preview = $action->execute(['id' => 71, 'data' => $data]);
	$check($preview['dryRun'] && !$preview['applied'] && $model->stored['associations'] === $before, 'Native preview never mutates association membership.');
	$result = $action->execute(['id' => 71, 'data' => $data, 'dryRun' => false, '_edgeConfirmed' => true]);
	$expected = array_key_exists('associations', $data) ? (array) $data['associations'] : $before;
	$check($model->stored['associations'] === $expected, 'Native update preserves omission and exactly applies replacements or explicit clears.');
	$check($result['item']['associations'] === $expected, 'Native saved representation replaces stale Joomla helper membership.');
	$get = (new CoreEntityAction($entity, 'get', $provider, $reader))->execute(['id' => 71]);
	$check($get['item']['associations'] === $expected, 'Independent native item read remains fresh in the same persistent process.');
}
$create = new CoreEntityAction($entity, 'create', $provider, $reader);
$created = $create->execute(['data' => ['title' => 'Associated create', 'associations' => (object) ['fr-FR' => 72]], 'dryRun' => false, '_edgeConfirmed' => true]);
$check($created['item']['associations'] === ['fr-FR' => 72], 'Native create passes the language map to the model and freshly reads it back.');
$writes = $model->writes;
foreach ([null, true, [72], (object) ['*' => 72], (object) ['fr-FR' => 0], (object) ['fr-FR' => '72'], (object) ['fr-FR' => 1.5]] as $invalid)
{
	try
	{
		$action->execute(['id' => 71, 'data' => ['associations' => $invalid], 'dryRun' => false, '_edgeConfirmed' => true]);
		throw new RuntimeException('Invalid association input was accepted.');
	}
	catch (ActionException $error)
	{
		$check($error->errorCode === 'INVALID_INPUT', 'Malformed association input fails before native save.');
	}
}
$check($model->writes === $writes, 'Rejected maps cause no native mutations.');
$failed = new CoreEntityAction($entity, 'update', $provider, static function (): never
{
	throw new ActionException('ASSOCIATION_READ_FAILED', 'Controlled unavailable membership read.');
});
try
{
	$failed->execute(['id' => 71, 'data' => ['title' => 'Must not persist'], 'dryRun' => false, '_edgeConfirmed' => true]);
	throw new RuntimeException('Unreadable current membership was silently discarded.');
}
catch (ActionException $error)
{
	$check($error->errorCode === 'ASSOCIATION_READ_FAILED' && $model->writes === $writes, 'Unavailable current membership blocks destructive fallback to stale or empty data.');
}
$model->enabled = false;
$readsBeforeDisabled = $reads;
$action->execute(['id' => 71, 'data' => ['title' => 'Single-language edit'], 'dryRun' => false, '_edgeConfirmed' => true]);
$check($reads === $readsBeforeDisabled && $model->stored['title'] === 'Single-language edit', 'Disabled native association support does not block unrelated menu edits.');

$database = new class implements \Joomla\Database\DatabaseInterface
{
	/** @var array Controlled native association rows. */
	public array $rows = [['language' => 'de-DE', 'id' => '73'], ['language' => 'fr-FR', 'id' => 72]];
	/** @var ?object Recorded fixed query. */
	public ?object $query = null;
	/** @var array Recorded query bounds. */
	public array $bounds = [];
	/** @var bool Simulated driver failure. */
	public bool $fail = false;
	/** @inheritDoc */
	public function createQuery(): object
	{
		return new class
		{
			/** @var array Recorded fixed query construction. */
			public array $parts = [];
			/** @return self Record only fluent query-building calls. */
			public function __call(string $method, array $arguments): self
			{
				$this->parts[$method][] = $arguments;
				return $this;
			}
		};
	}
	/** @inheritDoc */
	public function quoteName(array|string $name, ?string $alias = null): array|string
	{
		if (is_array($name))
		{
			return array_map(fn (string $value): string => $this->quoteName($value), $name);
		}
		return '`' . str_replace('.', '`.`', $name) . '`' . ($alias === null ? '' : ' AS `' . $alias . '`');
	}
	/** @inheritDoc */
	public function quote(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'";
	}
	/** @inheritDoc */
	public function setQuery(object $query, int $offset = 0, int $limit = 0): \Joomla\Database\DatabaseInterface
	{
		$this->query = $query;
		$this->bounds = [$offset, $limit];
		return $this;
	}
	/** @inheritDoc */
	public function loadAssocList(): array
	{
		if ($this->fail)
		{
			throw new RuntimeException('private-driver-diagnostic');
		}
		return $this->rows;
	}
};
$model->enabled = true;
$databaseReader = new NativeMenuAssociations($database);
$factory = new NativeFactory($provider, new JoomlaNativeOperations(new stdClass()), new stdClass(), $databaseReader);
$configuration['operation'] = 'get';
$nativeGet = $factory->create(['handler' => 'native.core-entity', 'configuration' => $configuration]);
$record = $nativeGet->execute(['id' => 71])['item'];
$check($record['associations'] === ['de-DE' => 73, 'fr-FR' => 72], 'Composed native factory reads the injected database without accessing a protected model method.');
$check($database->bounds === [0, 101], 'Native storage inspection uses an explicit bounded query.');
$clauses = array_column($database->query->parts['where'], 0);
$check(in_array("`a`.`context` = 'com_menus.item'", $clauses, true) && in_array('`a`.`id` = 71', $clauses, true)
	&& in_array('`m`.`id` <> 71', $clauses, true) && in_array('`m`.`client_id` = 0', $clauses, true), 'Fixed query retains association context, source identity, self exclusion and site-only scope.');
$database->rows = [];
$check($nativeGet->execute(['id' => 71])['item']['associations'] === [], 'Composed native reads observe an explicit clear despite a stale native helper snapshot.');
foreach ([[['language' => '*', 'id' => 72]], [['language' => 'fr-FR', 'id' => 0]],
	[['language' => 'fr-FR', 'id' => 72], ['language' => 'fr-FR', 'id' => 73]], array_fill(0, 101, ['language' => 'fr-FR', 'id' => 72])] as $invalidRows)
{
	$database->rows = $invalidRows;
	try
	{
		$databaseReader->read(71);
		throw new RuntimeException('Malformed native rows were accepted.');
	}
	catch (ActionException $error)
	{
		$check($error->errorCode === 'ASSOCIATION_READ_FAILED', 'Malformed or excessive native association rows fail closed.');
	}
}
$database->fail = true;
try
{
	$databaseReader->read(71);
	throw new RuntimeException('Unavailable native storage was accepted.');
}
catch (ActionException $error)
{
	$check($error->errorCode === 'ASSOCIATION_READ_FAILED' && !str_contains($error->getMessage(), 'private-driver-diagnostic'), 'Database failures expose only the stable association error without driver diagnostics.');
}
echo json_encode(['passed' => $checks, 'suite' => 'native-language-associations'], JSON_THROW_ON_ERROR) . PHP_EOL;
