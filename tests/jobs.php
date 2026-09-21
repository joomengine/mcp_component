<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Job\Artifacts;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
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
$base = sys_get_temp_dir() . '/mcp-jobs-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
mkdir($base . '/native', 0700);
$now = time();
$clock = static function () use (&$now): int
{
	return $now;
};
$store = new MemoryStore();
$owner = new Principal('joomla:17', 'api', [1]);
$other = new Principal('joomla:18', 'api', [1]);
$envelope = new Envelope(str_repeat('job-test-secret-', 3));
$files = new Artifacts($store, $owner, $base . '/owned', [$base . '/native'], $clock, 4096, 8192, 60);
$otherFiles = new Artifacts($store, $other, $base . '/owned', [$base . '/native'], $clock, 4096, 8192, 60);
$tickets = [];
$launches = 0;
$launch = static function (string $id, string $ticket) use (&$tickets, &$launches): void
{
	$tickets[$id] = $ticket;
	$launches++;
};
$finalized = 0;
$finalize = static function (array $execution, array $result, bool $settled, string $action) use ($store, &$finalized): array
{
	$updated = $store->update('execution', ['status' => $settled ? 'completed' : 'uncertain'], ['uuid' => $execution['uuid'], 'status' => 'running']);

	if ($updated !== 1)
	{
		throw new OperationException('EXECUTION_STATE_CHANGED', 'The execution was already finalized.');
	}

	$finalized++;

	if ($settled)
	{
		$store->remove('lease', ['owner_uuid' => $execution['uuid']]);
	}

	return $result + ['executionId' => $execution['uuid']];
};
$allow = true;
$authorized = static function (string $action, string $track) use (&$allow): bool
{
	return $allow && $action === 'fixture.compile' && $track === 'api';
};
$jobs = new Jobs($store, $owner, $envelope, $files, $clock, $launch, $finalize, $authorized);
$otherJobs = new Jobs($store, $other, $envelope, $otherFiles, $clock, $launch, $finalize);
$claim = static function () use ($store, $owner, &$now): array
{
	$execution = ['uuid' => Json::uuid(), 'principal_key' => hash('sha256', $owner->getId()), 'status' => 'running',
		'idempotency_key' => Json::uuid(), 'plan_uuid' => Json::uuid(), 'fingerprint' => hash('sha256', random_bytes(20)),
		'result_cipher' => '', 'created_at' => $now, 'updated_at' => $now, 'version' => 1];
	$store->insert('execution', $execution);
	$store->insert('lease', ['resource_key' => hash('sha256', $execution['uuid']), 'owner_uuid' => $execution['uuid'], 'expires_at' => $now + 120]);

	return $execution;
};
$payload = ['action' => 'fixture.compile', 'prepared' => ['definition' => 'secret-owned-input']];

try
{
	$execution = $claim();
	$queued = $jobs->enqueue($execution, $payload);
	$id = $queued['jobId'];
	$check($queued['status'] === 'queued' && !isset($queued['result']) && $launches === 1, 'Dispatch acknowledges a queue, never completion.');
	$check($jobs->forExecution($execution['uuid'])['jobId'] === $id, 'Existing execution resolves the same durable job.');
	$row = $store->one('job', ['uuid' => $id]);
	$check(!str_contains(Json::encode($row), 'secret-owned-input') && !str_contains(Json::encode($queued), $tickets[$id]), 'Frozen inputs and tickets are never public or plaintext at rest.');
	$auth = Jobs::authenticateWorker($store, $envelope, $id, $tickets[$id]);
	$check($auth === ['principal_id' => 'joomla:17', 'track' => 'api'], 'Authenticated worker restores the requesting HTTP identity.');
	$store->update('job', ['track' => 'cli'], ['uuid' => $id]);
	$reject(static fn () => Jobs::authenticateWorker($store, $envelope, $id, $tickets[$id]), 'JOB_UNAVAILABLE');
	$store->update('job', ['track' => 'api'], ['uuid' => $id]);
	$reject(static fn () => $otherJobs->status($id), 'JOB_UNAVAILABLE');
	$reject(static fn () => $otherJobs->cancel($id), 'JOB_UNAVAILABLE');
	$reject(static fn () => $jobs->enqueue($execution, $payload), 'JOB_CLAIM_INVALID');
	$reject(static fn () => $jobs->run($id, str_repeat('a', 64), static fn (): array => []), 'JOB_UNAVAILABLE');
	$allow = false;
	$reject(static fn () => $jobs->status($id), 'JOB_UNAVAILABLE');
	$reject(static fn () => $jobs->run($id, $tickets[$id], static fn (): array => []), 'JOB_UNAVAILABLE');
	$allow = true;
	$source = $base . '/native/component.zip';
	file_put_contents($source, 'verified native archive payload');
	$descriptor = ['path' => $source, 'name' => 'component.zip', 'size' => filesize($source), 'sha256' => hash_file('sha256', $source)];
	$invocations = 0;
	$result = $jobs->run($id, $tickets[$id], static function (array $frozen, callable $progress, callable $cancel) use (&$invocations, $descriptor, $jobs, $id, &$tickets, $reject, $check): array
	{
		$invocations++;
		$check($frozen['prepared']['definition'] === 'secret-owned-input' && $frozen['authority']['track'] === 'api', 'Worker receives immutable validated input under original authority.');
		$progress(25, 'Compiling approved definitions.');
		$check($jobs->status($id)['progress'] === 25 && $cancel() === false, 'Progress persists and heartbeats remain observable.');
		$reject(static fn () => $jobs->run($id, $tickets[$id], static fn (): array => []), 'JOB_UNAVAILABLE');

		return ['mutation' => ['artifacts' => [$descriptor]], 'verification' => ['status' => 'verified']];
	});
	$check($result['status'] === 'completed' && $result['progress'] === 100 && $invocations === 1 && $finalized === 1, 'One worker finalizes one claimed execution.');
	$check($store->one('lease', ['owner_uuid' => $execution['uuid']]) === null && $store->one('job', ['uuid' => $id])['payload_cipher'] === '', 'Known completion releases its lease and removes saved private inputs.');
	$check(!str_contains(Json::encode($result), $base), 'Public artifact results contain no filesystem paths.');
	$artifact = $jobs->artifacts($id)['artifacts'][0];
	$chunk = $jobs->readArtifact($artifact['artifactId'], 2, 8);
	$check(base64_decode($chunk['data'], true) === substr('verified native archive payload', 2, 8) && !$chunk['eof'], 'Artifact ranges return verified immutable bytes.');
	$reject(static fn () => $otherJobs->readArtifact($artifact['artifactId']), 'ARTIFACT_UNAVAILABLE');
	$reject(static fn () => $jobs->readArtifact($artifact['artifactId'], -1), 'INVALID_INPUT');
	$reject(static fn () => $jobs->readArtifact($artifact['artifactId'], 0, 262145), 'INVALID_INPUT');
	$reject(static fn () => $jobs->run($id, $tickets[$id], static fn (): array => []), 'JOB_UNAVAILABLE');
	$reject(static fn () => $jobs->redispatch($id), 'JOB_NOT_RETRYABLE');
	$outside = $base . '/private.txt';
	file_put_contents($outside, 'outside approved roots');
	$reject(static fn () => $files->capture($id, ['path' => $outside]), 'ARTIFACT_INVALID');
	symlink($source, $base . '/native/link.zip');
	$reject(static fn () => $files->capture($id, ['path' => $base . '/native/link.zip']), 'ARTIFACT_INVALID');
	$reject(static fn () => $files->capture($id, ['path' => $source, 'sha256' => str_repeat('0', 64)]), 'ARTIFACT_CHANGED');
	file_put_contents($base . '/owned/' . $artifact['artifactId'] . '.bin', 'tampered');
	$reject(static fn () => $jobs->readArtifact($artifact['artifactId']), 'ARTIFACT_CHANGED');
	$largeFiles = new Artifacts($store, $owner, $base . '/owned', [$base . '/native'], $clock, 1048576, 2097152, 60);
	$largeBytes = str_repeat('verified block bytes ', 30000);
	file_put_contents($base . '/native/large.zip', $largeBytes);
	$large = $largeFiles->capture($id, ['path' => $base . '/native/large.zip']);
	$crossing = $largeFiles->read($large['artifactId'], 262140, 20);
	$check(base64_decode($crossing['data'], true) === substr($largeBytes, 262140, 20), 'An artifact range crossing hash-block boundaries verifies both blocks.');
	$end = $largeFiles->read($large['artifactId'], strlen($largeBytes), 1);
	$check($end['eof'] && $end['length'] === 0 && $end['data'] === '', 'An exact-end artifact range is an empty verified EOF.');
	$mutable = fopen($base . '/owned/' . $large['artifactId'] . '.bin', 'r+b');
	fseek($mutable, 262150);
	fwrite($mutable, 'changed!');
	fclose($mutable);
	$reject(static fn () => $largeFiles->read($large['artifactId'], 262144, 20), 'ARTIFACT_CHANGED');

	$cancelExecution = $claim();
	$cancelled = $jobs->enqueue($cancelExecution, $payload);
	$stopped = $jobs->cancel($cancelled['jobId']);
	$check($stopped['status'] === 'cancelled' && $stopped['result']['verification']['effects'] === 'not-started', 'Queued cancellation acknowledges that mutation never started.');
	$check($store->one('lease', ['owner_uuid' => $cancelExecution['uuid']]) === null, 'Queued cancellation releases only its own claim.');
	$reject(static fn () => $jobs->run($cancelled['jobId'], $tickets[$cancelled['jobId']], static fn (): array => []), 'JOB_UNAVAILABLE');

	$runningExecution = $claim();
	$running = $jobs->enqueue($runningExecution, $payload);
	$runningId = $running['jobId'];
	$stopped = $jobs->run($runningId, $tickets[$runningId], static function (array $frozen, callable $progress, callable $cancel) use ($jobs, $runningId, $check): array
	{
		$state = $jobs->cancel($runningId);
		$check($state['status'] === 'running' && $state['cancelRequested'] && $cancel(), 'Running cancellation remains a request until the worker acknowledges termination.');
		throw new OperationException('WORKER_CANCELLED', 'Potentially partial mutation.');
	});
	$check($stopped['status'] === 'uncertain' && $stopped['reconciliationRequired'], 'Termination after start records possible partial effects.');
	$check($store->one('lease', ['owner_uuid' => $runningExecution['uuid']]) !== null, 'Uncertain cancellation retains the installation write lease.');

	$lostExecution = $claim();
	$lost = $jobs->enqueue($lostExecution, $payload);
	$lostId = $lost['jobId'];
	$store->update('job', ['status' => 'running', 'worker_uuid' => Json::uuid(), 'lease_until' => $now - 1, 'token_hash' => ''], ['uuid' => $lostId]);
	$check($jobs->status($lostId)['status'] === 'uncertain' && $store->one('lease', ['owner_uuid' => $lostExecution['uuid']]) !== null, 'A lost worker is reconciled honestly without replay or lease release.');
	$reject(static fn () => $jobs->redispatch($lostId), 'JOB_NOT_RETRYABLE');
	$store->update('execution', ['status' => 'reconciled', 'result_cipher' => $envelope->encrypt(Json::encode(['reconciliation' => ['outcome' => 'partial']]),
		'execution:' . hash('sha256', $owner->getId()) . ':' . $lostExecution['uuid'])], ['uuid' => $lostExecution['uuid']]);
	$reconciled = $jobs->status($lostId);
	$check($reconciled['status'] === 'reconciled' && !$reconciled['reconciliationRequired'] && $reconciled['result']['reconciliation']['outcome'] === 'partial', 'Job status reflects explicit administrator reconciliation without replaying the mutation.');
	$check($store->one('job', ['uuid' => $lostId])['payload_cipher'] === '', 'Reconciliation clears retained private worker input.');

	$retryExecution = $claim();
	$retry = $jobs->enqueue($retryExecution, $payload);
	$retryId = $retry['jobId'];
	$oldTicket = $tickets[$retryId];
	$jobs->redispatch($retryId);
	$check($tickets[$retryId] !== $oldTicket && $jobs->forExecution($retryExecution['uuid'])['jobId'] === $retryId, 'Redispatch fences the old ticket and preserves the original claim.');
	$reject(static fn () => $jobs->run($retryId, $oldTicket, static fn (): array => []), 'JOB_UNAVAILABLE');
	$jobs->cancel($retryId);
	$now += 61;
	$check($files->purgeExpired() === 2 && $jobs->artifacts($id)['artifacts'] === [], 'Expired owned artifacts are removed with their metadata.');
	$check($otherJobs->listing()['jobs'] === [], 'Cross-principal listings disclose no job metadata.');
	echo Json::encode(['checks' => $checks, 'jobs' => 'passed with transactional persistence double', 'artifacts' => 'passed with actual filesystem copies', 'liveJoomla' => 'not run by this contract suite']) . PHP_EOL;
}
finally
{
	$remove = static function (string $path) use (&$remove): void
	{
		if (is_dir($path) && !is_link($path))
		{
			foreach (scandir($path) as $name)
			{
				if ($name !== '.' && $name !== '..')
				{
					$remove($path . '/' . $name);
				}
			}

			rmdir($path);
		}
		else
		{
			unlink($path);
		}
	};
	$remove($base);
	restore_error_handler();
}
