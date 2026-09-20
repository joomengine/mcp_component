<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Fingerprints installed JCB definition state and configuration before a high-risk
 * package/compiler operation. This never interprets source fields as executable
 * MCP code and never returns their values. Unrelated checkout changes are ignored;
 * actual definition or repository configuration changes invalidate the plan.
 *
 * @since  0.1.0
 */
final class DefinitionSnapshot
{
	/** @var DatabaseInterface Native Joomla database. @since 0.1.0 */
	private DatabaseInterface $database;

	/** @param DatabaseInterface $database Current installation database. @since 0.1.0 */
	public function __construct(DatabaseInterface $database)
	{
		$this->database = $database;
	}

	/**
	 * Hash the bounded definition graph without caching across protocol requests.
	 *
	 * @return  string  Digest of the installed definition and configuration graph.
	 * @since   0.1.0
	 */
	public function fingerprint(): string
	{
		$db = $this->database;
		$prefix = $db->replacePrefix('#__componentbuilder_');
		$tables = array_values(array_filter($db->getTableList(), static fn (string $table): bool => str_starts_with($table, $prefix)));
		sort($tables, SORT_STRING);

		if ($tables === [] || count($tables) > 128)
		{
			throw new OperationException('JCB_SCHEMA_UNAVAILABLE', 'The installed JCB definition schema is unavailable or exceeds its boundary.');
		}

		$hash = hash_init('sha256');
		$bytes = 0;
		$records = 0;

		foreach ($tables as $table)
		{
			$columns = $db->getTableColumns($table, false);

			if (!isset($columns['id']))
			{
				continue;
			}

			hash_update($hash, $table . "\0");
			$last = 0;

			do
			{
				$query = $db->createQuery()->select('*')->from($db->quoteName($table))
					->where($db->quoteName('id') . ' > ' . (int) $last)->order($db->quoteName('id') . ' ASC');
				$rows = $db->setQuery($query, 0, 250)->loadAssocList();

				foreach ($rows as $row)
				{
					$last = (int) $row['id'];
					unset($row['checked_out'], $row['checked_out_time']);
					$encoded = Json::canonical($row);
					$bytes += strlen($encoded);

					if (++$records > 100000 || $bytes > 134217728)
					{
						throw new OperationException('JCB_GRAPH_LIMIT', 'The definition graph exceeds the reviewed preflight boundary.');
					}

					hash_update($hash, $encoded . "\0");
				}
			}
			while (count($rows) === 250);
		}

		$query = $db->createQuery()->select($db->quoteName(['element', 'folder', 'params', 'manifest_cache', 'enabled']))
			->from($db->quoteName('#__extensions'))->where($db->quoteName('element') . ' = ' . $db->quote('com_componentbuilder'))
			->order($db->quoteName('extension_id') . ' ASC');
		hash_update($hash, Json::canonical($db->setQuery($query)->loadAssocList()));

		return hash_final($hash);
	}
}
