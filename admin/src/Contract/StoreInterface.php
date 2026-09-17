<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


/**
 * Portable persistence boundary with explicit compare-and-swap criteria.
 *
 * Conditions are column => scalar/null, or column => [eq|ne|lt|lte|gt|gte|in, value].
 * Only columns in the reviewed Structure are accepted. This is not a public SQL API.
 *
 * @since 0.1.0
 */
interface StoreInterface
{
	/**
	 * Read a bounded, stable page of rows matching all conditions.
	 *
	 * @param string $entity Reviewed persistence entity.
	 * @param array<string,mixed> $conditions Equality/comparison criteria.
	 * @param int $limit Maximum rows.
	 * @param int $offset Page offset.
	 * @return array<int,array<string,mixed>> Persisted scalar rows.
	 * @since 0.1.0
	 */
	public function find(string $entity, array $conditions = [], int $limit = 500, int $offset = 0): array;

	/** @param string $entity Entity. @param array<string,mixed> $conditions Criteria. @return array<string,mixed>|null One matching row. @since 0.1.0 */
	public function one(string $entity, array $conditions): ?array;

	/** @param string $entity Entity. @param array<string,mixed> $values Complete new record. @return int New primary key. @since 0.1.0 */
	public function insert(string $entity, array $values): int;

	/** @param string $entity Entity. @param array<string,mixed> $values Changed fields. @param array<string,mixed> $conditions Atomic preconditions. @return int Affected rows. @since 0.1.0 */
	public function update(string $entity, array $values, array $conditions): int;

	/** @param string $entity Entity. @param array<string,mixed> $conditions Mandatory deletion criteria. @return int Deleted rows. @since 0.1.0 */
	public function remove(string $entity, array $conditions): int;

	/** @param callable $operation Transactional operation. @return mixed Callback result after commit. @since 0.1.0 */
	public function transaction(callable $operation): mixed;
}
