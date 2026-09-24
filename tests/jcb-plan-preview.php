<?php
/**
 * @package    JoomEngine.Mcp
 * @created    24 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandHandler;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandInput;
use VDM\Component\JoomEngineMcp\Administrator\Job\Artifacts;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
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
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
};
$reject = static function (callable $operation, string $code) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $error->getIdentifier());
		return;
	}
	throw new RuntimeException('Expected rejection: ' . $code);
};
$guid = '62c69e4d-9b19-478a-a88a-6f00316ff1db';
$repositoryGuid = 'e623ab72-e916-40a0-9b4c-01ee20de06a9';
$value = ['acceptsValue' => true, 'valueRequired' => false, 'valueOptional' => true, 'array' => false, 'default' => null];
$flag = ['acceptsValue' => false, 'valueRequired' => false, 'valueOptional' => false, 'array' => false, 'default' => false];
$contract = ['name' => 'componentbuilder:compile:component', 'arguments' => (object) [], 'options' => (object) [
	'components' => $value, 'components-file' => $value, 'options' => $value, 'joomla-version' => $value,
	'backup' => $flag, 'repository' => $flag, 'install' => $flag]];
$contract['fingerprint'] = hash('sha256', Json::canonical($contract));
$contract['implementation'] = str_repeat('a', 64);
$snapshot = str_repeat('b', 64);
$environment = ['JCB_JOOMLA_VERSION' => '5', 'JCB_BACKUP' => '1', 'JCB_BUILD_DATE' => '/private/build-date-secret'];
$input = new CommandInput(static fn (string $name) => $environment[$name] ?? false, static fn (string $path): string => $guid);
$mutations = 0;
$handler = new CommandHandler(static fn (array $configuration): array => $contract, $input,
	static function () use (&$snapshot): string { return $snapshot; },
	static function () use (&$mutations): array { $mutations++; return []; });
$principal = new Principal('local:preview-fixture', 'cli');
$binding = ['handler' => 'jcb.command', 'track' => 'cli', 'configuration' => ['command' => $contract['name'], 'contract' => $contract]];
$arguments = ['options' => ['components-file' => '/private/component-input-secret', 'joomla-version' => '6',
	'options' => Json::encode(['minify' => '0', 'powers' => '2', ' INSTALL ' => 'false', 'build_date' => '/private/bundle-secret', 'token' => 'compiler-token-secret'])]];
$prepared = $handler->prepare($arguments, $binding, $principal);
$preview = $handler->preview($prepared);
$check($preview['command'] === $contract['name'] && $preview['selection']['entity'] === 'component'
	&& $preview['selection']['selectors'] === [['value' => $guid]], 'Preview identifies the exact command and frozen file-derived selectors.');
$check($preview['frozenOptions']['explicit']->{'joomla-version'} === '6'
	&& $preview['frozenOptions']['environment']->{'joomla-version'} === '5'
	&& $preview['frozenOptions']['bundle']->minify === '0'
	&& $preview['frozenOptions']['bundle']->powers === '2', 'Preview preserves option layers and native zero/global text without inventing effective booleans.');
$check($preview['effects']['installExtensions'] === true, 'Native nonempty textual false still previews installer effects.');
$check($preview['revisions'] === ['commandContract' => $contract['fingerprint'], 'implementation' => $contract['implementation'],
	'definitionsAndConfiguration' => $snapshot], 'Preview identifies the exact prepared command and installed graph revisions.');
$check($preview['dependencies']['revisionScope'] === 'entire-installed-jcb-definition-and-configuration-graph', 'Revision scope accurately states the conservative whole-graph precondition.');
$serialized = Json::encode($preview);
foreach (['/private/', 'compiler-token-secret', 'component-input-secret'] as $private)
{
	$check(!str_contains($serialized, $private), 'Preview leaked private native input: ' . $private);
}
$check($mutations === 0 && str_contains($prepared['input']['options']->options, 'compiler-token-secret'), 'Public redaction preserves private frozen execution input without executing it.');

foreach (['get', 'init', 'pull', 'push', 'reset'] as $family)
{
	$package = $prepared;
	$package['command'] = 'componentbuilder:' . $family . ':field';
	$package['input'] = ['selectors' => [$guid, '/private/path-secret'], 'options' => (object) [
		'repo' => '{"url":"https://private.example","token":"repository-token-secret"}', 'force' => true], 'environment' => (object) ['JCB_GET_RESOLVE' => '1']];
	$packagePreview = $handler->preview($package);
	$check($packagePreview['selection']['count'] === 2 && $packagePreview['selection']['selectors'][1]['redacted'] === true,
		'Package selector count remains reviewable without exposing a path: ' . $family);
	$check($packagePreview['repository']['detailsRedacted'] === true && $packagePreview['frozenOptions']['explicit']->force === true
		&& $packagePreview['frozenOptions']['environment']->resolve === '1', 'Package preview retains non-secret controls: ' . $family);
	$check(!str_contains(Json::encode($packagePreview), 'secret') && !str_contains(Json::encode($packagePreview), 'private.example'),
		'Package preview exposed repository credentials or paths: ' . $family);
	$check($packagePreview['effects']['description'] !== '' && $packagePreview['effects']['atomicRollback'] === false,
		'Package plan must describe its effects without promising rollback: ' . $family);
}
$package['input']['options']->repo = $repositoryGuid;
$check($handler->preview($package)['repository'] === ['selection' => 'configured-repository', 'guid' => $repositoryGuid], 'Configured repository GUID remains visible for approval.');

$seed = (new CatalogueBuilder())->build(['commands' => [$contract]], ['routes' => []]);
$store = new MemoryStore($seed['entities']);
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
	static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
$clock = static fn (): int => time();
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$envelope = new Envelope(str_repeat('preview-fixture-secret-', 3));
$executions = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
$directory = sys_get_temp_dir() . '/mcp-preview-' . bin2hex(random_bytes(8));
$artifacts = new Artifacts($store, $principal, $directory, [], $clock);
$jobs = new Jobs($store, $principal, $envelope, $artifacts, $clock);
$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['jcb.command' => $handler]),
	$permissions, $executions, $audit, $settings, new ApiRequestBuilder(), $jobs, static function (): void {});

try
{
	$idempotencyKey = Json::uuid();
	$dry = $executor->plan('jcb.compile.component', $arguments, $idempotencyKey, true);
	$check(Json::canonical($dry['operation']['details']) === Json::canonical($preview) && $store->find('plan') === [] && $mutations === 0,
		'Dry-run returns actual frozen details without storing an executable plan or invoking JCB.');
	$plan = $executor->plan('jcb.compile.component', $arguments, $idempotencyKey);
	$resolved = $executions->resolve($plan['confirmationToken']);
	$check(Json::canonical($plan['operation']['details']) === Json::canonical($dry['operation']['details']) && $plan['operation']['definitionRevision'] === $resolved['revision'],
		'Confirmation and dry-run show the same frozen details and catalogue revision.');
	$check(Json::canonical($resolved['preview']['details']) === Json::canonical($handler->preview($resolved['payload']['prepared']))
		&& $resolved['fingerprint'] === $plan['operation']['fingerprint'], 'Persisted approval details correspond to the signed frozen execution payload.');
	$check(!str_contains($store->find('plan')[0]['preview_json'], 'compiler-token-secret')
		&& !str_contains($store->find('plan')[0]['input_cipher'], 'compiler-token-secret'), 'Public plan JSON and encrypted state do not disclose compiler bundle secrets.');
	$snapshot = str_repeat('c', 64);
	$reject(static fn () => $executor->apply($plan['confirmationToken']), 'PREFLIGHT_CHANGED');
	$check($mutations === 0 && $store->find('execution') === [], 'A changed graph rejects the approved operation before claiming or mutating.');

	echo Json::encode(['checks' => $checks, 'jcbPlanPreview' => 'passed with frozen native inputs and transactional persistence doubles']) . PHP_EOL;
}
finally
{
	rmdir($directory);
	restore_error_handler();
}
