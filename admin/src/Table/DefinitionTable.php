<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Table;


use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Administration\DefinitionValidator;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Native Joomla table template for the fixed catalogue definition entities.
 *
 * Subclasses select ENTITY only. Joomla retains checkout, observer and nested
 * asset behaviour. JSON normalization and relation checks occur before store;
 * the owning model supplies permissions, transactions and revision claiming.
 *
 * @since  0.1.0
 */
abstract class DefinitionTable extends Table
{
	/**
	 * Fixed schema identity supplied by the concrete table.
	 *
	 * @var    string
	 * @since  0.1.0
	 */
	protected const ENTITY = '';

	/**
	 * Permit native nullable checkout/date columns.
	 *
	 * @var    bool
	 * @since  0.1.0
	 */
	protected $_supportNullValue = true;

	/**
	 * Shared inert definition validator.
	 *
	 * @var    DefinitionValidator
	 * @since  0.1.0
	 */
	private DefinitionValidator $validator;

	/**
	 * Bind this table to its fixed database definition and native dispatcher.
	 *
	 * @param   DatabaseInterface   $db          Joomla database.
	 * @param   ?DispatcherInterface $dispatcher Joomla table events.
	 * @since   0.1.0
	 */
	public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
	{
		parent::__construct(Structure::table(static::ENTITY), 'id', $db, $dispatcher);
		$this->validator = new DefinitionValidator(new JoomlaStore($db), new SchemaValidator());
	}

	/**
	 * Bind only owned columns and separately preserve native asset rules.
	 *
	 * @param   array|object  $src     Form data or an existing record.
	 * @param   array|string  $ignore  Native ignored columns.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function bind($src, $ignore = [])
	{
		$data = (array) $src;

		if (isset($data['rules']))
		{
			$this->setRules($data['rules']);
		}

		$data = array_intersect_key($data, Structure::columns(static::ENTITY));

		foreach (Structure::columns(static::ENTITY) as $name => $type)
		{
			if ($type === 'json' && array_key_exists($name, $data) && !is_string($data[$name]))
			{
				$data[$name] = Json::encode((object) $data[$name]);
			}
		}

		return parent::bind($data, $ignore);
	}

	/**
	 * Validate a complete row and its viewing-level relationship.
	 *
	 * @return  bool  False with a native table error when validation fails.
	 * @since   0.1.0
	 */
	public function check()
	{
		try
		{
			$data = $this->validator->validate(static::ENTITY, get_object_vars($this));
			$db = $this->getDatabase();
			$level = (int) $data['access'];
			$query = $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__viewlevels'))
				->where($db->quoteName('id') . ' = :viewlevel')->bind(':viewlevel', $level);

			if (!$db->setQuery($query)->loadResult())
			{
				throw new \InvalidArgumentException('The selected Joomla viewing access level does not exist.');
			}

			return parent::bind($data) && parent::check();
		}
		catch (Throwable $error)
		{
			$this->setError($error->getMessage());

			return false;
		}
	}

	/**
	 * Persist the row and Joomla nested-set asset in a single savepoint scope.
	 *
	 * Native row observers still run; asset persistence uses Joomla's Asset class
	 * with transaction-scoped locking instead of its implicit-commit table lock.
	 * Model after-save observers run only after both records have been stored.
	 *
	 * @param   bool  $updateNulls  Native option; nullable references are always stored.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function store($updateNulls = false)
	{
		$db = $this->getDatabase();
		$db->transactionStart(true);
		$tracking = $this->_trackAssets;

		try
		{
			$this->_trackAssets = false;

			if (!parent::store(true))
			{
				throw new \RuntimeException((string) $this->getError());
			}

			$this->_trackAssets = $tracking;
			$asset = new CatalogueAsset($db, $this->getDispatcher());
			$asset->loadByName($this->_getAssetName());
			$parent = $this->_getAssetParentId();

			if (!$asset->id || (int) $asset->parent_id !== $parent)
			{
				$asset->setLocation($parent, 'last-child');
			}

			$asset->name = $this->_getAssetName();
			$asset->title = mb_substr($this->_getAssetTitle(), 0, 100);
			$asset->parent_id = $parent;
			$rules = $this->getRules();

			if ($rules !== null)
			{
				$asset->rules = (string) $rules;
			}

			if (!$asset->check() || !$asset->store())
			{
				throw new \RuntimeException((string) $asset->getError());
			}

			$this->asset_id = (int) $asset->id;
			$id = (int) $this->id;
			$assetId = $this->asset_id;
			$query = $db->createQuery()->update($db->quoteName($this->_tbl))
				->set($db->quoteName('asset_id') . ' = :asset_id')->where($db->quoteName('id') . ' = :record_id')
				->bind(':asset_id', $assetId)->bind(':record_id', $id);
			$db->setQuery($query)->execute();
			$db->transactionCommit(true);

			return true;
		}
		catch (Throwable $error)
		{
			$db->transactionRollback(true);
			$this->setError($error->getMessage());

			return false;
		}
		finally
		{
			$this->_trackAssets = $tracking;
		}
	}

	/**
	 * Refuse dangling relations and atomically remove the native row and asset.
	 *
	 * @param   mixed  $pk  Native primary key, defaulting to the loaded row.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function delete($pk = null)
	{
		$db = $this->getDatabase();
		$db->transactionStart(true);
		$tracking = $this->_trackAssets;

		try
		{
			$id = (int) ($pk ?? $this->id);
			$this->validator->requireUnreferenced(static::ENTITY, $id);
			$asset = new CatalogueAsset($db, $this->getDispatcher());

			if ($asset->loadByName('com_joomengine_mcp.' . static::ENTITY . '.' . $id) && !$asset->delete())
			{
				throw new \RuntimeException((string) $asset->getError());
			}

			$this->_trackAssets = false;

			if (!parent::delete($pk))
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
		finally
		{
			$this->_trackAssets = $tracking;
		}
	}
	/** @return string Native row asset name. @since 0.1.0 */
	protected function _getAssetName()
	{
		return 'com_joomengine_mcp.' . static::ENTITY . '.' . (int) $this->id;
	}

	/** @return string Native row asset title. @since 0.1.0 */
	protected function _getAssetTitle()
	{
		return (string) $this->title;
	}

	/**
	 * Parent row assets beneath the provider, or the component for providers.
	 *
	 * @param   ?Table  $table  Native compatibility argument.
	 * @param   mixed   $id     Native compatibility argument.
	 * @return  int
	 * @throws  \RuntimeException  When the owning asset has not been installed.
	 * @since   0.1.0
	 */
	protected function _getAssetParentId(?Table $table = null, $id = null)
	{
		$name = static::ENTITY === 'provider' ? 'com_joomengine_mcp'
			: 'com_joomengine_mcp.provider.' . (int) $this->provider_id;
		$db = $this->getDatabase();
		$query = $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__assets'))
			->where($db->quoteName('name') . ' = :asset_name')->bind(':asset_name', $name);
		$parent = (int) $db->setQuery($query)->loadResult();

		if ($parent < 1)
		{
			throw new \RuntimeException('The owning catalogue asset is missing. Repair the component installation.');
		}

		return $parent;
	}
}
