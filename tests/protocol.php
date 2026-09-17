<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Uid\Uuid;
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\DatabaseRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ServerFactory;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\SessionStore;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
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

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never
{
	throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void
{
	$checks++;

	if (!$value)
	{
		throw new RuntimeException($message);
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
$principal = new Principal('joomla:17', 'api', [1]);
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
	static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
$now = 1900000000;
$clock = static function () use (&$now): int
{
	return $now;
};
$envelope = new Envelope(str_repeat('test-only-secret-', 3));
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$state = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
/** Test-local primitive records SDK argument mapping without calling a Joomla installation. */
$handler = new class implements HandlerInterface
{
	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		return ['status' => 200, 'headers' => [], 'data' => ['id' => $arguments['id'] ?? 1, 'title' => 'Protocol fixture']];
	}
};
$actions = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]),
	$permissions, $state, $audit, $settings, new ApiRequestBuilder());
$tools = new ToolDispatcher($catalogue, $actions, $permissions, $schemas, $principal, $settings);
$registry = new DatabaseRegistry($catalogue, $tools, $actions, $schemas);
$sessions = new SessionStore($store, $envelope, $principal, 3600, $clock);
$server = (new ServerFactory($registry, $sessions, $settings))->create();
$factory = new Psr17Factory();
$sessionId = '';
$exchange = static function (array $message, string $method = 'POST') use ($server, $factory, &$sessionId): array
{
	$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25'];

	if ($sessionId !== '')
	{
		$headers['Mcp-Session-Id'] = $sessionId;
	}

	$request = new ServerRequest($method, 'http://127.0.0.1/mcp', $headers, Json::encode($message));
	$response = $server->run(new StreamableHttpTransport($request, $factory, $factory));
	$sessionId = $response->getHeaderLine('Mcp-Session-Id') ?: $sessionId;
	$body = (string) $response->getBody();

	return ['status' => $response->getStatusCode(), 'body' => $body === '' ? null : Json::decode($body)];
};
$init = $exchange(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
	'protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'php-contract', 'version' => '1.0.0'],
]]);
$check($init['status'] === 200 && ($init['body']['result']['protocolVersion'] ?? '') === '2025-11-25', 'SDK handshake failed: ' . Json::encode($init));
$check($sessionId !== '', 'Handshake did not issue a session identifier.');
$check(($init['body']['result']['capabilities']['resources']['subscribe'] ?? false) === false, 'Unimplemented subscriptions advertised.');
$exchange(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$list = $exchange(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
$check(isset($list['body']['result']['tools']), 'Tool listing failed: ' . Json::encode($list));
$names = array_column($list['body']['result']['tools'], 'name');
$check(in_array('joomla_sites_list', $names, true), 'Published API tool is missing.');
$check(!in_array('joomla_cli_inventory', $names, true), 'HTTP disclosed a CLI-only tool.');
$call = $exchange(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'joomla_sites_list', 'arguments' => (object) []]]);
$check(($call['body']['result']['structuredContent']['sites'][0]['id'] ?? '') === 'default', 'Empty-argument tool failed: ' . Json::encode($call));
$call = $exchange(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'joomla_content_article_get', 'arguments' => ['id' => 12]]]);
$check(($call['body']['result']['isError'] ?? true) === false, 'Explicit handler did not receive argument map: ' . Json::encode($call));
$check(str_contains($call['body']['result']['content'][0]['text'] ?? '', 'Protocol fixture'), 'Read handler result missing.');
$wrong = $exchange(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'joomla_capabilities', 'arguments' => ['site' => 'foreign-site']]]);
$check(($wrong['body']['result']['isError'] ?? false) === true, 'Site alias pivot accepted.');
$invalid = $exchange(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'joomla_content_article_get', 'arguments' => ['id' => 'bad']]]);
$check(isset($invalid['body']['error']) || ($invalid['body']['result']['isError'] ?? false), 'Invalid input did not fail.');
$resource = $exchange(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/read', 'params' => ['uri' => 'joomla://catalog/core']]);
$check(isset($resource['body']['result']['contents'][0]['text']), 'Catalogue resource failed: ' . Json::encode($resource));
$resourceData = Json::decode($resource['body']['result']['contents'][0]['text']);
$check(($resourceData['cli']['targets'] ?? null) === [], 'HTTP resource leaked local-only target catalogue.');
$tool = $store->one('tool', ['name' => 'joomla_sites_list']);
$store->update('tool', ['published' => 0], ['id' => $tool['id']]);
$list = $exchange(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/list']);
$check(!in_array('joomla_sites_list', array_column($list['body']['result']['tools'], 'name'), true), 'Persistent SDK registry ignored unpublication.');
$hidden = $exchange(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'joomla_sites_list', 'arguments' => (object) []]]);
$check(isset($hidden['body']['error']), 'Direct invocation of an unpublished tool succeeded.');
$store->update('tool', ['published' => 1], ['id' => $tool['id']]);
$other = new SessionStore($store, $envelope, new Principal('joomla:18'), 3600, $clock);
$uuid = Uuid::fromString($sessionId);
$check(!$other->exists($uuid) && $other->read($uuid) === false, 'Session crossed principal boundary.');
$stored = $store->one('session', ['uuid' => $sessionId]);
$check(!str_contains($stored['data_cipher'], 'php-contract'), 'Protocol session was not encrypted.');
$first = new SessionStore($store, $envelope, $principal, 3600, $clock);
$second = new SessionStore($store, $envelope, $principal, 3600, $clock);
$one = $first->read($uuid);
$two = $second->read($uuid);
$check(is_string($one) && $one === $two, 'Session read-back failed.');
$check($first->write($uuid, $one), 'First session update failed.');
$check(!$second->write($uuid, $two), 'Stale concurrent session write overwrote newer state.');
$now += 3601;
$check(!$first->exists($uuid), 'Expired session remained valid.');
$check(count($first->gc()) === 1, 'Expired session was not collected.');
$check($store->one('session', ['uuid' => $sessionId]) === null, 'Expired session row remains.');

$modernExchange = static function (string $method, array $params = [], array $extraHeaders = []) use ($server, $factory): array
{
	$params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) [],
		'io.modelcontextprotocol/clientInfo' => ['name' => 'modern-php-fixture', 'version' => '1.0.0']];
	$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Mcp-Method' => $method, 'MCP-Protocol-Version' => '2026-07-28'];

	if (isset($params['name']) || isset($params['uri']))
	{
		$headers['Mcp-Name'] = $params['name'] ?? $params['uri'];
	}

	$headers = array_replace($headers, $extraHeaders);
	$request = new ServerRequest('POST', 'http://127.0.0.1/mcp', $headers,
		Json::encode(['jsonrpc' => '2.0', 'id' => 100, 'method' => $method, 'params' => $params]));
	$response = $server->run(new StreamableHttpTransport($request, $factory, $factory));

	return ['status' => $response->getStatusCode(), 'body' => Json::decode((string) $response->getBody()),
		'session' => $response->getHeaderLine('Mcp-Session-Id')];
};
$modern = $modernExchange('server/discover');
$check($modern['status'] === 200 && isset($modern['body']['result']['supportedVersions']), 'Modern discovery failed: ' . Json::encode($modern));
$check($modern['session'] === '' && $store->find('session') === [], 'Modern requests unexpectedly create handshake sessions.');
$modern = $modernExchange('tools/list');
$check(isset($modern['body']['result']['tools']), 'Modern tool listing failed: ' . Json::encode($modern));
$modern = $modernExchange('tools/call', ['name' => 'joomla_sites_list', 'arguments' => (object) []]);
$check(($modern['body']['result']['structuredContent']['sites'][0]['id'] ?? '') === 'default', 'Modern tool invocation failed: ' . Json::encode($modern));
$modern = $modernExchange('tools/call', ['name' => 'joomla_sites_list', 'arguments' => (object) []], ['Mcp-Name' => 'different-tool']);
$check($modern['status'] === 400 && isset($modern['body']['error']), 'Mismatched modern subject header accepted.');
$modern = $modernExchange('tools/list', [], ['Mcp-Method' => 'tools/call']);
$check($modern['status'] === 400, 'Mismatched modern method header accepted.');
$modern = $modernExchange('initialize');
$check(($modern['body']['error']['code'] ?? null) === -32601, 'Modern era incorrectly accepted the removed initialize method.');

$common = \VDM\Component\JoomEngineMcp\Administrator\Database\Structure::common();
$record = [];

foreach ($common as $name => $type)
{
	$record[$name] = in_array($type, ['int', 'published', 'access', 'version'], true) ? 0 : '';
}

$record = array_replace($record, ['published' => 1, 'access' => 1, 'version' => 1, 'params' => '{}', 'provider_id' => 1]);
$schemaId = $store->insert('schema', array_replace($record, ['name' => 'fixture.prompt.arguments', 'title' => 'Prompt arguments', 'document' => Json::encode(['type' => 'object',
	'properties' => ['subject' => ['type' => 'string', 'maxLength' => 100]], 'required' => ['subject'], 'additionalProperties' => false])]));
$store->update('schema', ['name' => 'fixture.prompt.arguments', 'title' => 'Prompt arguments'], ['id' => $schemaId]);
$promptId = $store->insert('prompt', array_replace($record, ['name' => 'fixture_prompt', 'title' => 'Fixture prompt',
	'input_schema_id' => $schemaId, 'description' => 'Data-only prompt fixture.', 'definition' => '{}',
	'configuration' => Json::encode(['messages' => [['role' => 'user', 'text' => 'Discuss {{subject}}.']]])]));
$prompts = $modernExchange('prompts/list');
$check(in_array('fixture_prompt', array_column($prompts['body']['result']['prompts'], 'name'), true), 'New database prompt was not discovered.');
$prompt = $modernExchange('prompts/get', ['name' => 'fixture_prompt', 'arguments' => ['subject' => '{{untouched}}']]);
$check(($prompt['body']['result']['messages'][0]['content']['text'] ?? '') === 'Discuss {{untouched}}.', 'Prompt substitution was recursive or wrong: ' . Json::encode($prompt));
$resourceId = $store->insert('resource', array_replace($record, ['name' => 'fixture_text', 'title' => 'Fixture resource', 'uri' => 'fixture://text',
	'is_template' => 0, 'mime_type' => 'text/plain', 'description' => 'Inert content.', 'handler' => 'resource.text',
	'configuration' => Json::encode(['text' => '<?php echo "not executed";']), 'definition' => '{}']));
$resource = $modernExchange('resources/read', ['uri' => 'fixture://text']);
$check(($resource['body']['result']['contents'][0]['text'] ?? '') === '<?php echo "not executed";', 'Stored resource content was not kept inert.');
$store->update('prompt', ['access' => 99], ['id' => $promptId]);
$prompts = $modernExchange('prompts/list');
$check($prompts['body']['result']['prompts'] === [], 'A newly restricted prompt remained discoverable.');

echo Json::encode(['checks' => $checks, 'sdkHttpHandshake' => 'passed', 'databaseRegistry' => 'passed', 'principalBoundSessions' => 'passed', 'liveJoomla' => 'not run by this suite']) . PHP_EOL;
