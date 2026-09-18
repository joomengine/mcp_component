<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Site\Controller;


use Joomla\CMS\MVC\Controller\BaseController;


/**
 * Refuses a site-application shortcut around Joomla's authenticated API route.
 *
 * @since  0.1.0
 */
final class DisplayController extends BaseController
{
	/** @inheritDoc */
	public function display($cachable = false, $urlparams = [])
	{
		throw new \RuntimeException('Use the authenticated Joomla API MCP endpoint.', 404);
	}
}
