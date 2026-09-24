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
use ReflectionProperty;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
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
	/** @var ?string Read-only installed JCB source hash, shared across one inventory. @since 0.1.0 */
	private ?string $libraryFingerprint = null;
	/** @var ?array<int,string[]> Native plugin provenance captured by the registration event. @since 0.1.1 */
	private ?array $owners;

	/**
	 * Inject the genuine local registry; HTTP dispatch cannot construct it.
	 *
	 * @param   ConsoleApplication  $application  Booted Joomla console.
	 * @param   ?array  $owners  Observed plugin dependencies indexed by command object ID.
	 * @since   0.1.0
	 */
	public function __construct(ConsoleApplication $application, ?array $owners = null)
	{
		if (PHP_SAPI !== 'cli')
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'JCB command resolution is available only inside the local worker.');
		}

		$this->application = $application;
		$this->owners = $owners;
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
	 * Inventory only commands actually registered by JCB's console plugin.
	 *
	 * @return array Registered, reviewed definitions and explicit unsupported names.
	 * @since 0.1.0
	 */
	public function inventory(): array
	{
		$commands = [];
		$unsupported = [];

		foreach ($this->application->getAllCommands() as $command)
		{
			$name = $command->getName();

			if (!is_string($name) || !str_starts_with($name, 'componentbuilder:') || isset($commands[$name]))
			{
				continue;
			}

			try
			{
				$native = $this->get($name);
				$dependencies = $this->owners === null ? [] : ($this->owners[spl_object_id($native)] ?? []);

				if ($this->owners !== null && $dependencies === [])
				{
					throw new OperationException('JCB_COMMAND_OWNER_UNAVAILABLE', 'The registered command has no observed owning plugin.');
				}

				$contract = CommandContract::describe($native);
				$commands[$name] = $this->inspect(['command' => $name, 'contract' => $contract])
					+ ['description' => $native->getDescription(), 'aliases' => $native->getAliases(), 'required_extensions' => $dependencies];
			}
			catch (OperationException $error)
			{
				$unsupported[$name] = $error->getIdentifier();
			}
		}

		ksort($commands, SORT_STRING);
		ksort($unsupported, SORT_STRING);

		return ['commands' => array_values($commands), 'unsupported' => $unsupported,
			'fingerprint' => hash('sha256', Json::canonical(['commands' => $commands, 'unsupported' => $unsupported]))];
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

		$capability = [];
		$sources['installed-vdm-library'] = $this->libraryFingerprint();

		if ($command->getName() !== 'componentbuilder:compile:component')
		{
			$entity = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Abstraction\\Console\\Package', 'entity'))->getValue($command);
			$factory = \VDM\Joomla\Componentbuilder\Factory::getEntityFactory($entity);
			$area = \VDM\Joomla\Componentbuilder\Factory::getArea($entity);
			$direction = str_starts_with($command->getName(), 'componentbuilder:push:') ? 'Set' : 'Get';
			$service = $area . '.Remote.' . $direction;

			if ($factory === null || $area === null || !$factory::getContainer()->has($service))
			{
				throw new OperationException('JCB_HANDLER_UNAVAILABLE', 'The registered JCB command has no native entity handler; its upstream no-op is not executable coverage.');
			}

			$capability = ['entity' => $entity, 'nativeService' => $service];
		}

		return $actual + $capability + ['implementation' => hash('sha256', Json::canonical($sources))];
	}

	/**
	 * Hash installed source without constructing native services during planning.
	 * Some JCB dependency-resolver constructors populate missing GUIDs in the DB.
	 *
	 * @return string Digest of the actual bounded VDM PHP source tree.
	 * @since 0.1.0
	 */
	private function libraryFingerprint(): string
	{
		if ($this->libraryFingerprint !== null)
		{
			return $this->libraryFingerprint;
		}

		$file = (new ReflectionClass('VDM\\Joomla\\Componentbuilder\\Factory'))->getFileName();

		if (!is_string($file) || !is_file($file))
		{
			throw new OperationException('JCB_COMMAND_SOURCE', 'The installed JCB factory source is unavailable.');
		}

		// Include sibling VDM Git/Gitea libraries used by native package calls.
		$root = dirname($file, 4);
		$files = [];
		$bytes = 0;

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry)
		{
			if (!$entry->isFile() || $entry->getExtension() !== 'php')
			{
				continue;
			}

			$bytes += $entry->getSize();

			if ($entry->isLink() || count($files) >= 8192 || $bytes > 268435456)
			{
				throw new OperationException('JCB_COMMAND_SOURCE', 'The installed JCB library exceeds the reviewed source boundary.');
			}

			$digest = hash_file('sha256', $entry->getPathname());

			if ($digest === false)
			{
				throw new OperationException('JCB_COMMAND_SOURCE', 'An installed JCB library source could not be read.');
			}

			$files[substr($entry->getPathname(), strlen($root) + 1)] = $digest;
		}

		ksort($files, SORT_STRING);
		$this->libraryFingerprint = hash('sha256', Json::canonical($files));

		return $this->libraryFingerprint;
	}
}
