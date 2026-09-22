<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Bounded command output with separate stdout/stderr buffers. JCB's compiler
 * chooses stderr for human messages and stdout for artifact paths. Neither
 * stream is ever sent unframed to the MCP client.
 *
 * @since  0.1.0
 */
final class CommandOutput extends Output
{
	/** @var string Captured native stream. @since 0.1.0 */
	private string $buffer = '';
	/** @var int Maximum output bytes per stream. @since 0.1.0 */
	private int $maximum;
	/** @var ?OutputInterface Separate diagnostic stream. @since 0.1.0 */
	private ?OutputInterface $error;

	/** @param int $maximum Maximum stream bytes. @param bool $stderr Whether constructing the leaf diagnostic stream. @since 0.1.0 */
	public function __construct(int $maximum = 1048576, bool $stderr = false)
	{
		parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);
		$this->maximum = $maximum;
		$this->error = $stderr ? null : new self($maximum, true);
	}

	/** @inheritDoc */
	protected function doWrite(string $message, bool $newline): void
	{
		$text = $message . ($newline ? PHP_EOL : '');

		if (strlen($this->buffer) + strlen($text) > $this->maximum)
		{
			throw new OperationException('WORKER_OUTPUT_LIMIT', 'Native JCB output exceeded the configured bound.');
		}

		$this->buffer .= $text;
	}

	/** @return OutputInterface The separately bounded native diagnostic stream. @since 0.1.0 */
	public function getErrorOutput(): OutputInterface
	{
		return $this->error ?? $this;
	}

	/** @param OutputInterface $error Inject a native diagnostic stream. @return void @since 0.1.0 */
	public function setErrorOutput(OutputInterface $error): void
	{
		$this->error = $error;
	}

	/** @return string Captured data; callers decide redaction before disclosure. @since 0.1.0 */
	public function contents(): string
	{
		return $this->buffer;
	}
}
