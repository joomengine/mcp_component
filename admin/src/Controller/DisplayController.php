<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Controller;


use Joomla\CMS\MVC\Controller\BaseController;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


/**
 * Restricts administrator rendering to the component's explicit native views.
 *
 * @since  0.1.0
 */
final class DisplayController extends BaseController
{
	/** @var string Initial catalogue screen. @since 0.1.0 */
	protected $default_view = 'providers';

	/** @inheritDoc */
	public function display($cachable = false, $urlparams = [])
	{
		$view = $this->input->getCmd('view', $this->default_view);
		$definitions = array_keys(Structure::definitions());
		$allowed = array_merge($definitions, array_map(static fn (string $name): string => $name . 's', $definitions), ['operations']);
		$permission = $view === 'operations' ? 'mcp.audit' : 'core.admin';

		if (!in_array($view, $allowed, true) || !$this->app->getIdentity()->authorise($permission, 'com_joomengine_mcp'))
		{
			throw new \RuntimeException('Not authorized to view this MCP administration screen.', 403);
		}

		if ($this->input->get('layout') === 'edit' && in_array($view, $definitions, true))
		{
			$id = $this->input->getInt('id');

			if ($id > 0 && !$this->checkEditId('com_joomengine_mcp.edit.' . $view, $id))
			{
				$this->setRedirect('index.php?option=com_joomengine_mcp&view=' . $view . 's');

				return $this;
			}
		}

		return parent::display(false, $urlparams);
	}
}
