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


/**
 * Bounded protocol header extraction without passing credentials into the SDK.
 *
 * Modern MCP method, name and declared argument mirrors must reach the SDK so
 * its standard-header validator can compare them with the JSON-RPC body.
 *
 * @since 0.1.0
 */
final class RequestHeaders
{
	/**
	 * Preserve approved protocol headers and actual routing-boundary headers.
	 *
	 * @param array<string,mixed> $server Joomla's server input.
	 * @param array<string,mixed> $native Original header names when PHP supplies them.
	 * @return array<string,string> Non-credential HTTP metadata.
	 * @throws InvalidArgumentException For oversized or malformed header values.
	 * @since 0.1.0
	 */
	public static function extract(array $server, array $native = []): array
	{
		$headers = [];

		foreach ($server as $key => $value)
		{
			if (!is_string($key))
			{
				continue;
			}

			$name = str_starts_with($key, 'HTTP_') ? substr($key, 5) : $key;
			$name = strtolower(str_replace('_', '-', $name));

			if (self::allowed($name))
			{
				self::assign($headers, $name, $value);
			}
		}

		foreach ($native as $name => $value)
		{
			if (is_string($name) && self::allowed(strtolower($name)))
			{
				self::assign($headers, strtolower($name), $value);
			}
		}

		if (count($headers) > 128 || array_sum(array_map('strlen', $headers)) > 65536)
		{
			throw new InvalidArgumentException('MCP request headers exceed the supported bound.');
		}

		return $headers;
	}

	/** @param string $name Lowercase header name. @return bool Whether the SDK or transport boundary consumes this header. @since 0.1.0 */
	private static function allowed(string $name): bool
	{
		return in_array($name, ['accept', 'content-type', 'content-length', 'host', 'origin', 'mcp-protocol-version',
			'mcp-session-id', 'last-event-id', 'mcp-method', 'mcp-name', 'access-control-request-method', 'access-control-request-headers'], true)
			|| preg_match('/\Amcp-param-[a-z0-9][a-z0-9_-]{0,119}\z/D', $name) === 1;
	}

	/** @param array<string,string> $headers Result map. @param string $name Validated header name. @param mixed $value Raw header value. @return void @since 0.1.0 */
	private static function assign(array &$headers, string $name, mixed $value): void
	{
		if (!is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1)
		{
			throw new InvalidArgumentException('An MCP request header is invalid.');
		}

		if ($value !== '')
		{
			$headers[$name] = $value;
		}
	}
}
