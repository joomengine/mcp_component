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
 * Marker for operations executed by the component's isolated CLI worker after
 * confirmation. The durable execution/plan rows are the job, not an in-memory
 * queue. A queued response is explicitly not successful target execution.
 *
 * @since  0.1.0
 */
interface DeferredHandlerInterface extends PlannedHandlerInterface
{
}
