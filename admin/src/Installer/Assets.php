<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Installer;


use Joomla\CMS\Table\Asset;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


/**
 * Creates Joomla's native nested-set assets without replacing existing ACL rules.
 *
 * @since 0.1.0
 */
final class Assets
{
	/** @var DatabaseInterface Native asset persistence. @since 0.1.0 */
	private DatabaseInterface $database;
	/** @var StoreInterface Component definitions. @since 0.1.0 */
	private StoreInterface $store;

	/** @param DatabaseInterface $database Joomla database. @param StoreInterface $store Definitions. @since 0.1.0 */
	public function __construct(DatabaseInterface $database, StoreInterface $store)
	{
		$this->database = $database;
		$this->store = $store;
	}

	/** @return void Synchronize asset identities after installation or upgrade. @since 0.1.0 */
	public function synchronize(): void
	{
		$component = $this->ensure('com_joomengine_mcp', 'JoomEngine MCP', 1);
		$providers = [];

		foreach (Structure::definitions() as $entity => $columns)
		{
			for ($offset = 0; ; $offset += 1000)
			{
				$rows = $this->store->find($entity, [], 1000, $offset);

				foreach ($rows as $row)
				{
					$id = (int) $row['id'];
					$parent = $entity === 'provider' ? $component : ($providers[(int) $row['provider_id']] ?? 0);

					if ($parent < 1)
					{
						throw new RuntimeException('A catalogue definition references a missing provider asset.');
					}

					$asset = $this->ensure('com_joomengine_mcp.' . $entity . '.' . $id, $row['title'], $parent);
					$this->store->update($entity, ['asset_id' => $asset], ['id' => $id]);

					if ($entity === 'provider')
					{
						$providers[$id] = $asset;
					}
				}

				if (count($rows) < 1000)
				{
					break;
				}
			}
		}
	}

	/** @param string $name Stable asset identity. @param string $title Human label. @param int $parent Native parent asset. @return int Persisted native asset ID. @since 0.1.0 */
	private function ensure(string $name, string $title, int $parent): int
	{
		$asset = new Asset($this->database);
		$exists = $asset->loadByName($name);

		if (!$exists)
		{
			$asset->name = $name;
			$asset->rules = '{}';
			$asset->setLocation($parent, 'last-child');
		}
			elseif ((int) $asset->parent_id !== $parent)
		{
			$asset->setLocation($parent, 'last-child');
		}
		elseif ($asset->title === $title)
		{
			return (int) $asset->id;
		}

		$asset->parent_id = $parent;
		$asset->title = $title;

		if (!$asset->check() || !$asset->store())
		{
			throw new RuntimeException('The Joomla catalogue asset could not be stored.');
		}

		return (int) $asset->id;
	}
}
