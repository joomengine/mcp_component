<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\DefinitionSnapshot;
use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;
use VDM\Component\JoomEngineMcp\Administrator\Security\LocalPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/integration/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
require __DIR__ . '/JcbStdioFixture.php';
require dirname(__DIR__) . '/integration/HttpFixture.php';
$db = $container->get(DatabaseInterface::class);
$store = new JoomlaStore($db);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void
{
	if (!$ok)
	{
		throw new RuntimeException($label);
	}

	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
$decode = static fn (string $value): array => json_decode($value, true, 128, JSON_THROW_ON_ERROR);
$sorted = static function (array $values): array
{
	$values = array_values(array_unique($values));
	sort($values, SORT_STRING);
	return $values;
};
$track = getenv('MCP_TEST_JCB_TRANSPORT') ?: 'cli';
$check(in_array($track, ['cli', 'api'], true), 'Fixture selects one real authority track');
$principal = new LocalPrincipal($app);
$snapshot = new DefinitionSnapshot($db);
$beforeInventory = $snapshot->fingerprint();
$inventory = (new PhpProcess(PHP_BINARY, JPATH_COMPONENT . '/cli/jcb.php', JPATH_ROOT))->run([
	'protocol' => 'joomengine-worker/1', 'operation' => 'jcb.inventory',
	'authority' => ['id' => $principal->getId(), 'track' => 'cli'],
], 90, 8388608, static fn (): bool => false);
$check(!isset($inventory['error']) && isset($inventory['commands']['commands'], $inventory['api']['routes']),
	'Installed isolated worker inventories actual native commands and API routes');
$check(hash_equals($beforeInventory, $snapshot->fingerprint()), 'Discovery does not mutate any persisted JCB definition');
foreach (['behaviour', 'system', 'console'] as $group)
{
	PluginHelper::importPlugin($group, null, true, $app->getDispatcher());
}
$app->getDispatcher()->dispatch(ApplicationEvents::BEFORE_EXECUTE, new ApplicationEvent(ApplicationEvents::BEFORE_EXECUTE, $app));
$registered = [];
foreach ($app->getAllCommands() as $command)
{
	if (str_starts_with((string) $command->getName(), 'componentbuilder:'))
	{
		$registered[] = $command->getName();
	}
}
$supported = array_column($inventory['commands']['commands'], null, 'name');
$unsupported = $inventory['commands']['unsupported'] ?? [];
$check(count($registered) > 1 && $sorted($registered) === $sorted(array_merge(array_keys($supported), array_keys($unsupported))),
	'Every registered JCB command is either executable or explicitly diagnosed unavailable');
$provider = $store->one('provider', ['name' => 'jcb.installed']);
$check($provider !== null, 'Explicit native sync persists the JCB provider');
$definition = $decode($provider['definition']);
$check(Json::canonical($definition['unsupportedCommands'] ?? []) === Json::canonical($unsupported),
	'Persisted unsupported diagnostics exactly match installed native capabilities');
$bindings = $store->find('binding', ['provider_id' => (int) $provider['id']], 10000);
$bound = ['cli' => [], 'api' => []];
$apiRoutes = [];
foreach ($bindings as $binding)
{
	$config = $decode($binding['configuration']);
	if ($binding['handler'] === 'jcb.command')
	{
		$name = $config['command'];
		$native = $supported[$name] ?? null;
		$action = $store->one('action', ['id' => (int) $binding['action_id']]);
		$check($native !== null && $action !== null && $action['effect'] === 'write'
			&& ($config['async'] ?? false) === true && ($decode($binding['params'])['async'] ?? false) === true
			&& $config['implementation'] === $native['implementation']
			&& Json::canonical($config['contract']) === Json::canonical(['arguments' => $native['arguments'], 'options' => $native['options']]),
			'Persisted native contract, source and confirmed job semantics: ' . $binding['name']);
		$bound[$binding['track']][] = $name;
	}
	elseif ($binding['handler'] === 'api.request')
	{
		$route = $decode($binding['definition'])['nativeRoute'] ?? [];
		$apiRoutes[] = Json::canonical($route);
	}
}
foreach (['cli', 'api'] as $bindingTrack)
{
	$check($sorted($bound[$bindingTrack]) === $sorted(array_keys($supported))
		&& count($bound[$bindingTrack]) === count($supported), 'Exact executable native coverage on ' . $bindingTrack);
}
foreach ($unsupported as $name => $reason)
{
	$target = $store->one('target', ['provider_id' => (int) $provider['id'], 'name' => $name]);
	$check($target !== null && (int) $target['published'] === 0 && $target['status'] === 'unavailable'
		&& ($decode($target['definition'])['unavailableReason'] ?? '') === $reason,
		'Unavailable native registration stays disabled with its actual reason: ' . $name);
}
$check($sorted($apiRoutes) === $sorted(array_map([Json::class, 'canonical'], $inventory['api']['routes'])),
	'Persisted API bindings match actual installed owned routes, including an empty native API');

// This complete Demo J6 definition is shipped in the pinned JCB installer SQL.
$demo = '1c20aec5-bf1a-44e7-9deb-d1c920ca591d';
$check((bool) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__componentbuilder_joomla_component')
	. ' WHERE guid = ' . $db->quote($demo))->loadResult(), 'The native installer supplied the complete Demo J6 component');
$previousUmask = umask(0022);
$client = new JcbStdioFixture();
$http = null;
$evidence = ['track' => $track, 'nativeCommands' => count($registered), 'executableCommands' => count($supported),
	'unavailableCommands' => $unsupported, 'nativeApiRoutes' => count($inventory['api']['routes']), 'jobs' => []];
$wait = static function (string $job) use (&$client): array
{
	$deadline = microtime(true) + 900;
	do
	{
		$state = $client->tool('joomla_job_status', ['jobId' => $job]);
		if (!in_array($state['status'], ['queued', 'running', 'cancelling'], true))
		{
			return $state;
		}
		usleep(500000);
	}
	while (microtime(true) < $deadline);
	throw new RuntimeException('The actual installed JCB job did not finish within its bounded acceptance deadline.');
};
$start = static function (string $action, array $options) use (&$client, $track, $check, $store, $snapshot): array
{
	$beforePlan = $snapshot->fingerprint();
	$plan = $client->tool('joomla_action_write_plan', ['action' => $action, 'transport' => $track,
		'idempotencyKey' => Json::uuid(), 'input' => ['options' => $options]]);
	$check(is_string($plan['confirmationToken'] ?? null), 'Actual JCB preflight issues a principal-bound plan: ' . $action);
	$check(hash_equals($beforePlan, $snapshot->fingerprint()), 'Planning leaves the persisted JCB definition graph unchanged: ' . $action);
	$apply = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$job = $apply['job']['jobId'] ?? null;
	$check(is_string($job), 'Confirmed JCB apply creates a durable job: ' . $action);
	$replay = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$rows = $store->find('job', ['execution_uuid' => $apply['executionId']], 10);
	$check(($replay['idempotentReplay'] ?? false) === true && $replay['executionId'] === $apply['executionId']
		&& count($rows) === 1 && $rows[0]['uuid'] === $job, 'Confirmation replay cannot launch a second native job: ' . $action);
	return [$job, $plan['confirmationToken']];
};
try
{
	$names = array_map(static fn ($tool): string => $tool->name, $client->sdk()->listTools()->tools);
	foreach (['joomla_action_write_plan', 'joomla_write_apply', 'joomla_job_status', 'joomla_job_artifact_read'] as $name)
	{
		$check(in_array($name, $names, true), 'Actual stdio discovery exposes ' . $name);
	}
	if ($track === 'api')
	{
		$request = $client->tool('joomla_permission_request', ['toolsets' => ['jcb.execute'], 'duration' => '30-minutes',
			'reason' => 'Disposable installed compiler and package acceptance']);
		$client->tool('joomla_permission_approve', ['requestId' => $request['requestId'], 'acknowledgement' => $request['acknowledgement']]);
	}
	[$packageJob] = $start('jcb.init.joomla_component', ['items' => $demo]);
	$package = $wait($packageJob);
	if ($track === 'cli' && $package['status'] !== 'completed')
	{
		// This disposable fixture contains no repository credentials. Native
		// command diagnostics are needed to distinguish execution from read-back.
		$native = $package['result']['mutation'] ?? [];
		fwrite(STDERR, json_encode(['nativeExitCode' => $native['exitCode'] ?? null, 'nativeError' => $native['nativeError'] ?? null,
			'stdout' => substr((string) ($native['stdout'] ?? ''), 0, 8192),
			'stderr' => substr((string) ($native['stderr'] ?? ''), 0, 8192)], JSON_THROW_ON_ERROR) . PHP_EOL);
	}
	echo json_encode(['packageOutcome' => ['status' => $package['status'], 'verification' => $package['result']['verification'] ?? null,
		'error' => $package['result']['error']['code'] ?? null]], JSON_THROW_ON_ERROR) . PHP_EOL;
	$check($package['status'] === 'completed' && ($package['result']['verification']['status'] ?? '') === 'verified',
		'Actual native package init independently verifies the already-local component');
	$packageResult = $package['result']['mutation']['package'] ?? [];
	$check(($packageResult['categories']['local'][$demo] ?? '') === 'joomla_component'
		&& count(array_filter($packageResult['readBack'] ?? [], static fn (array $row): bool => $row['value'] === $demo
			&& $row['persisted'] === true && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1)) === 1,
		'Package success retains native categories and independent persisted definition hashes');
	$evidence['jobs']['package'] = ['jobId' => $packageJob, 'status' => $package['status'], 'verification' => $package['result']['verification']];

	$compileOptions = ['component' => $demo, 'joomla-version' => '6',
		'add-build-date' => '2', 'build-date' => '2026-01-01'];
	if ($track === 'cli')
	{
		$compileOptions['install'] = true;
	}
	[$compileJob] = $start('jcb.compile.component', $compileOptions);
	$client->disconnect();
	$client = new JcbStdioFixture();
	$compile = $wait($compileJob);
	if ($track === 'cli' && $compile['status'] !== 'completed')
	{
		$native = $compile['result']['mutation'] ?? [];
		fwrite(STDERR, json_encode(['nativeExitCode' => $native['exitCode'] ?? null, 'nativeError' => $native['nativeError'] ?? null,
			'stdout' => substr((string) ($native['stdout'] ?? ''), 0, 8192),
			'stderr' => substr((string) ($native['stderr'] ?? ''), 0, 8192)], JSON_THROW_ON_ERROR) . PHP_EOL);
	}
	echo json_encode(['compilerOutcome' => ['status' => $compile['status'], 'verification' => $compile['result']['verification'] ?? null,
		'error' => $compile['result']['error']['code'] ?? null]], JSON_THROW_ON_ERROR) . PHP_EOL;
	$check($compile['status'] === 'completed' && ($compile['result']['verification']['status'] ?? '') === 'verified'
		&& ($compile['result']['mutation']['exitCode'] ?? -1) === 0,
		'Actual native Demo compilation survives stdio disconnection and verifies completion');
	$artifacts = $client->tool('joomla_job_artifacts', ['jobId' => $compileJob])['artifacts'] ?? [];
	$check($artifacts !== [], 'Real compiler job retains generated archives');
	$installations = $compile['result']['mutation']['installations'] ?? [];
	$installedManifests = [];
	if ($track === 'cli')
	{
		echo json_encode(['nativeInstallations' => $installations], JSON_THROW_ON_ERROR) . PHP_EOL;
		$check(count($installations) === count($artifacts)
			&& ($compile['result']['verification']['installationCount'] ?? 0) === count($artifacts)
			&& count(array_unique(array_column($installations, 'extensionId'))) === count($artifacts),
			'Native compile-install records one verified installation for every retained archive');
		foreach ($installations as $installation)
		{
			$query = $db->createQuery()->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'manifest_cache']))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('extension_id') . ' = ' . (int) ($installation['extensionId'] ?? 0));
			$row = $db->setQuery($query)->loadAssoc();
			$check(is_array($row) && ($installation['persisted'] ?? false) === true
				&& $row['element'] === ($installation['element'] ?? null)
				&& $row['type'] === ($installation['type'] ?? null)
				&& hash_equals((string) ($installation['sha256'] ?? ''), hash('sha256', Json::canonical($row))),
				'Independent Joomla extension read-back matches the native installation event');
			$cache = $decode($row['manifest_cache']);
			$installedManifests[Json::canonical([$row['type'], $cache['name'], $row['folder']])] = $cache['version'];
			if ($row['type'] === 'component')
			{
				$phpFiles = 0;
				$readable = true;
				foreach ([JPATH_ROOT . '/components/', JPATH_ADMINISTRATOR . '/components/'] as $directory)
				{
					$directory .= $row['element'];
					if (!is_dir($directory))
					{
						continue;
					}
					foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file)
					{
						if ($file->isFile() && strtolower($file->getExtension()) === 'php')
						{
							$phpFiles++;
							// The fixture installs as root and serves as Apache's user.
							// Root's is_readable() would incorrectly accept mode 0600.
							$readable = $readable && ($file->getPerms() & 0004) !== 0;
						}
					}
				}
				$check($phpFiles > 0 && $readable, 'Native CLI installation leaves component PHP readable by the web server under fixture mask 0022');
			}
		}
		$check(count($installedManifests) === count($artifacts), 'Installed extension manifests have distinct native identities');
	}
	foreach ($artifacts as $artifact)
	{
		$check(!is_file(rtrim((string) $app->get('tmp_path'), '/\\') . '/' . $artifact['name']),
			'Compiler jobs leave no generated archive in the public Joomla temporary directory');
		$temporary = tempnam(sys_get_temp_dir(), 'mcp-jcb-download-');
		$stream = fopen($temporary, 'wb');
		try
		{
			$offset = 0;
			do
			{
				$chunk = $client->tool('joomla_job_artifact_read', ['artifactId' => $artifact['artifactId'], 'offset' => $offset, 'length' => 262144]);
				$bytes = base64_decode($chunk['data'], true);
				$check(is_string($bytes) && $chunk['offset'] === $offset && strlen($bytes) === $chunk['length'], 'Owned artifact range matches its declared offset and length');
				fwrite($stream, $bytes);
				$offset += strlen($bytes);
			}
			while (!$chunk['eof']);
			fclose($stream);
			$stream = null;
			$check($offset === $artifact['size'] && hash_equals($artifact['sha256'], hash_file('sha256', $temporary)),
				'Full MCP artifact download matches the actual compiled archive SHA-256');
			$zip = new ZipArchive();
			$check($zip->open($temporary, ZipArchive::CHECKCONS) === true && $zip->numFiles > 0, 'Downloaded compiler artifact is a valid nonempty ZIP');
			$manifest = false;
			$installed = false;
			for ($index = 0; $index < $zip->numFiles; $index++)
			{
				$name = $zip->getNameIndex($index);
				if (str_ends_with($name, '.xml'))
				{
					$xml = @simplexml_load_string($zip->getFromIndex($index));
					$manifest = $manifest || ($xml !== false && $xml->getName() === 'extension');
					if ($track === 'cli' && $xml !== false && $xml->getName() === 'extension')
					{
						$key = Json::canonical([(string) $xml['type'], (string) $xml->name, (string) $xml['group']]);
						$installed = $installed || ($installedManifests[$key] ?? null) === (string) $xml->version;
					}
				}
			}
			$zip->close();
			$check($manifest, 'Downloaded real JCB output contains a Joomla extension manifest');
			if ($track === 'cli')
			{
				$check($installed, 'Retained archive manifest identity and version match its independently read installed extension');
			}
		}
		finally
		{
			if (is_resource($stream))
			{
				fclose($stream);
			}
			unlink($temporary);
		}
	}
	$evidence['jobs']['compiler'] = ['jobId' => $compileJob, 'status' => $compile['status'],
		'artifacts' => $artifacts, 'installations' => $installations];
	if ($track === 'cli')
	{
		$http = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
		$http->initialize();
		foreach (['joomla_job_status' => ['jobId' => $compileJob], 'joomla_job_artifact_read' => ['artifactId' => $artifacts[0]['artifactId']]] as $name => $arguments)
		{
			$denied = $http->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
			$check(($denied['result']['isError'] ?? false) === true, 'An authenticated HTTP administrator cannot read the local owner\'s ' . $name);
		}
	}

	$beforeFailure = $snapshot->fingerprint();
	[$failedJob, $failedToken] = $start('jcb.compile.component', ['component' => Json::uuid(), 'joomla-version' => '6']);
	$failed = $wait($failedJob);
	$check(in_array($failed['status'], ['partial', 'uncertain'], true) && $failed['reconciliationRequired'] === true
		&& ($failed['result']['verification']['status'] ?? '') !== 'verified', 'A real missing-component compiler failure never claims verified success');
	$check(($client->tool('joomla_job_artifacts', ['jobId' => $failedJob])['artifacts'] ?? []) === [], 'Failed real compilation invents no generated archive');
	$replay = $client->tool('joomla_write_apply', ['confirmationToken' => $failedToken]);
	$check(($replay['idempotentReplay'] ?? false) === true && count($store->find('job', ['execution_uuid' => $failed['executionId']], 10)) === 1,
		'An uncertain native result remains retained and cannot silently rerun');
	$check(hash_equals($beforeFailure, $snapshot->fingerprint()), 'Independent inspection confirms the missing-component attempt changed no JCB definition');
	$execution = $store->one('execution', ['uuid' => $failed['executionId']]);
	$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
	(new Operations($store, new Envelope((string) $app->get('secret'))))->reconcile($admin,
		(int) $execution['id'], (int) $execution['version'], 'verified_no_effect',
		'The selected component was absent. Independent definition hashes are unchanged and the actual job retained no compiled archives.', true);
	$check($store->one('lease', ['owner_uuid' => $failed['executionId']]) === null
		&& $client->tool('joomla_job_status', ['jobId' => $failedJob])['status'] === 'reconciled',
		'Authorized inspection reconciles the failed job and releases its exact retained write lease');
	$evidence['jobs']['failedCompiler'] = ['jobId' => $failedJob, 'status' => $failed['status']];
	$client->sdk()->ping();
}
finally
{
	$client->disconnect();
	$http?->disconnect();
	umask($previousUmask);
}
$evidence['checks'] = $checks;
echo json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
