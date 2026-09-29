<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\StdioTransport as SdkStdioTransport;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;


/**
 * SDK stdio with bounded fragment assembly and complete protocol writes.
 *
 * Nonblocking pipe reads can stop before a newline even for a small request.
 * Only complete frames enter the SDK. Input is read in small chunks rather
 * than allocating the configured maximum line length on every idle tick.
 *
 * @since 0.1.1
 */
final class StdioTransport extends SdkStdioTransport
{
	/** @var resource Component-owned reference to the SDK's input stream. @since 0.1.1 */
	private $inputStream;
	/** @var resource Component-owned reference to the SDK's output stream. @since 0.1.1 */
	private $outputStream;
	/** @var int Maximum request bytes, excluding its newline. @since 0.1.1 */
	private int $maximum;
	/** @var string Incomplete current frame only. @since 0.1.1 */
	private string $frame = '';
	/** @var bool Whether an oversized frame is being discarded. @since 0.1.1 */
	private bool $discarding = false;
	/** @var ?WireInput Component wire validation and object-preservation scope. @since 0.1.1 */
	private ?WireInput $wire;

	/** @param resource $input Input stream. @param resource $output Output stream. @param ?LoggerInterface $logger Diagnostics. @param int $maxLineBytes Request bound. @param ?WireInput $wire Wire adapter. @since 0.1.1 */
	public function __construct($input = STDIN, $output = STDOUT, ?LoggerInterface $logger = null, int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES, ?WireInput $wire = null)
	{
		if ($maxLineBytes < 1 || !is_resource($input) || !is_resource($output))
		{
			throw new InvalidArgumentException('MCP stdio requires streams and a positive request bound.');
		}

		parent::__construct($input, $output, $logger, maxLineBytes: $maxLineBytes);
		$this->inputStream = $input;
		$this->outputStream = $output;
		$this->maximum = $maxLineBytes;
		$this->wire = $wire;
	}

	/** @inheritDoc */
	public function onMessage(callable $listener): void
	{
		if ($this->wire === null)
		{
			parent::onMessage($listener);

			return;
		}

		parent::onMessage(function (TransportInterface $transport, string $payload, ?Uuid $session) use ($listener): void
		{
			$this->wire->dispatch($transport, $payload, $session, $listener);
		});
	}

	/** @inheritDoc */
	protected function processInput(): void
	{
		$chunk = fgets($this->inputStream, 8193);

		if ($chunk === false)
		{
			usleep(1000);

			return;
		}

		$complete = str_ends_with($chunk, "\n");
		$bytes = strlen($chunk) - ($complete ? 1 : 0);

		if (!$this->discarding && strlen($this->frame) + $bytes > $this->maximum)
		{
			$this->discarding = true;
			$this->frame = '';
			$this->send(json_encode(Error::forInvalidRequest('The request exceeds the configured byte limit.'), JSON_THROW_ON_ERROR), []);
		}

		if (!$this->discarding)
		{
			$this->frame .= $chunk;
		}

		if (!$complete)
		{
			return;
		}

		$frame = trim($this->frame);
		$this->frame = '';
		$this->discarding = false;

		if ($frame !== '')
		{
			$this->handleMessage($frame, $this->sessionId);
		}
	}

	/** @inheritDoc */
	public function send(string $data, array $context): void
	{
		if (isset($context['session_id']))
		{
			$this->sessionId = $context['session_id'];
		}

		$this->write($data);
	}

	/** @inheritDoc */
	protected function getOutgoingMessages(?Uuid $sessionId): array
	{
		// The SDK's private flush method uses one fwrite(), which may be short
		// when a large catalogue meets pipe backpressure. Consume its queue here
		// and finish each write before allowing the next protocol message.
		foreach (parent::getOutgoingMessages($sessionId) as $message)
		{
			$this->write($message['message']);
		}

		return [];
	}

	/** @param string $data Encoded protocol message. @return void @since 0.1.1 */
	private function write(string $data): void
	{
		$line = $data . "\n";

		for ($offset = 0, $length = strlen($line); $offset < $length; $offset += $written)
		{
			$written = fwrite($this->outputStream, substr($line, $offset, 65536));

			if ($written === false || $written === 0)
			{
				throw new RuntimeException('The MCP stdio output stream is closed.');
			}
		}
	}
}
