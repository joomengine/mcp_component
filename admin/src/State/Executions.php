<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\State;


use Closure;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;


/**
 * Durable plans and exactly-once execution claims, with explicit uncertain outcomes.
 *
 * Execution intent is committed before external I/O. A lost worker or uncertain
 * network write retains the site lease until an administrator reconciles it; expiry
 * alone never authorizes replay of a potentially running or completed mutation.
 *
 * @since 0.1.0
 */
final class Executions
{
	/** @var StoreInterface Transactional persistence. @since 0.1.0 */
	private StoreInterface $store;
	/** @var Envelope Context-bound encrypted state. @since 0.1.0 */
	private Envelope $envelope;
	/** @var Permissions Explicit current grants. @since 0.1.0 */
	private Permissions $permissions;
	/** @var Settings Bounded installation policy. @since 0.1.0 */
	private Settings $settings;
	/** @var Audit Durable redacted events. @since 0.1.0 */
	private Audit $audit;
	/** @var Closure():int Injectable clock. @since 0.1.0 */
	private Closure $clock;
	/** @var string Non-forgeable principal key. @since 0.1.0 */
	private string $principalKey;

	/** @param StoreInterface $store Storage. @param PrincipalInterface $principal Authority. @param Envelope $envelope Encryption. @param Permissions $permissions Grants. @param Settings $settings Policy. @param Audit $audit Audit. @param callable $clock Clock. @since 0.1.0 */
	public function __construct(StoreInterface $store, PrincipalInterface $principal, Envelope $envelope, Permissions $permissions, Settings $settings, Audit $audit, callable $clock)
	{
		$this->store = $store;
		$this->envelope = $envelope;
		$this->permissions = $permissions;
		$this->settings = $settings;
		$this->audit = $audit;
		$this->clock = Closure::fromCallable($clock);
		$this->principalKey = hash('sha256', $principal->getId());
	}

	/**
	 * Persist a validated plan; tokens contain neither credentials nor input values.
	 *
	 * @param array<string,mixed> $resolved Current authorized action/binding/revision.
	 * @param array<string,mixed> $payload Validated input and read-back preconditions.
	 * @param array<string,mixed> $preview Redacted operator preview.
	 * @param string $idempotencyKey Caller-provided stable operation UUID.
	 * @param string $grant Bound explicit grant or trusted-local empty value.
	 * @return array<string,mixed> Confirmation contract.
	 * @since 0.1.0
	 */
	public function plan(array $resolved, array $payload, array $preview, string $idempotencyKey, string $grant): array
	{
		$now = ($this->clock)();

		if (count($this->store->find('plan', ['principal_key' => $this->principalKey, 'status' => 'pending', 'expires_at' => ['gt', $now]], 1000)) >= 1000)
		{
			throw new OperationException('PLAN_LIMIT', 'The pending confirmation plan limit has been reached.');
		}

		$id = Json::uuid();
		$key = Json::requireUuid($idempotencyKey);
		$fingerprint = hash('sha256', Json::canonical([$resolved['action']['name'], $resolved['binding']['track'], $resolved['revision'], $key, $payload]));
		$secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$token = $id . '.' . $secret;
		$token .= '.' . $this->envelope->sign($token);
		$expires = $now + $this->settings->get('plan_ttl');
		$preview['fingerprint'] = $fingerprint;
		$preview['idempotencyKey'] = $key;
		$this->store->transaction(function () use ($id, $resolved, $payload, $preview, $key, $grant, $fingerprint, $token, $expires, $now): void
		{
			$this->store->insert('plan', [
				'uuid' => $id, 'principal_key' => $this->principalKey, 'action_id' => (int) $resolved['action']['id'], 'binding_id' => (int) $resolved['binding']['id'],
				'revision' => $resolved['revision'], 'token_hash' => hash('sha256', $token), 'fingerprint' => $fingerprint,
				'input_cipher' => $this->envelope->encrypt(Json::encode($payload), $this->context('plan', $id)),
				'preview_json' => Json::encode($preview), 'grant_uuid' => $grant, 'idempotency_key' => $key,
				'status' => 'pending', 'expires_at' => $expires, 'created_at' => $now, 'version' => 1,
			]);
			$this->audit->record('write.planned', 'planned', $resolved['action']['name'], $id, metadata: ['transport' => $resolved['binding']['track']]);
		});

		return ['confirmationToken' => $token, 'expiresAt' => Permissions::iso($expires), 'operation' => $preview];
	}

	/**
	 * Resolve a principal-bound confirmation without consuming it.
	 *
	 * @param string $token Opaque confirmation token.
	 * @return array<string,mixed> Internal plan plus decrypted payload.
	 * @since 0.1.0
	 */
	public function resolve(string $token): array
	{
		$parts = explode('.', $token);

		if (strlen($token) > 4096 || count($parts) !== 3 || !hash_equals($this->envelope->sign($parts[0] . '.' . $parts[1]), $parts[2]))
		{
			throw new OperationException('PLAN_UNAVAILABLE', 'The confirmation plan is unavailable.');
		}

		$id = Json::requireUuid($parts[0]);
		$row = $this->store->one('plan', ['uuid' => $id, 'principal_key' => $this->principalKey]);

		if ($row === null || !hash_equals($row['token_hash'], hash('sha256', $token)))
		{
			throw new OperationException('PLAN_UNAVAILABLE', 'The confirmation plan is unavailable.');
		}

		$row['payload'] = Json::decode($this->envelope->decrypt($row['input_cipher'], $this->context('plan', $id)));
		$row['preview'] = Json::decode($row['preview_json']);

		return $row;
	}

	/**
	 * Return an already recorded result, never reissuing its mutation.
	 *
	 * @param array<string,mixed> $plan Authenticated current plan.
	 * @return array<string,mixed>|null Recorded execution result or no execution.
	 * @since 0.1.0
	 */
	public function previous(array $plan): ?array
	{
		$row = $this->store->one('execution', ['principal_key' => $this->principalKey, 'idempotency_key' => $plan['idempotency_key']]);

		if ($row === null)
		{
			return null;
		}

		if (!hash_equals($row['fingerprint'], $plan['fingerprint']))
		{
			throw new OperationException('IDEMPOTENCY_CONFLICT', 'This idempotency key is already bound to a different operation.');
		}

		if ($row['result_cipher'] === '')
		{
			throw new OperationException('EXECUTION_UNCERTAIN', 'The operation is running or lost its worker. Inspect the execution record before taking any further write action.', ['executionId' => $row['uuid']]);
		}

		$result = Json::decode($this->envelope->decrypt($row['result_cipher'], $this->context('execution', $row['uuid'])));

		return array_replace($result, ['replayed' => true, 'idempotentReplay' => true, 'executionId' => $row['uuid']]);
	}

	/**
	 * Reject expired or changed confirmations before any precondition I/O.
	 *
	 * @param array<string,mixed> $plan Principal-owned plan.
	 * @param array<string,mixed> $resolved Current authorized resolution.
	 * @return void
	 * @since 0.1.0
	 */
	public function assertFresh(array $plan, array $resolved): void
	{
		if (($plan['status'] ?? '') !== 'pending' || (int) $plan['expires_at'] <= ($this->clock)()
			|| !hash_equals($plan['revision'], $resolved['revision'])
			|| (int) $plan['action_id'] !== (int) $resolved['action']['id']
			|| (int) $plan['binding_id'] !== (int) $resolved['binding']['id'])
		{
			throw new OperationException('PLAN_STALE', 'The confirmation expired, changed or has already been claimed.');
		}
	}

	/**
	 * Atomically claim a fresh plan, site lease, idempotency key and grant.
	 *
	 * @param array<string,mixed> $plan Validated plan, after fresh resource preconditions.
	 * @param array<string,mixed> $resolved Re-authorized action/binding/revision.
	 * @return array<string,mixed> Durable execution record.
	 * @since 0.1.0
	 */
	public function claim(array $plan, array $resolved): array
	{
		return $this->store->transaction(function () use ($plan, $resolved): array
		{
			$now = ($this->clock)();
			$fresh = $this->store->one('plan', ['id' => (int) $plan['id'], 'principal_key' => $this->principalKey, 'status' => 'pending', 'version' => (int) $plan['version'], 'expires_at' => ['gt', $now]]);

			if ($fresh === null || !hash_equals($fresh['revision'], $resolved['revision'])
				|| (int) $fresh['action_id'] !== (int) $resolved['action']['id'] || (int) $fresh['binding_id'] !== (int) $resolved['binding']['id'])
			{
				throw new OperationException('PLAN_STALE', 'The confirmation expired, changed or has already been claimed.');
			}

			if ($this->store->one('execution', ['principal_key' => $this->principalKey, 'idempotency_key' => $fresh['idempotency_key']]) !== null)
			{
				throw new OperationException('IDEMPOTENCY_CONFLICT', 'An execution already owns this operation key; read its recorded outcome rather than retrying.');
			}

			$id = Json::uuid();
			$resource = hash('sha256', 'joomengine-mcp:installation-write');

			if ($this->store->one('lease', ['resource_key' => $resource]) !== null)
			{
				throw new OperationException('WRITE_BUSY', 'A write is running or awaiting reconciliation on this Joomla installation.');
			}

			try
			{
				$this->store->insert('lease', ['resource_key' => $resource, 'owner_uuid' => $id, 'expires_at' => $now + 600]);
			}
			catch (Throwable)
			{
				throw new OperationException('WRITE_BUSY', 'Another worker acquired the installation write lease.');
			}

			$this->permissions->consume($fresh['grant_uuid'], $resolved['action']['toolset']);
			$execution = [
				'uuid' => $id, 'principal_key' => $this->principalKey, 'plan_uuid' => $fresh['uuid'],
				'idempotency_key' => $fresh['idempotency_key'], 'fingerprint' => $fresh['fingerprint'],
				'status' => 'running', 'result_cipher' => '', 'created_at' => $now, 'updated_at' => $now, 'version' => 1,
			];
			$this->store->insert('execution', $execution);

			if ($this->store->update('plan', ['status' => 'executing', 'version' => (int) $fresh['version'] + 1], ['id' => (int) $fresh['id'], 'status' => 'pending', 'version' => (int) $fresh['version']]) !== 1)
			{
				throw new OperationException('PLAN_STALE', 'Another worker already claimed this confirmation.');
			}

			$this->audit->record('write.claimed', 'running', $resolved['action']['name'], $fresh['uuid'], $id, ['transport' => $resolved['binding']['track']]);

			return $execution;
		});
	}

	/**
	 * Persist an observed outcome, retaining the lease when effects remain uncertain.
	 *
	 * @param array<string,mixed> $execution Claimed execution.
	 * @param array<string,mixed> $result Safe principal-owned result, encrypted at rest.
	 * @param bool $settled Whether completion is known; verification detail remains in the result.
	 * @param string $action Semantic action name.
	 * @return array<string,mixed> Recorded response.
	 * @since 0.1.0
	 */
	public function finish(array $execution, array $result, bool $settled, string $action): array
	{
		$result['executionId'] = $execution['uuid'];
		$status = $settled ? 'completed' : 'uncertain';
		$this->store->transaction(function () use ($execution, $result, $settled, $status, $action): void
		{
			$updated = $this->store->update('execution', [
				'status' => $status, 'result_cipher' => $this->envelope->encrypt(Json::encode($result), $this->context('execution', $execution['uuid'])),
				'updated_at' => ($this->clock)(), 'version' => 2,
			], ['uuid' => $execution['uuid'], 'principal_key' => $this->principalKey, 'status' => 'running', 'version' => 1]);

			if ($updated !== 1)
			{
				throw new OperationException('EXECUTION_STATE_CHANGED', 'The execution state changed; its effects require reconciliation.');
			}

			$this->store->update('plan', ['status' => $status], ['uuid' => $execution['plan_uuid'], 'principal_key' => $this->principalKey, 'status' => 'executing']);

			if ($settled)
			{
				$this->store->remove('lease', ['owner_uuid' => $execution['uuid']]);
			}

			$this->audit->record('write.finished', $status, $action, $execution['plan_uuid'], $execution['uuid']);
		});

		return $result;
	}

	/** @param string $purpose State domain. @param string $uuid Record identifier. @return string Principal- and record-bound authenticated data. @since 0.1.0 */
	private function context(string $purpose, string $uuid): string
	{
		return $purpose . ':' . $this->principalKey . ':' . $uuid;
	}
}
