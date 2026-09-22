<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\NativeFactory;
use VDM\Component\JoomEngineMcp\Administrator\Job\Artifacts;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\LocalPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\ExtensionAvailability;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;

require __DIR__ . '/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
$db = $container->get(DatabaseInterface::class);
$store = new JoomlaStore($db);
$principal = new LocalPrincipal($app);
$envelope = new Envelope((string) $app->get('secret'));
$clock = static fn (): int => time();
$settings = new Settings(['joomla_version' => JVERSION]);
$available = new ExtensionAvailability($db);
$catalogue = new Catalogue($store, new Authorizer(), $principal, new SchemaValidator(), $settings,
	[$available, 'enabled'], static fn (string $kind, string $key): bool => $kind !== 'binding' || in_array($key, NativeFactory::keys(), true));
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$executions = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
$child = ($argv[1] ?? '') === '--worker';
$request = $child ? Json::decode(stream_get_contents(STDIN, 8192)) : [];
$base = $child ? (string) $request['directory'] : sys_get_temp_dir() . '/mcp-live-jobs-' . bin2hex(random_bytes(8));
if (!$child)
{
	mkdir($base, 0700);
	mkdir($base . '/artifacts', 0700);
}
$tickets = [];
$jobs = new Jobs($store, $principal, $envelope, new Artifacts($store, $principal, $base . '/artifacts', [$base], $clock), $clock,
	static function (string $id, string $ticket) use (&$tickets): void
	{
		$tickets[$id] = $ticket;
	}, [$executions, 'finish'], static function (string $name, string $track) use ($catalogue): void
	{
		$catalogue->refresh();
		$resolved = $catalogue->action($name, $track);
		$catalogue->requireExecution($resolved['action']);
		$catalogue->requireExecution($resolved['binding'] + ['effect' => $resolved['action']['effect']]);
	});
$wait = static function (callable $ready, string $label): void
{
	$deadline = microtime(true) + 30;
	do
	{
		clearstatcache();
		if ($ready())
		{
			return;
		}
		usleep(20000);
	}
	while (microtime(true) < $deadline);
	throw new RuntimeException('Timed out waiting for ' . $label);
};

// Every contender and observer boots a new Joomla application and database
// connection. Only the operation callback is a fixture: its effect is a real
// append-only marker, so a duplicate invocation remains independently visible.
if ($child)
{
	try
	{
		if ($request['mode'] === 'observe')
		{
			$result = $jobs->status($request['job']);
		}
		else
		{
			$owner = Jobs::authenticateWorker($store, $envelope, $request['job'], $request['ticket']);
			if ($owner !== ['principal_id' => $principal->getId(), 'track' => 'cli'])
			{
				throw new RuntimeException('The fixture worker recovered a different authority.');
			}
			file_put_contents($base . '/' . $request['label'] . '.ready', 'ready');
			$wait(static fn (): bool => is_file($base . '/' . $request['mode'] . '.release'), 'shared claim barrier');
			$result = $jobs->run($request['job'], $request['ticket'], static function (array $payload, callable $progress, callable $cancel) use ($base, $request, $wait): array
			{
				$progress(20, 'The disposable operation is running in its own PHP worker.');
				file_put_contents($base . '/' . $request['mode'] . '.effects', "effect\n", FILE_APPEND | LOCK_EX);
				$wait(static function () use ($base, $request, $cancel): bool
				{
					if ($cancel())
					{
						throw new OperationException('WORKER_CANCELLED', 'The fixture effect started before cancellation.');
					}
					return is_file($base . '/' . $request['mode'] . '.finish');
				}, 'operation completion or cancellation');
				return ['mutation' => ['effect' => 'fixture-marker'], 'verification' => ['status' => 'verified']];
			});
		}
		echo Json::encode(['result' => $result]) . PHP_EOL;
	}
	catch (OperationException $error)
	{
		echo Json::encode(['error' => $error->getIdentifier()]) . PHP_EOL;
	}
	exit(0);
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
$workers = [];
$fixtures = [];
$start = static function (array $input, string $label) use ($base, &$workers): int
{
	$arguments = [PHP_BINARY];
	if (is_string(php_ini_loaded_file()))
	{
		$arguments = array_merge($arguments, ['-c', php_ini_loaded_file()]);
	}
	$arguments = array_merge($arguments, ['-d', 'extension_dir=' . ini_get('extension_dir'), __FILE__, '--worker']);
	$process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $base . '/' . $label . '.out', 'w'],
		2 => ['file', $base . '/' . $label . '.err', 'w']], $pipes, JPATH_ROOT, null, ['bypass_shell' => true]);
	if (!is_resource($process))
	{
		throw new RuntimeException('Could not launch installed job contender.');
	}
	$workers[] = ['process' => $process, 'label' => $label];
	fwrite($pipes[0], Json::encode($input + ['directory' => $base, 'label' => $label]));
	fclose($pipes[0]);
	return array_key_last($workers);
};
$finish = static function (int $index, bool $killed = false) use (&$workers, $wait, $base): array
{
	$process = $workers[$index]['process'];
	$label = $workers[$index]['label'];
	$wait(static fn (): bool => !proc_get_status($process)['running'], 'PHP worker ' . $label);
	proc_close($process);
	$workers[$index]['process'] = null;
	if ($killed)
	{
		return [];
	}
	$output = file_get_contents($base . '/' . $label . '.out');
	if (trim($output) === '')
	{
		throw new RuntimeException('The installed worker failed: ' . file_get_contents($base . '/' . $label . '.err'));
	}
	return Json::decode(trim($output));
};
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$operations = new Operations($store, $envelope);
$resolved = $catalogue->action('content.articles.create', 'cli');
try
{
	foreach (['race', 'cancel', 'lost'] as $mode)
	{
		$planResult = $executions->plan($resolved, ['fixture' => $mode], ['fixture' => $mode], Json::uuid(), '');
		$plan = $executions->resolve($planResult['confirmationToken']);
		$fixtures[] = ['plan' => $plan['uuid'], 'execution' => null, 'job' => null];
		$fixture = array_key_last($fixtures);
		$execution = $executions->claim($plan, $resolved);
		$fixtures[$fixture]['execution'] = $execution['uuid'];
		$queued = $jobs->enqueue($execution, ['action' => $resolved['action']['name'], 'prepared' => ['fixture' => $mode]]);
		$id = $queued['jobId'];
		$fixtures[$fixture]['job'] = $id;
		$input = ['mode' => $mode, 'job' => $id, 'ticket' => $tickets[$id]];
		$first = $start($input, $mode . '-a');
		$second = $mode === 'race' ? $start($input, $mode . '-b') : null;
		$wait(static fn (): bool => is_file($base . '/' . $mode . '-a.ready')
			&& ($second === null || is_file($base . '/' . $mode . '-b.ready')), 'independent authenticated contenders');
		file_put_contents($base . '/' . $mode . '.release', 'release');
		$wait(static fn (): bool => is_file($base . '/' . $mode . '.effects'), 'persisted running effect');
		$running = $jobs->status($id);
		$check($running['status'] === 'running' && $running['progress'] === 20, 'Another process sees durable running progress: ' . $mode);
		if ($mode === 'race')
		{
			file_put_contents($base . '/race.finish', 'finish');
			$results = [$finish($first), $finish($second)];
			$check(count(array_filter($results, static fn (array $row): bool => ($row['result']['status'] ?? '') === 'completed')) === 1
				&& count(array_filter($results, static fn (array $row): bool => ($row['error'] ?? '') === 'JOB_UNAVAILABLE')) === 1,
				'Two independent PHP workers competing for one ticket produce one winner');
			$check($store->one('lease', ['owner_uuid' => $execution['uuid']]) === null
				&& $store->one('execution', ['uuid' => $execution['uuid']])['status'] === 'completed',
				'Actual execution finalization commits completion and releases its database lease');
		}
		elseif ($mode === 'cancel')
		{
			$requested = $jobs->cancel($id);
			$check(in_array($requested['status'], ['running', 'uncertain'], true) && $requested['cancelRequested'],
				'Cancellation records a request without reporting rollback');
			$result = $finish($first)['result'];
			$check($result['status'] === 'uncertain' && ($result['result']['error']['code'] ?? '') === 'WORKER_CANCELLED'
				&& $store->one('lease', ['owner_uuid' => $execution['uuid']]) !== null,
				'Running persisted worker observes cancellation and retains potentially partial effects');
		}
		else
		{
			proc_terminate($workers[$first]['process'], 9);
			$finish($first, true);
			$check($store->one('job', ['uuid' => $id])['status'] === 'running', 'Abrupt process loss cannot fabricate a terminal outcome');
			$active = $store->one('execution', ['uuid' => $execution['uuid']]);
			$denied = false;
			try
			{
				$operations->reconcile($admin, (int) $active['id'], (int) $active['version'], 'partial', 'The disposable marker was inspected after process loss.', true);
			}
			catch (RuntimeException $error)
			{
				$denied = $error->getCode() === 409;
			}
			$check($denied, 'Recovery cannot release an execution whose worker lease is still live');
			// The worker is confirmed dead. Accelerate only its lease deadlines;
			// production status/reconciliation must derive all state transitions.
			$store->update('job', ['lease_until' => time() - 1], ['uuid' => $id, 'status' => 'running']);
			$store->update('lease', ['expires_at' => time() - 1], ['owner_uuid' => $execution['uuid']]);
			$observed = $finish($start(['mode' => 'observe', 'job' => $id], 'lost-observer'))['result'];
			$check($observed['status'] === 'uncertain' && $observed['reconciliationRequired']
				&& $store->one('lease', ['owner_uuid' => $execution['uuid']]) !== null,
				'A fresh Joomla process detects the expired worker and preserves its write lease');
		}
		$check(file_get_contents($base . '/' . $mode . '.effects') === "effect\n", 'Exactly one real callback effect occurred: ' . $mode);
		if ($mode !== 'race')
		{
			$denied = false;
			try
			{
				$jobs->redispatch($id);
			}
			catch (OperationException $error)
			{
				$denied = $error->getIdentifier() === 'JOB_NOT_RETRYABLE';
			}
			$check($denied, 'Uncertain effects cannot be redispatched: ' . $mode);
			$record = $store->one('execution', ['uuid' => $execution['uuid']]);
			$operations->reconcile($admin, (int) $record['id'], (int) $record['version'], 'partial',
				'The fixture worker stopped and exactly one disposable marker effect was inspected.', true);
			$observed = $finish($start(['mode' => 'observe', 'job' => $id], $mode . '-reconciled'))['result'];
			$check($observed['status'] === 'reconciled' && !$observed['reconciliationRequired']
				&& $store->one('lease', ['owner_uuid' => $execution['uuid']]) === null
				&& $store->one('job', ['uuid' => $id])['payload_cipher'] === '',
				'Fresh process observes administrator reconciliation and private input cleanup: ' . $mode);
		}
	}
}
finally
{
	foreach ($workers as $worker)
	{
		if (is_resource($worker['process']))
		{
			proc_terminate($worker['process'], 9);
			proc_close($worker['process']);
		}
	}
	foreach ($fixtures as $fixture)
	{
		$store->remove('audit', ['plan_uuid' => $fixture['plan']]);
		if ($fixture['job'] !== null)
		{
			$store->remove('job', ['uuid' => $fixture['job']]);
		}
		if ($fixture['execution'] !== null)
		{
			$store->remove('lease', ['owner_uuid' => $fixture['execution']]);
			$store->remove('execution', ['uuid' => $fixture['execution']]);
		}
		$store->remove('plan', ['uuid' => $fixture['plan']]);
	}
	foreach (glob($base . '/*') as $file)
	{
		is_dir($file) ? rmdir($file) : unlink($file);
	}
	rmdir($base);
}
echo Json::encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(),
	'liveDatabaseAndPhpWorkers' => true, 'operation' => 'disposable filesystem marker', 'expiredLease' => 'accelerated after confirmed process termination']) . PHP_EOL;
