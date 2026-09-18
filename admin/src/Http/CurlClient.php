<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Http;


use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;


/**
 * Bounded PSR-18 HTTPS client with no redirects, no implicit credentials and no retries.
 *
 * HTTP errors remain responses. Network/size failures are ambiguous for mutations
 * and are deliberately not converted to successful or automatically retried calls.
 *
 * @since 0.1.0
 */
final class CurlClient implements ClientInterface
{
	/** @var int Maximum exchange duration in seconds. @since 0.1.0 */
	private int $timeout;

	/** @var int Maximum request and response body bytes. @since 0.1.0 */
	private int $maximum;

	/** @var bool Explicit test-only allowance for literal loopback HTTP. @since 0.1.0 */
	private bool $allowLoopbackHttp;

	/** @param int $timeout Exchange time limit. @param int $maximum Body byte limit. @param bool $allowLoopbackHttp Explicit local-test HTTP allowance. @since 0.1.0 */
	public function __construct(int $timeout = 30, int $maximum = 8388608, bool $allowLoopbackHttp = false)
	{
		if ($timeout < 1 || $timeout > 120 || $maximum < 1024 || $maximum > 16777216)
		{
			throw new InvalidArgumentException('Invalid HTTP transport bounds.');
		}

		$this->timeout = $timeout;
		$this->maximum = $maximum;
		$this->allowLoopbackHttp = $allowLoopbackHttp;
	}

	/**
	 * Send exactly one validated HTTP request without following redirects.
	 *
	 * @param RequestInterface $request Request created by trusted application code.
	 * @return ResponseInterface Bounded response, including non-2xx status codes.
	 * @throws NetworkException When the exchange or bounds fail.
	 * @since 0.1.0
	 */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$uri = $request->getUri();
		$loopback = in_array(strtolower($uri->getHost()), ['127.0.0.1', '[::1]', '::1', 'localhost'], true);

		if (!function_exists('curl_init') || $uri->getUserInfo() !== '' || $uri->getFragment() !== ''
			|| $uri->getHost() === '' || preg_match('/[\x00-\x20\\\\]/', (string) $uri) === 1
			|| ($uri->getScheme() !== 'https' && !($uri->getScheme() === 'http' && $loopback && $this->allowLoopbackHttp)))
		{
			throw new NetworkException($request);
		}

		$stream = $request->getBody();

		if ($stream->isSeekable())
		{
			$stream->rewind();
		}

		$body = '';

		while (!$stream->eof())
		{
			$chunk = $stream->read(min(8192, $this->maximum + 1 - strlen($body)));
			$body .= $chunk;

			if (strlen($body) > $this->maximum || ($chunk === '' && !$stream->eof()))
			{
				throw new NetworkException($request);
			}
		}

		$headers = [];

		foreach ($request->getHeaders() as $name => $values)
		{
			if (in_array(strtolower($name), ['host', 'content-length', 'transfer-encoding', 'connection', 'proxy-authorization'], true))
			{
				continue;
			}

			foreach ($values as $value)
			{
				if (preg_match('/[\r\n\x00]/', $name . $value) === 1)
				{
					throw new NetworkException($request);
				}

				$headers[] = $name . ': ' . $value;
			}
		}

		$headers[] = 'Expect:';
		$responseBody = '';
		$responseHeaders = [];
		$headerBytes = 0;
		$maximum = $this->maximum;
		$handle = curl_init((string) $uri);

		if ($handle === false)
		{
			throw new NetworkException($request);
		}

		$options = [
			CURLOPT_CUSTOMREQUEST => $request->getMethod(), CURLOPT_HTTPHEADER => $headers,
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
			CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout), CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ($this->allowLoopbackHttp ? CURLPROTO_HTTP : 0),
			CURLOPT_REDIR_PROTOCOLS => 0, CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
			CURLOPT_ENCODING => '', CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, $maximum): int
			{
				if (strlen($responseBody) + strlen($chunk) > $maximum)
				{
					return 0;
				}

				$responseBody .= $chunk;

				return strlen($chunk);
			},
			CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$headerBytes): int
			{
				$headerBytes += strlen($line);

				if ($headerBytes > 65536)
				{
					return 0;
				}

				if (str_starts_with($line, 'HTTP/'))
				{
					$responseHeaders = [];
				}
				elseif (($colon = strpos($line, ':')) !== false)
				{
					$name = strtolower(trim(substr($line, 0, $colon)));
					$value = trim(substr($line, $colon + 1));

					if (preg_match('/\A[a-z0-9!#$%&\x27*+.^_`|~-]+\z/D', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1)
					{
						return 0;
					}

					$responseHeaders[$name][] = $value;
				}

				return strlen($line);
			},
		];

		if ($body !== '')
		{
			$options[CURLOPT_POSTFIELDS] = $body;
		}

		try
		{
			if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false)
			{
				throw new NetworkException($request);
			}

			$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

			if ($status < 100 || $status > 599)
			{
				throw new NetworkException($request);
			}
		}
		finally
		{
			curl_close($handle);
		}

		unset($responseHeaders['content-encoding'], $responseHeaders['transfer-encoding'], $responseHeaders['content-length']);

		return new Response($status, $responseHeaders, $responseBody);
	}
}
