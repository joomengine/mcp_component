<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Console;


use Joomla\CMS\Application\ConsoleApplication;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Isolated CLI application exposing Joomla's protected command runner, so native
 * before/error/terminate events remain intact. Used only by the fixed installed
 * worker bootstrap; never created in the HTTP MCP application.
 *
 * @since  0.1.0
 */
final class WorkerApplication extends ConsoleApplication
{
	/**
	 * Execute a registry-resolved command through the native event lifecycle.
	 *
	 * @param   AbstractCommand  $command  JCB's reviewed registered command.
	 * @param   InputInterface   $input    Validated, noninteractive native arguments.
	 * @param   OutputInterface  $output   Bounded captured output.
	 * @return  int  Native command exit status, including event overrides.
	 * @since   0.1.0
	 */
	public function invokeNativeCommand(AbstractCommand $command, InputInterface $input, OutputInterface $output): int
	{
		return $this->runCommand($command, $input, $output);
	}
}
