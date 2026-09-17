<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Domain;


use RuntimeException;


/**
 * Safe, stable operation failure suitable for a structured MCP response.
 *
 * @since  0.1.0
 */
final class OperationException extends RuntimeException
{
	/** @var string Public error identifier, never an exception class or SQL code. @since 0.1.0 */
	private string $identifier;

	/** @var array<string,mixed> Non-sensitive reconciliation information. @since 0.1.0 */
	private array $details;

	/**
	 * Construct a deliberately public diagnostic without retaining sensitive causes.
	 *
	 * @param string $identifier Stable error identifier.
	 * @param string $message Safe operator explanation.
	 * @param array<string,mixed> $details Safe structured details.
	 * @since 0.1.0
	 */
	public function __construct(string $identifier, string $message, array $details = [])
	{
		parent::__construct($message);
		$this->identifier = $identifier;
		$this->details = $details;
	}

	/** @return string The stable public error identifier. @since 0.1.0 */
	public function getIdentifier(): string
	{
		return $this->identifier;
	}

	/** @return array<string,mixed> The safe error envelope. @since 0.1.0 */
	public function toArray(): array
	{
		return ['code' => $this->identifier, 'message' => $this->getMessage(), 'details' => $this->details];
	}
}
