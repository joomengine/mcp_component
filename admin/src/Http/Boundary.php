<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Http;


use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;


/**
 * Canonical-host, origin, content-type and size enforcement around the SDK.
 *
 * This layer does not authenticate: the Joomla API route and native token plugin
 * have already established the identity. Protocol-version rules remain in the SDK.
 *
 * @since 0.1.0
 */
final class Boundary implements MiddlewareInterface
{
	/** @var Settings Trusted endpoint configuration. @since 0.1.0 */
	private Settings $settings;

	/** @param Settings $settings Canonical host and bounds. @since 0.1.0 */
	public function __construct(Settings $settings)
	{
		$this->settings = $settings;
	}

	/** @inheritDoc */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$error = $this->validate($request);

		if ($error !== null)
		{
			return $error;
		}

		return $this->headers($handler->handle($request), $request);
	}

	/** @param ServerRequestInterface $request Actual request headers and bounded body. @return ?ResponseInterface Rejection or null. @since 0.1.0 */
	public function validate(ServerRequestInterface $request): ?ResponseInterface
	{
		$base = $this->settings->get('api_base');

		if ($base === '')
		{
			return $this->error(503, 'Configure the canonical Joomla API URL in JoomEngine MCP options.');
		}

		$parts = parse_url($base);
		$port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
		$authority = strtolower($parts['host']);
		$host = strtolower($request->getHeaderLine('Host'));
		$allowed = [$authority . ':' . $port];

		if (($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80))
		{
			$allowed[] = $authority;
		}

		if (!in_array($host, $allowed, true))
		{
			return $this->error(403, 'The request host is not the configured MCP host.');
		}

		$origin = $request->getHeaderLine('Origin');

		if ($origin !== '' && !in_array($origin, $this->settings->get('allowed_origins'), true))
		{
			return $this->error(403, 'The request origin is not permitted.');
		}

		if ($request->getMethod() === 'POST')
		{
			if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0])) !== 'application/json')
			{
				return $this->error(415, 'MCP POST requests require application/json.');
			}

			$length = $request->getHeaderLine('Content-Length');
			$size = $request->getBody()->getSize();

			if (($length !== '' && (preg_match('/\A[0-9]+\z/D', $length) !== 1 || (float) $length > $this->settings->get('max_request_bytes')))
				|| ($size !== null && $size > $this->settings->get('max_request_bytes')))
			{
				return $this->error(413, 'The MCP request exceeds the configured byte limit.');
			}
		}

		return null;
	}

	/** @param ServerRequestInterface $request CORS preflight only. @return ResponseInterface Empty validated response, never protocol discovery or execution. @since 0.1.0 */
	public function preflight(ServerRequestInterface $request): ResponseInterface
	{
		$invalid = $this->validate($request);

		if ($invalid !== null)
		{
			return $invalid;
		}

		$method = $request->getHeaderLine('Access-Control-Request-Method');

		if ($method !== '' && !in_array($method, ['POST', 'GET', 'DELETE', 'HEAD'], true))
		{
			return $this->error(405, 'The requested MCP method is not supported.');
		}

		$allowed = ['content-type', 'authorization', 'x-joomla-token', 'mcp-protocol-version',
			'mcp-session-id', 'last-event-id', 'mcp-method', 'mcp-name'];
		$requested = array_filter(array_map('trim', explode(',', strtolower($request->getHeaderLine('Access-Control-Request-Headers')))));

		if (count($requested) > 128)
		{
			return $this->error(400, 'The CORS header list exceeds the supported bound.');
		}

		foreach ($requested as $name)
		{
			if (!in_array($name, $allowed, true))
			{
				if (preg_match('/\Amcp-param-[a-z0-9][a-z0-9_-]{0,119}\z/D', $name) !== 1)
				{
					return $this->error(403, 'A requested CORS header is not supported.');
				}

				$allowed[] = $name;
			}
		}

		return $this->headers(new Response(204, [
			'Access-Control-Allow-Methods' => 'POST, GET, DELETE, HEAD, OPTIONS',
			'Access-Control-Allow-Headers' => implode(', ', $allowed),
			'Access-Control-Max-Age' => '600',
		]), $request);
	}

	/** @param ResponseInterface $response SDK response. @param ServerRequestInterface $request Validated origin. @return ResponseInterface Non-cacheable exact-origin response. @since 0.1.0 */
	private function headers(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
	{
		$response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Vary', 'Origin, Authorization, X-Joomla-Token')
			->withHeader('X-Content-Type-Options', 'nosniff');
		$origin = $request->getHeaderLine('Origin');

		if ($origin !== '')
		{
			$response = $response->withHeader('Access-Control-Allow-Origin', $origin)
				->withHeader('Access-Control-Allow-Credentials', 'true')
				->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, MCP-Protocol-Version');
		}

		return $response;
	}

	/** @param int $status HTTP rejection status. @param string $message Safe diagnostic. @return ResponseInterface Transport error. @since 0.1.0 */
	private function error(int $status, string $message): ResponseInterface
	{
		return new Response($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Vary' => 'Origin'],
			Json::encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => $message]]));
	}
}
