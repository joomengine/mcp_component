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


use InvalidArgumentException;
use JsonSerializable;


/**
 * Describe a native action, its schemas and required Joomla permissions.
 *
 * @since  0.1.0
 */
final class ActionDescriptor implements JsonSerializable
{
	/**
	 * The stable public action identifier.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $name;

	/**
	 * The human-readable action purpose.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $description;

	/**
	 * The read, write or high-risk classification.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $risk;

	/**
	 * The Joomla action and asset requirements for this operation.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $acl;

	/**
	 * The accepted native action input schema.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $inputSchema;

	/**
	 * The native action result schema.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $outputSchema;

	/**
	 * Validate and retain the fixed native action contract.
	 *
	 * @param   string                                      $name          The stable public action identifier.
	 * @param   string                                      $description   The human-readable action purpose.
	 * @param   string                                      $risk          The read, write or high-risk classification.
	 * @param   list<array{action: string, asset: string}>  $acl           The Joomla action and asset requirements for this operation.
	 * @param   array<string, mixed>                        $inputSchema   The accepted native action input schema.
	 * @param   array<string, mixed>                        $outputSchema  The native action result schema.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		string $name,
		string $description,
		string $risk,
		array $acl,
		array $inputSchema,
		array $outputSchema,
	)
	{
		$this->name = $name;
		$this->description = $description;
		$this->risk = $risk;
		$this->acl = $acl;
		$this->inputSchema = $inputSchema;
		$this->outputSchema = $outputSchema;

		if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/', $name))
		{
			throw new InvalidArgumentException(sprintf('Invalid action name "%s".', $name));
		}

		if (!in_array($risk, ['read', 'write', 'high'], true))
		{
			throw new InvalidArgumentException(sprintf('Invalid action risk "%s".', $risk));
		}
	}

	/**
	 * Return the wire representation of this action descriptor.
	 *
	 *  @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'description' => $this->description,
			'risk' => $this->risk,
			'drivers' => ['cli-companion'],
			'joomla' => ['min' => '6.1.0', 'canary' => '7.0.0'],
			'acl' => $this->acl,
			'inputSchema' => $this->inputSchema,
			'outputSchema' => $this->outputSchema,
		];
	}
}
