<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Table;


use Joomla\CMS\Table\Asset;


/**
 * Joomla nested-set asset operations participating in the caller's transaction.
 *
 * Native PgsqlDriver::lockTable starts a transaction and unlockTables commits it;
 * MySQL table locks also commit implicitly. Neither can nest in an atomic record
 * save. This adapter changes only locking: all nested-set and rules operations
 * remain Joomla's Asset implementation. The caller must own a transaction.
 * PostgreSQL locks the asset table inside it; MySQL locks the existing root row.
 * Native concurrent table writers serialize with these transaction-held locks.
 *
 * @since  0.1.0
 */
final class CatalogueAsset extends Asset
{
	/**
	 * Acquire a transaction-scoped native asset-tree write lock.
	 *
	 * @return  bool
	 * @throws  \RuntimeException  For database locking failure.
	 * @since   0.1.0
	 */
	protected function _lock()
	{
		if (!$this->_locked)
		{
			$db = $this->getDatabase();

			if ($db->getServerType() === 'postgresql')
			{
				$db->setQuery('LOCK TABLE ' . $db->quoteName('#__assets') . ' IN SHARE ROW EXCLUSIVE MODE')->execute();
			}
			else
			{
				$db->setQuery('SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__assets')
					. ' WHERE ' . $db->quoteName('id') . ' = 1 FOR UPDATE')->loadResult();
			}

			$this->_locked = true;
		}

		return true;
	}

	/**
	 * Release the object's lock flag; the owning transaction releases database locks.
	 *
	 * @return  bool
	 * @since   0.1.0
	 */
	protected function _unlock()
	{
		$this->_locked = false;

		return true;
	}
}
