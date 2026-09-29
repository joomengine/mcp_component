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
use Mcp\Server\Transport\ReadsBoundedBody;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Preserves JSON argument types across both SDK HTTP protocol dispatchers.
 *
 * @since 0.1.2
 */
final class WireInputMiddleware implements MiddlewareInterface
{
	use ReadsBoundedBody;

	/** @var WireInput Original dispatch-scoped JSON types. @since 0.1.2 */
	private WireInput $wire;
	/** @var ResponseFactoryInterface Native HTTP response construction. @since 0.1.2 */
	private ResponseFactoryInterface $responses;
	/** @var StreamFactoryInterface Replacement bounded body construction. @since 0.1.2 */
	private StreamFactoryInterface $streams;
	/** @var int Maximum original request bytes. @since 0.1.2 */
	private int $maximum;

	/** @param WireInput $wire Wire types. @param ResponseFactoryInterface $responses Responses. @param StreamFactoryInterface $streams Streams. @param int $maximum Request limit. @since 0.1.2 */
	public function __construct(WireInput $wire, ResponseFactoryInterface $responses, StreamFactoryInterface $streams, int $maximum = 4194304)
	{
		$this->wire = $wire;
		$this->responses = $responses;
		$this->streams = $streams;
		$this->maximum = $maximum;
	}

	/** @inheritDoc */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($request->getMethod() !== 'POST')
		{
			return $handler->handle($request);
		}

		$payload = $this->readBoundedBody($request->getBody(), $this->maximum);

		if ($payload === null)
		{
			return $this->response([Error::forInvalidRequest('The request exceeds the configured byte limit.')], false, 413);
		}

		$prepared = $this->wire->prepare($payload);

		if (($prepared['errors'][0]->code ?? null) === Error::PARSE_ERROR)
		{
			return $this->response($prepared['errors'], false, 400);
		}

		// Native era, session, metadata, version and header checks always receive
		// the original payload. Parameter failures on decoded requests are served
		// by the registered guard only after those native checks have completed.
		$request = $request->withBody($this->streams->createStream($payload));
		$response = $this->wire->scoped($prepared['arguments'], static fn () => $handler->handle($request), $prepared['errors']);
		$body = (string) $response->getBody();

		if ($body === '' || !str_starts_with($response->getHeaderLine('Content-Type'), 'application/json')
			|| ($prepared['errors'] === [] && $prepared['ignored'] === []))
		{
			return $response;
		}

		$decoded = Json::decode($body, false);
		$messages = is_array($decoded) ? $decoded : [$decoded];
		$adapted = [];

		foreach ($messages as $message)
		{
			if ($message instanceof stdClass && ($message->error->code ?? null) === Error::INVALID_REQUEST)
			{
				if (!property_exists($message, 'id') && in_array($message->error->message ?? '', $prepared['ignored'], true))
				{
					continue;
				}

				foreach ($prepared['errors'] as $failure)
				{
					if ($failure->id !== null && ($message->id ?? null) === $failure->id)
					{
						$message = $failure;
						break;
					}
				}
			}

			$adapted[] = $message;
		}

		if ($adapted === [])
		{
			return $response->withStatus(202)->withBody($this->streams->createStream(''));
		}

		return $response->withHeader('Content-Type', 'application/json')
			->withBody($this->streams->createStream(Json::encode(is_array($decoded) ? $adapted : $adapted[0])));
	}

	/** @param array $errors Wire failures. @param bool $batch Batch response shape. @param int $status HTTP status. @return ResponseInterface Bounded error response. @since 0.1.2 */
	private function response(array $errors, bool $batch, int $status): ResponseInterface
	{
		return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
			->withBody($this->streams->createStream(Json::encode($batch ? $errors : $errors[0])));
	}
}
