<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Model;


use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


/**
 * Native admin editing with explicit configuration authority and revision claims.
 *
 * Concrete models select ENTITY and native message prefix. Joomla still owns
 * forms, checkout and save/delete events; this template prevents stale edits,
 * preserves seed ownership, and keeps assets and records in one transaction.
 *
 * @since  0.1.0
 */
abstract class DefinitionModel extends AdminModel
{
	/** @var string Fixed definition selected by the concrete model. @since 0.1.0 */
	protected const ENTITY = '';

	/**
	 * Load the component's XML form using Joomla's native form factory.
	 *
	 * @param   array  $data      Current form values.
	 * @param   bool   $loadData  Whether to load persisted/session values.
	 * @return  \Joomla\CMS\Form\Form|false
	 * @since   0.1.0
	 */
	public function getForm($data = [], $loadData = true)
	{
		return $this->loadForm('com_joomengine_mcp.' . static::ENTITY, static::ENTITY,
			['control' => 'jform', 'load_data' => $loadData]);
	}

	/**
	 * Preserve an unsuccessful form submission before reloading the record.
	 *
	 * @return  mixed
	 * @since   0.1.0
	 */
	protected function loadFormData()
	{
		$data = Factory::getApplication()->getUserState('com_joomengine_mcp.edit.' . static::ENTITY . '.data', []);

		return $data === [] ? $this->getItem() : $data;
	}

	/**
	 * Load JSON fields without converting object text into rendered PHP arrays.
	 *
	 * @param   mixed  $pk  Native primary key.
	 * @return  object|false
	 * @since   0.1.0
	 */
	public function getItem($pk = null)
	{
		if (!$this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			$this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

			return false;
		}

		$item = parent::getItem($pk);

		if ($item !== false)
		{
			foreach (Structure::columns(static::ENTITY) as $name => $type)
			{
				if ($type === 'json' && !is_string($item->{$name} ?? null))
				{
					$item->{$name} = json_encode((object) ($item->{$name} ?? []), JSON_THROW_ON_ERROR);
				}
			}

			if (empty($item->id))
			{
				$item->access = 1;
				$item->published = 0;
				$item->version = 1;
			}
		}

		return $item;
	}

	/**
	 * Save a native record while atomically rejecting stale revisions.
	 *
	 * @param   array  $data  Validated Joomla form data.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function save($data)
	{
		$user = $this->getCurrentUser();
		$id = (int) ($data['id'] ?? 0);
		$asset = $id > 0 ? 'com_joomengine_mcp.' . static::ENTITY . '.' . $id : 'com_joomengine_mcp';

		if (!$user->authorise('core.admin', 'com_joomengine_mcp')
			|| !$user->authorise($id > 0 ? 'core.edit' : 'core.create', $asset))
		{
			$this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

			return false;
		}

		$db = $this->getDatabase();
		$db->transactionStart(true);

		try
		{
			unset($data['asset_id'], $data['seed_hash'], $data['seed_revision'], $data['created'], $data['created_by'],
				$data['modified'], $data['modified_by'], $data['checked_out'], $data['checked_out_time']);
			$data['customized'] = 1;

			if ($id > 0)
			{
				$table = $this->getTable();

				if (!$table->load($id) || ($table->checked_out && (int) $table->checked_out !== (int) $user->id))
				{
					throw new \RuntimeException(Text::_('COM_JOOMENGINE_MCP_EDIT_CONFLICT'));
				}

				$version = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT);

				if ($version === false || $version < 1)
				{
					throw new \RuntimeException(Text::_('COM_JOOMENGINE_MCP_EDIT_CONFLICT'));
				}

				$query = $db->createQuery()->update($db->quoteName(Structure::table(static::ENTITY)))
					->set($db->quoteName('version') . ' = ' . $db->quoteName('version') . ' + 1')
					->where($db->quoteName('id') . ' = :record_id')
					->where($db->quoteName('version') . ' = :record_version')
					->bind(':record_id', $id)->bind(':record_version', $version);
				$db->setQuery($query)->execute();

				if ($db->getAffectedRows() !== 1)
				{
					throw new \RuntimeException(Text::_('COM_JOOMENGINE_MCP_EDIT_CONFLICT'));
				}

				$data['version'] = $version + 1;
			}
			else
			{
				$data['version'] = 1;
				$data['seed_revision'] = '';
				$data['seed_hash'] = '';
			}

			if (!parent::save($data))
			{
				throw new \RuntimeException((string) $this->getError());
			}

			$db->transactionCommit(true);

			return true;
		}
		catch (Throwable $error)
		{
			$db->transactionRollback(true);
			$this->setError($error->getMessage());

			return false;
		}
	}

	/**
	 * Apply native creation/modification metadata without trusting form values.
	 *
	 * @param   Table  $table  Loaded and bound Joomla table.
	 * @return  void
	 * @since   0.1.0
	 */
	protected function prepareTable($table)
	{
		$now = gmdate('Y-m-d H:i:s');
		$user = $this->getCurrentUser();

		if (empty($table->id))
		{
			$table->created = $now;
			$table->created_by = (int) $user->id;
			$table->checked_out = null;
			$table->checked_out_time = null;
		}
		else
		{
			$table->modified = $now;
			$table->modified_by = (int) $user->id;
		}
	}

	/** @param object $record Native row. @return bool Whether it can be deleted. @since 0.1.0 */
	protected function canDelete($record)
	{
		return !empty($record->id) && (int) $record->published === -2
			&& $this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp')
			&& $this->getCurrentUser()->authorise('core.delete', 'com_joomengine_mcp.' . static::ENTITY . '.' . (int) $record->id);
	}

	/** @param object $record Native row. @return bool Whether publication can change. @since 0.1.0 */
	protected function canEditState($record)
	{
		return $this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp')
			&& $this->getCurrentUser()->authorise('core.edit.state', 'com_joomengine_mcp.' . static::ENTITY . '.' . (int) $record->id);
	}

	/**
	 * Publish through the native model and record administrator ownership.
	 *
	 * @param   array  &$pks   Selected native row IDs.
	 * @param   int    $value  Publication state.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function publish(&$pks, $value = 1)
	{
		if (!in_array((int) $value, [-2, 0, 1, 2], true))
		{
			$this->setError(Text::_('COM_JOOMENGINE_MCP_INVALID_STATE'));

			return false;
		}

		$db = $this->getDatabase();
		$db->transactionStart(true);

		try
		{
			if (!parent::publish($pks, $value))
			{
				throw new \RuntimeException((string) $this->getError());
			}

			$this->touch($pks);
			$db->transactionCommit(true);

			return true;
		}
		catch (Throwable $error)
		{
			$db->transactionRollback(true);
			$this->setError($error->getMessage());

			return false;
		}
	}

	/**
	 * Retain native ordering permissions and make priority changes invalidate plans.
	 *
	 * @param   array   $pks    Selected row IDs.
	 * @param   ?array  $order  Native ordering values.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function saveorder($pks = [], $order = null)
	{
		if (!$this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			$this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

			return false;
		}

		$db = $this->getDatabase();
		$db->transactionStart(true);

		try
		{
			if (!parent::saveorder($pks, $order))
			{
				throw new \RuntimeException((string) $this->getError());
			}

			$this->touch($pks);
			$db->transactionCommit(true);

			return true;
		}
		catch (Throwable $error)
		{
			$db->transactionRollback(true);
			$this->setError($error->getMessage());

			return false;
		}
	}

	/**
	 * Increment a revision after non-form native mutations.
	 *
	 * @param   array  $ids  Selected native row IDs.
	 * @return  void
	 * @since   0.1.0
	 */
	private function touch(array $ids): void
	{
		$db = $this->getDatabase();

		foreach (array_unique(array_map('intval', $ids)) as $id)
		{
			$query = $db->createQuery()->update($db->quoteName(Structure::table(static::ENTITY)))
				->set($db->quoteName('version') . ' = ' . $db->quoteName('version') . ' + 1')
				->set($db->quoteName('customized') . ' = 1')->where($db->quoteName('id') . ' = :record_id')->bind(':record_id', $id);
			$db->setQuery($query)->execute();
		}
	}
}
