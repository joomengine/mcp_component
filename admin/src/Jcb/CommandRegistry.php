<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\CMS\Application\ConsoleApplication;
use Joomla\Console\Command\AbstractCommand;
use ReflectionClass;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Resolves only actual JCB-owned command objects from Joomla's native registry.
 * A row may select one of these objects, never a PHP class or shell executable.
 *
 * @since  0.1.0
 */
final class CommandRegistry
{
	/** @var ConsoleApplication Native console application after plugin registration. @since 0.1.0 */
	private ConsoleApplication $application;

	/**
	 * Inject the genuine local registry; HTTP dispatch cannot construct it.
	 *
	 * @param   ConsoleApplication  $application  Booted Joomla console.
	 * @since   0.1.0
	 */
	public function __construct(ConsoleApplication $application)
	{
		if (PHP_SAPI !== 'cli')
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'JCB command resolution is available only inside the local worker.');
		}

		$this->application = $application;
	}

	/**
	 * Resolve a registered command and check its reviewed native implementation.
	 *
	 * @param   string  $name  Command name from an authorized binding.
	 * @return  AbstractCommand  JCB's own command, not a replacement registration.
	 * @since   0.1.0
	 */
	public function get(string $name): AbstractCommand
	{
		if (preg_match('/\Acomponentbuilder:(compile|get|init|pull|push|reset):([a-z][a-z0-9_]*)\z/D', $name, $matches) !== 1
			|| !$this->application->hasCommand($name))
		{
			throw new OperationException('JCB_COMMAND_UNAVAILABLE', 'The installed JCB plugin has not registered the requested command.');
		}

		$class = $matches[1] === 'compile' ? 'VDM\\Joomla\\Componentbuilder\\Console\\Compiler'
			: 'VDM\\Joomla\\Componentbuilder\\Console\\Package\\' . ucfirst($matches[1]);
		$command = $this->application->getCommand($name);

		if (get_class($command) !== $class || $command->getName() !== $name
			|| ($matches[1] === 'compile' && $matches[2] !== 'component'))
		{
			throw new OperationException('JCB_COMMAND_IDENTITY', 'The command registry does not contain the reviewed JCB implementation.');
		}

		return $command;
	}

	/**
	 * Validate the database input contract and fingerprint all inherited source.
	 *
	 * @param   array<string,mixed>  $configuration  Reviewed command binding.
	 * @return  array<string,mixed>  Public definition and implementation digest.
	 * @since   0.1.0
	 */
	public function inspect(array $configuration): array
	{
		$command = $this->get((string) ($configuration['command'] ?? ''));
		$actual = CommandContract::describe($command);
		$expected = $configuration['contract'] ?? [];
		$expected = ['name' => $configuration['command'], 'arguments' => (object) ($expected['arguments'] ?? []),
			'options' => (object) ($expected['options'] ?? [])];

		if (!hash_equals(hash('sha256', Json::canonical($expected)), $actual['fingerprint']))
		{
			throw new OperationException('JCB_COMMAND_CHANGED', 'The installed JCB input definition differs from this binding. Review and update the catalogue first.');
		}

		$sources = [];
		$class = new ReflectionClass($command);

		do
		{
			$file = $class->getFileName();

			if (!is_string($file) || !is_file($file) || ($digest = hash_file('sha256', $file)) === false)
			{
				throw new OperationException('JCB_COMMAND_SOURCE', 'The native command source cannot be verified.');
			}

			$sources[$class->getName()] = $digest;
		}
		while (($class = $class->getParentClass()) !== false);

		return $actual + ['implementation' => hash('sha256', Json::canonical($sources))];
	}
}
