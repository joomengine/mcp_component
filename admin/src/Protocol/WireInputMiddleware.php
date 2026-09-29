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
use Mcp\Server\Wire\InboundClassifier;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

		$headers = [];

		foreach ($request->getHeaders() as $name => $values)
		{
			$headers[$name] = implode(', ', $values);
		}

		$classification = (new InboundClassifier())->classify($request->getMethod(), $payload, $headers);

		if ($classification->isRejected() || $classification->modern)
		{
			// Native era, metadata, version and header checks see the original
			// message, including prohibited modern batches. Only a method-param
			// decoder failure is remapped after those checks have completed.
			$request = $request->withBody($this->streams->createStream($payload));
			$response = $this->wire->scoped($prepared['arguments'], static fn () => $handler->handle($request), $prepared['errors']);
			$body = (string) $response->getBody();

			if (!$classification->isRejected() && $body !== '' && $prepared['errors'] !== [])
			{
				$reply = Json::decode($body);

				if (($reply['error']['code'] ?? null) === Error::INVALID_REQUEST)
				{
					foreach ($prepared['errors'] as $failure)
					{
						if (($reply['id'] ?? null) === $failure->id)
						{
							$response = $response->withBody($this->streams->createStream(Json::encode($failure)));
							break;
						}
					}
				}
			}

			return $response;
		}

		if ($prepared['payload'] === null)
		{
			return $prepared['errors'] === [] ? $this->responses->createResponse(202)
				: $this->response($prepared['errors'], $prepared['batch'], 400);
		}

		$request = $request->withBody($this->streams->createStream($prepared['payload']));
		$response = $this->wire->scoped($prepared['arguments'], static fn () => $handler->handle($request));

		if ($prepared['errors'] === [])
		{
			return $response;
		}

		// Keep native session headers and valid batch responses alongside errors.
		$body = (string) $response->getBody();
		$messages = $body === '' ? [] : Json::decode($body, false);
		$messages = is_array($messages) ? $messages : [$messages];
		$messages = array_merge($prepared['errors'], $messages);

		return $response->withHeader('Content-Type', 'application/json')
			->withBody($this->streams->createStream(Json::encode($messages)));
	}

	/** @param array $errors Wire failures. @param bool $batch Batch response shape. @param int $status HTTP status. @return ResponseInterface Bounded error response. @since 0.1.2 */
	private function response(array $errors, bool $batch, int $status): ResponseInterface
	{
		return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
			->withBody($this->streams->createStream(Json::encode($batch ? $errors : $errors[0])));
	}
}
