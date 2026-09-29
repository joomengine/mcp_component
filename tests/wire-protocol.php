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
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\DatabaseRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ServerFactory;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\SessionStore;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\StdioTransport;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\WireInputMiddleware;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
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
$check = static function (bool $ok, string $label) use (&$checks): void
{
	$checks++;

	if (!$ok)
	{
		throw new RuntimeException($label);
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
$principal = new Principal('console:wire-fixture', 'cli');
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
	static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
$envelope = new Envelope(str_repeat('wire-fixture-only-secret-', 3));
$clock = static fn (): int => time();
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$state = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
/** The boundary contract uses a deterministic semantic handler, without booting Joomla. */
$native = new class implements HandlerInterface
{
	/** @var int Number of validated native calls. */
	public int $calls = 0;
	/** @var array Last validated semantic input. */
	public array $arguments = [];

	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		$this->calls++;
		$this->arguments = $arguments;

		return ['joomlaVersion' => '6.1.0', 'phpVersion' => PHP_VERSION];
	}
};
$actions = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['native.system-info' => $native]),
	$permissions, $state, $audit, $settings, new ApiRequestBuilder());
$tools = new ToolDispatcher($catalogue, $actions, $permissions, $schemas, $principal, $settings);
$registry = new DatabaseRegistry($catalogue, $tools, $actions, $schemas);
$servers = new ServerFactory($registry, new SessionStore($store, $envelope, $principal, 3600, $clock), $settings);
$server = $servers->create();
$messages = [
	['jsonrpc' => '2.0', 'id' => 'initialize-wire', 'method' => 'initialize', 'params' => [
		'protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'wire-fixture', 'version' => '1.0.0']]],
	['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
];
$validIds = [];
$invalidIds = [];

foreach (['joomla_action_read', 'joomla_companion_action_read'] as $name)
{
	foreach ([false, true] as $explicit)
	{
		$id = $name . ($explicit ? '-empty-object' : '-omitted');
		$arguments = ['action' => 'system.info'];

		if ($explicit)
		{
			$arguments['input'] = (object) [];
		}

		$validIds[] = $id;
		$messages[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
	}

	foreach ([[], ['unexpected'], null, true, 1, 'scalar'] as $index => $invalid)
	{
		$id = $name . '-invalid-input-' . $index;
		$invalidIds[$id] = -32602;
		$messages[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $name,
			'arguments' => ['action' => 'system.info', 'input' => $invalid]]];
	}
}

foreach ([[], null, 7, false, 'scalar'] as $index => $invalid)
{
	$id = 'invalid-arguments-' . $index;
	$invalidIds[$id] = -32602;
	$messages[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => 'joomla_sites_list', 'arguments' => $invalid]];
}

$deep = 'leaf';

for ($depth = 0; $depth < 70; $depth++)
{
	$deep = (object) ['nested' => $deep];
}

$messages[] = ['jsonrpc' => '2.0', 'id' => 'deep-invalid-input', 'method' => 'tools/call', 'params' => ['name' => 'joomla_action_read',
	'arguments' => ['action' => 'system.info', 'input' => []], '_meta' => ['reviewDepth' => $deep]]];
$messages[] = ['jsonrpc' => '2.0', 'id' => 'ping-after-errors', 'method' => 'ping'];
$messages[] = ['jsonrpc' => '2.0', 'id' => 100, 'method' => 'ping'];
$input = fopen('php://temp', 'w+');
$outputPath = tempnam(sys_get_temp_dir(), 'mcp-wire-output-');
$output = fopen($outputPath, 'w+');

try
{
	foreach ($messages as $message)
	{
		fwrite($input, Json::encode($message) . "\n");
	}

	rewind($input);
	$check($server->run(new StdioTransport($input, $output, wire: $servers->wireInput())) === 0, 'The actual newline/stdin transport finishes cleanly.');
	$lines = explode("\n", trim(file_get_contents($outputPath)));
	$replies = [];
	$nullFailures = 0;
	$depthFailures = 0;

	foreach ($lines as $line)
	{
		$message = Json::decode($line);

		if (!isset($message['id']))
		{
			if (($message['error']['code'] ?? null) === -32700)
			{
				$depthFailures++;
			}
			else
			{
				$check(($message['error']['code'] ?? null) === -32600, 'MCP null request IDs remain invalid requests.');
				$nullFailures++;
			}
			continue;
		}

		$check(!array_key_exists($message['id'], $replies), 'Every wire request receives exactly one response.');
		$replies[$message['id']] = $message;
	}

	foreach ($validIds as $id)
	{
		$reply = $replies[$id] ?? [];
		$check(($reply['result']['structuredContent']['response']['data']['joomlaVersion'] ?? '') === '6.1.0'
			&& ($reply['result']['isError'] ?? false) === false, 'Both read primitives accept omitted and explicit empty-object input: ' . $id);
	}

	foreach ($invalidIds as $id => $code)
	{
		$check(($replies[$id]['error']['code'] ?? null) === $code, 'Protocol failures retain the correct error code and request ID: ' . $id);
	}

	$check($native->calls === 4, 'Invalid scalar, list and null arguments never reach the native handler.');
	$check($depthFailures === 1, 'Deep metadata cannot bypass original JSON argument shape validation.');
	$check($nullFailures === 0 && count($replies) === 1 + count($validIds) + count($invalidIds) + 2,
		'Unknown and malformed notifications emit no response.');
	$check(($replies['ping-after-errors']['result'] ?? null) === [] && ($replies[100]['result'] ?? null) === [],
		'Consecutive pings remain usable with exact string and integer IDs after rejected input.');
}
finally
{
	if (is_resource($input))
	{
		fclose($input);
	}

	if (is_resource($output))
	{
		fclose($output);
	}

	unlink($outputPath);
}

// A current action contract can accept object-valued input. Preserve it through
// the generic tool's validation, action validation and handler invocation.
$binding = $store->one('binding', ['name' => 'system.info.cli']);
$actionSchema = ['type' => 'object', 'properties' => ['payload' => ['type' => 'object',
	'additionalProperties' => ['type' => 'object']]], 'required' => ['payload'], 'additionalProperties' => false];
$store->update('schema', ['document' => Json::encode($actionSchema)], ['id' => $binding['input_schema_id']]);
$readTool = $store->one('tool', ['name' => 'joomla_action_read']);
$readSchema = Json::decode($catalogue->schema((int) $readTool['input_schema_id']), false);
$readSchema->properties->input = (object) $actionSchema;
$store->update('schema', ['document' => Json::encode($readSchema)], ['id' => $readTool['input_schema_id']]);

// The object/list distinction must also survive nested object schemas, lists,
// additionalProperties, numeric keys and default values on both HTTP eras.
$tool = $store->one('tool', ['name' => 'joomla_sites_list']);
$schema = ['type' => 'object', 'properties' => [
	'objectValue' => ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
	'listValue' => ['type' => 'array', 'items' => ['type' => 'object']],
	'defaultObject' => ['type' => 'object', 'default' => (object) []],
], 'required' => ['objectValue', 'listValue'], 'additionalProperties' => false];
$store->update('schema', ['document' => Json::encode($schema)], ['id' => $tool['input_schema_id']]);
$factory = new Psr17Factory();
$httpSession = '';
$http = static function (array $message, bool $modern = false) use ($server, $servers, $factory, &$httpSession): array
{
	$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];

	if ($modern)
	{
		$message['params']['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => (object) [],
			'io.modelcontextprotocol/clientInfo' => ['name' => 'wire-http-fixture', 'version' => '1.0.0']];
		$headers['MCP-Protocol-Version'] = '2026-07-28';
		$headers['Mcp-Method'] = $message['method'];
		$headers['Mcp-Name'] = $message['params']['name'] ?? '';
	}
	else
	{
		$headers['MCP-Protocol-Version'] = '2025-11-25';

		if ($httpSession !== '')
		{
			$headers['Mcp-Session-Id'] = $httpSession;
		}
	}

	$request = new ServerRequest('POST', 'http://127.0.0.1/mcp', $headers, Json::encode($message));
	$response = $server->run(new StreamableHttpTransport($request, $factory, $factory,
		middleware: [...StreamableHttpTransport::defaultMiddleware(), new WireInputMiddleware($servers->wireInput(), $factory, $factory)]));
	$httpSession = $response->getHeaderLine('Mcp-Session-Id') ?: $httpSession;
	$body = (string) $response->getBody();

	return $body === '' ? [] : Json::decode($body);
};
$http($messages[0]);
$http(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$valid = ['objectValue' => (object) ['0' => (object) [], '1' => (object) []], 'listValue' => [(object) []]];

foreach ([false, true] as $modern)
{
	$reply = $http(['jsonrpc' => '2.0', 'id' => 'nested-action-map', 'method' => 'tools/call', 'params' => ['name' => 'joomla_action_read',
		'arguments' => ['action' => 'system.info', 'input' => (object) ['payload' => (object) ['0' => (object) [], '1' => (object) []]]]]], $modern);
	$check(($reply['result']['isError'] ?? false) === false && isset($reply['result']['structuredContent']['response'])
		&& Json::encode($native->arguments['payload'] ?? null) === '{"0":{},"1":{}}',
		'Numeric-only object maps survive generic tool validation, action validation and native invocation in both HTTP eras.');

	$reply = $http(['jsonrpc' => '2.0', 'id' => 401, 'method' => 'tools/call', 'params' => ['name' => 'joomla_sites_list', 'arguments' => $valid]], $modern);
	$check(($reply['result']['structuredContent']['sites'][0]['id'] ?? '') === 'default', 'HTTP validates nested empty JSON objects and numeric object keys in both eras.');

	foreach ([['objectValue' => [], 'listValue' => []], ['objectValue' => (object) [], 'listValue' => (object) []],
		['objectValue' => (object) ['empty' => []], 'listValue' => []], ['objectValue' => (object) [], 'listValue' => [[]]]] as $arguments)
	{
		$reply = $http(['jsonrpc' => '2.0', 'id' => 402, 'method' => 'tools/call', 'params' => ['name' => 'joomla_sites_list', 'arguments' => $arguments]], $modern);
		$check(($reply['error']['code'] ?? null) === -32602, 'HTTP never coerces empty JSON arrays into required objects or objects into arrays.');
	}

}

echo Json::encode(['checks' => $checks, 'newlineStdio' => 'passed', 'jsonObjectTypes' => 'passed', 'invalidInputTypes' => 'passed', 'httpEras' => 'passed']) . PHP_EOL;
