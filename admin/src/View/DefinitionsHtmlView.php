<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\View;


use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;


/**
 * Shared list presentation for concrete Joomla catalogue views.
 *
 * Only presentation is shared; each concrete view has its own model, XML filters,
 * controller and template in the standard Joomla extension structure.
 *
 * @since  0.1.0
 */
abstract class DefinitionsHtmlView extends HtmlView
{
	/** @var string Concrete entity. @since 0.1.0 */
	protected const ENTITY = '';
	/** @var array Native list rows. @since 0.1.0 */
	public $items;
	/** @var object Joomla list state. @since 0.1.0 */
	public $state;
	/** @var object Native pagination. @since 0.1.0 */
	public $pagination;
	/** @var object Native filter form consumed by searchtools. @since 0.1.0 */
	public $filterForm;
	/** @var array Applied Joomla filters. @since 0.1.0 */
	public $activeFilters;

	/** @inheritDoc */
	public function display($tpl = null)
	{
		if (!$this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$model = $this->getModel();
		$this->state = $model->getState();
		$this->items = $model->getItems();
		$this->pagination = $model->getPagination();
		$this->filterForm = $model->getFilterForm();
		$this->activeFilters = $model->getActiveFilters();

		if ($model->getErrors())
		{
			throw new \RuntimeException(implode("\n", $model->getErrors()));
		}

		HTMLHelper::_('behavior.multiselect');
		$entity = static::ENTITY;
		$list = $entity . 's';
		ToolbarHelper::title(Text::_('COM_JOOMENGINE_MCP_' . strtoupper($list)), 'plug');
		ToolbarHelper::addNew($entity . '.add');
		ToolbarHelper::publish($list . '.publish', 'JTOOLBAR_PUBLISH', true);
		ToolbarHelper::unpublish($list . '.unpublish', 'JTOOLBAR_UNPUBLISH', true);
		ToolbarHelper::archiveList($list . '.archive');
		ToolbarHelper::checkin($list . '.checkin');

		if ((string) $this->state->get('filter.published') === '-2')
		{
			ToolbarHelper::deleteList('JGLOBAL_CONFIRM_DELETE', $list . '.delete');
		}
		else
		{
			ToolbarHelper::trash($list . '.trash');
		}

		ToolbarHelper::preferences('com_joomengine_mcp');

		return parent::display($tpl);
	}

	/** @return string Fixed singular name used by the shared template. @since 0.1.0 */
	public function entity(): string
	{
		return static::ENTITY;
	}
}
