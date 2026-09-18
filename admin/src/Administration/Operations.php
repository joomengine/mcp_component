<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Administration;


use Joomla\CMS\User\User;
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Authorized administrator revocation and explicit uncertain-write reconciliation.
 *
 * These operations never invoke handlers, undo changes or replay a write. State
 * and audit updates share a transaction and an optimistic version predicate.
 *
 * @since  0.1.0
 */
final class Operations
{
	/** @var StoreInterface Atomic state persistence. @since 0.1.0 */
	private StoreInterface $store;
	/** @var Envelope Site-owned authenticated encryption. @since 0.1.0 */
	private Envelope $envelope;

	/** @param StoreInterface $store State store. @param Envelope $envelope Site encryption. @since 0.1.0 */
	public function __construct(StoreInterface $store, Envelope $envelope)
	{
		$this->store = $store;
		$this->envelope = $envelope;
	}

	/**
	 * Revoke a grant without deleting its approval or historical execution records.
	 *
	 * @param User $user Authenticated administrator supplied by Joomla.
	 * @param int $id Native grant row ID.
	 * @param int $version Last observed row revision.
	 * @return void
	 * @throws RuntimeException For denied or concurrent changes.
	 * @since 0.1.0
	 */
	public function revoke(User $user, int $id, int $version): void
	{
		$this->authorize($user, 'mcp.grants');
		$this->store->transaction(function () use ($user, $id, $version): void
		{
			$row = $this->store->one('grant', ['id' => $id, 'version' => $version]);

			if ($row === null || $this->store->update('grant', ['revoked' => 1, 'version' => $version + 1], ['id' => $id, 'version' => $version]) !== 1)
			{
				throw new RuntimeException('The grant changed. Reload it before revoking it.', 409);
			}

			$this->audit($user, 'grant.revoked.admin', 'revoked', '', '', ['grantId' => $row['uuid']]);
		});
	}

	/**
	 * Record inspected effects and release only this execution's retained lease.
	 *
	 * @param User $user Authenticated administrator supplied by Joomla.
	 * @param int $id Native execution ID.
	 * @param int $version Last observed revision.
	 * @param string $outcome Explicit inspected outcome, not inferred success.
	 * @param string $note Non-secret evidence recorded in the encrypted result.
	 * @param bool $acknowledged Whether the operator explicitly acknowledges inspection.
	 * @return void
	 * @throws RuntimeException For denied, active, invalid or concurrent operations.
	 * @since 0.1.0
	 */
	public function reconcile(User $user, int $id, int $version, string $outcome, string $note, bool $acknowledged): void
	{
		$this->authorize($user, 'mcp.reconcile');
		$note = trim($note);

		if (!$acknowledged || !in_array($outcome, ['verified_complete', 'verified_no_effect', 'partial'], true)
			|| strlen($note) < 10 || strlen($note) > 4000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $note))
		{
			throw new RuntimeException('Record the inspected effects and explicitly acknowledge reconciliation.', 400);
		}

		$this->store->transaction(function () use ($user, $id, $version, $outcome, $note): void
		{
			$row = $this->store->one('execution', ['id' => $id, 'version' => $version]);

			if ($row === null || !in_array($row['status'], ['running', 'uncertain'], true))
			{
				throw new RuntimeException('This execution is not awaiting reconciliation.', 409);
			}

			$lease = $this->store->one('lease', ['owner_uuid' => $row['uuid']]);

			if ($row['status'] === 'running' && $lease !== null && (int) $lease['expires_at'] > time())
			{
				throw new RuntimeException('The execution still has an active worker lease. Do not release it while its worker may be running.', 409);
			}

			$context = 'execution:' . $row['principal_key'] . ':' . $row['uuid'];
			$result = $row['result_cipher'] === '' ? ['executionId' => $row['uuid']]
				: Json::decode($this->envelope->decrypt($row['result_cipher'], $context));
			$result['reconciliation'] = ['outcome' => $outcome, 'actorId' => (int) $user->id, 'assessedAt' => gmdate('c'), 'note' => $note, 'replayed' => false];

			if ($this->store->update('execution', ['status' => 'reconciled', 'version' => $version + 1, 'updated_at' => time(),
				'result_cipher' => $this->envelope->encrypt(Json::encode($result), $context)], ['id' => $id, 'version' => $version, 'status' => $row['status']]) !== 1)
			{
				throw new RuntimeException('The execution changed during reconciliation. Reload it.', 409);
			}

			$this->store->update('plan', ['status' => 'reconciled'], ['uuid' => $row['plan_uuid'], 'principal_key' => $row['principal_key']]);
			$this->store->remove('lease', ['owner_uuid' => $row['uuid']]);
			$this->audit($user, 'write.reconciled.admin', $outcome, $row['plan_uuid'], $row['uuid'], ['reasonCode' => 'operator-inspected']);
		});
	}

	/** @param User $user Joomla identity. @param string $action Required administrator permission. @return void @since 0.1.0 */
	private function authorize(User $user, string $action): void
	{
		if ((int) $user->id < 1 || $user->guest || $user->block || !$user->authorise('core.manage', 'com_joomengine_mcp')
			|| !$user->authorise($action, 'com_joomengine_mcp'))
		{
			throw new RuntimeException('This administrator action is not authorized.', 403);
		}
	}

	/** @param User $user Actor. @param string $event Audit event. @param string $outcome Outcome. @param string $plan Plan UUID. @param string $execution Execution UUID. @param array $metadata Redacted metadata. @return void @since 0.1.0 */
	private function audit(User $user, string $event, string $outcome, string $plan, string $execution, array $metadata): void
	{
		$this->store->insert('audit', ['uuid' => Json::uuid(), 'principal_key' => hash('sha256', 'joomla:' . (int) $user->id),
			'actor_id' => (int) $user->id, 'event' => $event, 'action_name' => '', 'plan_uuid' => $plan,
			'execution_uuid' => $execution, 'outcome' => $outcome, 'metadata' => Json::encode((object) $metadata), 'created_at' => time()]);
	}
}
