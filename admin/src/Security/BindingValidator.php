<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use InvalidArgumentException;
use VDM\Component\JoomEngineMcp\Administrator\Service\HandlerRegistry;


/**
 * Reject executable or cross-origin content in declarative API bindings.
 *
 * @since  0.1.0
 */
final class BindingValidator
{
	/**
	 * Explicitly registered execution services.
	 *
	 * @var    HandlerRegistry
	 * @since  0.1.0
	 */
	private HandlerRegistry $registry;

	/**
	 * Inject the reviewed handler registry.
	 *
	 * @param   HandlerRegistry  $registry  Registered implementations.
	 * @since   0.1.0
	 */
	public function __construct(HandlerRegistry $registry)
	{
		$this->registry = $registry;
	}

	/**
	 * Validate the handler and transport boundary before dispatch.
	 *
	 * @param   array<string,mixed>  $binding  Database binding.
	 * @param   string               $track    Actual authority track.
	 * @return  void
	 * @throws  InvalidArgumentException  For unsupported or unsafe bindings.
	 * @since   0.1.0
	 */
	public function validate(array $binding, string $track): void
	{
		$key = $binding['handler'] ?? null;

		if (!in_array($track, ['api', 'cli'], true) || ($binding['track'] ?? null) !== $track
			|| !is_string($key) || !$this->registry->has($key))
		{
			throw new InvalidArgumentException('The MCP binding is unavailable for this track.');
		}

		foreach (['php', 'sql', 'shell', 'class', 'file', 'url', 'baseUrl'] as $forbidden)
		{
			if (array_key_exists($forbidden, $binding))
			{
				throw new InvalidArgumentException('Executable or external binding content is not allowed.');
			}
		}

		if ($key === 'api.request')
		{
			$method = $binding['method'] ?? null;

			if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true))
			{
				throw new InvalidArgumentException('Unsupported Joomla API method.');
			}

			$this->validateRoute($binding['route'] ?? '');
		}

		if (str_starts_with($key, 'console.') && $track !== 'cli')
		{
			throw new InvalidArgumentException('Console bindings are local-only.');
		}
	}

	/**
	 * Validate a canonical relative Joomla API route without URL authority.
	 *
	 * @param   mixed  $route  Route template from a database binding.
	 * @return  void
	 * @throws  InvalidArgumentException  For malformed or escaping routes.
	 * @since   0.1.0
	 */
	public function validateRoute(mixed $route): void
	{
		if (!is_string($route) || strlen($route) > 1024
			|| preg_match('/\A\/v[1-9][0-9]*\/(?:[a-zA-Z0-9_-]+|\{[a-zA-Z_][a-zA-Z0-9_]*\})(?:\/(?:[a-zA-Z0-9_-]+|\{[a-zA-Z_][a-zA-Z0-9_]*\}))*\z/D', $route) !== 1)
		{
			throw new InvalidArgumentException('A canonical relative Joomla API route is required.');
		}
	}

	/**
	 * Substitute scalar path arguments without permitting additional segments.
	 *
	 * @param   string               $route      Validated route template.
	 * @param   array<string,mixed>  $arguments  Validated action arguments.
	 * @return  string
	 * @throws  InvalidArgumentException  For missing or unsafe path values.
	 * @since   0.1.0
	 */
	public function expandRoute(string $route, array $arguments): string
	{
		$this->validateRoute($route);

		return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $match) use ($arguments): string
		{
			$value = $arguments[$match[1]] ?? null;

			if ((!is_string($value) && !is_int($value))
				|| preg_match('/\A[a-zA-Z0-9_-]+\z/D', (string) $value) !== 1)
			{
				throw new InvalidArgumentException('A valid path argument is required.');
			}

			return rawurlencode((string) $value);
		}, $route);
	}
}
