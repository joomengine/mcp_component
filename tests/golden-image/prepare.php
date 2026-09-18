<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;

require dirname(__DIR__) . '/integration/bootstrap.php';

// This account exists exclusively inside the disposable, unexposed Docker stack.
$user = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');

if ((int) $user->id === 0)
{
	$user = new User();

	if (!$user->bind([
		'name' => 'MCP Test Administrator',
		'username' => 'mcp_test_admin',
		'email' => 'mcp-fixture@example.test',
		'password' => 'Disposable!McpFixture321',
		'password2' => 'Disposable!McpFixture321',
		'groups' => [8],
		'block' => 0,
	]) || !$user->save())
	{
		throw new RuntimeException('The disposable golden-image administrator could not be created.');
	}
}

echo "Disposable administrator is available for the native browser and HTTP tests.\n";
