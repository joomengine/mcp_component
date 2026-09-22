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
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;


/**
 * Expose only allowlisted, non-secret Joomla configuration values.
 *
 * @since  0.1.0
 */
final class SafeConfigurationAction implements ActionInterface
{
	/**
	 * The explicitly non-secret configuration keys.
	 *
	 * @since  0.1.0
	 */
	private const KEYS = [
		'sitename',
		'offline',
		'display_offline_message',
		'editor',
		'captcha',
		'list_limit',
		'sef',
		'sef_rewrite',
		'gzip',
		'debug',
		'debug_lang',
	];

	/**
	 * The current Joomla application context.
	 *
	 * @var   object
	 *
	 * @since  0.1.0
	 */
	private object $application;

	/**
	 * The stable identifier exposed for this action.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $actionName;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   object  $application  The current Joomla application context.
	 * @param   string  $actionName   The stable identifier exposed for this action.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		object $application,
		string $actionName = 'configuration.get_safe',
	)
	{
		$this->application = $application;
		$this->actionName = $actionName;
	}

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
			$this->actionName,
			'Return only explicitly non-secret Joomla global configuration values.',
			'read',
			[['action' => 'core.admin', 'asset' => 'com_config']],
			['type' => 'object', 'properties' => [], 'additionalProperties' => false],
			['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer', 'boolean', 'null']]],
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

		if (!method_exists($this->application, 'get'))
		{
			throw new ActionException('JOOMLA_RUNTIME_UNAVAILABLE', 'Joomla configuration is unavailable.');
		}

		$values = [];

		foreach (self::KEYS as $key)
		{
			$value = $this->application->get($key);
			$values[$key] = is_scalar($value) || $value === null ? $value : null;
		}

		return $values;
	}
}
