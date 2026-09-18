<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use InvalidArgumentException;
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;


/**
 * Registry of injected handlers; database values can never instantiate classes.
 *
 * @since  0.1.0
 */
final class HandlerRegistry
{
	/**
	 * Reviewed implementations indexed by stable service key.
	 *
	 * @var    array<string,HandlerInterface>
	 * @since  0.1.0
	 */
	private array $handlers;

	/**
	 * Accept handlers from the Joomla service-provider composition root.
	 *
	 * @param   array<string,HandlerInterface>  $handlers  Registered implementations.
	 * @throws  InvalidArgumentException  For invalid keys or implementations.
	 * @since   0.1.0
	 */
	public function __construct(array $handlers)
	{
		foreach ($handlers as $key => $handler)
		{
			if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_.-]{1,127}\z/D', $key) !== 1
				|| !$handler instanceof HandlerInterface)
			{
				throw new InvalidArgumentException('Invalid registered MCP handler.');
			}
		}

		$this->handlers = $handlers;
	}

	/**
	 * Determine whether an explicit service key is registered.
	 *
	 * @param   string  $key  Handler service key.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function has(string $key): bool
	{
		return isset($this->handlers[$key]);
	}

	/**
	 * Return a reviewed implementation without reflection or dynamic loading.
	 *
	 * @param   string  $key  Handler service key.
	 * @return  HandlerInterface
	 * @throws  RuntimeException  When the handler has not been registered.
	 * @since   0.1.0
	 */
	public function get(string $key): HandlerInterface
	{
		if (!$this->has($key))
		{
			throw new RuntimeException('The requested MCP handler is unavailable.');
		}

		return $this->handlers[$key];
	}
}
