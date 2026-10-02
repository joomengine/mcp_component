<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\InventoryTransport;
use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

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
$fails = static function (callable $operation, string $identifier, string $message) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $identifier, $message . ': ' . $error->getIdentifier());
		return;
	}
	throw new RuntimeException($message);
};
$withManifest = static function (array $wire, callable $change): array
{
	$manifest = Json::native(Json::decode($wire['inventory'], false));
	$change($manifest);
	$wire['inventory'] = Json::canonical($manifest);
	return $wire;
};

foreach ([[], (object) [], [1, 2], (object) ['0' => 1.0, '1' => 2],
	['z' => ['b' => true, 'a' => null], 'a' => ['雪', 'literal / slash', 1.0, 1, false]]] as $value)
{
	$check(Json::canonicalHash($value) === hash('sha256', Json::canonical($value)),
		'Incremental canonical hashing matches exact existing bytes and JSON shapes.');
}
$check(Json::canonicalHash((object) []) !== Json::canonicalHash([])
	&& Json::canonicalHash((object) ['0' => 1, '1' => 2]) !== Json::canonicalHash([1, 2])
	&& Json::canonicalHash(1.0) !== Json::canonicalHash(1), 'Canonical hashing preserves ambiguous object/list and numeric representations.');
$check(Json::canonicalHash(['b' => 2, 'a' => 1]) === Json::canonicalHash(['a' => 1, 'b' => 2])
	&& Json::canonicalHash([1, 2]) !== Json::canonicalHash([2, 1]), 'Canonical maps sort while native list order remains meaningful.');
$deep = [];
for ($depth = 0; $depth < 66; $depth++)
{
	$deep = [$deep];
}
$fails(static fn () => Json::canonicalHash($deep), 'RESULT_INVALID', 'Excessive canonical nesting fails closed.');
$cyclic = new stdClass();
$cyclic->cycle = $cyclic;
$fails(static fn () => Json::canonicalHash($cyclic), 'RESULT_INVALID', 'Cyclic inventory objects fail at bounded nesting.');
$fails(static fn () => Json::canonicalHash(INF), 'RESULT_INVALID', 'Unencodable numeric values never produce a success fingerprint.');
$chunk = str_repeat('b', 1048576);
$aggregate = array_fill(0, 65, $chunk);
$fails(static fn () => Json::canonicalHash($aggregate), 'RESULT_TOO_LARGE', 'Canonical aggregate hashing remains bounded at64MiB.');
unset($aggregate, $chunk, $deep, $cyclic);

$process = new PhpProcess(PHP_BINARY, __DIR__ . '/fixtures/inventory-child.php', __DIR__);
try
{
	$process->run(['routes' => 80], 10, InventoryTransport::MAX_WIRE_BYTES, static fn (): bool => false);
	throw new RuntimeException('The historical full repeated worker envelope must exceed8MiB.');
}
catch (OperationException $error)
{
	$check(in_array($error->getIdentifier(), ['WORKER_OUTPUT_LIMIT', 'WORKER_FAILED'], true),
		'Historical full inventory is rejected by the unchanged8MiB worker output bound.');
}
$wire = $process->run(['routes' => 80, 'inventory_format' => InventoryTransport::FORMAT], 10,
	InventoryTransport::MAX_WIRE_BYTES, static fn (): bool => false);
$manifest = Json::native(Json::decode($wire['inventory'], false));
$check(count($wire['form_contracts']) === 2 && count($manifest['api']['routes']) === 80
	&& strlen(Json::encode($wire)) < 1048576, 'Actual bounded subprocess returns all80routes using two distinct native form contracts.');
$restored = InventoryTransport::unpack($wire);
$check(count($restored['api']['routes']) === 80 && !isset($restored['inventory_format'], $restored['form_contracts']),
	'Expansion returns the full native inventory without transport references.');
$check($restored['api']['components'] === ['com_example']
	&& $restored['api']['unsupported_components'] === ['PUT /v1/example/custom' => 'com_example']
	&& $restored['api']['unsupported'] === ['PUT /v1/example/custom' => 'Reviewed adapter required.'],
	'Component scopes and unsupported owner diagnostics survive isolated IPC unchanged.');
$check($restored['api']['native_observation']['empty'] instanceof stdClass
	&& $restored['api']['native_observation']['numeric'] instanceof stdClass
	&& $restored['api']['native_observation']['numeric']->{'0'} === 1.0
	&& $restored['api']['routes'][0]['defaults']['rate'] === 1.0,
	'Manifest JSON preserves nested empty and numeric-key objects and floats outside form contracts.');
$check($restored['api']['fingerprint'] === Json::canonicalHash(['routes' => $restored['api']['routes'],
	'unsupported' => $restored['api']['unsupported'], 'components' => $restored['api']['components']]),
	'Expanded source inventory reproduces its worker-side fingerprint beyond8MiB.');
$first = $restored['api']['routes'][0]['form_contract'];
$second = $restored['api']['routes'][1]['form_contract'];
$check($first['schema']['required'] === ['title'] && !isset($second['schema']['required'])
	&& $first['fingerprint'] !== $second['fingerprint'], 'POST requirements and PATCH omission semantics remain separate exact contracts.');
$check($first['empty_object'] instanceof stdClass && $first['empty_list'] === []
	&& $first['numeric_object'] instanceof stdClass && $first['numeric_object']->{'0'} === 1.0
	&& $first['schema']['properties']['custom'] instanceof stdClass,
	'Contract strings retain empty objects, lists, numeric-key objects and floats across associative worker decoding.');
$validator = new SchemaValidator();
$check($validator->input(['title' => 'Accepted native input'], Json::encode($first['schema'])) === ['title' => 'Accepted native input'],
	'Expanded native create schema accepts valid input without inserting omitted fields.');
$fails(static fn () => $validator->input([], Json::encode($first['schema'])), 'INVALID_INPUT', 'Expanded native create schema still rejects missing required input.');
$check($validator->input([], Json::encode($second['schema'])) === [], 'Expanded PATCH schema preserves omitted input.');
$seed = (new CatalogueBuilder())->build($restored['commands'], $restored['api']);
$check($seed['source'] === Json::canonicalHash(['commands' => $restored['commands'], 'api' => $restored['api']])
	&& count($seed['entities']['binding']) === 80, 'Production catalogue materializes every large-inventory route with the unchanged canonical source hash.');
$binding = Json::decode($seed['entities']['binding'][0]['configuration'], false);
$check($binding->api_form->fingerprint === $first['fingerprint']
	&& $binding->api_form->schema->properties->custom instanceof stdClass,
	'Persisted API bindings retain full source fingerprints and native schema object shapes.');
$check($wire === $process->run(['routes' => 80, 'inventory_format' => InventoryTransport::FORMAT], 10,
	InventoryTransport::MAX_WIRE_BYTES, static fn (): bool => false), 'Repeated installed-style inventory exchanges are deterministic.');

$bad = $withManifest($wire, static function (array &$manifest): void
{
	array_shift($manifest['api']['routes']);
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Dropping a route with a still-used contract cannot preserve the complete observed inventory fingerprint.');
$bad = $withManifest($wire, static function (array &$manifest): void
{
	$first = $manifest['api']['routes'][0]['form_contract_ref'];
	$manifest['api']['routes'][0]['form_contract_ref'] = $manifest['api']['routes'][1]['form_contract_ref'];
	$manifest['api']['routes'][1]['form_contract_ref'] = $first;
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Swapping valid POST and PATCH contract references cannot retain the complete inventory fingerprint.');
$bad = $withManifest($wire, static function (array &$manifest): void
{
	$manifest['api']['components'] = ['com_other'];
	$manifest['api']['unsupported_components']['PUT /v1/example/custom'] = 'com_other';
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Altered component scopes and diagnostic owners are detected before synchronization.');
$bad = $withManifest($wire, static function (array &$manifest): void
{
	$manifest['commands']['unsupported']['componentbuilder:changed'] = 'Changed diagnostic.';
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Command metadata is covered by the same complete inventory fingerprint.');
$bad = $wire;
unset($bad['inventory_fingerprint']);
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'A compact inventory without complete source integrity evidence is rejected.');
$bad = $withManifest($wire, static function (array &$manifest): void
{
	$manifest['api']['routes'][0]['form_contract_ref'] = str_repeat('0', 64);
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Missing dictionary references reject the entire inventory.');
$bad = $wire;
$reference = array_key_first($bad['form_contracts']);
$bad['form_contracts'][$reference] .= ' ';
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Altered dictionary bytes cannot retain a content fingerprint.');
$bad = $wire;
$contract = Json::decode($bad['form_contracts'][$reference], false);
$contract->fingerprint = str_repeat('0', 64);
$text = Json::canonical($contract);
$replacement = hash('sha256', $text);
unset($bad['form_contracts'][$reference]);
$bad['form_contracts'][$replacement] = $text;
$badManifest = Json::native(Json::decode($bad['inventory'], false));
foreach ($badManifest['api']['routes'] as &$route)
{
	if ($route['form_contract_ref'] === $reference)
	{
		$route['form_contract_ref'] = $replacement;
	}
}
unset($route);
$bad['inventory'] = Json::canonical($badManifest);
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'A new dictionary hash cannot hide an altered native source fingerprint.');
$bad = $withManifest($wire, static function (array &$manifest): void
{
	$manifest['api']['routes'] = array_fill(0, 600, $manifest['api']['routes'][0]);
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_LIMIT', 'Repeated compact references cannot exceed the bounded64MiB expansion.');
$bad = $withManifest($wire, static function (array &$manifest): void
{
	$manifest['api']['routes'] = array_fill(0, InventoryTransport::MAX_ROUTES + 1, $manifest['api']['routes'][0]);
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Native route cardinality is bounded before expansion.');
$bad = $withManifest($wire, static function (array &$manifest) use ($first): void
{
	$manifest['api']['routes'][0]['form_contract'] = $first;
});
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Mixed compact and expanded route contracts fail closed.');
$bad = $wire;
$bad['inventory_format'] = 'unknown';
$fails(static fn () => InventoryTransport::unpack($bad), 'JCB_INVENTORY_INVALID', 'Unsupported inventory encodings are rejected explicitly.');
$small = $restored;
$small['api']['routes'] = array_slice($small['api']['routes'], 0, 2);
$packed = InventoryTransport::pack($small);
$roundtrip = InventoryTransport::unpack(Json::decode(Json::encode($packed)));
$check(Json::canonicalHash($small) === Json::canonicalHash($roundtrip), 'Compact encoding roundtrips the exact full inventory value without truncation.');
$unused = $packed;
$unused = $withManifest(Json::decode(Json::encode($unused)), static function (array &$manifest): void
{
	$manifest['api']['routes'] = array_slice($manifest['api']['routes'], 0, 1);
});
$fails(static fn () => InventoryTransport::unpack($unused), 'JCB_INVENTORY_INVALID', 'Unrelated dictionary entries cannot enter a partial inventory.');
$empty = ['commands' => ['commands' => []], 'api' => ['routes' => [], 'components' => ['com_example'], 'unsupported' => []]];
$check(Json::canonicalHash($empty) === Json::canonicalHash(InventoryTransport::unpack(Json::decode(Json::encode(InventoryTransport::pack($empty))))),
	'An authoritative empty component scope roundtrips without inventing or truncating routes.');
$unicode = $small;
$unicode['api']['routes'] = array_slice($unicode['api']['routes'], 0, 1);
$unicodeContract = $unicode['api']['routes'][0]['form_contract'];
unset($unicodeContract['fingerprint']);
$unicodeContract['fields']['title']['hint'] = str_repeat('雪', 1400000);
$unicodeContract['fingerprint'] = Json::canonicalHash($unicodeContract);
$unicode['api']['routes'][0]['form_contract'] = $unicodeContract;
$unicodePacked = InventoryTransport::pack($unicode);
$unicodeWire = Json::encode($unicodePacked, InventoryTransport::MAX_WIRE_BYTES);
$check(strlen($unicodeWire) < InventoryTransport::MAX_WIRE_BYTES
	&& strlen(json_encode($unicodePacked, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > InventoryTransport::MAX_WIRE_BYTES,
	'The compact final encoder preserves UTF-8 and uses the same checked byte budget even where escaped Unicode would overflow.');
$check(Json::canonicalHash($unicode) === Json::canonicalHash(InventoryTransport::unpack(Json::decode($unicodeWire))),
	'Near-boundary Unicode contract evidence survives the exact compact final encoding and worker decoding.');
unset($unicode, $unicodeContract, $unicodePacked, $unicodeWire);
$fails(static fn () => Json::encode($restored), 'RESULT_TOO_LARGE', 'Ordinary HTTP and result JSON still enforce their unchanged8MiB budget.');

echo Json::encode(['checks' => $checks, 'inventoryTransport' => 'passed']) . PHP_EOL;
