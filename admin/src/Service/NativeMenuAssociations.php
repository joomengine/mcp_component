<?php
/**
 * @package    JoomEngine.Mcp
 * @created    12 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;


/** Fresh site-menu membership reads for persistent CLI runtimes. */
final class NativeMenuAssociations
{
	/**
	 * Read current membership without Joomla's process-static association helper cache.
	 * The caller has already loaded and accepted this exact site-menu item through its model.
	 * No table, context, field or query fragment is supplied by the caller.
	 *
	 * @param object $model Accepted native menu model.
	 * @param int $id Model-confirmed site-menu identity.
	 * @return array<string,int> Current associated site-item IDs, excluding the source item.
	 */
	public static function read(object $model, int $id): array
	{
		try
		{
			if ($id < 1 || !method_exists($model, 'getDatabase'))
			{
				throw new \RuntimeException('The native model database is unavailable.');
			}

			$db = $model->getDatabase();
			$query = $db->createQuery()
				->select($db->quoteName(['m.language', 'm.id']))
				->from($db->quoteName('#__associations', 'a'))
				->join('INNER', $db->quoteName('#__associations', 'b'), $db->quoteName('a.key') . ' = ' . $db->quoteName('b.key')
					. ' AND ' . $db->quoteName('b.context') . ' = ' . $db->quote('com_menus.item'))
				->join('INNER', $db->quoteName('#__menu', 'm'), $db->quoteName('m.id') . ' = ' . $db->quoteName('b.id'))
				->where($db->quoteName('a.context') . ' = ' . $db->quote('com_menus.item'))
				->where($db->quoteName('a.id') . ' = ' . $id)
				->where($db->quoteName('m.id') . ' <> ' . $id)
				->where($db->quoteName('m.client_id') . ' = 0');
			$rows = $db->setQuery($query, 0, 101)->loadAssocList();

			if (!is_array($rows) || count($rows) > 100)
			{
				throw new \RuntimeException('Native associations exceed the supported bound.');
			}

			$members = [];

			foreach ($rows as $row)
			{
				$language = $row['language'] ?? null;
				$member = $row['id'] ?? null;

				if (!is_string($language) || strlen($language) > 50 || preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*\z/D', $language) !== 1
					|| isset($members[$language]) || (!is_int($member) && !(is_string($member) && ctype_digit($member)))
					|| (int) $member < 1 || (int) $member > 2147483647)
				{
					throw new \RuntimeException('Native association membership is invalid.');
				}

				$members[$language] = (int) $member;
			}

			ksort($members, SORT_STRING);

			return $members;
		}
		catch (Throwable $error)
		{
			throw new ActionException('ASSOCIATION_READ_FAILED', 'Current native menu associations could not be read.');
		}
	}
}
