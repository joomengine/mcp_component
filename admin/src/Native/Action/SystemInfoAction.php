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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Action;


use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;


/**
 * Report the current Joomla and PHP runtime versions.
 *
 * @since  0.1.0
 */
final class SystemInfoAction implements ActionInterface
{
	/**
	 * Return the action identity, schemas and required Joomla permissions.
	 *
	 * @return  ActionDescriptor
	 *
	 * @since  0.1.0
	 */
	public function descriptor(): ActionDescriptor
	{
		return new ActionDescriptor(
			'system.info',
			'Return non-secret Joomla and PHP runtime versions.',
			'read',
			[],
			['type' => 'object', 'properties' => [], 'additionalProperties' => false],
			[
				'type' => 'object',
				'required' => ['joomlaVersion', 'phpVersion'],
				'properties' => [
					'joomlaVersion' => ['type' => 'string'],
					'phpVersion' => ['type' => 'string'],
				],
				'additionalProperties' => false,
			],
		);
	}

	/**
	 * Validate the supplied input and perform this action within its native contract.
	 *
	 * @param   array  $input  The input value.
	 * @return  array
	 *
	 * @since  0.1.0
	 */
	public function execute(array $input): array
	{
		Input::rejectUnknown($input, []);

		return [
			'joomlaVersion' => defined('JVERSION') ? (string) JVERSION : 'unknown',
			'phpVersion' => PHP_VERSION,
		];
	}
}
