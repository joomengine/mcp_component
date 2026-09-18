<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Bounded loopback-only JSON-RPC fixture using the installed Joomla API application.
 *
 * Tokens are retained only in memory and never included in assertion diagnostics.
 * Each instance owns its session and therefore exercises native principal isolation.
 *
 * @since  0.1.0
 */
final class HttpFixture
{
	/** @var string Explicit disposable server URL. @since 0.1.0 */
	private string $endpoint;
	/** @var string Native Joomla token fixture. @since 0.1.0 */
	private string $token;
	/** @var string Negotiated MCP session, independent of authentication. @since 0.1.0 */
	private string $session = '';
	/** @var int Next JSON-RPC request identifier. @since 0.1.0 */
	private int $sequence = 1;

	/**
	 * Restrict the test transport to an explicitly selected loopback installation.
	 *
	 * @param   string  $base   Test server URL.
	 * @param   string  $token  Native token or deliberately invalid test credential.
	 * @since   0.1.0
	 */
	public function __construct(string $base, #[\SensitiveParameter] string $token)
	{
		if (preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+(?:/[a-zA-Z0-9_-]+)*\z~D', $base) !== 1)
		{
			throw new InvalidArgumentException('The integration transport requires an explicit loopback fixture.');
		}

		$this->endpoint = $base . '/api/index.php/v1/joomengine-mcp';
		$this->token = $token;
	}

	/**
	 * Send one bounded exchange, exposing no request credential in failures.
	 *
	 * @param   string               $body     Raw request body.
	 * @param   array<string,string> $headers  Additional test headers.
	 * @param   string               $method   HTTP verb.
	 * @return  array{status:int,headers:array<string,string>,body:string,json:mixed}
	 * @since   0.1.0
	 */
	public function exchange(string $body, array $headers = [], string $method = 'POST'): array
	{
		$lines = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];

		if ($this->token !== '')
		{
			$lines[] = 'X-Joomla-Token: ' . $this->token;
		}

		if ($this->session !== '')
		{
			$lines[] = 'Mcp-Session-Id: ' . $this->session;
			$lines[] = 'MCP-Protocol-Version: 2025-11-25';
		}

		foreach ($headers as $name => $value)
		{
			$lines[] = $name . ': ' . $value;
		}

		$responseHeaders = [];
		$output = '';
		$handle = curl_init($this->endpoint);
		curl_setopt_array($handle, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $lines,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 45,
			CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$output): int
			{
				if (strlen($output) + strlen($chunk) > 16777216)
				{
					return 0;
				}

				$output .= $chunk;

				return strlen($chunk);
			},
			CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int
			{
				if (($colon = strpos($line, ':')) !== false)
				{
					$responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
				}

				return strlen($line);
			},
		]);

		try
		{
			if (curl_exec($handle) === false)
			{
				throw new RuntimeException('The loopback integration exchange did not complete.');
			}

			$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		}
		finally
		{
			curl_close($handle);
		}

		return ['status' => $status, 'headers' => $responseHeaders, 'body' => $output, 'json' => json_decode($output, true)];
	}

	/**
	 * Initialize one real SDK session and complete the initialized notification.
	 *
	 * @return  array<string,mixed> Server initialization result.
	 * @since   0.1.0
	 */
	public function initialize(): array
	{
		$response = $this->exchange(json_encode(['jsonrpc' => '2.0', 'id' => $this->sequence++, 'method' => 'initialize',
			'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
				'clientInfo' => ['name' => 'installed-component-fixture', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR));

		if ($response['status'] !== 200 || !isset($response['json']['result']['protocolVersion']))
		{
			throw new RuntimeException('Native MCP initialization failed: ' . $response['status'] . ' ' . $response['body']);
		}

		$this->session = $response['headers']['mcp-session-id'] ?? '';
		$notification = $this->exchange('{"jsonrpc":"2.0","method":"notifications/initialized"}');

		if ($notification['status'] !== 202)
		{
			throw new RuntimeException('The initialized notification was not accepted.');
		}

		return $response['json']['result'];
	}

	/**
	 * Call one JSON-RPC method and retain native protocol results/errors.
	 *
	 * @param   string  $method  MCP method.
	 * @param   array   $params  Method parameters.
	 * @return  array<string,mixed> Complete JSON-RPC result.
	 * @since   0.1.0
	 */
	public function rpc(string $method, array $params = []): array
	{
		$id = $this->sequence++;
		$response = $this->exchange(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method,
			'params' => (object) $params], JSON_THROW_ON_ERROR));

		if ($response['status'] !== 200 || !is_array($response['json']) || ($response['json']['id'] ?? null) !== $id)
		{
			throw new RuntimeException('Invalid installed MCP response: ' . $response['status'] . ' ' . $response['body']);
		}

		return $response['json'];
	}

	/**
	 * Call a discovered tool and require its actual structured success envelope.
	 *
	 * @param   string  $name       Tool name.
	 * @param   array   $arguments  Valid input.
	 * @return  array<string,mixed> Decoded structured result.
	 * @since   0.1.0
	 */
	public function tool(string $name, array $arguments = []): array
	{
		$response = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
		$result = $response['result'] ?? [];

		if (isset($response['error']) || ($result['isError'] ?? false))
		{
			throw new RuntimeException('Installed tool ' . $name . ' failed: ' . json_encode($response, JSON_THROW_ON_ERROR));
		}

		return $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
	}

	/** @return void Close this fixture session without changing native credentials. @since 0.1.0 */
	public function disconnect(): void
	{
		if ($this->session !== '')
		{
			$this->exchange('', [], 'DELETE');
			$this->session = '';
		}
	}
}
