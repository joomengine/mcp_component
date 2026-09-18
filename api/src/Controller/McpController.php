<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Api\Controller;


use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\MVC\Controller\BaseController;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\RuntimeAwareInterface;
use VDM\Component\JoomEngineMcp\Administrator\Http\Boundary;
use VDM\Component\JoomEngineMcp\Administrator\Http\RequestHeaders;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\RuntimeFactory;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;


/**
 * Joomla-authenticated API controller emitting the SDK's unwrapped MCP response.
 *
 * @since 0.1.0
 */
final class McpController extends BaseController implements RuntimeAwareInterface
{
	/** @var RuntimeFactory Injected component composition root. @since 0.1.0 */
	private RuntimeFactory $runtimeFactory;

	/** @inheritDoc */
	public function setRuntimeFactory(RuntimeFactory $runtime): void
	{
		$this->runtimeFactory = $runtime;
	}

	/** @return void Handle MCP only after Joomla's non-public route authentication. @since 0.1.0 */
	public function handle(): void
	{
		$level = ob_get_level();
		ob_start(static fn (string $output): string => '', 4096);

		try
		{
			if (!$this->app instanceof ApiApplication)
			{
				throw new RuntimeException('The Joomla API application is required.', 403);
			}

			$runtime = $this->runtimeFactory->api($this->app);
			$settings = $runtime->settings();
			$request = $this->request($settings);
			$factory = new Psr17Factory();
			$response = $runtime->server()->run(new StreamableHttpTransport($request, $factory, $factory,
				middleware: [new Boundary($settings)], maxBodyBytes: $settings->get('max_request_bytes')));

			if (!$response instanceof ResponseInterface || $response->getBody()->getSize() > $settings->get('max_result_bytes'))
			{
				throw new RuntimeException('The MCP response could not be emitted within its configured limit.', 500);
			}
		}
		catch (Throwable $error)
		{
			$status = in_array($error->getCode(), [401, 403, 413, 503], true) ? $error->getCode() : 500;
			$message = match ($status)
			{
				401 => 'A valid Joomla API token is required.',
				403 => 'Access to this MCP endpoint is denied.',
				413 => 'The MCP request exceeds the configured byte limit.',
				503 => 'The MCP endpoint is not configured.',
				default => 'The MCP request could not complete.',
			};
			$response = new Response($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
				Json::encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => $message]]));
		}
		finally
		{
			while (ob_get_level() > $level)
			{
				ob_end_clean();
			}
		}

		$this->emit($response);
	}

	/** @return void Public CORS preflight exposes no catalogue, state or execution. @since 0.1.0 */
	public function options(): void
	{
		if (!$this->app instanceof ApiApplication || $this->input->getMethod() !== 'OPTIONS')
		{
			throw new RuntimeException('The request is not a CORS preflight.', 403);
		}

		$settings = $this->runtimeFactory->settings($this->app);
		$this->emit((new Boundary($settings))->preflight($this->request($settings)));
	}

	/** @param Settings $settings Trusted endpoint configuration. @return ServerRequest Canonical PSR request retaining the actual Host and Origin headers for validation. @since 0.1.0 */
	private function request(Settings $settings): ServerRequest
	{
		if ($settings->get('api_base') === '')
		{
			throw new RuntimeException('The MCP endpoint is not configured.', 503);
		}

		$native = function_exists('getallheaders') ? getallheaders() : [];
		$headers = RequestHeaders::extract($this->input->server->getArray(), is_array($native) ? $native : []);

		if (!isset($headers['host']))
		{
			throw new RuntimeException('The actual request Host header is required.', 403);
		}

		$raw = '';

		if ($this->input->getMethod() === 'POST')
		{
			$stream = fopen('php://input', 'rb');

			if (!is_resource($stream))
			{
				throw new RuntimeException('The MCP request body could not be read.', 400);
			}

			try
			{
				$raw = stream_get_contents($stream, $settings->get('max_request_bytes') + 1);
			}
			finally
			{
				fclose($stream);
			}

			if (!is_string($raw))
			{
				throw new RuntimeException('The MCP request body could not be read.', 400);
			}
		}

		if (strlen($raw) > $settings->get('max_request_bytes'))
		{
			throw new RuntimeException('The MCP request exceeds the configured byte limit.', 413);
		}

		return new ServerRequest($this->input->getMethod(), $settings->get('api_base') . '/v1/joomengine-mcp', $headers, $raw);
	}

	/** @param ResponseInterface $response SDK or safe transport response. @return void Preserve JSON-RPC framing rather than Joomla JSON:API wrapping. @since 0.1.0 */
	private function emit(ResponseInterface $response): void
	{
		$this->app->setHeader('status', (string) $response->getStatusCode(), true);

		foreach ($response->getHeaders() as $name => $values)
		{
			foreach ($values as $index => $value)
			{
				$this->app->setHeader($name, $value, $index === 0);
			}
		}

		$this->app->sendHeaders();
		echo (string) $response->getBody();
		$this->app->close();
	}
}
