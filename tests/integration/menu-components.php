<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$client = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
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
$title = 'MCP menu component fixture ' . bin2hex(random_bytes(6));
$id = null;
$grantId = null;
$stored = static function (int $itemId) use ($db): ?array
{
	$row = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'link', 'type', 'component_id', 'client_id']))
		->from($db->quoteName('#__menu'))->where($db->quoteName('id') . ' = ' . $itemId))->loadAssoc();

	return is_array($row) ? $row : null;
};

try
{
	$client->initialize();
	$menutype = $db->setQuery($db->createQuery()->select($db->quoteName('menutype'))->from($db->quoteName('#__menu_types'))
		->where($db->quoteName('client_id') . ' = 0')->order($db->quoteName('id') . ' ASC'), 0, 1)->loadResult();
	$check(is_string($menutype) && $menutype !== '', 'Native site menu fixture is available');
	$expected = [];

	foreach (['com_content', 'com_contact'] as $component)
	{
		$expected[$component] = (int) $db->setQuery($db->createQuery()->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote($component))->where($db->quoteName('type') . ' = ' . $db->quote('component')))->loadResult();
		$check($expected[$component] > 0, 'Native installed component identity for ' . $component);
	}

	$permission = $client->tool('joomla_permission_request', ['toolsets' => ['structure.write'], 'duration' => '30-minutes', 'reason' => 'Disposable stored menu component identity test']);
	$grant = $client->tool('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'] ?? null;
	$check($grantId !== null, 'Explicit menu mutation grant persisted');
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'menus.site-items.create', 'idempotencyKey' => Json::uuid(), 'input' => ['data' => [
		'title' => $title, 'alias' => 'mcp-menu-' . bin2hex(random_bytes(6)), 'menutype' => $menutype, 'type' => 'component',
		'link' => 'index.php?option=com_content&view=featured', 'published' => 0, 'parent_id' => 1,
		'browserNav' => 0, 'access' => 1, 'template_style_id' => 0, 'home' => 0, 'language' => '*', 'params' => [],
	]]]);
	$check(isset($plan['operation']['menuComponent']['followUp']), 'Approval discloses the component identity correction');
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$id = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__menu'))
		->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult();
	$row = $stored($id);
	$check($id > 0 && $row !== null && (int) $row['component_id'] === $expected['com_content'], 'API menu creation persists the native component ID in the actual table');
	$check(($result['verification']['menuComponent']['status'] ?? '') === 'verified', 'MCP confirms the stored collection value independently of derived item GET');
	$repeat = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check($repeat['idempotentReplay'] && (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__menu'))
		->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult() === 1, 'Replaying menu approval does not create a duplicate');
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'menus.site-items.update', 'idempotencyKey' => Json::uuid(),
		'input' => ['id' => $id, 'data' => ['link' => 'index.php?option=com_contact&view=categories']]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$row = $stored($id);
	$check($row !== null && (int) $row['component_id'] === $expected['com_contact'] && $row['link'] === 'index.php?option=com_contact&view=categories',
		'A changed component link persists the new target ID rather than the old item-read ID');
	$check(($result['verification']['menuComponent']['status'] ?? '') === 'verified', 'Cross-component update is verified from stored menu data');
	$client->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	$grantId = null;
}
finally
{
	$id ??= (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__menu'))
		->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult();

	if ($id > 0 && $stored($id) !== null)
	{
		$model = $app->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$ids = [$id];
		$check($model->publish($ids, -2) && $model->delete($ids), 'Native menu model removes the disposable item: ' . implode('; ', $model->getErrors()));
		$check($stored($id) === null, 'No disposable menu fixture remains');
	}

	if ($grantId !== null)
	{
		$client->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	}

	$client->disconnect();
}

echo Json::encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'storedMenuComponentIds' => 'verified']) . PHP_EOL;
