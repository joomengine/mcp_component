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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Joomla;


use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\NativeOperationsInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;


/**
 * Machine-readable adapter over fixed Joomla 6.1+ core console commands.
 *
 * The command names mirror Joomla CMS libraries/src/Console. No caller can
 * supply a command name, argument name, shell fragment, PHP, or filesystem path.
 *
 * @since  0.1.0
 */
final class JoomlaNativeOperations implements NativeOperationsInterface
{
	/**
	 * The current Joomla application context.
	 *
	 * @var   object
	 *
	 * @since  0.1.0
	 */
	private object $application;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   object  $application  The current Joomla application context.
	 *
	 * @since  0.1.0
	 */
	public function __construct(object $application)
	{
		$this->application = $application;
	}

	/**
	 * Read the effective Joomla site offline state.
	 *
	 * @return  bool
	 *
	 * @since  0.1.0
	 */
	public function siteOfflineState(): bool
	{
		// SiteUpCommand/SiteDownCommand persist configuration.php, while the
		// already-loaded JConfig class retains the pre-command value.
		if (defined('JPATH_CONFIGURATION'))
		{
			$configurationFile = JPATH_CONFIGURATION . '/configuration.php';
			$contents = @file_get_contents($configurationFile);

			if (is_string($contents)
				&& preg_match(
					'/public\\s+\\$offline\\s*=\\s*(true|false|0|1|[\'"]0[\'"]|[\'"]1[\'"])\\s*;/i',
					$contents,
					$match,
				) === 1)
			{
				return in_array(strtolower(trim($match[1], '\'"')), ['true', '1'], true);
			}
		}

		if (!method_exists($this->application, 'get'))
		{
			throw new ActionException('JOOMLA_RUNTIME_UNAVAILABLE', 'Joomla site state is unavailable.');
		}

		return (bool) $this->application->get('offline', false);
	}

	/**
	 * Invoke the native command that changes the site offline state.
	 *
	 * @param   bool  $offline  The offline value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function setSiteOffline(bool $offline): int
	{
		// Native Joomla commands: SiteDownCommand / SiteUpCommand.
		return $this->run($offline ? 'site:down' : 'site:up');
	}

	/**
	 * Invoke native session cleanup for the selected Joomla application.
	 *
	 * @param   string  $application  The current Joomla application context.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function garbageCollectSessions(string $application): int
	{
		// Native Joomla command: SessionGcCommand. The public action permits
		// only the two web-session services registered by Joomla for this use.
		if (!in_array($application, ['site', 'administrator'], true))
		{
			throw new ActionException('INVALID_INPUT', 'Unsupported Joomla session application.');
		}

		return $this->run('session:gc', ['--application' => $application]);
	}

	/**
	 * Invoke native cleanup of expired session metadata.
	 *
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function garbageCollectSessionMetadata(): int
	{
		// Native Joomla command: SessionMetadataGcCommand.
		return $this->run('session:metadata:gc');
	}

	/**
	 * Invoke the native state command for one scheduler task.
	 *
	 * @param   int  $id     The stable entity identifier.
	 * @param   int  $state  The state value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function setSchedulerTaskState(int $id, int $state): int
	{
		// Native Joomla command: TasksStateCommand.
		if ($id < 1 || !in_array($state, [-2, 0, 1], true))
		{
			throw new ActionException('INVALID_INPUT', 'Invalid scheduler task state transition.');
		}

		return $this->run('scheduler:state', ['--id' => $id, '--state' => $state]);
	}

	/**
	 * Invoke the native run command for one scheduler task.
	 *
	 * @param   int  $id  The stable entity identifier.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public function runSchedulerTask(int $id): int
	{
		// Native Joomla command: TasksRunCommand. Only one explicit task may run.
		if ($id < 1)
		{
			throw new ActionException('INVALID_INPUT', 'Invalid scheduler task identifier.');
		}

		return $this->run('scheduler:run', ['--id' => $id]);
	}

	/**
	 * Invoke one internally selected Joomla command and preserve application state.
	 *
	 * @param   string                          $name       The stable public action identifier.
	 * @param   array<string, int|string|bool>  $arguments  The arguments value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	private function run(string $name, array $arguments = []): int
	{
		if (!method_exists($this->application, 'hasCommand')
			|| !method_exists($this->application, 'getCommand')
			|| !$this->application->hasCommand($name))
		{
			throw new ActionException('NATIVE_COMMAND_UNAVAILABLE', sprintf('Required Joomla command "%s" is unavailable.', $name));
		}

		try
		{
			$command = $this->application->getCommand($name);

			if (!is_object($command) || !method_exists($command, 'execute'))
			{
				throw new ActionException('NATIVE_COMMAND_UNAVAILABLE', sprintf('Required Joomla command "%s" is incompatible.', $name));
			}

			$stream = fopen('php://temp/maxmemory:4096', 'w+b');

			if ($stream === false)
			{
				throw new ActionException('NATIVE_COMMAND_FAILED', 'Could not allocate bounded native-command diagnostics.');
			}

			try
			{
				$exitCode = (int) $command->execute(new ArrayInput($arguments), new StreamOutput($stream));
				rewind($stream);
				$diagnostic = stream_get_contents($stream, 2_048);
			}
			finally
			{
				fclose($stream);
			}

			if ($exitCode !== 0)
			{
				$detail = is_string($diagnostic)
					? trim(preg_replace('/\s+/u', ' ', strip_tags($diagnostic)) ?? '')
					: '';
				throw new ActionException(
					'NATIVE_COMMAND_FAILED',
					sprintf(
						'Joomla command "%s" exited %d.%s',
						$name,
						$exitCode,
						$detail === '' ? '' : ' Output: ' . substr($detail, 0, 1_500),
					),
				);
			}

			return $exitCode;
		}
		catch (ActionException $exception)
		{
			throw $exception;
		}
		catch (Throwable)
		{
			throw new ActionException('NATIVE_COMMAND_FAILED', sprintf('Joomla command "%s" failed.', $name));
		}
	}
}
