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
use JsonException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\CapabilityResolverInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Native\Protocol\DescriptionService;


/**
 * Emit the available native action contracts as a JSON document.
 *
 * @since  0.1.0
 */
final class DescribeCommand extends AbstractCommand
{
	/**
	 * The console command identifier inherited from Joomla.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	protected static $defaultName = 'joomla:mcp:describe';

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
		$this->setDescription('Describe allowlisted Joomla MCP actions and effective ACL as JSON.');
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
			$this->write('{"protocol":"joomla-mcp/1","ok":false,"error":{"code":"INVALID_FORMAT","message":"Describe format must be json."}}');

			return 2;
		}

		try
		{
			$this->write((new DescriptionService($this->registry, $this->capabilities))->toJson());

			return 0;
		}
		catch (JsonException)
		{
			$this->write('{"protocol":"joomla-mcp/1","ok":false,"error":{"code":"ENCODING_FAILED","message":"Description encoding failed."}}');

			return 1;
		}
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
