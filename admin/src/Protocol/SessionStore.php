<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Closure;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;


/**
 * Encrypted, principal-bound SDK sessions with compare-and-swap persistence.
 *
 * MCP session identifiers never authorize Joomla requests. Joomla authenticates
 * before this store is constructed; concurrent stale session writes fail closed.
 *
 * @since 0.1.0
 */
final class SessionStore implements SessionStoreInterface
{
	/** @var StoreInterface Durable database. @since 0.1.0 */
	private StoreInterface $store;
	/** @var Envelope Installation encryption key. @since 0.1.0 */
	private Envelope $envelope;
	/** @var string Current principal fingerprint. @since 0.1.0 */
	private string $principalKey;
	/** @var int Session lifetime in seconds. @since 0.1.0 */
	private int $ttl;
	/** @var Closure():int Unix clock. @since 0.1.0 */
	private Closure $clock;
	/** @var array<string,int> Versions read by this request only. @since 0.1.0 */
	private array $versions = [];

	/** @param StoreInterface $store Storage. @param Envelope $envelope Encryption. @param PrincipalInterface $principal Identity. @param int $ttl Lifetime. @param callable $clock Clock. @since 0.1.0 */
	public function __construct(StoreInterface $store, Envelope $envelope, PrincipalInterface $principal, int $ttl, callable $clock)
	{
		$this->store = $store;
		$this->envelope = $envelope;
		$this->principalKey = hash('sha256', $principal->getId());
		$this->ttl = $ttl;
		$this->clock = Closure::fromCallable($clock);
	}

	/** @inheritDoc */
	public function exists(Uuid $id): bool
	{
		return $this->row($id) !== null;
	}

	/** @inheritDoc */
	public function read(Uuid $id): string|false
	{
		$row = $this->row($id);

		if ($row === null)
		{
			return false;
		}

		$this->versions[$id->toRfc4122()] = (int) $row['version'];

		try
		{
			return $this->envelope->decrypt($row['data_cipher'], $this->context($id));
		}
		catch (Throwable)
		{
			return false;
		}
	}

	/** @inheritDoc */
	public function write(Uuid $id, string $data): bool
	{
		if (strlen($data) > 1048576)
		{
			return false;
		}

		$key = $id->toRfc4122();
		$values = ['data_cipher' => $this->envelope->encrypt($data, $this->context($id)), 'expires_at' => ($this->clock)() + $this->ttl];

		try
		{
			if (!isset($this->versions[$key]))
			{
				$this->store->insert('session', $values + ['uuid' => $key, 'principal_key' => $this->principalKey, 'version' => 1]);
				$this->versions[$key] = 1;

				return true;
			}

			$version = $this->versions[$key];
			$affected = $this->store->update('session', $values + ['version' => $version + 1], [
				'uuid' => $key, 'principal_key' => $this->principalKey, 'version' => $version,
				'expires_at' => ['gt', ($this->clock)()],
			]);

			if ($affected === 1)
			{
				$this->versions[$key]++;
			}

			return $affected === 1;
		}
		catch (Throwable)
		{
			return false;
		}
	}

	/** @inheritDoc */
	public function destroy(Uuid $id): bool
	{
		unset($this->versions[$id->toRfc4122()]);
		$this->store->remove('session', ['uuid' => $id->toRfc4122(), 'principal_key' => $this->principalKey]);

		return true;
	}

	/** @inheritDoc */
	public function gc(): array
	{
		$expired = $this->store->find('session', ['principal_key' => $this->principalKey, 'expires_at' => ['lte', ($this->clock)()]], 100);
		$removed = [];

		foreach ($expired as $row)
		{
			if ($this->store->remove('session', ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'expires_at' => ['lte', ($this->clock)()]]) === 1)
			{
				$removed[] = Uuid::fromString($row['uuid']);
			}
		}

		return $removed;
	}

	/** @param Uuid $id Protocol identifier. @return ?array<string,mixed> Live current-principal session. @since 0.1.0 */
	private function row(Uuid $id): ?array
	{
		return $this->store->one('session', ['uuid' => $id->toRfc4122(), 'principal_key' => $this->principalKey, 'expires_at' => ['gt', ($this->clock)()]]);
	}

	/** @param Uuid $id Protocol identifier. @return string AEAD associated data. @since 0.1.0 */
	private function context(Uuid $id): string
	{
		return 'mcp-session:' . $this->principalKey . ':' . $id->toRfc4122();
	}
}
