<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\View\Operations;


use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as JoomlaHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;


/**
 * Renders redacted durable-state lists and separately authorized manual actions.
 *
 * @since  0.1.0
 */
final class HtmlView extends JoomlaHtmlView
{
	/** @var array Redacted state rows. @since 0.1.0 */
	public $items;
	/** @var object Native list state. @since 0.1.0 */
	public $state;
	/** @var object Native pagination. @since 0.1.0 */
	public $pagination;

	/** @inheritDoc */
	public function display($tpl = null)
	{
		if (!$this->getCurrentUser()->authorise('mcp.audit', 'com_joomengine_mcp'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$model = $this->getModel();
		$this->state = $model->getState();
		$this->items = $model->getItems();
		$this->pagination = $model->getPagination();

		if ($model->getErrors())
		{
			throw new \RuntimeException(implode("\n", $model->getErrors()));
		}

		ToolbarHelper::title(Text::_('COM_JOOMENGINE_MCP_OPERATIONS'), 'history');

		return parent::display($tpl);
	}
}
