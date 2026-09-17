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
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;


/**
 * Durable, principal-bound explicit grants with revocation and atomic one-shot use.
 *
 * A phrase is explicit acknowledgement, not cryptographic proof of a human.
 * Client applications must obtain it from their operator; Joomla ACL remains the
 * server-side ceiling regardless of any phrase or grant.
 *
 * @since 0.1.0
 */
final class Permissions
{
	/** @var StoreInterface Durable grant storage. @since 0.1.0 */
	private StoreInterface $store;
	/** @var PrincipalInterface Current authenticated authority. @since 0.1.0 */
	private PrincipalInterface $principal;
	/** @var Catalogue Authorized database catalogue. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var Settings Validated grant policy. @since 0.1.0 */
	private Settings $settings;
	/** @var Audit Redacted durable events. @since 0.1.0 */
	private Audit $audit;
	/** @var Closure():int Injectable clock. @since 0.1.0 */
	private Closure $clock;
	/** @var string Principal fingerprint, never supplied by a request. @since 0.1.0 */
	private string $principalKey;

	/** @param StoreInterface $store Storage. @param PrincipalInterface $principal Authority. @param Catalogue $catalogue Catalogue. @param Settings $settings Policy. @param Audit $audit Audit. @param callable $clock Clock. @since 0.1.0 */
	public function __construct(StoreInterface $store, PrincipalInterface $principal, Catalogue $catalogue, Settings $settings, Audit $audit, callable $clock)
	{
		$this->store = $store;
		$this->principal = $principal;
		$this->catalogue = $catalogue;
		$this->settings = $settings;
		$this->audit = $audit;
		$this->clock = Closure::fromCallable($clock);
		$this->principalKey = hash('sha256', $principal->getId());
	}

	/**
	 * Create an expiring request with an exact random acknowledgement phrase.
	 *
	 * @param array<string,mixed> $input Validated permission tool input.
	 * @return array<string,mixed> Original request/phrase contract.
	 * @since 0.1.0
	 */
	public function request(array $input): array
	{
		$requested = $input['toolsets'] ?? [];

		if (!is_array($requested) || !array_is_list($requested) || count($requested) > 64
			|| array_filter($requested, static fn (mixed $value): bool => !is_string($value)) !== [])
		{
			throw new OperationException('INVALID_PERMISSION_REQUEST', 'A bounded list of toolset names is required.');
		}

		$toolsets = array_values(array_unique($requested));
		sort($toolsets, SORT_STRING);
		$duration = $input['duration'] ?? '';
		$reason = is_string($input['reason'] ?? null) ? preg_replace('/\s+/u', ' ', trim($input['reason'])) : null;

		if (count($toolsets) < 1 || !in_array($duration, ['once', '30-minutes', 'indefinite'], true)
			|| ($duration === 'indefinite' && !$this->settings->get('allow_indefinite'))
			|| !is_string($reason) || strlen($reason) < 3 || strlen($reason) > 500 || str_contains($reason, "\0"))
		{
			throw new OperationException('INVALID_PERMISSION_REQUEST', 'The requested scope, duration or explanation is invalid or disabled.');
		}

		$this->requireScope($toolsets);
		$now = ($this->clock)();

		if (count($this->store->find('permission_request', ['principal_key' => $this->principalKey, 'status' => 'pending', 'expires_at' => ['gt', $now]], 1000)) >= 1000)
		{
			throw new OperationException('REQUEST_LIMIT', 'The pending permission request limit has been reached.');
		}

		$id = Json::uuid();
		$expires = $now + $this->settings->get('request_ttl');
		$site = $this->settings->get('site_alias');
		$label = match ($duration)
		{
			'once' => 'ONE OPERATION', '30-minutes' => '30 MINUTES', 'indefinite' => 'INDEFINITELY UNTIL REVOKED',
		};
		$code = strtoupper(rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '='));
		$phrase = 'I GRANT ' . $label . ' ON ' . $site . ' FOR ' . implode(', ', $toolsets) . ' — ' . $code;
		$scope = ['site' => $site, 'toolsets' => $toolsets];
		$this->store->transaction(function () use ($id, $scope, $duration, $reason, $phrase, $expires, $now): void
		{
			$this->store->insert('permission_request', [
				'uuid' => $id, 'principal_key' => $this->principalKey, 'scope_json' => Json::encode($scope),
				'duration' => $duration, 'reason' => $reason, 'acknowledgement' => $phrase,
				'status' => 'pending', 'expires_at' => $expires, 'created_at' => $now, 'version' => 1,
			]);
			$this->audit->record('permission.requested', 'pending', metadata: ['requestId' => $id]);
		});

		return [
			'requestId' => $id, 'acknowledgement' => $phrase, 'expiresAt' => self::iso($expires),
			'requested' => $scope + ['duration' => $duration, 'reason' => $reason],
			'instructions' => 'Show the complete request to the operator. Submit the exact acknowledgement only after the operator explicitly supplies it. A grant never overrides Joomla permissions.',
		];
	}

	/** @param string $id Request UUID. @param string $acknowledgement Exact operator phrase. @return array<string,mixed> Public grant. @since 0.1.0 */
	public function approve(string $id, string $acknowledgement): array
	{
		$id = Json::requireUuid($id);

		if (!$this->principal->isLocal() && !$this->principal->authorise('mcp.approve', 'com_joomengine_mcp'))
		{
			throw new OperationException('PERMISSION_DENIED', 'This Joomla identity may not approve MCP grants.');
		}

		return $this->store->transaction(function () use ($id, $acknowledgement): array
		{
			$now = ($this->clock)();
			$row = $this->store->one('permission_request', ['uuid' => $id, 'principal_key' => $this->principalKey, 'status' => 'pending', 'expires_at' => ['gt', $now]]);

			if ($row === null || !hash_equals($row['acknowledgement'], $acknowledgement)
				|| ($row['duration'] === 'indefinite' && !$this->settings->get('allow_indefinite')))
			{
				throw new OperationException('PERMISSION_REQUEST_UNAVAILABLE', 'The permission request or exact acknowledgement is unavailable.');
			}

			$scope = Json::decode($row['scope_json']);
			$this->requireScope($scope['toolsets']);
			$updated = $this->store->update('permission_request', ['status' => 'approved', 'version' => (int) $row['version'] + 1], ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'status' => 'pending']);

			if ($updated !== 1)
			{
				throw new OperationException('PERMISSION_REQUEST_UNAVAILABLE', 'The permission request has already been handled.');
			}

			$grant = [
				'uuid' => Json::uuid(), 'principal_key' => $this->principalKey, 'scope_json' => $row['scope_json'],
				'duration' => $row['duration'], 'request_uuid' => $id, 'remaining_uses' => $row['duration'] === 'once' ? 1 : -1,
				'revoked' => 0, 'expires_at' => $row['duration'] === '30-minutes' ? $now + 1800 : 0, 'created_at' => $now, 'version' => 1,
			];
			$this->store->insert('grant', $grant);
			$this->audit->record('permission.approved', 'granted', metadata: ['requestId' => $id, 'grantId' => $grant['uuid']]);

			return $this->publicGrant($grant);
		});
	}

	/** @return array<int,array<string,mixed>> Current principal's unexpired usable grants. @since 0.1.0 */
	public function list(): array
	{
		return array_values(array_map([$this, 'publicGrant'], array_filter($this->grants(), [$this, 'usable'])));
	}

	/** @param string $id Principal-owned grant. @return array<string,mixed> Revoked grant information. @since 0.1.0 */
	public function revoke(string $id): array
	{
		$id = Json::requireUuid($id);

		return $this->store->transaction(function () use ($id): array
		{
			$row = $this->store->one('grant', ['uuid' => $id, 'principal_key' => $this->principalKey]);

			if ($row === null)
			{
				throw new OperationException('GRANT_UNAVAILABLE', 'The requested grant is unavailable.');
			}

			if ((int) $row['revoked'] === 0 && $this->store->update('grant', ['revoked' => 1, 'version' => (int) $row['version'] + 1], ['id' => (int) $row['id'], 'version' => (int) $row['version']]) !== 1)
			{
				throw new OperationException('GRANT_CHANGED', 'The grant changed concurrently; retry revocation against its current state.');
			}

			$this->audit->record('permission.revoked', 'revoked', metadata: ['grantId' => $id]);

			return $this->publicGrant($row) + ['revoked' => true];
		});
	}

	/**
	 * Select the narrowest usable grant for a toolset, without consuming it.
	 *
	 * @param string $toolset Required current action scope.
	 * @return string Grant UUID; trusted local console needs no Joomla grant.
	 * @since 0.1.0
	 */
	public function authorize(string $toolset): string
	{
		if ($this->principal->isLocal())
		{
			return '';
		}

		$this->requireScope([$toolset]);
		$grants = $this->grants();
		usort($grants, static function (array $left, array $right): int
		{
			$rank = ['once' => 0, '30-minutes' => 1, 'indefinite' => 2];

			return [$rank[$left['duration']], (int) $left['created_at']] <=> [$rank[$right['duration']], (int) $right['created_at']];
		});

		foreach ($grants as $grant)
		{
			$scope = Json::decode($grant['scope_json']);

			if ($this->usable($grant) && $scope['site'] === $this->settings->get('site_alias') && in_array($toolset, $scope['toolsets'], true))
			{
				return $grant['uuid'];
			}
		}

		throw new OperationException('PERMISSION_REQUIRED', 'An explicit, current operator grant is required for this write toolset.', ['toolset' => $toolset]);
	}

	/**
	 * Atomically consume or validate a grant inside the execution-claim transaction.
	 *
	 * @param string $id Bound grant UUID.
	 * @param string $toolset Current required scope.
	 * @return void
	 * @since 0.1.0
	 */
	public function consume(string $id, string $toolset): void
	{
		if ($this->principal->isLocal() && $id === '')
		{
			return;
		}

		$this->requireScope([$toolset]);
		$row = $this->store->one('grant', ['uuid' => Json::requireUuid($id), 'principal_key' => $this->principalKey]);
		$scope = $row === null ? [] : Json::decode($row['scope_json']);

		if ($row === null || !$this->usable($row) || ($scope['site'] ?? '') !== $this->settings->get('site_alias') || !in_array($toolset, $scope['toolsets'] ?? [], true))
		{
			throw new OperationException('GRANT_UNAVAILABLE', 'The planned grant is expired, revoked, consumed or outside the current action scope.');
		}

		$remaining = (int) $row['remaining_uses'];
		$values = ['version' => (int) $row['version'] + 1, 'remaining_uses' => $remaining > 0 ? $remaining - 1 : $remaining];

		if ($this->store->update('grant', $values, ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'revoked' => 0, 'remaining_uses' => $remaining]) !== 1)
		{
			throw new OperationException('GRANT_CHANGED', 'The grant changed concurrently and was not consumed.');
		}
	}

	/** @param array<string,mixed> $row Grant record. @return bool Current usability. @since 0.1.0 */
	private function usable(array $row): bool
	{
		return (int) $row['revoked'] === 0 && (int) $row['remaining_uses'] !== 0
			&& ((int) $row['expires_at'] === 0 || (int) $row['expires_at'] > ($this->clock)())
			&& ($row['duration'] !== 'indefinite' || $this->settings->get('allow_indefinite'));
	}

	/** @return array<int,array<string,mixed>> Bounded principal-owned grants. @since 0.1.0 */
	private function grants(): array
	{
		$rows = $this->store->find('grant', ['principal_key' => $this->principalKey, 'revoked' => 0], 10000);

		if (count($rows) >= 10000)
		{
			throw new OperationException('GRANT_LIMIT', 'Archive expired grant history before issuing additional grants.');
		}

		return $rows;
	}

	/** @param string[] $toolsets Requested scopes. @return void @since 0.1.0 */
	private function requireScope(array $toolsets): void
	{
		$allowed = [];

		foreach ($this->catalogue->all('action') as $action)
		{
			if ($action['effect'] === 'write' && ($this->principal->isLocal() || $this->principal->authorise('mcp.write', $action['asset_name'])))
			{
				$allowed[$action['toolset']] = true;
			}
		}

		foreach ($toolsets as $toolset)
		{
			if (!is_string($toolset) || !isset($allowed[$toolset]))
			{
				throw new OperationException('PERMISSION_DENIED', 'The requested write scope is unavailable to this Joomla identity.');
			}
		}
	}

	/** @param array<string,mixed> $row Persisted grant. @return array<string,mixed> Non-secret public fields. @since 0.1.0 */
	private function publicGrant(array $row): array
	{
		$scope = Json::decode($row['scope_json']);

		return [
			'id' => $row['uuid'], 'site' => $scope['site'], 'toolsets' => $scope['toolsets'], 'duration' => $row['duration'],
			'createdAt' => self::iso((int) $row['created_at']), 'expiresAt' => (int) $row['expires_at'] === 0 ? null : self::iso((int) $row['expires_at']),
			'remainingUses' => (int) $row['remaining_uses'] < 0 ? null : (int) $row['remaining_uses'],
		];
	}

	/** @param int $seconds Unix timestamp. @return string UTC ISO-8601 timestamp. @since 0.1.0 */
	public static function iso(int $seconds): string
	{
		return gmdate('Y-m-d\TH:i:s', $seconds) . '.000Z';
	}
}
