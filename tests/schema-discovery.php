<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\SystemInfoAction;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\DatabaseRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ServerFactory;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\SessionStore;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaDocument;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\HandlerRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;


require dirname(__DIR__) . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never
{
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	$checks++;

	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};

// Exercise value-bearing keywords as well as schema maps. Numeric object keys
// must remain object keys; required/default/examples/enum arrays must stay lists.
$shapeDocument = <<<'JSON'
{
  "type": "object",
  "properties": {
    "options": {"type": "object", "properties": {}, "additionalProperties": {}, "default": {}},
    "values": {"type": "array", "items": {}, "default": []},
    "choice": {"enum": [{}, [], {"enabled": true}]},
    "numbered": {"type": "object", "properties": {"0": {"type": "string"}}},
    "examples": {"examples": [{"nested": {}, "values": []}]}
  },
  "$defs": {},
  "definitions": {},
  "dependentSchemas": {},
  "patternProperties": {},
  "required": [],
  "additionalProperties": false
}
JSON;
$expectedShape = Json::decode($shapeDocument, false);
$check(Json::canonical(SchemaDocument::decode($shapeDocument)) === Json::canonical($expectedShape),
	'Discovery decoding changed a schema map, numeric object key or legitimate array value.');
$nativeSchema = Json::decode(Json::encode((new SystemInfoAction())->descriptor()), false);
$check($nativeSchema->inputSchema->properties instanceof stdClass,
	'The native companion descriptor must serialize its empty properties map as an object.');

$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
foreach (['api', 'cli'] as $track)
{
	$store = new MemoryStore($seed);
	$schemaTemplate = array_diff_key($seed['schema'][0], ['id' => true]);
	$schemaId = $store->insert('schema', array_replace($schemaTemplate,
		['name' => 'fixture.schema', 'document' => $shapeDocument]));
	$toolTemplate = array_diff_key(array_column($seed['tool'], null, 'name')['joomla_sites_list'], ['id' => true]);
	$store->insert('tool', array_replace($toolTemplate, [
		'name' => 'fixture_schema', 'input_schema_id' => $schemaId, 'output_schema_id' => $schemaId]));
	$principal = new Principal('fixture:schema:' . $track, $track, [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
	$envelope = new Envelope(str_repeat('schema-discovery-fixture-', 3));
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$executions = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
	$actions = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry([]),
		$permissions, $executions, $audit, $settings, new ApiRequestBuilder());
	$tools = new ToolDispatcher($catalogue, $actions, $permissions, $schemas, $principal, $settings);
	$registry = new DatabaseRegistry($catalogue, $tools, $actions, $schemas);
	$server = (new ServerFactory($registry, new SessionStore($store, $envelope, $principal, 3600, $clock), $settings))->create();
	$factory = new Psr17Factory();
	$sessionId = '';
	$id = 0;
	$exchange = static function (string $method, mixed $params = null) use ($server, $factory, &$sessionId, &$id, $check): mixed
	{
		$message = ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method];

		if ($params !== null)
		{
			$message['params'] = $params;
		}

		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
			'MCP-Protocol-Version' => '2025-11-25'];

		if ($sessionId !== '')
		{
			$headers['Mcp-Session-Id'] = $sessionId;
		}

		$request = new ServerRequest('POST', 'http://127.0.0.1/mcp', $headers, Json::encode($message));
		$response = $server->run(new StreamableHttpTransport($request, $factory, $factory));
		$sessionId = $response->getHeaderLine('Mcp-Session-Id') ?: $sessionId;
		$body = Json::decode((string) $response->getBody(), false);
		$check($response->getStatusCode() === 200 && isset($body->result) && !isset($body->error),
			'Actual SDK discovery exchange failed for ' . $method . ': ' . Json::encode($body));

		return $body->result;
	};
	$exchange('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
		'clientInfo' => ['name' => 'schema-discovery-contract', 'version' => '1.0.0']]);

	$listedTools = [];
	$cursor = null;
	do
	{
		$listing = $exchange('tools/list', $cursor === null ? null : ['cursor' => $cursor]);
		$listedTools = array_merge($listedTools, $listing->tools);
		$cursor = $listing->nextCursor ?? null;
	} while ($cursor !== null);

	$rows = array_column($catalogue->all('tool'), null, 'name');
	foreach ($listedTools as $tool)
	{
		$row = $rows[$tool->name];
		$expectedInput = Json::decode($catalogue->schema((int) $row['input_schema_id']), false);
		$check(Json::canonical($tool->inputSchema) === Json::canonical($expectedInput),
			$track . ' tools/list changed the stored input schema for ' . $tool->name);

		if (!empty($row['output_schema_id']))
		{
			$expectedOutput = Json::decode($catalogue->schema((int) $row['output_schema_id']), false);
			$check(Json::canonical($tool->outputSchema) === Json::canonical($expectedOutput),
				$track . ' tools/list changed the stored output schema for ' . $tool->name);
		}
	}
	$check(count($listedTools) === count($rows), 'Schema checks must cover the entire authorized tool listing.');
	$byName = array_column($listedTools, null, 'name');
	foreach (['joomla_action_read', 'joomla_action_write_plan', 'joomla_companion_action_read'] as $name)
	{
		if (isset($byName[$name]))
		{
			$check($byName[$name]->inputSchema->properties->input->default instanceof stdClass,
				$name . ' must advertise an object default for its object input.');
		}
	}

	if ($track === 'cli')
	{
		$search = $exchange('tools/call', ['name' => 'joomla_actions_search', 'arguments' => ['text' => 'system.info']]);
		$searchText = Json::decode($search->content[0]->text, false);
		foreach ([$search->structuredContent, $searchText] as $result)
		{
			$action = array_column($result->actions, null, 'id')['system.info'];
			$check($action->inputSchema->properties instanceof stdClass,
				'Action search text and structured content must retain the system.info empty schema map.');
		}
		$description = $exchange('tools/call', ['name' => 'joomla_action_describe', 'arguments' => ['action' => 'system.info']]);
		$check($description->structuredContent->action->inputSchema->properties instanceof stdClass,
			'Action description must retain the system.info empty schema map.');
		$companion = $exchange('tools/call', ['name' => 'joomla_companion_capabilities', 'arguments' => (object) []]);
		$system = array_column($companion->structuredContent->actions, null, 'name')['system.info'];
		$check($system->inputSchema->properties instanceof stdClass,
			'Companion capability discovery must retain the system.info empty schema map.');
	}
}

echo Json::encode(['checks' => $checks, 'sdkDiscoverySchemas' => 'all authorized tools on API and CLI fixture tracks',
	'actionSchemaMaps' => 'search, describe and companion discovery passed', 'jsonArrays' => 'retained including defaults, enum, examples and required',
	'liveJoomla' => 'not run by this fixture suite']) . PHP_EOL;
