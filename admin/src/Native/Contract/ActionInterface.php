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


use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;


/**
 * Define the descriptor and execution boundary for a native action.
 *
 * @since  0.1.0
 */
interface ActionInterface
{
	/**
	 * Return the action identity, schemas and required Joomla permissions.
	 *
	 * @return  ActionDescriptor
	 *
	 * @since  0.1.0
	 */
	public function descriptor(): ActionDescriptor;

	/**
	 * Validate the supplied input and perform this action within its native contract.
	 *
	 * @param   array<string, mixed>  $input  The input value.
	 * @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	public function execute(array $input): array;
}
