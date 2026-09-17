<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Tests\Support;


use RuntimeException;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


/**
 * Behavioural persistence double; never used or advertised as a production backend.
 *
 * @since 0.1.0
 */
final class MemoryStore implements StoreInterface
{
	/** @var array<string,array<int,array<string,mixed>>> Test-owned records. @since 0.1.0 */
	private array $records;

	/** @param array<string,array<int,array<string,mixed>>> $records Initial test fixture. @since 0.1.0 */
	public function __construct(array $records = [])
	{
		$this->records = [];

		foreach ($records as $entity => $rows)
		{
			foreach ($rows as $row)
			{
				$this->records[$entity][(int) $row['id']] = $row;
			}
		}
	}

	/** @inheritDoc */
	public function find(string $entity, array $conditions = [], int $limit = 500, int $offset = 0): array
	{
		Structure::columns($entity);
		$found = [];
		$rows = $this->records[$entity] ?? [];
		ksort($rows, SORT_NUMERIC);

		foreach ($rows as $row)
		{
			if ($this->matches($row, $conditions))
			{
				$found[] = $row;
			}
		}

		return array_slice($found, $offset, $limit);
	}

	/** @inheritDoc */
	public function one(string $entity, array $conditions): ?array
	{
		return $this->find($entity, $conditions, 1)[0] ?? null;
	}

	/** @inheritDoc */
	public function insert(string $entity, array $values): int
	{
		Structure::columns($entity);
		$constraints = [];

		if (isset(Structure::definitions()[$entity]))
		{
			$constraints[] = ['name'];
		}

		if (isset($values['uuid']))
		{
			$constraints[] = $entity === 'session' ? ['principal_key', 'uuid'] : ['uuid'];
		}

		if ($entity === 'execution')
		{
			$constraints[] = ['principal_key', 'idempotency_key'];
		}

		if ($entity === 'lease')
		{
			$constraints[] = ['resource_key'];
		}

		foreach ($constraints as $columns)
		{
			if ($this->one($entity, array_intersect_key($values, array_flip($columns))) !== null)
			{
				throw new RuntimeException('Unique persistence constraint.');
			}
		}

		$id = $values['id'] ?? (max([0, ...array_keys($this->records[$entity] ?? [])]) + 1);
		$this->records[$entity][$id] = $values + ['id' => $id];

		return $id;
	}

	/** @inheritDoc */
	public function update(string $entity, array $values, array $conditions): int
	{
		$count = 0;

		foreach ($this->records[$entity] ?? [] as $id => $row)
		{
			if ($this->matches($row, $conditions))
			{
				$this->records[$entity][$id] = array_replace($row, $values);
				$count++;
			}
		}

		return $count;
	}

	/** @inheritDoc */
	public function remove(string $entity, array $conditions): int
	{
		$count = 0;

		foreach ($this->records[$entity] ?? [] as $id => $row)
		{
			if ($this->matches($row, $conditions))
			{
				unset($this->records[$entity][$id]);
				$count++;
			}
		}

		return $count;
	}

	/** @inheritDoc */
	public function transaction(callable $operation): mixed
	{
		$before = $this->records;

		try
		{
			return $operation();
		}
		catch (Throwable $error)
		{
			$this->records = $before;

			throw $error;
		}
	}

	/** @param array<string,mixed> $row Candidate row. @param array<string,mixed> $conditions Atomic criteria. @return bool Whether all conditions hold. @since 0.1.0 */
	private function matches(array $row, array $conditions): bool
	{
		foreach ($conditions as $column => $condition)
		{
			[$operator, $expected] = is_array($condition) ? $condition : ['eq', $condition];
			$value = $row[$column] ?? null;
			$equal = $operator !== 'in' && ($expected === null ? $value === null : $value !== null && (string) $value === (string) $expected);
			$matches = match ($operator)
			{
				'eq' => $equal,
				'ne' => !$equal,
				'lt' => $value < $expected,
				'lte' => $value <= $expected,
				'gt' => $value > $expected,
				'gte' => $value >= $expected,
				'in' => in_array($value, $expected, true),
				default => throw new RuntimeException('Unsupported fixture comparison.'),
			};

			if (!$matches)
			{
				return false;
			}
		}

		return true;
	}
}
