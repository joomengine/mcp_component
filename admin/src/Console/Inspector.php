<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Console;


use Joomla\CMS\Application\ConsoleApplication;
use Joomla\Console\Command\AbstractCommand;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Bounded introspection of the actual Joomla console registry, never execution.
 *
 * @since 0.1.0
 */
final class Inspector
{
	/** @var ConsoleApplication Genuine local console registry. @since 0.1.0 */
	private ConsoleApplication $application;

	/** @param ConsoleApplication $application Local application. @since 0.1.0 */
	public function __construct(ConsoleApplication $application)
	{
		if (PHP_SAPI !== 'cli')
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'Console introspection requires the local Joomla console.');
		}

		$this->application = $application;
	}

	/** @return array<string,mixed> Native installed command argument and option definitions. @since 0.1.0 */
	public function inventory(): array
	{
		$commands = [];

		foreach ($this->application->getAllCommands() as $command)
		{
			$name = $command->getName();

			if (!is_string($name) || $name === '' || isset($commands[$name]))
			{
				continue;
			}

			if (count($commands) >= 512)
			{
				throw new OperationException('COMMAND_LIMIT', 'The installed console registry exceeds the supported bound.');
			}

			$commands[$name] = $this->describe($command);
		}

		ksort($commands, SORT_STRING);
		$namespaces = [];

		foreach (array_keys($commands) as $name)
		{
			if (str_contains($name, ':'))
			{
				$namespaces[] = explode(':', $name, 2)[0];
			}
		}

		$namespaces = array_values(array_unique($namespaces));
		sort($namespaces, SORT_STRING);
		$result = ['protocol' => 'joomla-mcp/1', 'ok' => true,
			'runtime' => ['joomlaVersion' => defined('JVERSION') ? JVERSION : 'unknown', 'phpVersion' => PHP_VERSION],
			'commandCount' => count($commands), 'namespaces' => $namespaces, 'commands' => array_values($commands)];
		Json::encode($result);

		return $result;
	}

	/** @return array<string,mixed> A text listing accompanied by its structured source. @since 0.1.0 */
	public function text(): array
	{
		$inventory = $this->inventory();
		$text = implode("\n", array_map(static fn (array $command): string => $command['name'] . '  ' . $command['description'], $inventory['commands'])) . "\n";

		return ['command' => 'list', 'exitCode' => 0, 'stdout' => $text, 'stderr' => '', 'truncated' => false, 'inventory' => $inventory];
	}

	/** @param string $name Installed command identifier. @return array<string,mixed> Help without running the requested command. @since 0.1.0 */
	public function help(string $name): array
	{
		if (strlen($name) > 160 || preg_match('/\A[a-zA-Z0-9:_-]+\z/D', $name) !== 1 || !$this->application->hasCommand($name))
		{
			throw new OperationException('COMMAND_UNAVAILABLE', 'The requested console command is unavailable.');
		}

		$command = $this->application->getCommand($name);
		$text = $command->getDescription() . "\n\n" . $command->getSynopsis() . "\n\n" . $command->getHelp();

		if (strlen($text) > 1048576)
		{
			throw new OperationException('RESULT_TOO_LARGE', 'The installed command help exceeds the supported bound.');
		}

		return ['command' => $name, 'exitCode' => 0, 'stdout' => $text, 'stderr' => '', 'truncated' => false, 'definition' => $this->describe($command)];
	}

	/** @param AbstractCommand $command Native registered command. @return array<string,mixed> Inert non-secret command contract. @since 0.1.0 */
	private function describe(AbstractCommand $command): array
	{
		$arguments = [];
		$options = [];

		foreach ($command->getDefinition()->getArguments() as $argument)
		{
			$arguments[] = ['name' => $argument->getName(), 'description' => $argument->getDescription(),
				'required' => $argument->isRequired(), 'array' => $argument->isArray()];
		}

		foreach ($command->getDefinition()->getOptions() as $option)
		{
			$shortcut = $option->getShortcut();
			$options[] = ['name' => $option->getName(), 'description' => $option->getDescription(),
				'shortcuts' => $shortcut === null || $shortcut === '' ? [] : (is_array($shortcut) ? array_values($shortcut) : explode('|', $shortcut)),
				'acceptsValue' => $option->acceptValue(), 'valueRequired' => $option->isValueRequired(),
				'valueOptional' => $option->isValueOptional(), 'array' => $option->isArray()];
		}

		$aliases = $command->getAliases();
		sort($aliases, SORT_STRING);

		return ['name' => $command->getName(), 'description' => $command->getDescription(), 'aliases' => $aliases,
			'hidden' => $command->isHidden(), 'arguments' => $arguments, 'options' => $options];
	}
}
