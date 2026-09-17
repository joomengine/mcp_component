<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


use VDM\Component\JoomEngineMcp\Administrator\Service\RuntimeFactory;


/**
 * Injected composition boundary for Joomla-created controllers and models.
 *
 * @since 0.1.0
 */
interface RuntimeAwareInterface
{
	/** @param RuntimeFactory $runtime Component composition root. @return void @since 0.1.0 */
	public function setRuntimeFactory(RuntimeFactory $runtime): void;
}
