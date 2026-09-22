<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use VDM\Joomla\Interfaces\Data\ItemsInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Isolates native verifier reads and forbids resolver-generated GUID writes.
 *
 * @since 0.1.0
 */
final class ReadOnlyItems implements ItemsInterface
{
	/** @var ItemsInterface Cloned native read provider, sharing only its database. @since 0.1.0 */
	private ItemsInterface $items;

	/** @param ItemsInterface $items Already-initialized native provider. @since 0.1.0 */
	public function __construct(ItemsInterface $items)
	{
		$this->items = clone $items;
	}

	/** @inheritDoc */
	public function ids(): array
	{
		return [];
	}

	/** @inheritDoc */
	public function table(string $table): self
	{
		$this->items->table($table);

		return $this;
	}

	/** @inheritDoc */
	public function get(array $values, string $key = 'guid'): ?array
	{
		return $this->items->get($values, $key);
	}

	/** @inheritDoc */
	public function values(array $values, string $key = 'guid', string $get = 'id'): ?array
	{
		return $this->items->values($values, $key, $get);
	}

	/** @inheritDoc */
	public function set(array $items, string $key = 'guid'): bool
	{
		throw new OperationException('JCB_VERIFY_WRITE_DENIED', 'Independent verification cannot populate or modify native definitions.');
	}

	/** @inheritDoc */
	public function delete(array $values, string $key = 'guid'): bool
	{
		throw new OperationException('JCB_VERIFY_WRITE_DENIED', 'Independent verification cannot delete native definitions.');
	}

	/** @inheritDoc */
	public function getTable(): string
	{
		return $this->items->getTable();
	}
}
