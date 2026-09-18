<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Installer;


use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Applies versioned catalogue changes without overwriting administrator ownership.
 *
 * Stable names map shipped foreign keys to installation-local IDs. The updater
 * never reads this file during discovery, and never deletes customized records.
 *
 * @since 0.1.0
 */
final class SeedUpdater
{
	/** @var StoreInterface Transactional configuration persistence. @since 0.1.0 */
	private StoreInterface $store;

	/** @param StoreInterface $store Configuration persistence. @since 0.1.0 */
	public function __construct(StoreInterface $store)
	{
		$this->store = $store;
	}

	/**
	 * Apply a complete source-pinned installation graph atomically.
	 *
	 * @param array<string,mixed> $seed Shipped graph read only by the installer.
	 * @return array<string,int> Added, updated, preserved and retired record counts.
	 * @since 0.1.0
	 */
	public function apply(array $seed): array
	{
		if (!is_string($seed['source'] ?? null) || preg_match('/\A[0-9a-f]{40,64}\z/D', $seed['source']) !== 1
			|| array_keys($seed['entities'] ?? []) !== array_keys(Structure::definitions()))
		{
			throw new RuntimeException('The shipped catalogue graph has invalid provenance or entity ordering.');
		}

		return $this->store->transaction(function () use ($seed): array
		{
			$map = [];
			$counts = ['added' => 0, 'updated' => 0, 'preserved' => 0, 'retired' => 0];

			foreach (Structure::definitions() as $entity => $columns)
			{
				$names = [];

				foreach ($seed['entities'][$entity] as $record)
				{
					$sourceId = (int) $record['id'];
					$names[$record['name']] = true;

					foreach ($columns as $field => $type)
					{
						if ((str_starts_with($type, 'ref:') || str_starts_with($type, 'optional:')) && !empty($record[$field]))
						{
							$parent = explode(':', $type, 2)[1];
							$record[$field] = $map[$parent][(int) $record[$field]] ?? 0;

							if ($record[$field] < 1)
							{
								throw new RuntimeException('A shipped catalogue relationship is unresolved.');
							}
						}
					}

					$current = $this->store->one($entity, ['name' => $record['name']]);
					unset($record['id']);
					$record['seed_revision'] = $seed['source'];
					$record['seed_hash'] = self::hash($entity, $record);

					if ($current === null)
					{
						$map[$entity][$sourceId] = $this->store->insert($entity, $record);
						$counts['added']++;
						continue;
					}

					if ($current['seed_revision'] === '')
					{
						throw new RuntimeException('A custom definition conflicts with a newly shipped name: ' . $record['name']);
					}

					$map[$entity][$sourceId] = (int) $current['id'];

					if ((int) $current['customized'] !== 0 || !hash_equals($current['seed_hash'], self::hash($entity, $current)))
					{
						$counts['preserved']++;
						continue;
					}

					if ($record['seed_hash'] === $current['seed_hash'] && $current['seed_revision'] === $seed['source'])
					{
						continue;
					}

					foreach (['asset_id', 'checked_out', 'checked_out_time', 'created', 'created_by'] as $field)
					{
						$record[$field] = $current[$field];
					}

					$record['version'] = (int) $current['version'] + 1;
					$record['modified'] = gmdate('Y-m-d H:i:s');
					$this->store->update($entity, $record, ['id' => (int) $current['id'], 'version' => (int) $current['version']]);
					$counts['updated']++;
				}

				for ($offset = 0; ; $offset += 1000)
				{
					$page = $this->store->find($entity, [], 1000, $offset);

					foreach ($page as $current)
					{
						if (!isset($names[$current['name']]) && $current['seed_revision'] !== '' && (int) $current['customized'] === 0
							&& (int) $current['published'] === 1 && hash_equals($current['seed_hash'], self::hash($entity, $current)))
						{
							$current['published'] = 0;
							$this->store->update($entity, ['published' => 0, 'seed_revision' => $seed['source'],
								'seed_hash' => self::hash($entity, $current), 'version' => (int) $current['version'] + 1], ['id' => (int) $current['id']]);
							$counts['retired']++;
						}
					}

					if (count($page) < 1000)
					{
						break;
					}
				}
			}

			return $counts;
		});
	}

	/** @param string $entity Fixed definition type. @param array<string,mixed> $record Current values. @return string Seed ownership hash, portable across database scalar types. @since 0.1.0 */
	public static function hash(string $entity, array $record): string
	{
		$owned = array_intersect_key($record, Structure::columns($entity));
		$owned = array_diff_key($owned, array_flip(['id', 'asset_id', 'checked_out', 'checked_out_time', 'created', 'created_by',
			'modified', 'modified_by', 'version', 'seed_revision', 'seed_hash', 'customized']));

		foreach (Structure::columns($entity) as $field => $type)
		{
			if (array_key_exists($field, $owned) && $owned[$field] !== null
				&& (in_array($type, ['int', 'published', 'access'], true) || str_starts_with($type, 'ref:') || str_starts_with($type, 'optional:')))
			{
				$owned[$field] = (int) $owned[$field];
			}
		}

		ksort($owned, SORT_STRING);

		return hash('sha256', Json::encode($owned));
	}
}
