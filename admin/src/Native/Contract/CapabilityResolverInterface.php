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
 * Resolve the actor and effective Joomla permissions for native actions.
 *
 * @since  0.1.0
 */
interface CapabilityResolverInterface
{
	/**
	 * Describe the effective native execution actor.
	 *
	 *  @return array{id: int|null, configured: bool}
	 *
	 * @since  0.1.0
	 */
	public function actor(): array;

	/**
	 * Intersect every required permission with the current actor authority.
	 *
	 * @param   ActionDescriptor  $descriptor  The descriptor value.
	 * @return array{allowed: bool, requirements: list<array{action: string, asset: string, allowed: bool}>}
	 *
	 * @since  0.1.0
	 */
	public function resolve(ActionDescriptor $descriptor): array;
}
