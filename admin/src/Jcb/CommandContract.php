<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\Console\Command\AbstractCommand;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Captures the actual native InputDefinition rather than guessing commands from
 * JCB's database tables. No class is loaded or instantiated from database data.
 *
 * @since  0.1.0
 */
final class CommandContract
{
	/**
	 * Describe an already registered command's public input and implementation.
	 *
	 * @param   AbstractCommand  $command  Native Joomla command instance.
	 * @return  array<string,mixed>  Stable argument/option contract and fingerprint.
	 * @since   0.1.0
	 */
	public static function describe(AbstractCommand $command): array
	{
		$arguments = [];
		$options = [];

		foreach ($command->getDefinition()->getArguments() as $argument)
		{
			if ($argument->getName() !== 'command')
			{
				$arguments[$argument->getName()] = ['required' => $argument->isRequired(),
					'array' => $argument->isArray(), 'default' => $argument->getDefault()];
			}
		}

		foreach ($command->getDefinition()->getOptions() as $option)
		{
			// Application-level options cannot select another command or interactive mode.
			if (in_array($option->getName(), ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'], true))
			{
				continue;
			}

			$options[$option->getName()] = ['acceptsValue' => $option->acceptValue(),
				'valueRequired' => $option->isValueRequired(), 'valueOptional' => $option->isValueOptional(),
				'array' => $option->isArray(), 'default' => $option->getDefault()];
		}

		ksort($options, SORT_STRING);
		$contract = ['name' => $command->getName(), 'arguments' => (object) $arguments, 'options' => (object) $options];

		return $contract + ['fingerprint' => hash('sha256', Json::canonical($contract))];
	}

	/**
	 * Validate option names and value modes against a stored, reviewed definition.
	 *
	 * @param   array<string,mixed>  $options   MCP option object using native long names.
	 * @param   array<string,mixed>  $contract  Reviewed command definition.
	 * @return  array<string,mixed>  Typed canonical options, without global CLI flags.
	 * @throws  OperationException  For undeclared arguments or incompatible values.
	 * @since   0.1.0
	 */
	public static function options(array $options, array $contract): array
	{
		$declared = (array) ($contract['options'] ?? []);

		if (count($options) > 64 || (isset($contract['arguments']) && (array) $contract['arguments'] !== []))
		{
			throw new OperationException('JCB_COMMAND_CONTRACT', 'This reviewed JCB adapter requires an option-only command.');
		}

		foreach ($options as $name => $value)
		{
			if (!is_string($name) || !isset($declared[$name]) || str_starts_with($name, '-')
				|| preg_match('/\A[a-z][a-z0-9-]*\z/D', $name) !== 1)
			{
				throw new OperationException('INVALID_INPUT', 'An option is not declared by the selected JCB command.');
			}

			$mode = (array) $declared[$name];

			if (!empty($mode['array']))
			{
				throw new OperationException('JCB_COMMAND_CONTRACT', 'An array-valued native option needs a reviewed binding update.');
			}

			if (empty($mode['acceptsValue']))
			{
				if (!is_bool($value))
				{
					throw new OperationException('INVALID_INPUT', 'A native flag requires a JSON boolean.');
				}
			}
			elseif ($value !== null && (!is_string($value) || strlen($value) > 1048576 || str_contains($value, "\0")))
			{
				throw new OperationException('INVALID_INPUT', 'A native JCB value option requires bounded text or null.');
			}
			elseif ($value === null && !empty($mode['valueRequired']))
			{
				throw new OperationException('INVALID_INPUT', 'A required native option value is missing.');
			}
		}

		ksort($options, SORT_STRING);

		return $options;
	}
}
