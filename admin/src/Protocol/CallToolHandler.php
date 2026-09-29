<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Validates tool calls against original JSON types and current authorized rows.
 *
 * @implements RequestHandlerInterface<\Mcp\Schema\Result\CallToolResult>
 * @since 0.1.2
 */
final class CallToolHandler implements RequestHandlerInterface
{
	/** @var DatabaseRegistry Principal-filtered live definitions. @since 0.1.2 */
	private DatabaseRegistry $registry;
	/** @var WireInput Original dispatch-scoped JSON types. @since 0.1.2 */
	private WireInput $wire;
	/** @var SchemaValidator Bounded inert schema evaluator. @since 0.1.2 */
	private SchemaValidator $schemas;
	/** @var ReferenceHandler Native explicit-handler invocation. @since 0.1.2 */
	private ReferenceHandler $references;

	/** @param DatabaseRegistry $registry Definitions. @param WireInput $wire Wire types. @param SchemaValidator $schemas Validation. @since 0.1.2 */
	public function __construct(DatabaseRegistry $registry, WireInput $wire, SchemaValidator $schemas)
	{
		$this->registry = $registry;
		$this->wire = $wire;
		$this->schemas = $schemas;
		$this->references = new ReferenceHandler();
	}

	/** @inheritDoc */
	public function supports(Request $request): bool
	{
		return $request instanceof CallToolRequest;
	}

	/** @inheritDoc */
	public function handle(Request $request, SessionInterface $session): Response|Error
	{
		if (!$request instanceof CallToolRequest)
		{
			return Error::forMethodNotFound('The requested MCP method is unavailable.', $request->getId());
		}

		try
		{
			if (($failure = $this->wire->failure($request)) !== null)
			{
				return $failure;
			}

			$reference = $this->registry->getTool($request->name);
			$original = $this->wire->arguments($request);
			$arguments = $this->schemas->input($original === null ? $request->arguments : (array) $original,
				Json::encode($reference->tool->inputSchema), $original !== null);
			$arguments['_session'] = $session;
			$arguments['_request'] = $request;

			return new Response($request->getId(), $this->references->handle($reference, $arguments));
		}
		catch (ToolNotFoundException)
		{
			return Error::forInvalidParams('The requested tool is unavailable.', $request->getId());
		}
		catch (OperationException $error)
		{
			return $error->getIdentifier() === 'INVALID_INPUT'
				? Error::forInvalidParams($error->getMessage(), $request->getId(), $error->toArray())
				: Error::forInternalError('The tool input contract could not be evaluated.', $request->getId());
		}
		catch (Throwable)
		{
			return Error::forInternalError('The tool call could not complete.', $request->getId());
		}
	}
}
