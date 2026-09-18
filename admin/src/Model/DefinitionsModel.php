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
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


/**
 * Native paginated list template for independently editable definition entities.
 *
 * Concrete models select ENTITY. The template owns whitelisted sorting, bounded
 * pagination and administrator authorization; schemas and state secrets are not
 * selected into list rows.
 *
 * @since  0.1.0
 */
abstract class DefinitionsModel extends ListModel
{
	/** @var string Concrete definition type. @since 0.1.0 */
	protected const ENTITY = '';

	/**
	 * Register native filter fields without accepting caller-supplied SQL names.
	 *
	 * @param   array                 $config   Native model configuration.
	 * @param   ?MVCFactoryInterface  $factory  Native component MVC factory.
	 * @since   0.1.0
	 */
	public function __construct($config = [], ?MVCFactoryInterface $factory = null)
	{
		$config['filter_fields'] = ['id', 'a.id', 'name', 'a.name', 'title', 'a.title', 'published', 'a.published',
			'access', 'a.access', 'ordering', 'a.ordering', 'modified', 'a.modified', 'provider_id', 'a.provider_id'];
		parent::__construct($config, $factory);
	}

	/**
	 * Load Joomla filter state and bound user-supplied pagination.
	 *
	 * @param   ?string  $ordering   Default sort column.
	 * @param   ?string  $direction  Default sort direction.
	 * @return  void
	 * @since   0.1.0
	 */
	protected function populateState($ordering = 'a.name', $direction = 'asc')
	{
		$app = Factory::getApplication();
		$this->setState('filter.search', $this->getUserStateFromRequest($this->context . '.search', 'filter_search', '', 'string'));
		$this->setState('filter.published', $this->getUserStateFromRequest($this->context . '.published', 'filter_published', '', 'string'));
		$this->setState('filter.access', $this->getUserStateFromRequest($this->context . '.access', 'filter_access', 0, 'uint'));
		$this->setState('filter.provider_id', $this->getUserStateFromRequest($this->context . '.provider_id', 'filter_provider_id', 0, 'uint'));
		parent::populateState($ordering, $direction);
		$this->setState('list.limit', max(1, min(500, (int) $this->state->get('list.limit', $app->get('list_limit', 20)))));
		$this->setState('list.start', max(0, (int) $this->state->get('list.start', 0)));
	}

	/**
	 * Include filters in the model cache identity.
	 *
	 * @param   string  $id  Caller cache suffix.
	 * @return  string
	 * @since   0.1.0
	 */
	protected function getStoreId($id = '')
	{
		foreach (['search', 'published', 'access', 'provider_id'] as $name)
		{
			$id .= ':' . (string) $this->getState('filter.' . $name);
		}

		return parent::getStoreId($id);
	}

	/**
	 * Build a parameter-bound list query over one fixed table.
	 *
	 * @return  \Joomla\Database\QueryInterface
	 * @throws  \RuntimeException  When configuration access is denied.
	 * @since   0.1.0
	 */
	protected function getListQuery()
	{
		if (!$this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			throw new \RuntimeException('Not authorized to manage the MCP catalogue.', 403);
		}

		$db = $this->getDatabase();
		$query = $db->createQuery()->select($db->quoteName([
			'a.id', 'a.name', 'a.title', 'a.published', 'a.access', 'a.ordering', 'a.checked_out', 'a.checked_out_time',
			'a.version', 'a.customized', 'a.seed_revision', 'a.modified',
		]))->select($db->quoteName('v.title', 'access_title'))->select($db->quoteName('u.name', 'editor'))
			->from($db->quoteName(Structure::table(static::ENTITY), 'a'))
			->leftJoin($db->quoteName('#__viewlevels', 'v') . ' ON ' . $db->quoteName('v.id') . ' = ' . $db->quoteName('a.access'))
			->leftJoin($db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.checked_out'));
		$search = trim((string) $this->getState('filter.search', ''));

		if (str_starts_with($search, 'id:') && ctype_digit(substr($search, 3)))
		{
			$id = (int) substr($search, 3);
			$query->where($db->quoteName('a.id') . ' = :search_id')->bind(':search_id', $id);
		}
		elseif ($search !== '')
		{
			$search = '%' . $db->escape(mb_substr($search, 0, 191), true) . '%';
			$searchName = $search;
			$query->where('(' . $db->quoteName('a.title') . ' LIKE :search_title OR ' . $db->quoteName('a.name') . ' LIKE :search_name)')
				->bind(':search_title', $search)->bind(':search_name', $searchName);
		}

		$state = $this->getState('filter.published');

		if (is_numeric($state))
		{
			$state = (int) $state;
			$query->where($db->quoteName('a.published') . ' = :published')->bind(':published', $state);
		}
		else
		{
			$query->where($db->quoteName('a.published') . ' IN (0, 1, 2)');
		}

		$access = (int) $this->getState('filter.access');

		if ($access > 0)
		{
			$query->where($db->quoteName('a.access') . ' = :access')->bind(':access', $access);
		}

		if (static::ENTITY !== 'provider')
		{
			$query->select($db->quoteName('a.provider_id'));
			$provider = (int) $this->getState('filter.provider_id');

			if ($provider > 0)
			{
				$query->where($db->quoteName('a.provider_id') . ' = :provider')->bind(':provider', $provider);
			}
		}

		$order = $this->getState('list.ordering', 'a.name');
		$allowed = ['a.id', 'a.name', 'a.title', 'a.published', 'a.access', 'a.ordering', 'a.modified'];
		$order = in_array($order, $allowed, true) ? $order : 'a.name';
		$direction = strtolower((string) $this->getState('list.direction')) === 'desc' ? 'DESC' : 'ASC';

		return $query->order($db->quoteName($order) . ' ' . $direction)->order($db->quoteName('a.id') . ' ASC');
	}
}
