<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use Joomla\CMS\User\User;
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;


/**
 * Joomla API identity, never elevated from client-provided claims.
 *
 * @since  0.1.0
 */
final class JoomlaPrincipal implements PrincipalInterface
{
	/**
	 * Identity already authenticated by Joomla's API application.
	 *
	 * @var    User
	 * @since  0.1.0
	 */
	private User $user;

	/**
	 * Reject guests, blocked accounts and identities without API login.
	 *
	 * @param   User  $user  Authenticated application identity.
	 * @throws  RuntimeException  When API access is not authorized.
	 * @since   0.1.0
	 */
	public function __construct(User $user)
	{
		if ((int) $user->id < 1 || (int) $user->guest !== 0 || (int) $user->block !== 0
			|| !$user->authorise('core.login.api'))
		{
			throw new RuntimeException('Joomla API authentication is required.', 401);
		}

		$this->user = $user;
	}

	/**
	 * Return the Joomla user identity for state isolation and auditing.
	 *
	 * @return  string
	 * @since   0.1.0
	 */
	public function getId(): string
	{
		return 'joomla:' . (int) $this->user->id;
	}

	/**
	 * Return the ACL-restricted API execution track.
	 *
	 * @return  string
	 * @since   0.1.0
	 */
	public function getTrack(): string
	{
		return 'api';
	}

	/**
	 * Remote identities never become trusted local authorities.
	 *
	 * @return  bool
	 * @since   0.1.0
	 */
	public function isLocal(): bool
	{
		return false;
	}

	/**
	 * Resolve authorized viewing levels through Joomla, not group IDs.
	 *
	 * @return  int[]
	 * @since   0.1.0
	 */
	public function getViewLevels(): array
	{
		return array_values(array_unique(array_map('intval', $this->user->getAuthorisedViewLevels())));
	}

	/**
	 * Delegate asset permissions to Joomla's authenticated identity.
	 *
	 * @param   string  $action  Joomla action.
	 * @param   string  $asset   Joomla asset.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function authorise(string $action, string $asset): bool
	{
		return $this->user->authorise($action, $asset);
	}
}
