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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Domain;


use RuntimeException;


/**
 * Carry a stable protocol error code alongside an action failure.
 *
 * @since  0.1.0
 */
final class ActionException extends RuntimeException
{
	/**
	 * The stable machine-readable protocol failure code.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $errorCode;

	/**
	 * Retain the stable error code and initialize the exception message.
	 *
	 * @param   string  $errorCode  The stable machine-readable protocol failure code.
	 * @param   string  $message    The message value.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		string $errorCode,
		string $message,
	)
	{
		$this->errorCode = $errorCode;

		parent::__construct($message);
	}
}
