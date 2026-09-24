<?php
/**
 * @package    JoomEngine.Mcp
 * @created    24 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


/**
 * Optional public explanation of a privately prepared native operation.
 *
 * Existing planned handlers remain substitutable without this capability. Only
 * reviewed handler code may select public fields; preparation itself may contain
 * credentials and must never be copied wholesale into a confirmation response.
 *
 * @since 0.1.1
 */
interface PlanPreviewInterface extends PlannedHandlerInterface
{
	/**
	 * Describe exactly the frozen preparation, without additional I/O or mutation.
	 *
	 * @param array<string,mixed> $prepared Private, already authorized preparation.
	 * @return array<string,mixed> Allowlisted public details without credentials or paths.
	 * @since 0.1.1
	 */
	public function preview(array $prepared): array;
}
