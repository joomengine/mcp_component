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
 * Fixed adapters for Joomla's own console operations.
 *
 * Deliberately expose semantic methods instead of a command-name parameter so
 * callers can never turn the companion into a generic Joomla CLI passthrough.
 *
 * @since  0.1.0
 */
interface NativeOperationsInterface
{
	/**
	 * Read the effective Joomla site offline state.
	 *
	 * @return  bool
	 *
	 * @since  0.1.0
	 */
	public function siteOfflineState(): bool;

	/**
	 * Invoke the native command that changes the site offline state.
	 *
	 * @param   bool  $offline  The offline value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function setSiteOffline(bool $offline): int;

	/**
	 * Invoke native session cleanup for the selected Joomla application.
	 *
	 * @param   string  $application  The current Joomla application context.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function garbageCollectSessions(string $application): int;

	/**
	 * Invoke native cleanup of expired session metadata.
	 *
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function garbageCollectSessionMetadata(): int;

	/**
	 * Invoke the native state command for one scheduler task.
	 *
	 * @param   int  $id     The stable entity identifier.
	 * @param   int  $state  The state value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function setSchedulerTaskState(int $id, int $state): int;

	/**
	 * Invoke the native run command for one scheduler task.
	 *
	 * @param   int  $id  The stable entity identifier.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function runSchedulerTask(int $id): int;
}
