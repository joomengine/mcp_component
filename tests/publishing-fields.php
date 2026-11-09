<?php
/**
 * @package    JoomEngine.Mcp
 * @created    9 November 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\CoreEntityDefinition;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;

require dirname(__DIR__) . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'), maximum: 16777216);
$bindings = array_column($seed['entities']['binding'], null, 'name');
$schemas = array_column($seed['entities']['schema'], null, 'id');
$validator = new SchemaValidator();
$builder = new ApiRequestBuilder();
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	$checks++;
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
};
$cases = [
	'content.articles' => ['created' => '2026-11-09 08:30:00', 'created_by' => 42],
	'modules.site' => ['publish_up' => '2026-11-09 08:30:00', 'publish_down' => '2026-11-16 08:30:00'],
	'modules.administrator' => ['publish_up' => '2026-11-09 08:30:00', 'publish_down' => null],
];

foreach ($cases as $base => $data)
{
	$model = new class
	{
		public array $stored = ['id' => 41, 'title' => 'Existing'];
		public array $saved = [];
		public function setState(string $key, mixed $value): void {}
		public function getState(string $key, int $default = 0): int { return 41; }
		public function getItem(int $id): object { return (object) $this->stored; }
		public function save(array $data): bool
		{
			$this->saved = $data;
			$this->stored = ['id' => 41] + $data;
			return true;
		}
	};
	$models = new class ($model) implements ModelProviderInterface
	{
		private object $model;
		public function __construct(object $model) { $this->model = $model; }
		public function administrator(string $component, string $modelName): object { return $this->model; }
	};
	foreach (['create', 'update'] as $operation)
	{
		$input = ['data' => $data + ['title' => 'Scheduled content']];
		if ($operation === 'update') { $input['id'] = 41; }
		foreach (['api', 'cli'] as $track)
		{
			$binding = $bindings[$base . '.' . $operation . '.' . $track];
			$validated = $validator->input($input, $schemas[$binding['input_schema_id']]['document']);
			$config = Json::decode($binding['configuration']);
			$check($validated['data'] === $input['data'], $binding['name'] . ' changed publishing input.');
			$check($binding['seed_revision'] === $seed['runtimeSource'], $binding['name'] . ' lost runtime provenance.');
			if ($track === 'api')
			{
				$request = $builder->build($validated, $config);
				$check(array_intersect_key($request['body'], $data) === $data, $binding['name'] . ' omitted approved publishing data.');
			}
			else
			{
				$action = new CoreEntityAction(new CoreEntityDefinition(...$config['entity']), $operation, $models);
				$preview = $action->execute($validated);
				$check(($preview['dryRun'] ?? false) === true, $binding['name'] . ' must preview by default.');
				$result = $action->execute(array_replace($validated, ['dryRun' => false, '_edgeConfirmed' => true]));
				$check(array_intersect_key($model->saved, $data) === $data, $binding['name'] . ' omitted fields before native save.');
				$check(array_intersect_key($result['item'], $data) === $data, $binding['name'] . ' omitted publishing read-back.');
			}
			foreach (array_keys($data) as $field)
			{
				$bad = $input;
				$bad['data'][$field] = (object) ['invalid' => true];
				try
				{
					$validator->input($bad, $schemas[$binding['input_schema_id']]['document']);
					throw new RuntimeException($binding['name'] . ' accepted an invalid publishing field.');
				}
				catch (OperationException $error)
				{
					$check($error->getIdentifier() === 'INVALID_INPUT', 'Unexpected schema rejection.');
				}
			}
		}
	}
	if (str_starts_with($base, 'modules.'))
	{
		foreach (['list', 'get'] as $operation)
		{
			$config = Json::decode($bindings[$base . '.' . $operation . '.cli']['configuration']);
			$check(array_diff(array_keys($data), $config['entity']['readFields']) === [], $base . ' native read contract omits its dates.');
		}
	}
}

// Simulate an installed catalogue from before the publishing-field extension.
// Keep the original schema rows, just as installation does, so customized
// schemas and bindings remain owned by the administrator after an upgrade.
$legacy = $seed;
$legacy['runtimeSource'] = hash('sha256', 'pre-publishing-fields');
foreach ($legacy['entities'] as &$rows)
{
	foreach ($rows as &$row)
	{
		if ($row['seed_revision'] === $seed['runtimeSource']) { $row['seed_revision'] = $legacy['runtimeSource']; }
	}
	unset($row);
}
unset($rows);
foreach ($legacy['entities']['binding'] as &$binding)
{
	if (preg_match('/\A(content\.articles|modules\.site|modules\.administrator)\.(create|update)\.(api|cli)\z/D', $binding['name'], $parts) !== 1) { continue; }
	$schema = $schemas[$binding['input_schema_id']];
	$document = json_decode($schema['document'], false, 128, JSON_THROW_ON_ERROR);
	foreach (array_keys($cases[$parts[1]]) as $field) { unset($document->properties->data->properties->{$field}); }
	$schema['id'] = count($legacy['entities']['schema']) + 1;
	$schema['name'] = 'schema.' . hash('sha256', Json::canonical($document));
	$schema['document'] = Json::encode($document);
	$schema['seed_revision'] = $legacy['runtimeSource'];
	$legacy['entities']['schema'][] = $schema;
	$binding['input_schema_id'] = $schema['id'];
	if ($parts[3] === 'cli')
	{
		$config = json_decode($binding['configuration'], false, 128, JSON_THROW_ON_ERROR);
		foreach (['readFields', 'writeFields'] as $fields)
		{
			$config->entity->{$fields} = array_values(array_diff($config->entity->{$fields}, array_keys($cases[$parts[1]])));
		}
		$binding['configuration'] = Json::encode($config);
	}
}
unset($binding);
foreach (['managed', 'customized-binding', 'customized-schema'] as $case)
{
	$store = new MemoryStore();
	$updater = new SeedUpdater($store);
	$updater->apply($legacy);
	$before = $store->one('binding', ['name' => 'modules.site.update.api']);
	if ($case === 'customized-binding') { $store->update('binding', ['customized' => 1], ['id' => $before['id']]); }
	if ($case === 'customized-schema') { $store->update('schema', ['customized' => 1], ['id' => $before['input_schema_id']]); }
	$updater->apply($seed);
	$after = $store->one('binding', ['name' => $before['name']]);
	$document = Json::decode($store->one('schema', ['id' => $after['input_schema_id']])['document']);
	$check(isset($document['properties']['data']['properties']['publish_up']) === ($case === 'managed'), 'Publishing upgrade violated ownership for ' . $case . '.');
}
echo Json::encode(['checks' => $checks, 'publishingFields' => 'passed', 'liveJoomla' => 'not implied by recording model contracts']) . PHP_EOL;
