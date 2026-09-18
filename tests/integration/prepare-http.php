<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Table\Extension;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

require __DIR__ . '/bootstrap.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$base = getenv('MCP_TEST_BASE_URL');

if (!is_string($base) || preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~D', $base) !== 1)
{
	throw new RuntimeException('This fixture only accepts an explicit loopback HTTP URL.');
}

// Joomla's URI helper treats cli-server as CLI. Pin only the disposable fixture's
// native live_site so the PHP test server routes like an Apache/FPM installation.
$configuration = new Registry(new \JConfig());
$configuration->set('live_site', $base);
file_put_contents(JPATH_CONFIGURATION . '/configuration.php', $configuration->toString('PHP', ['class' => 'JConfig', 'closingtag' => false]));
$extension = new Extension($db);
$extension->load(['type' => 'component', 'element' => 'com_joomengine_mcp']);
$params = new Registry($extension->params);
$params->set('api_base', $base . '/api/index.php');
$params->set('allow_loopback_http', true);
$extension->params = (string) $params;
if (!$extension->store())
{
	throw new RuntimeException($extension->getError());
}

// Install a native Joomla token fixture. Only its native plugin validates it.
$seed = random_bytes(32);
foreach (['joomlatoken.token' => base64_encode($seed), 'joomlatoken.enabled' => '1'] as $key => $value)
{
	$query = $db->createQuery()->delete($db->quoteName('#__user_profiles'))->where($db->quoteName('user_id') . ' = ' . (int) $admin->id)
		->where($db->quoteName('profile_key') . ' = ' . $db->quote($key));
	$db->setQuery($query)->execute();
	$row = (object) ['user_id' => (int) $admin->id, 'profile_key' => $key, 'profile_value' => $value, 'ordering' => 1];
	$db->insertObject('#__user_profiles', $row);
}

$token = base64_encode('sha256:' . (int) $admin->id . ':' . hash_hmac('sha256', $seed, $app->get('secret')));
$destination = getenv('MCP_TEST_TOKEN_FILE');
if (!is_string($destination) || $destination === '')
{
	throw new RuntimeException('Provide a private token-file path outside the repository.');
}
$oldMask = umask(0077);
try
{
	file_put_contents($destination, $token);
}
finally
{
	umask($oldMask);
}
echo "Native Joomla token and endpoint fixture configured; no credential printed.\n";
