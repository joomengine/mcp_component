<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Console;


use Throwable;


/**
 * Scope console diagnostics away from the MCP protocol's stdout stream.
 *
 * Protocol writers use STDOUT directly. Ordinary PHP/Joomla echoed diagnostics
 * are discarded, and PHP native fatal diagnostics and exception renderers use
 * stderr. PHP may discard output buffers after memory exhaustion; fatal
 * isolation therefore uses stderr settings and the registered exception
 * renderer, rather than relying on buffering. Extensions writing directly
 * to STDOUT remain responsible for obeying the protocol stream contract.
 *
 * @since 0.1.1
 */
final class ProtocolOutput
{
	/** @param callable():int $operation Server operation. @return int Process exit status. @since 0.1.1 */
	public function run(callable $operation): int
	{
		$configuration = [];

		foreach (['display_errors' => 'stderr', 'html_errors' => '0', 'log_errors' => '1', 'error_log' => 'php://stderr'] as $key => $value)
		{
			$configuration[$key] = ini_get($key);
			ini_set($key, $value);
		}

		$level = ob_get_level();
		ob_start(static fn (string $output): string => '', 4096);
		set_exception_handler(static function (Throwable $error): void
		{
			fwrite(STDERR, 'The MCP stdio runtime terminated after ' . get_class($error) . ".\n");
		});

		try
		{
			return $operation();
		}
		catch (Throwable $error)
		{
			fwrite(STDERR, 'The MCP stdio runtime stopped after ' . get_class($error) . ".\n");

			return 1;
		}
		finally
		{
			restore_exception_handler();

			while (ob_get_level() > $level)
			{
				ob_end_clean();
			}

			foreach ($configuration as $key => $value)
			{
				ini_set($key, $value === false ? '' : $value);
			}
		}
	}
}
