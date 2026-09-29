<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;


/**
 * Rejects original parameter types after the SDK's native HTTP boundary checks.
 *
 * @implements RequestHandlerInterface<never>
 * @since 0.1.2
 */
final class ParameterErrorHandler implements RequestHandlerInterface
{
	/** @var WireInput Original dispatch-scoped parameter errors. @since 0.1.2 */
	private WireInput $wire;

	/** @param WireInput $wire Original parameter types. @since 0.1.2 */
	public function __construct(WireInput $wire)
	{
		$this->wire = $wire;
	}

	/** @inheritDoc */
	public function supports(Request $request): bool
	{
		return $this->wire->failure($request) !== null;
	}

	/** @inheritDoc */
	public function handle(Request $request, SessionInterface $session): Response|Error
	{
		return $this->wire->failure($request)
			?? Error::forInternalError('The original parameter validation result is unavailable.', $request->getId());
	}
}
