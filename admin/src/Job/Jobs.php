<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Job;


use Closure;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Durable owned jobs above existing plan/grant/execution claims.
 *
 * Dispatch tickets authorize one queued worker, never a new mutation. Lost leases
 * retain uncertain outcomes and are never reclaimed automatically. Cancellation
 * after start requests termination without promising rollback of partial effects.
 *
 * @since 0.1.0
 */
final class Jobs
{
	/** @var StoreInterface Transactional persistence. @since 0.1.0 */
	private StoreInterface $store;
	/** @var PrincipalInterface Real request or reconstructed worker authority. @since 0.1.0 */
	private PrincipalInterface $principal;
	/** @var string Ownership digest. @since 0.1.0 */
	private string $principalKey;
	/** @var Envelope Context-bound encryption. @since 0.1.0 */
	private Envelope $envelope;
	/** @var Artifacts Private immutable artifacts. @since 0.1.0 */
	private Artifacts $files;
	/** @var Closure Injectable clock. @since 0.1.0 */
	private Closure $clock;
	/** @var ?Closure Fixed server-owned background dispatcher. @since 0.1.0 */
	private ?Closure $launcher;
	/** @var ?Closure Existing execution finalizer. @since 0.1.0 */
	private ?Closure $finalize;
	/** @var ?Closure Current action authorization predicate. @since 0.1.0 */
	private ?Closure $authorize;

	/** @param StoreInterface $store Storage. @param PrincipalInterface $principal Authority. @param Envelope $envelope Encryption. @param Artifacts $files Artifact store. @param callable $clock Clock. @param ?callable $launcher Launch(jobId,ticket). @param ?callable $finalize Existing Executions::finish callback. @param ?callable $authorize Reauthorize(actionName,track). @since 0.1.0 */
	public function __construct(StoreInterface $store, PrincipalInterface $principal, Envelope $envelope, Artifacts $files, callable $clock, ?callable $launcher = null, ?callable $finalize = null, ?callable $authorize = null)
	{
		$this->store = $store;
		$this->principal = $principal;
		$this->principalKey = hash('sha256', $principal->getId());
		$this->envelope = $envelope;
		$this->files = $files;
		$this->clock = Closure::fromCallable($clock);
		$this->launcher = $launcher === null ? null : Closure::fromCallable($launcher);
		$this->finalize = $finalize === null ? null : Closure::fromCallable($finalize);
		$this->authorize = $authorize === null ? null : Closure::fromCallable($authorize);
	}

	/**
	 * Bind an already committed execution claim to one immutable job.
	 *
	 * @param array $execution Existing principal-owned running execution.
	 * @param array $payload Frozen plan, resolution and prepared operation.
	 * @return array Public queued job; completion is never inferred from dispatch.
	 * @since 0.1.0
	 */
	public function enqueue(array $execution, array $payload): array
	{
		$id = Json::uuid();
		$executionId = Json::requireUuid((string) ($execution['uuid'] ?? ''));
		$action = $payload['action'] ?? $payload['resolved']['action']['name'] ?? null;

		if (!is_string($action) || $action === '' || strlen($action) > 190)
		{
			throw new OperationException('JOB_INVALID', 'A job must retain its reviewed semantic action.');
		}

		$payload['execution'] = $execution;
		$payload['action'] = $action;
		$payload['authority'] = ['principal_id' => $this->principal->getId(), 'track' => $this->principal->getTrack()];
		$now = ($this->clock)();
		$ticket = bin2hex(random_bytes(32));
		$row = ['uuid' => $id, 'principal_key' => $this->principalKey, 'principal_id' => $this->principal->getId(),
			'track' => $this->principal->getTrack(), 'action_name' => $action, 'execution_uuid' => $executionId,
			'token_hash' => hash('sha256', $ticket), 'payload_cipher' => $this->envelope->encrypt(Json::encode($payload, 4194304), $this->context('job', $id)),
			'result_cipher' => '', 'status' => 'queued', 'progress' => 0, 'message' => 'Waiting for the approved worker.',
			'cancel_requested' => 0, 'worker_uuid' => '', 'lease_until' => 0, 'created_at' => $now, 'updated_at' => $now,
			'expires_at' => $now + 300, 'version' => 1];
		$this->checkAuthority($row);
		$this->store->transaction(function () use ($executionId, $row): void
		{
			$claimed = $this->store->one('execution', ['uuid' => $executionId, 'principal_key' => $this->principalKey, 'status' => 'running']);

			if ($claimed === null || $this->store->one('job', ['execution_uuid' => $executionId]) !== null)
			{
				throw new OperationException('JOB_CLAIM_INVALID', 'The job requires an unqueued principal-owned execution claim.');
			}

			$this->store->insert('job', $row);
		});

		if ($this->launcher !== null)
		{
			try
			{
				($this->launcher)($id, $ticket);
			}
			catch (Throwable)
			{
				// A dispatcher can fail after the child starts. Keep the same job and
				// execution claim; never issue a fresh mutation or release its lease.
				$this->store->update('job', ['message' => 'Worker dispatch was not acknowledged; inspect this job before retrying.',
					'updated_at' => ($this->clock)()], ['uuid' => $id, 'status' => 'queued', 'version' => 1]);
			}
		}

		return $this->status($id);
	}

	/**
	 * Authenticate the opaque worker ticket before restoring its recorded owner.
	 *
	 * @param StoreInterface $store Storage.
	 * @param Envelope $envelope Kept in the signature to bind worker composition.
	 * @param string $id Server-issued job UUID.
	 * @param string $ticket One-time dispatch secret.
	 * @return array Stored identity and track, never caller-supplied authority.
	 * @since 0.1.0
	 */
	public static function authenticateWorker(StoreInterface $store, Envelope $envelope, string $id, string $ticket): array
	{
		$row = $store->one('job', ['uuid' => Json::requireUuid($id), 'status' => 'queued']);

		if ($row === null || strlen($ticket) !== 64 || !ctype_xdigit($ticket)
			|| !hash_equals($row['token_hash'], hash('sha256', $ticket)) || (int) $row['expires_at'] <= time()
			|| !hash_equals($row['principal_key'], hash('sha256', $row['principal_id']))
			|| !in_array($row['track'], ['api', 'cli'], true))
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The worker job is unavailable.');
		}

		// Authenticate stored identity through the encrypted payload before any
		// application constructor can use its track to select local privilege.
		$payload = Json::decode($envelope->decrypt($row['payload_cipher'], 'job:' . $row['principal_key'] . ':' . $row['uuid']), maximum: 4194304);

		if (($payload['authority']['principal_id'] ?? null) !== $row['principal_id'] || ($payload['authority']['track'] ?? null) !== $row['track'])
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The worker authority does not match the approved job.');
		}

		return ['principal_id' => $row['principal_id'], 'track' => $row['track']];
	}

	/**
	 * Claim once, execute the reauthorized operation and retain its observed result.
	 *
	 * @param string $id Job UUID.
	 * @param string $ticket Dispatch capability.
	 * @param callable $operation Receives frozen payload, progress and cancel callbacks.
	 * @return array Owned terminal state and safe outcome.
	 * @since 0.1.0
	 */
	public function run(string $id, string $ticket, callable $operation): array
	{
		$row = $this->owned($id);
		$now = ($this->clock)();

		if ($row['status'] !== 'queued' || (int) $row['expires_at'] <= $now || strlen($ticket) !== 64
			|| !hash_equals($row['token_hash'], hash('sha256', $ticket)))
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The queued job cannot be claimed by this worker.');
		}

		$worker = Json::uuid();
		$claimed = $this->store->update('job', ['status' => 'running', 'worker_uuid' => $worker, 'token_hash' => '',
			'lease_until' => $now + 120, 'updated_at' => $now, 'message' => 'The approved operation is running.', 'version' => (int) $row['version'] + 1],
			['uuid' => $id, 'principal_key' => $this->principalKey, 'status' => 'queued', 'token_hash' => $row['token_hash'], 'version' => (int) $row['version']]);

		if ($claimed !== 1)
		{
			throw new OperationException('JOB_UNAVAILABLE', 'Another worker already claimed or cancelled this job.');
		}

		$payload = $this->payload($row);
		$lastHeartbeat = 0;
		$cancel = function () use ($id, $worker, &$lastHeartbeat): bool
		{
			$current = $this->store->one('job', ['uuid' => $id, 'principal_key' => $this->principalKey]);

			if ($current === null || $current['status'] !== 'running' || $current['worker_uuid'] !== $worker)
			{
				return true;
			}

			$now = ($this->clock)();

			if ($now - $lastHeartbeat >= 15)
			{
				$this->store->update('job', ['lease_until' => $now + 120, 'updated_at' => $now], ['uuid' => $id, 'status' => 'running', 'worker_uuid' => $worker]);
				$this->store->update('lease', ['expires_at' => $now + 120], ['owner_uuid' => $current['execution_uuid']]);
				$lastHeartbeat = $now;
			}

			return (int) $current['cancel_requested'] === 1;
		};
		$progress = function (int $percent, string $message = '') use ($id, $worker, $cancel): void
		{
			if ($percent < 0 || $percent > 100 || strlen($message) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $message))
			{
				throw new OperationException('JOB_PROGRESS_INVALID', 'Job progress must be a bounded percentage and safe summary.');
			}

			if ($cancel())
			{
				throw new OperationException('WORKER_CANCELLED', 'The running job was asked to stop; partial effects need inspection.');
			}

			$this->store->update('job', ['progress' => $percent, 'message' => $message, 'updated_at' => ($this->clock)()],
				['uuid' => $id, 'status' => 'running', 'worker_uuid' => $worker]);
		};

		try
		{
			// Composition rechecks identity; this predicate additionally rechecks
			// current action ACL immediately before the first mutation boundary.
			$this->checkAuthority($row);

			if ($cancel())
			{
				$result = ['action' => $row['action_name'], 'mutation' => null, 'verification' => ['status' => 'cancelled', 'effects' => 'not-started']];
			}
			else
			{
				$result = $operation($payload, $progress, $cancel);

				if (!is_array($result))
				{
					throw new OperationException('JOB_RESULT_INVALID', 'The native worker returned no structured result.');
				}

				$result = $this->retainArtifacts($id, $result);
			}

			$verification = $result['verification']['status'] ?? 'uncertain';
			$settled = in_array($verification, ['verified', 'notPerformed', 'cancelled'], true);
			$status = $verification === 'cancelled' ? 'cancelled' : ($settled ? 'completed' : ($verification === 'partial' ? 'partial' : 'uncertain'));
		}
		catch (Throwable $error)
		{
			$result = ['action' => $row['action_name'], 'mutation' => null,
				'verification' => ['status' => 'uncertain', 'reason' => 'The worker did not verify completion. Inspect partial effects before another mutation.'],
				'error' => ['code' => $error instanceof OperationException ? $error->getIdentifier() : 'JOB_EXECUTION_UNCERTAIN',
					'message' => 'The approved worker stopped without a verified complete outcome.']];
			$settled = false;
			$status = 'uncertain';
		}

		return $this->finish($row, $payload, $result, $settled, $status, $worker);
	}

	/** @param string $id Owned UUID. @return array Current safe state. @since 0.1.0 */
	public function status(string $id): array
	{
		$row = $this->owned($id);
		$execution = $this->store->one('execution', ['uuid' => $row['execution_uuid'], 'principal_key' => $this->principalKey]);

		if (($execution['status'] ?? '') === 'reconciled' && $row['status'] !== 'reconciled')
		{
			$result = $execution['result_cipher'] === '' ? ['executionId' => $row['execution_uuid']]
				: Json::decode($this->envelope->decrypt($execution['result_cipher'], $this->context('execution', $row['execution_uuid'])));
			$this->store->update('job', ['status' => 'reconciled', 'token_hash' => '', 'payload_cipher' => '', 'lease_until' => 0,
				'message' => 'An authorized administrator recorded the inspected effects.', 'updated_at' => ($this->clock)(),
				'result_cipher' => $this->envelope->encrypt(Json::encode($result), $this->context('job-result', $id)),
				'version' => (int) $row['version'] + 1], ['uuid' => $id, 'principal_key' => $this->principalKey,
				'status' => $row['status'], 'version' => (int) $row['version']]);
			$row = $this->owned($id);
		}

		if ($row['status'] === 'running' && (int) $row['lease_until'] <= ($this->clock)())
		{
			$this->store->update('job', ['status' => 'uncertain', 'message' => 'The worker lease expired; inspect effects before reconciliation.',
				'updated_at' => ($this->clock)(), 'version' => (int) $row['version'] + 1],
				['uuid' => $id, 'status' => 'running', 'version' => (int) $row['version'], 'lease_until' => (int) $row['lease_until']]);
			$row = $this->owned($id);
		}

		return $this->publicState($row);
	}

	/** @param string $executionId Existing claim UUID. @return ?array Owned queued/running/terminal job. @since 0.1.0 */
	public function forExecution(string $executionId): ?array
	{
		$row = $this->store->one('job', ['execution_uuid' => Json::requireUuid($executionId), 'principal_key' => $this->principalKey]);

		return $row === null ? null : $this->status($row['uuid']);
	}

	/**
	 * Redispatch only a never-started job, preserving its existing execution claim.
	 * Changing the ticket atomically fences an old launcher which has not claimed.
	 * Running, cancelled and uncertain jobs can never be replayed by this method.
	 *
	 * @param string $id Owned queued UUID.
	 * @return array Same durable job after a bounded dispatch attempt.
	 * @since 0.1.0
	 */
	public function redispatch(string $id): array
	{
		$row = $this->owned($id);

		if ($row['status'] !== 'queued' || $this->launcher === null)
		{
			throw new OperationException('JOB_NOT_RETRYABLE', 'Only a never-started queued job can be redispatched with its existing claim.');
		}

		$ticket = bin2hex(random_bytes(32));
		$updated = $this->store->update('job', ['token_hash' => hash('sha256', $ticket), 'expires_at' => ($this->clock)() + 300,
			'updated_at' => ($this->clock)(), 'version' => (int) $row['version'] + 1],
			['uuid' => $id, 'principal_key' => $this->principalKey, 'status' => 'queued', 'version' => (int) $row['version']]);

		if ($updated !== 1)
		{
			throw new OperationException('JOB_STATE_CHANGED', 'The queued job changed before it could be redispatched.');
		}

		try
		{
			($this->launcher)($id, $ticket);
		}
		catch (Throwable)
		{
			$this->store->update('job', ['message' => 'Worker dispatch was not acknowledged; inspect this job before retrying.'],
				['uuid' => $id, 'status' => 'queued', 'version' => (int) $row['version'] + 1]);
		}

		return $this->status($id);
	}

	/** @param int $limit Maximum rows. @return array Owned current jobs. @since 0.1.0 */
	public function listing(int $limit = 50): array
	{
		if ($limit < 1 || $limit > 100)
		{
			throw new OperationException('INVALID_INPUT', 'Job page limits must be between 1 and 100.');
		}

		$jobs = [];

		foreach ($this->store->find('job', ['principal_key' => $this->principalKey], $limit) as $row)
		{
			try
			{
				$jobs[] = $this->status($row['uuid']);
			}
			catch (OperationException $error)
			{
				if ($error->getIdentifier() !== 'JOB_UNAVAILABLE')
				{
					throw $error;
				}
			}
		}

		return ['jobs' => $jobs];
	}

	/** @param string $id Owned UUID. @return array Cancellation request or known pre-start cancellation. @since 0.1.0 */
	public function cancel(string $id): array
	{
		$row = $this->owned($id);

		if ($row['status'] === 'queued')
		{
			return $this->store->transaction(function () use ($row): array
			{
				$updated = $this->store->update('job', ['status' => 'cancelling', 'cancel_requested' => 1, 'token_hash' => '',
					'updated_at' => ($this->clock)(), 'version' => (int) $row['version'] + 1],
					['uuid' => $row['uuid'], 'status' => 'queued', 'version' => (int) $row['version']]);

				if ($updated !== 1)
				{
					throw new OperationException('JOB_STATE_CHANGED', 'The job started while cancellation was requested; inspect its current state.');
				}

				return $this->finish($row, $this->payload($row), ['action' => $row['action_name'], 'mutation' => null,
					'verification' => ['status' => 'cancelled', 'effects' => 'not-started']], true, 'cancelled', '');
			});
		}

		if ($row['status'] === 'running')
		{
			$this->store->update('job', ['cancel_requested' => 1, 'message' => 'Cancellation requested; partial effects are not rolled back.',
				'updated_at' => ($this->clock)()], ['uuid' => $id, 'status' => 'running', 'worker_uuid' => $row['worker_uuid']]);
		}

		return $this->status($id);
	}

	/** @param string $id Owned job UUID. @return array Retained file metadata. @since 0.1.0 */
	public function artifacts(string $id): array
	{
		$this->owned($id);

		return ['jobId' => $id, 'artifacts' => $this->files->listing($id)];
	}

	/** @param string $id Artifact UUID. @param int $offset Byte offset. @param int $length Chunk bytes. @return array Verified owned file content. @since 0.1.0 */
	public function readArtifact(string $id, int $offset = 0, int $length = 65536): array
	{
		$this->owned($this->files->job($id));

		return $this->files->read($id, $offset, $length);
	}

	/** @param array $row Owned job. @param array $payload Frozen input. @param array $result Safe result. @param bool $settled Effects known. @param string $status Terminal state. @param string $worker Actual worker UUID. @return array Durable public state. @since 0.1.0 */
	private function finish(array $row, array $payload, array $result, bool $settled, string $status, string $worker): array
	{
		return $this->store->transaction(function () use ($row, $payload, $result, $settled, $status, $worker): array
		{
			$current = $this->store->one('job', ['uuid' => $row['uuid'], 'principal_key' => $this->principalKey,
				'status' => $worker === '' ? 'cancelling' : 'running', 'worker_uuid' => $worker]);

			if ($current === null)
			{
				throw new OperationException('JOB_STATE_CHANGED', 'The worker lost its job lease; effects require reconciliation.');
			}

			if ($this->finalize !== null)
			{
				$result = ($this->finalize)($payload['execution'], $result, $settled, $row['action_name']);
			}

			$updated = $this->store->update('job', ['status' => $status, 'progress' => $status === 'completed' ? 100 : (int) $current['progress'],
				'message' => $status === 'completed' ? 'The worker retained its observed outcome.' : 'Inspect the retained cancellation or uncertain outcome.',
				'payload_cipher' => '', 'result_cipher' => $this->envelope->encrypt(Json::encode($result, 8388608), $this->context('job-result', $row['uuid'])),
				'token_hash' => '', 'lease_until' => 0, 'updated_at' => ($this->clock)(), 'version' => (int) $current['version'] + 1],
				['uuid' => $row['uuid'], 'status' => $current['status'], 'worker_uuid' => $worker, 'version' => (int) $current['version']]);

			if ($updated !== 1)
			{
				throw new OperationException('JOB_STATE_CHANGED', 'The job changed during completion; inspect its execution record.');
			}

			return $this->publicState($this->store->one('job', ['uuid' => $row['uuid'], 'principal_key' => $this->principalKey]));
		});
	}

	/** @param string $id Owned UUID. @return array Current row. @since 0.1.0 */
	private function owned(string $id): array
	{
		$row = $this->store->one('job', ['uuid' => Json::requireUuid($id), 'principal_key' => $this->principalKey]);

		if ($row === null)
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The requested job is unavailable.');
		}

		$this->checkAuthority($row);

		return $row;
	}

	/** @param array $row Owned job metadata. @return void @since 0.1.0 */
	private function checkAuthority(array $row): void
	{
		if ($row['principal_id'] !== $this->principal->getId() || $row['track'] !== $this->principal->getTrack()
			|| !$this->principal->authorise('mcp.access', 'com_joomengine_mcp'))
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The requested job is unavailable.');
		}

		if ($this->authorize !== null)
		{
			try
			{
				if (($this->authorize)($row['action_name'], $row['track']) === false)
				{
					throw new OperationException('JOB_UNAVAILABLE', 'The requested job is unavailable.');
				}
			}
			catch (Throwable)
			{
				throw new OperationException('JOB_UNAVAILABLE', 'The requested job is unavailable.');
			}
		}
	}

	/** @param array $row Owned row. @return array Frozen private plan. @since 0.1.0 */
	private function payload(array $row): array
	{
		return Json::decode($this->envelope->decrypt($row['payload_cipher'], $this->context('job', $row['uuid'])), maximum: 4194304);
	}

	/** @param array $row Owned row. @return array Public state without payload, credentials or filesystem paths. @since 0.1.0 */
	private function publicState(array $row): array
	{
		$result = ['jobId' => $row['uuid'], 'executionId' => $row['execution_uuid'], 'action' => $row['action_name'], 'status' => $row['status'],
			'progress' => (int) $row['progress'], 'message' => $row['message'], 'cancelRequested' => (bool) $row['cancel_requested'],
			'createdAt' => gmdate('c', (int) $row['created_at']), 'updatedAt' => gmdate('c', (int) $row['updated_at']),
			'reconciliationRequired' => in_array($row['status'], ['partial', 'uncertain'], true)];

		if ($row['result_cipher'] !== '')
		{
			$result['result'] = Json::decode($this->envelope->decrypt($row['result_cipher'], $this->context('job-result', $row['uuid'])), maximum: 8388608);
		}

		return $result;
	}

	/** @param string $job Job UUID. @param array $result Native result. @return array Result with private paths replaced by owned artifact references. @since 0.1.0 */
	private function retainArtifacts(string $job, array $result): array
	{
		foreach (['artifacts', 'mutation'] as $key)
		{
			if ($key === 'mutation' && isset($result[$key]) && is_array($result[$key]))
			{
				$result[$key] = $this->retainArtifacts($job, $result[$key]);
			}
			elseif ($key === 'artifacts' && isset($result[$key]))
			{
				if (!is_array($result[$key]) || count($result[$key]) > 32)
				{
					throw new OperationException('ARTIFACT_LIMIT', 'The native output artifact list exceeds its bound.');
				}

				$result[$key] = array_map(fn (array $descriptor): array => isset($descriptor['path'])
					? $this->files->capture($job, $descriptor) : $descriptor, $result[$key]);
			}
		}

		return $result;
	}

	/** @param string $purpose Encrypted domain. @param string $id UUID. @return string Associated-data binding. @since 0.1.0 */
	private function context(string $purpose, string $id): string
	{
		return $purpose . ':' . $this->principalKey . ':' . $id;
	}
}
