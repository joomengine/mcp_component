<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @git        JoomEngine MCP <https://github.com/joomengine/mcp_component>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSES/joomla-mcp.txt
 * @since      0.1.0
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Contract;


/**
 * Provide administrator models through a substitutable Joomla boundary.
 *
 * @since  0.1.0
 */
interface ModelProviderInterface
{
	/**
	 * Boot and return the selected native administrator model.
	 *
	 * @param   string  $component  The fixed Joomla component identifier.
	 * @param   string  $modelName  The fixed administrator model name.
	 * @return  object
	 *
	 * @since  0.1.0
	 */
	public function administrator(string $component, string $modelName): object;
}
