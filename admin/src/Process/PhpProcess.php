<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Process;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Bounded isolated PHP execution with an argv vector, never a shell command.
 * The executable and script are selected by trusted service composition. Input
 * data travels on stdin, not the command line or environment. Cancellation and
 * wall-clock timeout terminate the child and retain an uncertain write outcome.
 *
 * @since  0.1.0
 */
final class PhpProcess
{
	/** @var string Server-owned PHP CLI executable. @since 0.1.0 */
	private string $binary;
	/** @var string Server-owned child bootstrap file. @since 0.1.0 */
	private string $script;
	/** @var string Joomla installation working directory. @since 0.1.0 */
	private string $directory;

	/**
	 * Configure an isolated child from trusted server paths.
	 *
	 * @param   string  $binary     Actual PHP CLI executable.
	 * @param   string  $script     Fixed, installed child bootstrap.
	 * @param   string  $directory  Joomla installation root.
	 * @since   0.1.0
	 */
	public function __construct(string $binary, string $script, string $directory)
	{
		if (!is_file($binary) || !is_executable($binary) || !is_file($script) || !is_dir($directory))
		{
			throw new OperationException('WORKER_UNAVAILABLE', 'The installed PHP worker paths are unavailable.');
		}

		$this->binary = $binary;
		$this->script = $script;
		$this->directory = $directory;
	}

	/**
	 * Run one bounded IPC request and require one structured child response.
	 *
	 * @param   array     $payload  Server-created worker input.
	 * @param   int       $timeout  Maximum wall-clock seconds.
	 * @param   int       $maximum  Maximum total output bytes.
	 * @param   callable  $cancel   Polls cancellation without altering the process.
	 * @return  array  Structured child result, never an inferred success on timeout.
	 * @since   0.1.0
	 */
	public function run(array $payload, int $timeout, int $maximum, callable $cancel): array
	{
		if (!function_exists('proc_open') || $timeout < 1 || $timeout > 3600 || $maximum < 1024 || $maximum > 16777216)
		{
			throw new OperationException('WORKER_UNAVAILABLE', 'A bounded local process runtime is required.');
		}

		$input = Json::encode($payload, 4194304);
		$argv = [$this->binary];
		$ini = php_ini_loaded_file();

		if (is_string($ini))
		{
			$argv = array_merge($argv, ['-c', $ini]);
		}

		$argv = array_merge($argv, ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'display_errors=stderr', $this->script]);
		$environment = getenv();

		// Some process runtimes drop empty environment values. Preserve an explicitly
		// disabled INI scan using an existing regular file, which cannot be scanned
		// as a directory; do not fall back to unrelated system extension settings.
		if (($environment['PHP_INI_SCAN_DIR'] ?? null) === '')
		{
			$environment['PHP_INI_SCAN_DIR'] = __FILE__;
		}

		foreach (array_keys($environment) as $name)
		{
			if (str_starts_with($name, 'JCB_') || $name === 'JOOMENGINE_MCP_TOKEN')
			{
				unset($environment[$name]);
			}
		}

		$pipes = [];
		$process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
			$this->directory, $environment, ['bypass_shell' => true]);

		if (!is_resource($process))
		{
			throw new OperationException('WORKER_UNAVAILABLE', 'The isolated PHP worker could not be started.');
		}

		foreach ($pipes as $pipe)
		{
			stream_set_blocking($pipe, false);
		}

		$offset = 0;
		$stdout = '';
		$stderr = '';
		$deadline = hrtime(true) + $timeout * 1000000000;
		$nextPoll = 0;
		$exit = null;
		$stop = null;

		try
		{
			while (true)
			{
				$now = hrtime(true);

				if ($now >= $nextPoll)
				{
					$nextPoll = $now + 250000000;

					if ($cancel())
					{
						$stop = 'WORKER_CANCELLED';
						break;
					}
				}

				if ($now >= $deadline)
				{
					$stop = 'WORKER_TIMEOUT';
					break;
				}

				if (isset($pipes[0]))
				{
					$written = fwrite($pipes[0], substr($input, $offset, 8192));

					if ($written === false)
					{
						throw new OperationException('WORKER_IO', 'The child input channel failed.');
					}

					$offset += $written;

					if ($offset === strlen($input))
					{
						fclose($pipes[0]);
						unset($pipes[0]);
					}
				}

				$stdout .= stream_get_contents($pipes[1]);
				$stderr .= stream_get_contents($pipes[2]);

				if (strlen($stdout) + strlen($stderr) > $maximum)
				{
					$stop = 'WORKER_OUTPUT_LIMIT';
					break;
				}

				$status = proc_get_status($process);

				if (!$status['running'])
				{
					$exit = (int) $status['exitcode'];
					$stdout .= stream_get_contents($pipes[1]);
					$stderr .= stream_get_contents($pipes[2]);
					break;
				}

				usleep(10000);
			}

			if ($stop !== null)
			{
				throw new OperationException($stop, 'The native worker was stopped. It may have persisted partial effects; inspect and reconcile before replaying.');
			}

			if ($exit !== 0 || strlen($stdout) + strlen($stderr) > $maximum)
			{
				throw new OperationException('WORKER_FAILED', 'The native worker did not return a complete response; inspect its execution outcome.');
			}

			$result = Json::decode(trim($stdout), maximum: $maximum);

			if (!is_array($result) || !isset($result['protocol']) || $result['protocol'] !== 'joomengine-worker/1')
			{
				throw new OperationException('WORKER_PROTOCOL', 'The child did not return the installed worker protocol.');
			}

			return $result;
		}
		finally
		{
			if (proc_get_status($process)['running'])
			{
				proc_terminate($process, 15);
				$end = hrtime(true) + 2000000000;

				while (proc_get_status($process)['running'] && hrtime(true) < $end)
				{
					usleep(10000);
				}

				if (proc_get_status($process)['running'])
				{
					proc_terminate($process, 9);
				}
			}

			foreach ($pipes as $pipe)
			{
				fclose($pipe);
			}

			proc_close($process);
		}
	}
}
