<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Database;


use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;


/**
 * Joomla database implementation using reviewed identifiers and bound parameters.
 *
 * @since 0.1.0
 */
final class JoomlaStore implements StoreInterface
{
	/** @var DatabaseInterface Joomla's configured database driver. @since 0.1.0 */
	private DatabaseInterface $database;

	/** @var int Nesting depth for Joomla savepoint transactions. @since 0.1.0 */
	private int $depth = 0;

	/** @param DatabaseInterface $database Joomla database dependency. @since 0.1.0 */
	public function __construct(DatabaseInterface $database)
	{
		$this->database = $database;
	}

	/** @inheritDoc */
	public function find(string $entity, array $conditions = [], int $limit = 500, int $offset = 0): array
	{
		if ($limit < 1 || $limit > 10000 || $offset < 0 || $offset > 1000000)
		{
			throw new InvalidArgumentException('Invalid bounded database page.');
		}

		$query = $this->database->getQuery(true)
			->select('*')->from($this->database->quoteName(Structure::table($entity)))
			->order($this->database->quoteName('id') . ' ASC');
		$this->where($query, $entity, $conditions);

		return $this->database->setQuery($query, $offset, $limit)->loadAssocList();
	}

	/** @inheritDoc */
	public function one(string $entity, array $conditions): ?array
	{
		return $this->find($entity, $conditions, 1)[0] ?? null;
	}

	/** @inheritDoc */
	public function insert(string $entity, array $values): int
	{
		$this->validateColumns($entity, $values);

		if ($values === [])
		{
			throw new InvalidArgumentException('An empty database insert is not permitted.');
		}

		$query = $this->database->getQuery(true)->insert($this->database->quoteName(Structure::table($entity)))
			->columns($this->database->quoteName(array_keys($values)));
		$parameters = [];

		foreach (array_values($values) as $index => $value)
		{
			$parameters[] = $this->bind($query, ':v' . $index, $value);
		}

		$query->values(implode(', ', $parameters));
		$this->database->setQuery($query)->execute();

		return (int) $this->database->insertid();
	}

	/** @inheritDoc */
	public function update(string $entity, array $values, array $conditions): int
	{
		$this->validateColumns($entity, $values);

		if ($values === [] || $conditions === [] || array_key_exists('id', $values))
		{
			throw new InvalidArgumentException('Updates require values and atomic criteria; primary keys are immutable.');
		}

		$query = $this->database->getQuery(true)->update($this->database->quoteName(Structure::table($entity)));
		$index = 0;

		foreach ($values as $column => $value)
		{
			$query->set($this->database->quoteName($column) . ' = ' . $this->bind($query, ':v' . $index++, $value));
		}

		$this->where($query, $entity, $conditions);
		$this->database->setQuery($query)->execute();

		return (int) $this->database->getAffectedRows();
	}

	/** @inheritDoc */
	public function remove(string $entity, array $conditions): int
	{
		if ($conditions === [])
		{
			throw new InvalidArgumentException('Unbounded deletion is not permitted.');
		}

		$query = $this->database->getQuery(true)->delete($this->database->quoteName(Structure::table($entity)));
		$this->where($query, $entity, $conditions);
		$this->database->setQuery($query)->execute();

		return (int) $this->database->getAffectedRows();
	}

	/** @inheritDoc */
	public function transaction(callable $operation): mixed
	{
		$nested = $this->depth > 0;
		$this->database->transactionStart($nested);
		$this->depth++;

		try
		{
			$result = $operation();
			$this->database->transactionCommit($nested);

			return $result;
		}
		catch (Throwable $error)
		{
			$this->database->transactionRollback($nested);

			throw $error;
		}
		finally
		{
			$this->depth--;
		}
	}

	/**
	 * Add only allowlisted comparisons with independent bound values.
	 *
	 * @param QueryInterface $query Joomla query being composed.
	 * @param string $entity Reviewed entity.
	 * @param array<string,mixed> $conditions Required conditions.
	 * @return void
	 * @since 0.1.0
	 */
	private function where(QueryInterface $query, string $entity, array $conditions): void
	{
		$this->validateColumns($entity, $conditions, true);
		$operators = ['eq' => '=', 'ne' => '<>', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
		$index = 0;

		foreach ($conditions as $column => $condition)
		{
			[$operator, $value] = is_array($condition) ? $condition : ['eq', $condition];
			$name = $this->database->quoteName($column);

			if ($operator === 'in' && is_array($value) && count($value) <= 10000)
			{
				$parts = [];

				foreach ($value as $entry)
				{
					$parts[] = $this->bind($query, ':w' . $index++, $entry);
				}

				$query->where($parts === [] ? '1 = 0' : $name . ' IN (' . implode(', ', $parts) . ')');
				continue;
			}

			if (!isset($operators[$operator]))
			{
				throw new InvalidArgumentException('Unsupported database comparison.');
			}

			if ($value === null)
			{
				if (!in_array($operator, ['eq', 'ne'], true))
				{
					throw new InvalidArgumentException('NULL supports only equality comparisons.');
				}

				$query->where($name . ($operator === 'eq' ? ' IS NULL' : ' IS NOT NULL'));
				continue;
			}

			$query->where($name . ' ' . $operators[$operator] . ' ' . $this->bind($query, ':w' . $index++, $value));
		}
	}

	/** @param QueryInterface $query Query. @param string $name Unique parameter. @param mixed $value Scalar value. @return string SQL placeholder. @since 0.1.0 */
	private function bind(QueryInterface $query, string $name, mixed $value): string
	{
		if ($value === null)
		{
			return 'NULL';
		}

		if (!is_scalar($value))
		{
			throw new InvalidArgumentException('Database values must be scalar or NULL.');
		}

		$type = is_int($value) || is_bool($value) ? ParameterType::INTEGER : ParameterType::STRING;
		$value = $type === ParameterType::INTEGER ? (int) $value : (string) $value;
		$query->bind($name, $value, $type);

		return $name;
	}

	/** @param string $entity Entity. @param array<string,mixed> $values Values or criteria. @param bool $conditions Whether comparison arrays are allowed. @return void @since 0.1.0 */
	private function validateColumns(string $entity, array $values, bool $conditions = false): void
	{
		$columns = Structure::columns($entity);

		foreach ($values as $column => $value)
		{
			if (!is_string($column) || !isset($columns[$column])
				|| (!$conditions && $value !== null && !is_scalar($value))
				|| ($conditions && is_array($value) && (!array_is_list($value) || count($value) !== 2)))
			{
				throw new InvalidArgumentException('Invalid database column or value.');
			}
		}
	}
}
