<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/admin/autoload.php';
$root = dirname(__DIR__);
$seed = Json::decode(file_get_contents($root . '/admin/data/catalogue-seed.json'), maximum: 16777216);
// Compare original JSON objects, not an associative decode that could repeat
// the migration's object/list mistake and falsely certify a malformed schema.
$source = json_decode(file_get_contents($root . '/data/upstream-contracts.json'), false, 128, JSON_THROW_ON_ERROR);
$runtime = json_decode(file_get_contents($root . '/data/runtime-tools.json'), false, 128, JSON_THROW_ON_ERROR);
$tools = array_column($seed['entities']['tool'], null, 'name');
$documents = array_column($seed['entities']['schema'], 'document', 'id');
$overrides = array_column($runtime->inputSchemaOverrides ?? [], null, 'name');
$checks = 0;
foreach (array_merge($source->tools, $runtime->tools) as $original)
{
	$stored = json_decode($documents[$tools[$original->name]['input_schema_id']], false, 64, JSON_THROW_ON_ERROR);
	$expected = $overrides[$original->name]->inputSchema ?? $original->inputSchema;
	if (Json::canonical($stored) !== Json::canonical($expected))
	{
		throw new RuntimeException('Migration changed the declared JSON Schema object/default shape for ' . $original->name);
	}
	if (isset($overrides[$original->name]) && $tools[$original->name]['seed_revision'] !== $seed['runtimeSource'])
	{
		throw new RuntimeException('An explicit schema extension lost its component runtime provenance.');
	}
	$checks++;
}
$bindings = array_column($seed['entities']['binding'], null, 'name');
foreach ($runtime->bindingInputSchemaOverrides ?? [] as $override)
{
	$binding = $bindings[$override->name];
	$stored = json_decode($documents[$binding['input_schema_id']], false, 64, JSON_THROW_ON_ERROR);
	if (Json::canonical($stored) !== Json::canonical($override->inputSchema)
		|| $binding['seed_revision'] !== $seed['runtimeSource'])
	{
		throw new RuntimeException('A binding schema extension lost its declared shape or provenance: ' . $override->name);
	}
	$checks++;
}
$nested = ['data' => ['title' => 'Nested write', 'params' => ['enabled' => true, 'values' => [1, 2]]]];
$validated = (new SchemaValidator())->input(['action' => 'fixture.create', 'idempotencyKey' => Json::uuid(), 'input' => $nested],
	$documents[$tools['joomla_action_write_plan']['input_schema_id']]);
if ($validated['input'] !== $nested)
{
	throw new RuntimeException('Generic action planning lost arbitrary JSON-valued native arguments.');
}
$checks++;
$schemas = new SchemaValidator();
$readInput = (object) [
	'filter' => (object) ['search' => 'literal & nested=value', 'state' => 0, 'active' => true],
	'payload' => (object) ['values' => [(object) [], [], 1, 1.5, true, null, 'text'],
		'numbered' => (object) ['0' => (object) [], '1' => (object) []]],
];
$deep = 'leaf';

for ($depth = 0; $depth < 13; $depth++)
{
	$deep = (object) ['nested' => $deep];
}

foreach (['joomla_action_read', 'joomla_companion_action_read'] as $name)
{
	$document = $documents[$tools[$name]['input_schema_id']];
	$validated = $schemas->input(['action' => 'fixture.read', 'input' => $readInput], $document, true);

	if (Json::canonical($validated['input']) !== Json::canonical($readInput))
	{
		throw new RuntimeException($name . ' changed nested read JSON objects, arrays or scalar values.');
	}
	$checks++;
	$default = $schemas->input(['action' => 'fixture.read'], $document, true);

	if (!$default['input'] instanceof stdClass)
	{
		throw new RuntimeException($name . ' changed its omitted input object default.');
	}
	$checks++;
	$flat = ['id' => 7, 'offset' => 0, 'limit' => 20, 'search' => 'Joomla', 'published' => true, 'optional' => null];

	if ($schemas->input(['action' => 'fixture.read', 'input' => $flat], $document)['input'] !== $flat)
	{
		throw new RuntimeException($name . ' changed existing scalar read arguments.');
	}
	$checks++;

	foreach ([[], null, 'scalar', true, 7,
		(object) ['payload' => $deep],
		(object) array_fill_keys(range(0, 512), 'value'),
		(object) ['payload' => array_fill(0, 10001, 'value')],
		(object) ['payload' => (object) ['__proto__' => 'value']],
		(object) ['payload' => INF],
		(object) ['payload' => new DateTimeImmutable()],
	] as $invalid)
	{
		try
		{
			$schemas->input(['action' => 'fixture.read', 'input' => $invalid], $document, true);
			throw new RuntimeException($name . ' accepted an invalid JSON shape or exceeded shared input bounds.');
		}
		catch (OperationException $error)
		{
			if ($error->getIdentifier() !== 'INVALID_INPUT')
			{
				throw $error;
			}
			$checks++;
		}
	}

	try
	{
		$schemas->input(['action' => 'fixture.read', 'input' => (object) ['payload' => str_repeat('x', 1048576)]], $document, true);
		throw new RuntimeException($name . ' exceeded the existing input byte limit.');
	}
	catch (OperationException $error)
	{
		if ($error->getIdentifier() !== 'RESULT_TOO_LARGE')
		{
			throw $error;
		}
		$checks++;
	}
}

echo Json::encode(['checks' => $checks, 'sourceSchemaObjects' => 'preserved except declared runtime extensions',
	'nestedWriteArguments' => 'passed', 'nestedReadArguments' => 'passed', 'readInputBounds' => 'passed']) . PHP_EOL;
