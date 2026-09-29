<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Mcp\Server\Transport\StdioTransport as SdkStdioTransport;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;


/**
 * Preserves original wire objects around the native stdio dispatcher.
 *
 * @since 0.1.2
 */
final class StdioTransport extends SdkStdioTransport
{
	/** @var ?WireInput Original dispatch-scoped JSON argument types. @since 0.1.2 */
	private ?WireInput $wire;

	/** @param resource $input Input. @param resource $output Output. @param ?LoggerInterface $logger Diagnostics. @param int $maxLineBytes Request bound. @param ?WireInput $wire Original JSON types. @since 0.1.2 */
	public function __construct($input = STDIN, $output = STDOUT, ?LoggerInterface $logger = null, int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES, ?WireInput $wire = null)
	{
		parent::__construct($input, $output, $logger, maxLineBytes: $maxLineBytes);
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
}
