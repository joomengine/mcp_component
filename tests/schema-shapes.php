<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$seed = Json::decode(file_get_contents($root . '/data/catalogue-seed.json'), maximum: 16777216);
// Compare original JSON objects, not an associative decode that could repeat
// the migration's object/list mistake and falsely certify a malformed schema.
$source = json_decode(file_get_contents($root . '/data/upstream-contracts.json'), false, 128, JSON_THROW_ON_ERROR);
$tools = array_column($seed['entities']['tool'], null, 'name');
$documents = array_column($seed['entities']['schema'], 'document', 'id');
$checks = 0;
foreach ($source->tools as $original)
{
	$stored = json_decode($documents[$tools[$original->name]['input_schema_id']], false, 64, JSON_THROW_ON_ERROR);
	if (Json::canonical($stored) !== Json::canonical($original->inputSchema))
	{
		throw new RuntimeException('Migration changed the JSON Schema object/default shape for ' . $original->name);
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
echo Json::encode(['checks' => $checks + 1, 'sourceSchemaObjects' => 'preserved', 'nestedWriteArguments' => 'passed']) . PHP_EOL;
