<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Controller;


use Joomla\CMS\MVC\Controller\AdminController;


/**
 * Native batch-state controller resolving the explicit singular record model.
 *
 * @since  0.1.0
 */
abstract class DefinitionsController extends AdminController
{
	/** @var string Exact extension element; the namespace intentionally has a different spelling. @since 0.1.0 */
	protected $option = 'com_joomengine_mcp';

	/** @var string Fixed definition identity. @since 0.1.0 */
	protected const ENTITY = '';

	/** @inheritDoc */
	public function getModel($name = '', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		if (!$this->app->getIdentity()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			throw new \RuntimeException('Not authorized to change the MCP catalogue.', 403);
		}

		return parent::getModel(ucfirst(static::ENTITY), $prefix, $config);
	}
}
