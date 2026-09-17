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
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use RuntimeException;


/**
 * Non-persisted server-owner identity scoped to an actual console invocation.
 *
 * No Joomla user or group is created, impersonated or changed. The identity exists
 * only while a trusted console command runs; HTTP composition never constructs it.
 *
 * @since 0.1.0
 */
final class ConsoleIdentity extends User
{
	/** @var DatabaseInterface Actual viewing-level registry. @since 0.1.0 */
	private DatabaseInterface $database;

	/** @param ConsoleApplication $application Real console application. @param DatabaseInterface $database Joomla database. @since 0.1.0 */
	public function __construct(ConsoleApplication $application, DatabaseInterface $database)
	{
		if (PHP_SAPI !== 'cli')
		{
			throw new RuntimeException('Server-owner identity is restricted to the local console.');
		}

		parent::__construct(0);
		$this->database = $database;
		$this->guest = 0;
		$this->block = 0;
		$this->name = 'Local Joomla console';
		$this->username = 'joomengine-console';
		$this->isRoot = true;
	}

	/** @return int[] All actual viewing levels, without fabricated group membership. @since 0.1.0 */
	public function getAuthorisedViewLevels(): array
	{
		$query = $this->database->getQuery(true)->select($this->database->quoteName('id'))
			->from($this->database->quoteName('#__viewlevels'));

		return array_map('intval', $this->database->setQuery($query)->loadColumn());
	}
}
