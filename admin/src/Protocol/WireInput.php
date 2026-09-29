<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use JsonException;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\TransportInterface;
use stdClass;
use Symfony\Component\Uid\Uuid;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Keeps original JSON argument types until validation and classifies wire errors.
 *
 * The SDK decodes objects and lists into the same PHP array. This bounded,
 * dispatch-scoped adapter retains the original objects without modifying SDK
 * sources or accepting a client-supplied shape marker.
 *
 * @since 0.1.2
 */
final class WireInput
{
	/** @var MessageFactory Native SDK message catalogue. @since 0.1.2 */
	private MessageFactory $messages;
	/** @var array<string,list<stdClass>> Original arguments for only the active dispatch. @since 0.1.2 */
	private array $arguments = [];
	/** @var array<string,Error> Invalid parameters for only the active dispatch. @since 0.1.2 */
	private array $failures = [];

	/** @since 0.1.2 Construct the native method classifier. */
	public function __construct()
	{
		$this->messages = MessageFactory::make();
	}

	/**
	 * Filter notifications and parameter failures before the lossy SDK decoder.
	 *
	 * @param string $payload Already bounded transport JSON.
	 * @return array{payload:?string,arguments:array<string,list<stdClass>>,errors:list<Error>,ignored:list<string>,batch:bool} Prepared dispatch.
	 * @since 0.1.2
	 */
	public function prepare(string $payload): array
	{
		$prepared = ['payload' => $payload, 'arguments' => [], 'errors' => [], 'ignored' => [], 'batch' => false];

		try
		{
			$data = json_decode($payload, false, 64, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			$prepared['payload'] = null;
			$prepared['errors'][] = Error::forParseError('A valid JSON message within nesting bounds is required.');

			return $prepared;
		}

		$isBatch = is_array($data);

		// Leave malformed envelopes and over-limit batches to native SDK checks.
		if (($isBatch && ($data === [] || count($data) > MessageFactory::DEFAULT_MAX_BATCH_SIZE))
			|| (!$isBatch && !$data instanceof stdClass))
		{
			return $prepared;
		}

		$prepared['batch'] = $isBatch;
		$accepted = [];

		foreach ($isBatch ? $data : [$data] as $node)
		{
			if (!$node instanceof stdClass || ($node->jsonrpc ?? null) !== '2.0'
				|| !is_string($node->method ?? null)
				|| (property_exists($node, 'id') && !is_int($node->id) && !is_string($node->id)))
			{
				$accepted[] = $node;
				continue;
			}

			$notification = !property_exists($node, 'id');
			$parsed = $this->messages->create(Json::encode($node))[0];
			$unknown = $parsed instanceof InvalidInputMessageException
				&& $parsed->getMessage() === sprintf('Unknown method "%s".', $node->method)
				&& !in_array($node->method, [StatelessProtocol::DISCOVER_METHOD, StatelessProtocol::LISTEN_METHOD], true);

			if ($notification)
			{
				// JSON-RPC notifications never receive a response, including unknown
				// methods and malformed parameters on otherwise valid notifications.
				if ($parsed instanceof InvalidInputMessageException)
				{
					$prepared['ignored'][] = $parsed->getMessage();
					continue;
				}
			}
			elseif ($unknown)
			{
				$prepared['errors'][] = Error::forMethodNotFound('The requested MCP method is unavailable.', $node->id);
				continue;
			}
			elseif (property_exists($node, 'params') && !$node->params instanceof stdClass)
			{
				$prepared['errors'][] = Error::forInvalidParams('MCP method parameters must be a JSON object.', $node->id);
				continue;
			}
			elseif ($parsed instanceof InvalidInputMessageException
				&& !in_array($node->method, [StatelessProtocol::DISCOVER_METHOD, StatelessProtocol::LISTEN_METHOD], true))
			{
				$prepared['errors'][] = Error::forInvalidParams('The parameters do not match the requested MCP method.', $node->id);
				continue;
			}
			elseif ($node->method === CallToolRequest::getMethod())
			{
				$params = $node->params ?? null;

				if (!$params instanceof stdClass || !is_string($params->name ?? null)
					|| (property_exists($params, 'arguments') && !$params->arguments instanceof stdClass))
				{
					$prepared['errors'][] = Error::forInvalidParams('tools/call requires a string name and object-shaped arguments.', $node->id);
					continue;
				}

				$prepared['arguments'][$this->key($node->id)][] = $params->arguments ?? new stdClass();
			}

			$accepted[] = $node;
		}

		$prepared['payload'] = $accepted === [] ? null : Json::encode($isBatch ? $accepted : $accepted[0]);

		return $prepared;
	}

	/**
	 * Run one dispatch with its original arguments, releasing them on every path.
	 *
	 * @param array<string,list<stdClass>> $arguments Prepared original argument objects.
	 * @param callable $operation Native protocol dispatch.
	 * @return mixed Native result.
	 * @since 0.1.2
	 */
	public function scoped(array $arguments, callable $operation, array $failures = []): mixed
	{
		$previous = $this->arguments;
		$previousFailures = $this->failures;
		$this->arguments = $arguments;
		$this->failures = [];

		foreach ($failures as $failure)
		{
			if ($failure instanceof Error && $failure->id !== null)
			{
				$this->failures[$this->key($failure->id)] = $failure;
			}
		}

		try
		{
			return $operation();
		}
		finally
		{
			$this->arguments = $previous;
			$this->failures = $previousFailures;
		}
	}

	/** @param Request $request SDK request. @return ?Error Original argument shape failure. @since 0.1.2 */
	public function failure(Request $request): ?Error
	{
		return $this->failures[$this->key($request->getId())] ?? null;
	}

	/** @param Request $request SDK request. @return ?stdClass Original arguments, or null outside a wire dispatch. @since 0.1.2 */
	public function arguments(Request $request): ?stdClass
	{
		$key = $this->key($request->getId());

		if (!isset($this->arguments[$key]) || $this->arguments[$key] === [])
		{
			return null;
		}

		return array_shift($this->arguments[$key]);
	}

	/**
	 * Adapt a stdio input line without retaining messages across loop iterations.
	 *
	 * @param TransportInterface $transport Native transport.
	 * @param string $payload Bounded input line.
	 * @param ?Uuid $sessionId Current native session.
	 * @param callable $listener Native SDK message callback.
	 * @return void
	 * @since 0.1.2
	 */
	public function dispatch(TransportInterface $transport, string $payload, ?Uuid $sessionId, callable $listener): void
	{
		$prepared = $this->prepare($payload);

		foreach ($prepared['errors'] as $error)
		{
			$transport->send(Json::encode($error), ['session_id' => $sessionId, 'type' => 'response', 'status_code' => 400]);
		}

		if ($prepared['payload'] !== null)
		{
			$this->scoped($prepared['arguments'], static fn () => $listener($transport, $prepared['payload'], $sessionId));
		}
	}

	/** @param string|int $id Request identifier. @return string Type-preserving lookup key. @since 0.1.2 */
	private function key(string|int $id): string
	{
		return is_int($id) ? 'i:' . $id : 's:' . $id;
	}
}
