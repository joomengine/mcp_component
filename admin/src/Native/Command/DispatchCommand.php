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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Command;


defined('_JEXEC') or die;

use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\CapabilityResolverInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Native\Protocol\DispatchService;
use VDM\Component\JoomEngineMcp\Administrator\Native\Protocol\RequestDecoder;


/**
 * Process bounded native action requests from standard input.
 *
 * @since  0.1.0
 */
final class DispatchCommand extends AbstractCommand
{
	/**
	 * The maximum number of requests processed in one console session.
	 *
	 * @since  0.1.0
	 */
	private const MAX_NDJSON_REQUESTS = 1_000;

	/**
	 * The console command identifier inherited from Joomla.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	protected static $defaultName = 'joomla:mcp:dispatch';

	/**
	 * The registry of reviewed native action implementations.
	 *
	 * @var   ActionRegistry
	 *
	 * @since  0.1.0
	 */
	private ActionRegistry $registry;

	/**
	 * The current actor capability resolver.
	 *
	 * @var   CapabilityResolverInterface
	 *
	 * @since  0.1.0
	 */
	private CapabilityResolverInterface $capabilities;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   ActionRegistry               $registry      The registry of reviewed native action implementations.
	 * @param   CapabilityResolverInterface  $capabilities  The current actor capability resolver.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		ActionRegistry $registry,
		CapabilityResolverInterface $capabilities,
	)
	{
		$this->registry = $registry;
		$this->capabilities = $capabilities;

		parent::__construct();
	}

	/**
	 * Declare the fixed console command options and help text.
	 *
	 * @return  void
	 *
	 * @since  0.1.0
	 */
	protected function configure(): void
	{
		// Joomla Framework Console setters return void, unlike Symfony's
		// fluent Command API. Keep every configuration call independent.
		$this->setDescription('Dispatch allowlisted Joomla MCP actions from JSON or NDJSON on stdin.');
		$this->addOption('input', null, InputOption::VALUE_REQUIRED, 'Input source. Only - (stdin) is supported.', '-');
		$this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Input/output framing: json or ndjson.', 'json');
	}

	/**
	 * Run the command protocol and return a native console exit status.
	 *
	 * @param   InputInterface   $input   The input value.
	 * @param   OutputInterface  $output  The output value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	protected function doExecute(InputInterface $input, OutputInterface $output): int
	{
		if ($input->getOption('input') !== '-')
		{
			$this->write($this->commandError('INVALID_INPUT_SOURCE', 'Input source must be stdin (-).'));

			return 2;
		}

		$format = $input->getOption('format');

		if (!is_string($format) || !in_array($format, ['json', 'ndjson'], true))
		{
			$this->write($this->commandError('INVALID_FORMAT', 'Format must be json or ndjson.'));

			return 2;
		}

		$dispatcher = new DispatchService($this->registry, $this->capabilities);

		if ($format === 'json')
		{
			$payload = stream_get_contents(STDIN, RequestDecoder::MAX_BYTES + 1);
			$this->write($dispatcher->handleJson(is_string($payload) ? $payload : ''));

			return 0;
		}

		$count = 0;

		while (($line = fgets(STDIN, RequestDecoder::MAX_BYTES + 2)) !== false)
		{
			if (++$count > self::MAX_NDJSON_REQUESTS)
			{
				$this->write($this->commandError('REQUEST_LIMIT', 'NDJSON request limit exceeded.'));

				return 2;
			}

			if (trim($line) === '')
			{
				continue;
			}

			$this->write($dispatcher->handleJson($line));

			if (strlen($line) > RequestDecoder::MAX_BYTES)
			{
				// Stop instead of treating the remainder of an oversized line as another request.
				return 2;
			}
		}

		return 0;
	}

	/**
	 * Encode a console protocol failure without exposing an exception trace.
	 *
	 * @param   string  $code     The code value.
	 * @param   string  $message  The message value.
	 * @return  string
	 *
	 * @since  0.1.0
	 */
	private function commandError(string $code, string $message): string
	{
		return json_encode([
			'protocol' => RequestDecoder::PROTOCOL,
			'id' => null,
			'ok' => false,
			'error' => ['code' => $code, 'message' => $message],
		], JSON_UNESCAPED_SLASHES) ?: '{"protocol":"joomla-mcp/1","id":null,"ok":false,"error":{"code":"ENCODING_FAILED","message":"Response encoding failed."}}';
	}

	/**
	 * Write one complete protocol line to standard output.
	 *
	 * @param   string  $line  The line value.
	 * @return  void
	 *
	 * @since  0.1.0
	 */
	private function write(string $line): void
	{
		// Write protocol data directly so the edge can keep the JSON payload
		// separate from Joomla's interactive and ANSI console output.
		fwrite(STDOUT, $line . PHP_EOL);
	}
}
