<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use VDM\Component\JoomEngineMcp\Administrator\Console\ProtocolOutput;
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\DatabaseRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ServerFactory;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\SessionStore;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\StdioTransport;
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

require dirname(__DIR__, 2) . '/admin/autoload.php';
require __DIR__ . '/MemoryStore.php';
require __DIR__ . '/Principal.php';

if (($argv[1] ?? '') === 'fatal' || ($argv[1] ?? '') === 'oom')
{
	// Model a framework fatal adapter forwarding through the currently
	// registered exception renderer, as Symfony's fatal handler does.
	register_shutdown_function(static function (): void
	{
		$error = error_get_last();

		if ($error !== null && in_array($error['type'], [E_ERROR, E_USER_ERROR], true))
		{
			$handler = set_exception_handler(static fn (Throwable $exception): null => null);
			restore_exception_handler();

			if ($handler !== null)
			{
				$handler(new ErrorException('Forced framework fatal fixture.', 0, $error['type']));
			}
		}
	});
	(new ProtocolOutput())->run(static function () use ($argv): int
	{
		fwrite(STDOUT, "{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n");

		if ($argv[1] === 'fatal')
		{
			trigger_error('Forced MCP protocol fatal fixture.', E_USER_ERROR);
		}

		$allocations = [];

		while (true)
		{
			$allocations[] = str_repeat('x', 1048576);
		}
	});
	exit(1);
}

$seed = Json::decode(file_get_contents(dirname(__DIR__, 2) . '/admin/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
// A configurable tool with a larger inert input schema exercises persistent
// validator caches without creating Joomla content or executing any writes.
$properties = [];

for ($field = 0; $field < 120; $field++)
{
	$properties['field' . $field] = ['type' => 'string', 'description' => str_repeat('Inert installed extension metadata. ', 16)];
}
$schema = $seed['schema'][0];
$schema['name'] = 'fixture.wide.input';
$schema['document'] = Json::encode(['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false]);
unset($schema['id']);
$schemaId = $store->insert('schema', $schema);
$tool = $seed['tool'][0];
$tool['name'] = 'fixture_wide_read';
$tool['handler'] = 'action.read';
$tool['configuration'] = '{"action":"system.info"}';
$tool['input_schema_id'] = $schemaId;
$tool['output_schema_id'] = null;
unset($tool['id']);
$store->insert('tool', $tool);
unset($seed, $properties, $schema, $tool);

$principal = new Principal('stdio-server-fixture', 'cli', [1]);
$settings = new Settings(['joomla_version' => '6.1.3']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
	static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
$clock = static fn (): int => 1900000000;
$envelope = new Envelope(str_repeat('stdio-fixture-secret-', 3));
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$state = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
$handler = new class implements HandlerInterface
{
	/** @var int Actual native-fixture dispatch count. */
	private int $calls = 0;
	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		$this->calls++;

		if ($this->calls % 25 === 0)
		{
			fwrite(STDERR, Json::encode(['sample' => $this->calls, 'used' => memory_get_usage(), 'allocated' => memory_get_usage(true), 'peak' => memory_get_peak_usage(true)]) . "\n");
		}

		return ['joomlaVersion' => '6.1.3', 'phpVersion' => PHP_VERSION];
	}
};
$actions = new ActionExecutor($catalogue, $schemas, $principal,
	new HandlerRegistry(['native.system-info' => $handler, 'api.request' => $handler]),
	$permissions, $state, $audit, $settings, new ApiRequestBuilder());
$tools = new ToolDispatcher($catalogue, $actions, $permissions, $schemas, $principal, $settings);
$definitions = new DatabaseRegistry($catalogue, $tools, $actions, $schemas);
$sessions = new SessionStore($store, $envelope, $principal, 3600, $clock);
$factory = new ServerFactory($definitions, $sessions, $settings);
$status = (new ProtocolOutput())->run(static fn (): int => (int) $factory->create()->run(
	new StdioTransport(maxLineBytes: $settings->get('max_request_bytes'), wire: $factory->wireInput())));
fwrite(STDERR, Json::encode(['final' => true, 'used' => memory_get_usage(), 'allocated' => memory_get_usage(true), 'peak' => memory_get_peak_usage(true), 'memoryLimit' => ini_get('memory_limit')]) . "\n");
exit($status);
