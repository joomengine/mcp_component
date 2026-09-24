<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueSynchronizer;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandHandler;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandInput;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CompiledArchives;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
};
$reject = static function (callable $operation, string $code) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $exception)
	{
		$check($exception->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $exception->getIdentifier());
		return;
	}
	throw new RuntimeException('Expected rejection ' . $code);
};
$guid = '62c69e4d-9b19-478a-a88a-6f00316ff1db';
$second = 'e623ab72-e916-40a0-9b4c-01ee20de06a9';
$valueMode = ['acceptsValue' => true, 'valueRequired' => false, 'valueOptional' => true, 'array' => false, 'default' => null];
$flagMode = ['acceptsValue' => false, 'valueRequired' => false, 'valueOptional' => false, 'array' => false, 'default' => false];
$contract = ['name' => 'componentbuilder:compile:component', 'arguments' => (object) [], 'options' => (object) [
	'component' => $valueMode, 'components' => $valueMode, 'components-file' => $valueMode,
	'options' => $valueMode, 'joomla-version' => $valueMode, 'install' => $flagMode]];
$configuration = ['command' => $contract['name'], 'contract' => $contract];
$environment = ['JCB_COMPILE_COMPONENT' => $guid, 'JCB_JOOMLA_VERSION' => '5'];
$filesRead = 0;
$freezer = new CommandInput(static fn (string $name) => $environment[$name] ?? false,
	static function (string $path) use (&$filesRead, $second): string { $filesRead++; return $second; });
$frozen = $freezer->freeze(['components-file' => '/reviewed/local-input'], $configuration, true);
$check($frozen['selectors'] === [$guid, $second] && $filesRead === 1, 'Local CLI merges frozen file bytes and environment selectors.');
$check(!isset($frozen['options']->{'components-file'}) && !isset($frozen['environment']->JCB_COMPILE_COMPONENT), 'Mutable selector paths are absent from worker input.');
$check($frozen['environment']->JCB_JOOMLA_VERSION === '5', 'Explicit per-option native environment remains frozen.');
$reject(static fn () => $freezer->freeze(['components-file' => '/server/private'], $configuration, false), 'LOCAL_FILE_FORBIDDEN');
$check($filesRead === 1, 'Denied remote files never touch the file reader.');
$reject(static fn () => $freezer->freeze([], $configuration, false), 'INVALID_INPUT');
$remote = $freezer->freeze(['components' => $guid, 'joomla-version' => '6'], $configuration, false);
$check((array) $remote['environment'] === [], 'Remote jobs do not inherit ambient compiler flags.');
$reject(static fn () => $freezer->freeze(['components' => $guid, 'install' => 'yes'], $configuration, false), 'INVALID_INPUT');
$reject(static fn () => $freezer->freeze(['components' => $guid, 'shell' => 'id'], $configuration, false), 'INVALID_INPUT');
$reject(static fn () => $freezer->freeze(['components' => 'invalid-guid'], $configuration, false), 'INVALID_INPUT');
$reject(static fn () => $freezer->freeze(['components' => $guid, 'options' => '[]'], $configuration, false), 'INVALID_INPUT');
$remote = $freezer->freeze(['components' => $guid, 'options' => '{"minify":"0","joomla_version":"6"}'], $configuration, false);
$check($remote['options']->options === '{"minify":"0","joomla_version":"6"}', 'Compiler bundle values preserve native zero/global semantics.');

$actual = $contract + ['fingerprint' => hash('sha256', Json::canonical($contract)), 'implementation' => str_repeat('a', 64),
	'description' => 'Native compiler', 'required_extensions' => ['console/NativeBuilder']];
$snapshot = str_repeat('b', 64);
$called = 0;
$handler = new CommandHandler(static function (array $config) use (&$actual): array { return $actual; }, $freezer,
	static function () use (&$snapshot): string { return $snapshot; },
	static function (array $payload, array $execution) use (&$called): array { $called++; return ['exitCode' => 0, 'verification' => ['status' => 'verified'], 'workerPrincipal' => $payload['prepared']['principal']]; });
$principal = new Principal();
$binding = ['handler' => 'jcb.command', 'track' => 'api', 'configuration' => $configuration];
$arguments = ['options' => ['components' => $guid]];
$prepared = $handler->prepare($arguments, $binding, $principal);
$check($called === 0 && $prepared['principal'] === 'joomla:42' && $prepared['local'] === false, 'Planning performs no command and freezes the actual principal.');
$result = $handler->apply($prepared, $binding, $principal, ['uuid' => $guid]);
$check($called === 1 && $result['workerPrincipal'] === 'joomla:42', 'The worker receives the same remote principal.');
$reject(static fn () => $handler->execute($arguments, $binding, $principal), 'CONFIRMATION_REQUIRED');
$reject(static fn () => $handler->apply($prepared, $binding, new Principal('joomla:99'), []), 'PLAN_CHANGED');
$snapshot = str_repeat('c', 64);
$reject(static fn () => $handler->apply($prepared, $binding, $principal, []), 'PLAN_STALE');
$snapshot = $prepared['snapshot'];
$actual['implementation'] = str_repeat('d', 64);
$reject(static fn () => $handler->apply($prepared, $binding, $principal, []), 'JCB_COMMAND_CHANGED');
$actual['implementation'] = $prepared['implementation'];
$denied = new Principal();
$denied->deny('core.admin', 'com_componentbuilder');
$reject(static fn () => $handler->prepare($arguments, $binding, $denied), 'JCB_ACCESS_DENIED');
$installerDenied = new Principal();
$installerDenied->deny('core.admin', 'com_installer');
$reject(static fn () => $handler->prepare(['options' => ['components' => $guid, 'options' => '{"install":true}']], $binding, $installerDenied), 'JCB_INSTALL_DENIED');
$reject(static fn () => $handler->prepare(['options' => ['components' => $guid, 'options' => '{" INSTALL ":"false"}']], $binding, $installerDenied), 'JCB_INSTALL_DENIED');
$check(!CommandInput::requestsInstallation(['options' => ['options' => '{"INSTALL":0}']]), 'Native empty zero values do not enable installation.');
$check($called === 1, 'All rejected authority/staleness cases leave execution untouched.');
$check($handler->verify($prepared, ['exitCode' => 0], $binding, $principal)['status'] === 'unverified', 'Missing verification is never inferred as success.');
$check($handler->verify($prepared, ['exitCode' => 1], $binding, $principal)['status'] === 'partial', 'Nonzero native exits retain possible partial effects.');

$commands = ['commands' => [$actual], 'unsupported' => []];
$routes = ['routes' => [
	['method' => 'GET', 'route' => '/v1/actual-jcb/entities/:id', 'controller' => 'entities.displayItem', 'variables' => ['id'], 'rules' => ['id' => '(\\d+)'], 'defaults' => ['component' => 'com_componentbuilder']],
	['method' => 'POST', 'route' => '/v1/actual-jcb/entities', 'controller' => 'entities.add', 'variables' => [], 'rules' => [], 'defaults' => ['component' => 'com_componentbuilder']],
], 'unsupported' => []];
foreach ($routes['routes'] as &$route)
{
	$route['required_extensions'] = ['webservices/DynamicJcb'];
}
unset($route);
$builder = new CatalogueBuilder();
$seed = $builder->build($commands, $routes);
$check(count($seed['entities']['action']) === 3 && count($seed['entities']['binding']) === 4, 'Only observed native commands/routes become actions; command has both tracks.');
$check($builder->build(['commands' => [], 'unsupported' => []], ['routes' => [], 'unsupported' => []])['entities']['action'] === [], 'Missing JCB inventory never manufactures routes or command combinations.');
$limited = $builder->build(['commands' => [$actual], 'unsupported' => ['componentbuilder:new:operation' => 'unreviewed']], $routes);
$check(count($limited['entities']['action']) === 3 && $limited['entities']['target'][1]['published'] === 0
	&& $limited['entities']['target'][1]['status'] === 'unavailable', 'Unsupported registrations remain explicit disabled diagnostics without disabling supported operations.');
$schemas = new SchemaValidator();
foreach ($seed['entities']['schema'] as $row)
{
	$schemas->document($row['document']);
	$checks++;
}
$apiBindings = array_values(array_filter($seed['entities']['binding'], static fn (array $row): bool => $row['handler'] === 'api.request'));
$create = Json::decode($apiBindings[1]['configuration']);
$check(isset($create['read_action']), 'Actual matching item route supplies independent mutation read-back.');
$store = new MemoryStore();
$assetCalls = 0;
$sync = new CatalogueSynchronizer($store, static function () use (&$assetCalls): void { $assetCalls++; });
$changes = $sync->synchronize($commands, $routes, $principal);
$check($changes['commands'] === 1 && $changes['apiRoutes'] === 2 && $assetCalls === 1, 'Explicit sync persists the graph and establishes Joomla asset identities.');
$custom = $store->one('action', ['name' => 'jcb.compile.component']);
$store->update('action', ['title' => 'Administrator custom title', 'customized' => 1], ['id' => $custom['id']]);
$again = $sync->synchronize($commands, $routes, $principal);
$check($again['preserved'] >= 1 && $store->one('action', ['id' => $custom['id']])['title'] === 'Administrator custom title', 'Catalogue refresh preserves administrator ownership.');
$denied = new Principal();
$denied->deny('core.admin', 'com_joomengine_mcp');
$reject(static fn () => $sync->synchronize($commands, $routes, $denied), 'JCB_CATALOGUE_DENIED');

// Persisted native provenance makes plugin lifecycle changes visible without a
// synchronization write, native command construction or duplicated catalogue.
$enabled = ['com_componentbuilder' => true, 'console/NativeBuilder' => true, 'webservices/DynamicJcb' => true];
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, new Settings(['joomla_version' => '6.1.3']),
	static function (string $extension) use (&$enabled): bool { return $enabled[$extension] ?? false; },
	static fn (string $entity, string $handler): bool => true);
$check(count($catalogue->all('action')) === 3 && count($catalogue->all('target')) === 1, 'Enabled native plugin owners expose their synchronized definitions.');
$stored = Json::canonical($store->find('action'));
$enabled['console/NativeBuilder'] = false;
$catalogue->refresh();
$check(count($catalogue->all('action')) === 2 && $catalogue->all('target') === [], 'Disabling the native console plugin hides commands and their targets on the next discovery.');
$reject(static fn () => $catalogue->action('jcb.compile.component'), 'DEFINITION_UNAVAILABLE');
$enabled['webservices/DynamicJcb'] = false;
$catalogue->refresh();
$check($catalogue->all('action') === [], 'Disabling the native webservices plugin hides its API actions as well.');
$reject(static fn () => $catalogue->action(substr($apiBindings[0]['name'], 0, -4)), 'DEFINITION_UNAVAILABLE');
$enabled['console/NativeBuilder'] = true;
$enabled['webservices/DynamicJcb'] = true;
$catalogue->refresh();
$check(count($catalogue->all('action')) === 3 && $stored === Json::canonical($store->find('action')) && $assetCalls === 2,
	'Reenabling native plugins restores the unchanged stored catalogue without implicit synchronization.');

$temporary = sys_get_temp_dir() . '/mcp-jcb-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$zipPath = $temporary . '/compiled-component.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
$zip->addFromString('component.xml', '<extension type="component"><name>Example</name></extension>');
$zip->close();
$archives = new CompiledArchives($temporary);
$captured = $archives->capture([$zipPath], true);
$check(count($captured) === 1 && $captured[0]['staged'] === true && hash_file('sha256', $zipPath) === $captured[0]['sha256'], 'Before-install capture preserves exact compiled ZIP bytes.');
unlink($zipPath);
$afterCleanup = $archives->capture([$zipPath]);
$check(is_file($afterCleanup[0]['path']) && $afterCleanup === $captured, 'Native installer cleanup cannot destroy the retained compiler result.');
$check((fileperms(dirname($captured[0]['path'])) & 0777) === 0700 && (fileperms($captured[0]['path']) & 0777) === 0600, 'Staged archives have owner-only permissions.');
$invalid = $temporary . '/invalid.zip';
file_put_contents($invalid, 'not an archive');
$reject(static fn () => (new CompiledArchives($temporary))->capture([$invalid]), 'JCB_ARTIFACT_INVALID');
$outside = tempnam(sys_get_temp_dir(), 'mcp-jcb-outside-');
$reject(static fn () => $archives->capture([$outside], true), 'JCB_ARTIFACT_MISSING');
unlink($outside);
unlink($invalid);
unlink($captured[0]['path']);
rmdir(dirname($captured[0]['path']));
rmdir($temporary);

echo 'JCB authority, frozen-input and catalogue contracts passed: ' . $checks . PHP_EOL;
