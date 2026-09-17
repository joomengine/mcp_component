<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


/**
 * Reviewed execution primitive selected by declarative database bindings.
 *
 * @since  0.1.0
 */
interface HandlerInterface
{
	/**
	 * Execute validated arguments under the supplied authority.
	 *
	 * @param   array<string,mixed>  $arguments  Validated action arguments.
	 * @param   array<string,mixed>  $binding    Validated declarative binding.
	 * @param   PrincipalInterface  $principal  Trusted request authority.
	 * @return  array<string,mixed>  Structured result, including partial effects.
	 * @throws  \RuntimeException   When the operation cannot complete.
	 * @since   0.1.0
	 */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array;
}
