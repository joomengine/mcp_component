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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Protocol;


use JsonException;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\CapabilityResolverInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionRegistry;


/**
 * Validate, authorize and execute one bounded native protocol request.
 *
 * @since  0.1.0
 */
final class DispatchService
{
	/**
	 * The maximum serialized native action response size.
	 *
	 * @since  0.1.0
	 */
	private const MAX_RESULT_BYTES = 8_388_608;

	/**
	 * The registry of reviewed native action implementations.
	 *
	 * @var   ActionRegistry
	 *
	 * @since  0.1.0
	 */
	private ActionRegistry $registry;

	/**
	 * The current actor capability resolver.
	 *
	 * @var   CapabilityResolverInterface
	 *
	 * @since  0.1.0
	 */
	private CapabilityResolverInterface $capabilities;

	/**
	 * The bounded native request decoder.
	 *
	 * @var   RequestDecoder
	 *
	 * @since  0.1.0
	 */
	private RequestDecoder $decoder;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   ActionRegistry               $registry      The registry of reviewed native action implementations.
	 * @param   CapabilityResolverInterface  $capabilities  The current actor capability resolver.
	 * @param   RequestDecoder               $decoder       The bounded native request decoder.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		ActionRegistry $registry,
		CapabilityResolverInterface $capabilities,
		RequestDecoder $decoder = new RequestDecoder(),
	)
	{
		$this->registry = $registry;
		$this->capabilities = $capabilities;
		$this->decoder = $decoder;
	}

	/**
	 * Decode and authorize one request, then encode its bounded result.
	 *
	 * @param   string  $json  The json value.
	 * @return  string
	 *
	 * @since  0.1.0
	 */
	public function handleJson(string $json): string
	{
		$id = null;

		try
		{
			$request = $this->decoder->decode($json);
			$id = $request['id'];
			$action = $this->registry->get($request['action']);
			$effective = $this->capabilities->resolve($action->descriptor());

			if (!$effective['allowed'])
			{
				throw new ActionException('ACCESS_DENIED', 'The configured MCP actor lacks a required Joomla permission.');
			}

			$result = $this->executeWithoutOutput($action, $request['input']);

			try
			{
				$encodedResult = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			}
			catch (JsonException)
			{
				throw new ActionException('RESULT_INVALID', 'The Joomla action returned a result that cannot be encoded.');
			}

			if (strlen($encodedResult) > self::MAX_RESULT_BYTES)
			{
				throw new ActionException('RESULT_TOO_LARGE', 'The Joomla action result exceeds 8388608 bytes.');
			}

			$response = [
				'protocol' => RequestDecoder::PROTOCOL,
				'id' => $id,
				'ok' => true,
				'result' => $result,
			];
		}
		catch (ActionException $exception)
		{
			$response = $this->error($id, $exception->errorCode, $exception->getMessage());
		}
		catch (Throwable)
		{
			// Never leak paths, SQL, credentials, stack traces, or Joomla internals to MCP clients.
			$response = $this->error($id, 'ACTION_FAILED', 'The Joomla action failed.');
		}

		try
		{
			return json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}
		catch (JsonException)
		{
			return '{"protocol":"joomla-mcp/1","id":null,"ok":false,"error":{"code":"ENCODING_FAILED","message":"Response encoding failed."}}';
		}
	}

	/**
	 * Prevent incidental Joomla model or command output from corrupting the
	 * one-JSON-object CLI protocol. The callback discards chunks as they are
	 * produced so noisy actions cannot grow an unbounded in-memory buffer.
	 *
	 * @param   ActionInterface       $action  The action value.
	 * @param   array<string, mixed>  $input   The input value.
	 * @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	private function executeWithoutOutput(ActionInterface $action, array $input): array
	{
		$initialLevel = ob_get_level();

		if (!ob_start(static fn(string $buffer): string => '', 4096))
		{
			throw new ActionException('ACTION_FAILED', 'The Joomla action output could not be isolated.');
		}

		try
		{
			return $action->execute($input);
		}
		finally
		{
			while (ob_get_level() > $initialLevel)
			{
				ob_end_clean();
			}
		}
	}

	/**
	 * Build a stable failure envelope bound to the request identifier.
	 *
	 * @param   int|string|null  $id       The stable entity identifier.
	 * @param   string           $code     The code value.
	 * @param   string           $message  The message value.
	 *  @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	private function error(int|string|null $id, string $code, string $message): array
	{
		return [
			'protocol' => RequestDecoder::PROTOCOL,
			'id' => $id,
			'ok' => false,
			'error' => ['code' => $code, 'message' => $message],
		];
	}
}
