<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


/**
 * Optional side-effect-free handler availability, evaluated during discovery and
 * again before execution. Database bindings cannot advertise missing commands or
 * incompatible installed implementations merely because a primitive is loaded.
 *
 * @since  0.1.1
 */
interface HandlerAvailabilityInterface
{
	/** @param array $binding Reviewed row. @param PrincipalInterface $principal Current authority. @return bool Native capability exists for this binding. @since 0.1.1 */
	public function available(array $binding, PrincipalInterface $principal): bool;
}
