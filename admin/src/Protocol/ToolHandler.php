<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Explicit SDK handler for a database-defined tool, with protocol-safe errors.
 *
 * @since 0.1.0
 */
final class ToolHandler implements ToolHandlerInterface
{
	/** @var ToolDispatcher Shared protocol primitives. @since 0.1.0 */
	private ToolDispatcher $dispatcher;
	/** @var string Database tool name, not a PHP method. @since 0.1.0 */
	private string $name;

	/** @param ToolDispatcher $dispatcher Primitives. @param string $name Published tool name. @since 0.1.0 */
	public function __construct(ToolDispatcher $dispatcher, string $name)
	{
		$this->dispatcher = $dispatcher;
		$this->name = $name;
	}

	/** @inheritDoc */
	public function execute(array $arguments, ClientGateway $gateway): mixed
	{
		try
		{
			$result = $this->dispatcher->call($this->name, $arguments);
			$isError = ($result['verification']['status'] ?? '') === 'uncertain';

			return new CallToolResult([new TextContent(Json::encode($result))], $isError, (object) $result);
		}
		catch (OperationException $error)
		{
			return CallToolResult::error([new TextContent(Json::encode(['error' => $error->toArray()]))]);
		}
		catch (Throwable)
		{
			return CallToolResult::error([new TextContent(Json::encode(['error' => ['code' => 'TOOL_FAILED', 'message' => 'The Joomla operation could not complete.']]))]);
		}
	}
}
