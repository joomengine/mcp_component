<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Shared component services consumed by the independent console plugin.
 *
 * @since 0.1.0
 */
interface ConsoleRuntimeInterface
{
	/** @return int Protocol process exit code. @since 0.1.0 */
	public function serveStdio(): int;

	/** @param string $operation Fixed registered command. @param InputInterface $input Console input. @param OutputInterface $output Console output. @return int Exit status. @since 0.1.0 */
	public function executeCommand(string $operation, InputInterface $input, OutputInterface $output): int;
}
