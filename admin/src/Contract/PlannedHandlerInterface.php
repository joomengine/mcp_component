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
 * Explicit lifecycle for native operations which do not implement an API request
 * or the original companion's dryRun flags. Preparation must never mutate the
 * target. Its result is encrypted in a principal-bound plan and compared again
 * before execution. Verification must describe the actual observed effect.
 *
 * @since  0.1.0
 */
interface PlannedHandlerInterface extends HandlerInterface
{
	/**
	 * Resolve a deterministic operation without executing it.
	 *
	 * @param   array<string,mixed>  $arguments  Validated input.
	 * @param   array<string,mixed>  $binding    Authorized definition.
	 * @param   PrincipalInterface  $principal  Actual request authority.
	 * @return  array<string,mixed>  Prepared data; may contain secrets and is never public.
	 * @since   0.1.0
	 */
	public function prepare(array $arguments, array $binding, PrincipalInterface $principal): array;

	/**
	 * Apply exactly the prepared data after the durable execution claim.
	 *
	 * @param   array<string,mixed>  $prepared   Unchanged encrypted plan data.
	 * @param   array<string,mixed>  $binding    Re-authorized definition.
	 * @param   PrincipalInterface  $principal  Actual execution authority.
	 * @param   array<string,mixed>  $execution  Server-owned claim, grant and revision context.
	 * @return  array<string,mixed>  Mutation result, not an assertion of verification.
	 * @since   0.1.0
	 */
	public function apply(array $prepared, array $binding, PrincipalInterface $principal, array $execution): array;

	/**
	 * Independently inspect the effect of the applied operation.
	 *
	 * @param   array<string,mixed>  $prepared   Approved operation.
	 * @param   array<string,mixed>  $result     Mutation response.
	 * @param   array<string,mixed>  $binding    Re-authorized definition.
	 * @param   PrincipalInterface  $principal  Actual execution authority.
	 * @return  array<string,mixed>  A verification status and concrete observation.
	 * @since   0.1.0
	 */
	public function verify(array $prepared, array $result, array $binding, PrincipalInterface $principal): array;
}
