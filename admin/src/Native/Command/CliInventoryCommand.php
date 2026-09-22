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

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Protocol\RequestDecoder;


/**
 * Describe registered Joomla console commands without executing them.
 *
 * @since  0.1.0
 */
final class CliInventoryCommand extends AbstractCommand
{
	/**
	 * The maximum number of registered commands in an inventory response.
	 *
	 * @since  0.1.0
	 */
	private const MAX_COMMANDS = 512;

	/**
	 * The console command identifier inherited from Joomla.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	protected static $defaultName = 'joomla:mcp:cli-inventory';

	/**
	 * The genuine Joomla console application.
	 *
	 * @var   ConsoleApplication
	 *
	 * @since  0.1.0
	 */
	private ConsoleApplication $console;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   ConsoleApplication  $console  The genuine Joomla console application.
	 *
	 * @since  0.1.0
	 */
	public function __construct(ConsoleApplication $console)
	{
		$this->console = $console;

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
		$this->setDescription('Describe the installed Joomla CLI registry as bounded machine-readable JSON.');
		$this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format. Only json is supported.', 'json');
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
		if ($input->getOption('format') !== 'json')
		{
			$this->write('{"protocol":"joomla-mcp/1","ok":false,"error":{"code":"INVALID_FORMAT","message":"CLI inventory format must be json."}}');

			return 2;
		}

		try
		{
			$commands = [];

			foreach ($this->console->getAllCommands() as $command)
			{
				$name = $command->getName();

				if ($name === '' || isset($commands[$name]))
				{
					continue;
				}

				if (count($commands) >= self::MAX_COMMANDS)
				{
					throw new \RuntimeException('Installed command count exceeds the inventory limit.');
				}

				$commands[$name] = $this->describe($command);
			}

			ksort($commands, SORT_STRING);
			$namespaces = [];

			foreach (array_keys($commands) as $name)
			{
				$separator = strpos($name, ':');

				if ($separator !== false)
				{
					$namespaces[] = substr($name, 0, $separator);
				}
			}

			$namespaces = array_values(array_unique($namespaces));
			sort($namespaces, SORT_STRING);
			$this->write(json_encode([
				'protocol' => RequestDecoder::PROTOCOL,
				'ok' => true,
				'runtime' => [
					'joomlaVersion' => defined('JVERSION') ? (string) JVERSION : 'unknown',
					'phpVersion' => PHP_VERSION,
				],
				'commandCount' => count($commands),
				'namespaces' => $namespaces,
				'commands' => array_values($commands),
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

			return 0;
		}
		catch (Throwable)
		{
			$this->write('{"protocol":"joomla-mcp/1","ok":false,"error":{"code":"CLI_INVENTORY_FAILED","message":"The installed Joomla CLI registry could not be described."}}');

			return 1;
		}
	}

	/**
	 * Describe one registered command and its native input definition.
	 *
	 * @param   AbstractCommand  $command  The command value.
	 *  @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	private function describe(AbstractCommand $command): array
	{
		$arguments = array_map(
			static fn (InputArgument $argument): array => [
				'name' => $argument->getName(),
				'description' => $argument->getDescription(),
				'required' => $argument->isRequired(),
				'array' => $argument->isArray(),
			],
			array_values($command->getDefinition()->getArguments()),
		);
		$options = array_map(
			static function (InputOption $option): array
			{
				$shortcut = $option->getShortcut();

				return [
					'name' => $option->getName(),
					'shortcuts' => $shortcut === null || $shortcut === ''
						? []
						: (is_array($shortcut) ? array_values($shortcut) : explode('|', $shortcut)),
					'description' => $option->getDescription(),
					'acceptsValue' => $option->acceptValue(),
					'valueRequired' => $option->isValueRequired(),
					'valueOptional' => $option->isValueOptional(),
					'array' => $option->isArray(),
				];
			},
			array_values($command->getDefinition()->getOptions()),
		);
		$aliases = $command->getAliases();
		sort($aliases, SORT_STRING);

		return [
			'name' => $command->getName(),
			'description' => $command->getDescription(),
			'aliases' => array_values($aliases),
			'hidden' => $command->isHidden(),
			'arguments' => $arguments,
			'options' => $options,
		];
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
		fwrite(STDOUT, $line . PHP_EOL);
	}
}
