<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


/**
 * Authenticated execution authority supplied by a trusted composition root.
 *
 * @since  0.1.0
 */
interface PrincipalInterface
{
	/**
	 * Return the stable audit and state-isolation identity.
	 *
	 * @return  string
	 * @since   0.1.0
	 */
	public function getId(): string;

	/**
	 * Return the execution track, independently of the wire transport.
	 *
	 * @return  string  Either api or cli.
	 * @since   0.1.0
	 */
	public function getTrack(): string;

	/**
	 * Identify an authority created by the local console application only.
	 *
	 * @return  bool
	 * @since   0.1.0
	 */
	public function isLocal(): bool;

	/**
	 * Return Joomla viewing-access-level IDs, not user-group IDs.
	 *
	 * @return  int[]
	 * @since   0.1.0
	 */
	public function getViewLevels(): array;

	/**
	 * Ask the authenticated authority for permission on a Joomla asset.
	 *
	 * @param   string  $action  Joomla action name.
	 * @param   string  $asset   Joomla asset name.
	 * @return  bool
	 * @since   0.1.0
	 */
	public function authorise(string $action, string $asset): bool;
}
