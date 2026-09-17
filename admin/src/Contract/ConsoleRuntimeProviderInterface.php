<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


use Joomla\CMS\Application\ConsoleApplication;


/**
 * Native extension boundary for resolving a genuinely local console runtime.
 *
 * @since 0.1.0
 */
interface ConsoleRuntimeProviderInterface
{
	/** @param ConsoleApplication $application Actual Joomla console. @return ConsoleRuntimeInterface Shared component runtime. @since 0.1.0 */
	public function getConsoleRuntime(ConsoleApplication $application): ConsoleRuntimeInterface;
}
