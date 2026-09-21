<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Job;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;


/**
 * Fixed PHP CLI dispatcher. No shell, executable name, flags or paths come from a
 * tool, catalogue row or queued payload. Capabilities travel only through stdin.
 *
 * @since 0.1.0
 */
final class ProcessLauncher
{
	/** @var PhpProcess Bounded fixed-script IPC. @since 0.1.0 */
	private PhpProcess $process;

	/** @param string $binary Operator-selected PHP CLI. @param string $script Installed job bootstrap. @param string $directory Joomla root. @since 0.1.0 */
	public function __construct(string $binary, string $script, string $directory)
	{
		$this->process = new PhpProcess($binary, $script, $directory);
	}

	/** @return void Reject unsupported process isolation before claiming a write. @since 0.1.0 */
	public function ready(): void
	{
		$result = $this->process->run(['protocol' => 'joomengine-worker/1', 'operation' => 'job.probe'], 10, 65536, static fn (): bool => false);

		if (($result['available'] ?? false) !== true)
		{
			throw new OperationException('WORKER_UNAVAILABLE', 'Background jobs require the configured PHP CLI with pcntl and POSIX process support.');
		}
	}

	/** @param string $jobId Existing job UUID. @param string $ticket One-time opaque dispatch token. @return void @since 0.1.0 */
	public function __invoke(string $jobId, string $ticket): void
	{
		$result = $this->process->run(['protocol' => 'joomengine-worker/1', 'operation' => 'job.launch', 'jobId' => $jobId, 'ticket' => $ticket],
			10, 65536, static fn (): bool => false);

		if (($result['started'] ?? false) !== true)
		{
			throw new OperationException('WORKER_UNAVAILABLE', 'The background worker did not acknowledge dispatch.');
		}
	}
}
