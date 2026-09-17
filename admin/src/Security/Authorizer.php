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
 * Shared row-viewing and operation predicate, independent of target Joomla ACL.
 *
 * @since 0.1.0
 */
final class Authorizer
{
	/**
	 * Intersect publication, provider/row viewing levels and asset execute rules.
	 *
	 * @param array<string,mixed> $row Joined definition and provider metadata.
	 * @param PrincipalInterface $principal Trusted current authority.
	 * @return bool Whether the definition may be disclosed.
	 * @since 0.1.0
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

		$asset = $row['asset_name'] ?? 'com_joomengine_mcp';
		$providerAsset = $row['provider_asset_name'] ?? 'com_joomengine_mcp';

		if (!$this->validAsset($asset) || !$this->validAsset($providerAsset))
		{
			return false;
		}

		$levels = $principal->getViewLevels();

		return $principal->getTrack() === 'api'
			&& $principal->authorise('mcp.access', 'com_joomengine_mcp')
			&& in_array((int) ($row['access'] ?? 0), $levels, true)
			&& in_array((int) ($row['provider_access'] ?? 0), $levels, true)
			&& $principal->authorise('mcp.execute', $asset)
			&& $principal->authorise('mcp.execute', $providerAsset);
	}

	/**
	 * Recheck disclosure and effect-specific permission immediately before use.
	 *
	 * @param array<string,mixed> $row Joined current metadata.
	 * @param PrincipalInterface $principal Current authority.
	 * @return void
	 * @throws RuntimeException When the definition is unavailable.
	 * @since 0.1.0
	 */
	public function requireExecution(array $row, PrincipalInterface $principal): void
	{
		$effect = $row['effect'] ?? 'read';

		if (!in_array($effect, ['read', 'write'], true) || !$this->canView($row, $principal)
			|| (!$principal->isLocal() && $effect === 'write'
				&& !$principal->authorise('mcp.write', $row['asset_name'] ?? 'com_joomengine_mcp')))
		{
			throw new RuntimeException('The requested MCP definition is unavailable.', 403);
		}
	}

	/** @param mixed $asset Candidate asset name. @return bool Whether it belongs to this component. @since 0.1.0 */
	private function validAsset(mixed $asset): bool
	{
		return is_string($asset) && preg_match('/\Acom_joomengine_mcp(?:\.[a-z_]+\.[1-9][0-9]*)?\z/D', $asset) === 1;
	}
}
