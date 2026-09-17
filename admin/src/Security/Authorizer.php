<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;


/**
 * Shared discovery and execution predicate; visibility never grants write access.
 *
 * @since  0.1.0
 */
final class Authorizer
{
	/**
	 * Determine whether a published definition is visible to the principal.
	 *
	 * @param   array<string,mixed>  $row        Joined definition/provider metadata.
	 * @param   PrincipalInterface  $principal  Current authenticated authority.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function canView(array $row, PrincipalInterface $principal): bool
	{
		if ((int) ($row['published'] ?? 0) !== 1 || (int) ($row['provider_published'] ?? 0) !== 1)
		{
			return false;
		}

		if ($principal->isLocal())
		{
			return $principal->getTrack() === 'cli';
		}

		$levels = $principal->getViewLevels();

		return $principal->getTrack() === 'api'
			&& $principal->authorise('mcp.access', 'com_joomengine_mcp')
			&& in_array((int) ($row['access'] ?? 0), $levels, true)
			&& in_array((int) ($row['provider_access'] ?? 0), $levels, true)
			&& $principal->authorise('mcp.execute', $this->asset($row));
	}

	/**
	 * Recheck visibility and effect-specific permission immediately before use.
	 *
	 * @param   array<string,mixed>  $row        Definition to execute.
	 * @param   PrincipalInterface  $principal  Current authenticated authority.
	 * @return  void
	 * @throws  RuntimeException  For hidden, disabled or unauthorized definitions.
	 * @since   0.1.0
	 */
	public function requireExecution(array $row, PrincipalInterface $principal): void
	{
		$effect = $row['effect'] ?? 'read';

		if (!in_array($effect, ['read', 'write'], true) || !$this->canView($row, $principal)
			|| (!$principal->isLocal() && $effect === 'write'
				&& !$principal->authorise('mcp.write', $this->asset($row))))
		{
			throw new RuntimeException('The requested MCP definition is unavailable.', 403);
		}
	}

	/**
	 * Resolve only the component's own validated asset namespace.
	 *
	 * @param   array<string,mixed>  $row  Joined definition metadata.
	 * @return  string
	 * @since   0.1.0
	 */
	private function asset(array $row): string
	{
		$name = $row['asset_name'] ?? 'com_joomengine_mcp';

		if (!is_string($name) || preg_match('/\Acom_joomengine_mcp(?:\.[a-z_]+\.[1-9][0-9]*)?\z/D', $name) !== 1)
		{
			return 'com_joomengine_mcp.invalid';
		}

		return $name;
	}
}
