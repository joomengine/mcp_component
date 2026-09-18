<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\View;


use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;


/**
 * Standard Joomla edit view using XML fields, native validation and toolbar tasks.
 *
 * @since  0.1.0
 */
abstract class DefinitionHtmlView extends HtmlView
{
	/** @var string Concrete entity identity. @since 0.1.0 */
	protected const ENTITY = '';
	/** @var object Native Joomla form. @since 0.1.0 */
	public $form;
	/** @var object Loaded editable item. @since 0.1.0 */
	public $item;
	/** @var object Native model state. @since 0.1.0 */
	public $state;

	/** @inheritDoc */
	public function display($tpl = null)
	{
		if (!$this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$model = $this->getModel();
		$this->state = $model->getState();
		$this->item = $model->getItem();
		$this->form = $model->getForm();

		if (!$this->item || !$this->form || $model->getErrors())
		{
			throw new \RuntimeException(implode("\n", $model->getErrors()));
		}

		$entity = static::ENTITY;
		ToolbarHelper::title(Text::_('COM_JOOMENGINE_MCP_' . strtoupper($entity) . (empty($this->item->id) ? '_NEW' : '_EDIT')), 'plug');
		ToolbarHelper::apply($entity . '.apply');
		ToolbarHelper::save($entity . '.save');
		ToolbarHelper::save2new($entity . '.save2new');
		ToolbarHelper::cancel($entity . '.cancel', empty($this->item->id) ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
		$this->getDocument()->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_joomengine_mcp');
		$this->getDocument()->getWebAssetManager()->useScript('com_joomengine_mcp.edit');

		return parent::display($tpl);
	}

	/** @return string Fixed singular name used by the shared template. @since 0.1.0 */
	public function entity(): string
	{
		return static::ENTITY;
	}
}
