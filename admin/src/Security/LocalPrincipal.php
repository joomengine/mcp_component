<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use Joomla\CMS\Application\ConsoleApplication;
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;


/**
 * Trusted server authority constructible only with a real console application.
 *
 * @since  0.1.0
 */
final class LocalPrincipal implements PrincipalInterface
{
	/**
	 * Local process owner identity used for durable-state isolation.
	 *
	 * @var    string
	 * @since  0.1.0
	 */
	private string $identity;

	/**
	 * Require both the Joomla console application and CLI SAPI.
	 *
	 * @param   ConsoleApplication  $application  Actual Joomla console application.
	 * @throws  RuntimeException  Outside the local console boundary.
	 * @since   0.1.0
	 */
	public function __construct(ConsoleApplication $application)
	{
		if (PHP_SAPI !== 'cli')
		{
			throw new RuntimeException('Trusted MCP execution requires the local console.', 403);
		}

		$owner = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'local';
		$this->identity = 'console:' . $owner;
	}

	/**
	 * Return the local process owner identity without user-controlled claims.
	 *
	 * @return  string
	 * @since   0.1.0
	 */
	public function getId(): string
	{
		return $this->identity;
	}

	/**
	 * Select native console execution, never the HTTP API track.
	 *
	 * @return  string
	 * @since   0.1.0
	 */
	public function getTrack(): string
	{
		return 'cli';
	}

	/**
	 * Identify the verified server-console authority.
	 *
	 * @return  bool
	 * @since   0.1.0
	 */
	public function isLocal(): bool
	{
		return true;
	}

	/**
	 * Local authority is not represented by fabricated Joomla viewing levels.
	 *
	 * @return  int[]
	 * @since   0.1.0
	 */
	public function getViewLevels(): array
	{
		return [];
	}

	/**
	 * Local server ownership supplies the requested unrestricted authority.
	 *
	 * @param   string  $action  Joomla action retained for interface compatibility.
	 * @param   string  $asset   Joomla asset retained for interface compatibility.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function authorise(string $action, string $asset): bool
	{
		return true;
	}
}
