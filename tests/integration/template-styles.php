<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Installer\Installer;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
require __DIR__ . '/StdioFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$app->bootComponent('com_joomengine_mcp');
$base = (string) getenv('MCP_TEST_BASE_URL');
$token = trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE')));
$http = new HttpFixture($base, $token);
$cli = null;
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void
{
	if (!$value)
	{
		throw new RuntimeException($message);
	}
	$checks++;
	echo 'PASS ' . $message . PHP_EOL;
};
$suffix = bin2hex(random_bytes(5));
$template = 'cassiopeia_mcp_' . $suffix;
$prefix = 'MCP inheritance ' . $suffix;
$source = sys_get_temp_dir() . '/' . $template;
$extensionId = null;
$grantId = null;
$styles = [];
try
{
	// Mirror Joomla's native child-template package: only the manifest and empty
	// override/media directories are installed, so parent index/assets must work.
	foreach (['', '/html', '/media', '/media/css', '/media/js', '/media/images', '/media/scss'] as $path)
	{
		$check(mkdir($source . $path, 0700), 'Create isolated child template package directory ' . ($path ?: '/'));
	}
	$manifest = simplexml_load_file(JPATH_ROOT . '/templates/cassiopeia/templateDetails.xml');
	$check($manifest instanceof SimpleXMLElement, 'Read the installed Cassiopeia manifest');
	foreach (['languages', 'media', 'files', 'parent', 'inheritable', 'update', 'updateservers'] as $key)
	{
		unset($manifest->{$key});
	}
	$manifest->name = $template;
	$manifest->addChild('inheritable', '0');
	$manifest->addChild('parent', 'cassiopeia');
	$files = $manifest->addChild('files');
	$files->addChild('filename', 'templateDetails.xml');
	$files->addChild('folder', 'html');
	$media = $manifest->addChild('media');
	$media->addAttribute('destination', 'templates/site/' . $template);
	$media->addAttribute('folder', 'media');
	foreach (['css', 'js', 'images', 'scss'] as $folder)
	{
		$media->addChild('folder', $folder);
	}
	$check($manifest->asXML($source . '/templateDetails.xml'), 'Write a native child manifest without copying its parent files');
	$installer = new Installer();
	$installer->setDatabase($db);
	$check($installer->install($source), 'Install the disposable child through Joomla Installer');
	$extensionId = (int) $db->setQuery('SELECT extension_id FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('template') . ' AND element = ' . $db->quote($template) . ' AND client_id = 0')->loadResult();
	$check($extensionId > 0 && !is_file(JPATH_ROOT . '/templates/' . $template . '/index.php'), 'Installed child requires parent template rendering');
	$http->initialize();
	$permission = $http->tool('joomla_permission_request', ['toolsets' => ['structure.write'], 'duration' => '30-minutes', 'reason' => 'Disposable native template inheritance verification']);
	$grant = $http->tool('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'] ?? null;
	$check($grantId !== null, 'Approve the API template fixture write scope');
	$cli = new StdioFixture();
	foreach (['api' => $http, 'cli' => $cli] as $track => $client)
	{
		foreach ([[$template, 0, 'cassiopeia', 0], ['cassiopeia', 0, '', 1], ['atum', 1, '', 1]] as [$name, $nativeClient, $parent, $inheritable])
		{
			$title = $prefix . ' ' . $track . ' ' . $name;
			$action = 'templates.' . ($nativeClient === 0 ? 'site' : 'administrator') . '-styles.create';
			$data = ['title' => $title, 'template' => $name, 'home' => '0'];
			if ($name === $template)
			{
				$parameters = $db->setQuery('SELECT params FROM ' . $db->quoteName('#__template_styles') . ' WHERE template = ' . $db->quote($template) . ' AND client_id = 0 ORDER BY id', 0, 1)->loadResult();
				$data['params'] = json_decode((string) $parameters, true, 64, JSON_THROW_ON_ERROR);
				$data['params']['siteTitle'] = $template;
				// Installer defaults are strings; use the native form's field types
				// so strict read-back compares the values Joomla will actually save.
				$styleModel = $app->bootComponent('com_templates')->getMVCFactory()->createModel('Style', 'Administrator', ['ignore_request' => true]);
				$styleModel->setCurrentUser($admin);
				$formData = $data + ['client_id' => $nativeClient];
				$form = $styleModel->getForm($formData, false);
				$check($form !== false, 'Load the native child style form');
				$filtered = $styleModel->validate($form, $formData);
				$check(is_array($filtered) && is_array($filtered['params'] ?? null), 'Validate child parameters through the native form');
				$data['params'] = $filtered['params'];
			}
			$plan = $client->tool('joomla_action_write_plan', ['action' => $action, 'transport' => $track,
				'idempotencyKey' => Json::uuid(), 'input' => ['data' => $data]]);
			$check(!$db->setQuery('SELECT id FROM ' . $db->quoteName('#__template_styles') . ' WHERE title = ' . $db->quote($title))->loadResult(), $track . ' template plan performs no style write');
			$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
			$row = $db->setQuery('SELECT id, template, client_id, parent, inheritable, home FROM ' . $db->quoteName('#__template_styles') . ' WHERE title = ' . $db->quote($title))->loadAssoc();
			$check(is_array($row) && (int) $row['id'] > 0, $track . ' creates the requested installed style');
			$styles[] = (int) $row['id'];
			$check($row['template'] === $name && (int) $row['client_id'] === $nativeClient
				&& $row['parent'] === $parent && (int) $row['inheritable'] === $inheritable && (string) $row['home'] === '0',
				$track . ' preserves persisted template/client/parent/inheritable for ' . $name);
			$check(in_array($result['verification']['status'] ?? '', ['verified', 'partial'], true), $track . ' retains normal template style read-back');
			$check($client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']])['idempotentReplay'] === true,
				$track . ' template style replay does not create a duplicate');
			if ($name !== $template)
			{
				continue;
			}
			$handle = curl_init($base . '/index.php?templateStyle=' . (int) $row['id']);
			curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30]);
			try
			{
				$html = curl_exec($handle);
				$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			}
			finally
			{
				curl_close($handle);
			}
			$check($status === 200 && is_string($html) && str_contains($html, '<body') && str_contains($html, $template),
				$track . ' child style renders its actual frontend through the parent index');
			$check(str_contains($html, '/media/templates/site/cassiopeia/') && !str_contains($html, 'There is no') && !str_contains($html, 'UnknownAssetException'),
				$track . ' child frontend loads inherited Cassiopeia assets without missing preset errors');
		}
	}
}
finally
{
	$cli?->disconnect();
	$model = $app->bootComponent('com_templates')->getMVCFactory()->createModel('Style', 'Administrator', ['ignore_request' => true]);
	$model->setCurrentUser($admin);
	$styles = array_map('intval', $db->setQuery('SELECT id FROM ' . $db->quoteName('#__template_styles') . ' WHERE title LIKE ' . $db->quote($prefix . '%'))->loadColumn());
	if ($styles !== [])
	{
		$check($model->delete($styles), 'Remove every created template style through its native model');
	}
	$extensionId ??= (int) $db->setQuery('SELECT extension_id FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('template') . ' AND element = ' . $db->quote($template))->loadResult();
	if ($extensionId > 0)
	{
		$uninstaller = new Installer();
		$uninstaller->setDatabase($db);
		$check($uninstaller->uninstall('template', $extensionId), 'Uninstall the disposable child template through Joomla Installer');
	}
	if ($grantId !== null)
	{
		$http->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	}
	$http->disconnect();
	if (is_dir($source))
	{
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $file)
		{
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($source);
	}
}
echo Json::encode(['checks' => $checks, 'liveJoomlaTemplateInheritance' => 'native API and console style persistence with real child-template frontend rendering']) . PHP_EOL;
