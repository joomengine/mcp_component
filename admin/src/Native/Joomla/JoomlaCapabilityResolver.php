<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @git        JoomEngine MCP <https://github.com/joomengine/mcp_component>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSES/joomla-mcp.txt
 * @since      0.1.0
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Joomla;


use Joomla\CMS\User\UserFactoryInterface;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\CapabilityResolverInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;


/**
 * Resolve native action permissions against the current Joomla actor.
 *
 * @since  0.1.0
 */
final class JoomlaCapabilityResolver implements CapabilityResolverInterface
{
	/**
	 * The cached Joomla identity used for permission checks.
	 *
	 * @var   ?object
	 *
	 * @since  0.1.0
	 */
	private ?object $actor = null;

	/**
	 * The Joomla user identity factory.
	 *
	 * @var   UserFactoryInterface
	 *
	 * @since  0.1.0
	 */
	private UserFactoryInterface $users;

	/**
	 * The Joomla user identifier selected for native authorization.
	 *
	 * @var   int
	 *
	 * @since  0.1.0
	 */
	private int $actorUserId;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   UserFactoryInterface  $users        The Joomla user identity factory.
	 * @param   int                   $actorUserId  The Joomla user identifier selected for native authorization.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		UserFactoryInterface $users,
		int $actorUserId,
	)
	{
		$this->users = $users;
		$this->actorUserId = $actorUserId;
	}

	/**
	 * Describe the effective native execution actor.
	 *
	 * @return  array
	 *
	 * @since  0.1.0
	 */
	public function actor(): array
	{
		return [
			'id' => $this->actorUserId > 0 ? $this->actorUserId : null,
			'configured' => $this->actorUserId > 0,
		];
	}

	/**
	 * Intersect every required permission with the current actor authority.
	 *
	 * @param   ActionDescriptor  $descriptor  The descriptor value.
	 * @return  array
	 *
	 * @since  0.1.0
	 */
	public function resolve(ActionDescriptor $descriptor): array
	{
		$requirements = [];
		$allowed = true;

		foreach ($descriptor->acl as $requirement)
		{
			$granted = $this->authorise($requirement['action'], $requirement['asset']);
			$requirements[] = $requirement + ['allowed' => $granted];
			$allowed = $allowed && $granted;
		}

		return ['allowed' => $allowed, 'requirements' => $requirements];
	}

	/**
	 * Check one Joomla permission against its explicit asset.
	 *
	 * @param   string  $action  The action value.
	 * @param   string  $asset   The asset value.
	 * @return  bool
	 *
	 * @since  0.1.0
	 */
	private function authorise(string $action, string $asset): bool
	{
		if ($this->actorUserId <= 0)
		{
			return false;
		}

		try
		{
			$this->actor ??= $this->users->loadUserById($this->actorUserId);

			return method_exists($this->actor, 'authorise')
				&& $this->actor->authorise($action, $asset) === true;
		}
		catch (Throwable)
		{
			return false;
		}
	}
}
