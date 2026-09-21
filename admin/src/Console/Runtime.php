<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Console;


use Closure;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\Database\DatabaseInterface;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\ConsoleRuntimeInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Protocol\RequestDecoder;
use VDM\Component\JoomEngineMcp\Administrator\Security\ConsoleIdentity;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\RequestRuntime;


/**
 * Local-only command runtime with scoped native model authority and clean stdout.
 *
 * The server-owner identity is restored after every command, including failures.
 * Commands register lazily; unrelated Joomla commands never acquire this identity.
 *
 * @since 0.1.0
 */
final class Runtime implements ConsoleRuntimeInterface
{
	/** @var ConsoleApplication Actual local application. @since 0.1.0 */
	private ConsoleApplication $application;
	/** @var DatabaseInterface Native identity metadata. @since 0.1.0 */
	private DatabaseInterface $database;
	/** @var RequestRuntime Shared protocol and action services. @since 0.1.0 */
	private RequestRuntime $runtime;
	/** @var Inspector Native command introspection. @since 0.1.0 */
	private Inspector $inspector;
	/** @var ?Closure Explicit privileged JCB catalogue refresh. @since 0.1.1 */
	private ?Closure $synchronizeJcb;

	/** @param ConsoleApplication $application Application. @param DatabaseInterface $database Native database. @param RequestRuntime $runtime Shared services. @param Inspector $inspector Console registry. @since 0.1.0 */
	public function __construct(ConsoleApplication $application, DatabaseInterface $database, RequestRuntime $runtime, Inspector $inspector, ?callable $synchronizeJcb = null)
	{
		$this->application = $application;
		$this->database = $database;
		$this->runtime = $runtime;
		$this->inspector = $inspector;
		$this->synchronizeJcb = $synchronizeJcb === null ? null : Closure::fromCallable($synchronizeJcb);
	}

	/** @inheritDoc */
	public function serveStdio(): int
	{
		return $this->scoped(function (): int
		{
			return (int) $this->runtime->server()->run(new StdioTransport(maxLineBytes: $this->runtime->settings()->get('max_request_bytes')));
		});
	}

	/** @inheritDoc */
	public function executeCommand(string $operation, InputInterface $input, OutputInterface $output): int
	{
		return $this->scoped(function () use ($operation, $input): int
		{
			$format = $input->getOption('format');

			if (!in_array($format, $operation === 'dispatch' ? ['json', 'ndjson'] : ['json'], true))
			{
				return $this->write(['protocol' => 'joomla-mcp/1', 'ok' => false, 'error' => ['code' => 'INVALID_FORMAT', 'message' => 'Unsupported command framing.']]);
			}

			$this->runtime->catalogue()->refresh();

			if ($operation === 'jcb-sync')
			{
				if ($this->synchronizeJcb === null)
				{
					throw new OperationException('JCB_UNAVAILABLE', 'The installed JCB catalogue integration is unavailable.');
				}

				return $this->write(['protocol' => 'joomla-mcp/1', 'ok' => true, 'catalogue' => ($this->synchronizeJcb)()]);
			}

			if ($operation === 'describe')
			{
				return $this->write($this->runtime->tools()->companion());
			}

			if ($operation === 'cli-inventory')
			{
				return $this->write($this->inspector->inventory());
			}

			if ($operation === 'self-test')
			{
				$description = $this->runtime->tools()->companion();
				$system = $this->runtime->actions()->legacy('system.info', []);
				$ok = count($description['actions']) > 0 && is_string($system['joomlaVersion'] ?? null) && is_string($system['phpVersion'] ?? null);

				return $this->write(['protocol' => 'joomla-mcp/1', 'ok' => $ok, 'checks' => [
					'catalogue' => ['ok' => count($description['actions']) > 0, 'actionCount' => count($description['actions'])],
					'dispatch' => ['ok' => $ok, 'action' => 'system.info', 'result' => $system],
				]]);
			}

			if ($operation !== 'dispatch' || $input->getOption('input') !== '-')
			{
				throw new OperationException('INVALID_COMMAND', 'Dispatch accepts only stdin (-).');
			}

			if ($format === 'json')
			{
				$data = stream_get_contents(STDIN, RequestDecoder::MAX_BYTES + 1);

				return $this->dispatch(is_string($data) ? $data : '');
			}

			$count = 0;
			$status = 0;

			while (($line = fgets(STDIN, RequestDecoder::MAX_BYTES + 2)) !== false)
			{
				if (++$count > 1000)
				{
					throw new OperationException('REQUEST_LIMIT', 'The NDJSON command limit was exceeded.');
				}

				if (trim($line) === '')
				{
					continue;
				}

				$status = max($status, $this->dispatch($line));

				if (strlen($line) > RequestDecoder::MAX_BYTES)
				{
					return 1;
				}
			}

			return $status;
		});
	}

	/** @param string $json One legacy request frame. @return int Nonzero on an operation failure. @since 0.1.0 */
	private function dispatch(string $json): int
	{
		$id = null;

		try
		{
			$request = (new RequestDecoder())->decode($json);
			$id = $request['id'];
			$this->runtime->catalogue()->refresh();
			$result = $this->runtime->actions()->legacy($request['action'], $request['input']);

			return $this->write(['protocol' => 'joomla-mcp/1', 'id' => $id, 'ok' => true, 'result' => $result]);
		}
		catch (OperationException $error)
		{
			return $this->write(['protocol' => 'joomla-mcp/1', 'id' => $id, 'ok' => false, 'error' => $error->toArray()]);
		}
		catch (ActionException $error)
		{
			return $this->write(['protocol' => 'joomla-mcp/1', 'id' => $id, 'ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]);
		}
		catch (Throwable)
		{
			return $this->write(['protocol' => 'joomla-mcp/1', 'id' => $id, 'ok' => false, 'error' => ['code' => 'ACTION_FAILED', 'message' => 'The Joomla action could not complete.']]);
		}
	}

	/** @param array<string,mixed> $result Protocol result. @return int Exit status. @since 0.1.0 */
	private function write(array $result): int
	{
		$line = Json::encode($result, $this->runtime->settings()->get('max_result_bytes')) . "\n";

		for ($offset = 0, $length = strlen($line); $offset < $length; $offset += $written)
		{
			$written = fwrite(STDOUT, substr($line, $offset));

			if ($written === false || $written === 0)
			{
				return 1;
			}
		}

		return ($result['ok'] ?? false) ? 0 : 1;
	}

	/** @param callable():int $operation Protocol operation. @return int Exit status with restored Joomla identity. @since 0.1.0 */
	private function scoped(callable $operation): int
	{
		if (PHP_SAPI !== 'cli')
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'The local console is required.');
		}

		$previous = $this->application->getIdentity();
		$level = ob_get_level();
		$this->application->loadIdentity(new ConsoleIdentity($this->application, $this->database));
		ob_start(static fn (string $output): string => '', 4096);

		try
		{
			return $operation();
		}
		finally
		{
			$this->application->loadIdentity($previous);

			while (ob_get_level() > $level)
			{
				ob_end_clean();
			}
		}
	}
}
