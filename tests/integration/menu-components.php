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
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$app->bootComponent('com_joomengine_mcp');
$store = new JoomlaStore($db);
$base = (string) getenv('MCP_TEST_BASE_URL');
$token = trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE')));
$client = new HttpFixture($base, $token);
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
$trashKey = null;
$trashVerification = null;
$stored = static function (int $itemId) use ($db): ?array
{
	$row = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'link', 'type', 'component_id', 'client_id', 'menutype', 'published']))
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
		'browserNav' => 0, 'access' => 1, 'template_style_id' => 0, 'home' => 0, 'language' => '*',
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
	$contactCategory = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_contact'))->where($db->quoteName('published') . ' = 1')
		->order($db->quoteName('id') . ' ASC'), 0, 1)->loadResult();
	$check($contactCategory > 0, 'Native contact category required by the component menu form is available');
	$contactLink = 'index.php?option=com_contact&view=categories&id=' . $contactCategory;
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'menus.site-items.update', 'idempotencyKey' => Json::uuid(),
		'input' => ['id' => $id, 'data' => ['link' => $contactLink]]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$row = $stored($id);
	$check($row !== null && (int) $row['component_id'] === $expected['com_contact'] && $row['link'] === $contactLink,
		'A changed component link persists the new target ID rather than the old item-read ID');
	$check(($result['verification']['menuComponent']['status'] ?? '') === 'verified', 'Cross-component update is verified from stored menu data');

	// Probe the installed native collection, not a version number or a mock. A
	// future core that exposes the trashed row must support successful verification.
	$trashKey = Json::uuid();
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'menus.site-items.update', 'idempotencyKey' => $trashKey,
		'input' => ['id' => $id, 'data' => ['published' => -2]]]);
	$wire = $client->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $plan['confirmationToken']]]);
	$result = $wire['result']['structuredContent'] ?? [];
	$row = $stored($id);
	$check($row !== null && (int) $row['published'] === -2 && (int) $row['component_id'] === $expected['com_contact']
		&& (int) $row['client_id'] === 0 && $row['menutype'] === $menutype && $row['type'] === 'component' && $row['link'] === $contactLink,
		'Trash persists the exact approved menu state, client, menu type, link and component identity');
	$visible = false;
	$exhausted = false;

	for ($page = 0; $page < 100 && !$visible; $page++)
	{
		$query = http_build_query(['filter' => ['menutype' => $menutype, 'published' => -2], 'page' => ['offset' => $page * 100, 'limit' => 100]]);
		$handle = curl_init($base . '/api/index.php/v1/menus/site/items?' . $query);
		curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/vnd.api+json', 'X-Joomla-Token: ' . $token]]);

		try
		{
			$body = curl_exec($handle);
			$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		}
		finally
		{
			curl_close($handle);
		}

		$check($status === 200 && is_string($body), 'Independent native menu collection probe succeeds');
		$document = Json::decode($body);
		$items = $document['data'] ?? null;
		$check(is_array($items) && array_is_list($items) && count($items) <= 100, 'Native menu collection probe has bounded records');

		foreach ($items as $item)
		{
			if ((string) ($item['id'] ?? '') === (string) $id)
			{
				$visible = true;
				break;
			}
		}

		if (count($items) < 100 || (isset($document['meta']['total-pages']) && $page + 1 >= $document['meta']['total-pages']))
		{
			$exhausted = true;
			break;
		}
	}

	$check($visible || $exhausted, 'Native trash visibility probe completes without treating a page bound as absence');
	$execution = $store->one('execution', ['idempotency_key' => $trashKey]);
	$check($execution !== null && ($result['executionId'] ?? null) === $execution['uuid'], 'Trash result retains its owned durable execution identity');
	$trashVerification = $visible ? 'verified' : 'uncertain';
	$check(($result['verification']['status'] ?? '') === $trashVerification,
		'Trash result truthfully reflects whether the installed native collection exposes the exact item');
	$check($visible
		? $execution['status'] === 'completed' && $store->one('lease', ['owner_uuid' => $execution['uuid']]) === null
		: $execution['status'] === 'uncertain' && $store->one('lease', ['owner_uuid' => $execution['uuid']]) !== null
			&& ($result['error']['code'] ?? '') === 'MENU_VERIFICATION_UNAVAILABLE' && ($wire['result']['isError'] ?? false),
		'Hidden native trash retains its error and lease; visible verified trash settles and releases its lease');
	$audit = $store->find('audit', ['execution_uuid' => $execution['uuid']]);
	$repeat = $client->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $plan['confirmationToken']]]);
	$repeat = $repeat['result']['structuredContent'] ?? [];
	$check(($repeat['idempotentReplay'] ?? false) && ($repeat['executionId'] ?? null) === $execution['uuid']
		&& ($repeat['mutation'] ?? null) === ($result['mutation'] ?? null) && ($repeat['verification'] ?? null) === $result['verification']
		&& $store->one('execution', ['uuid' => $execution['uuid']]) === $execution
		&& $store->find('audit', ['execution_uuid' => $execution['uuid']]) === $audit && $stored($id) === $row,
		'Trash replay preserves the original result and leaves the stored row, execution and mutation audit unchanged');
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
		$trashIds = $ids;
		$check($model->publish($trashIds, -2) && $model->delete($ids), 'Native menu model removes the disposable item: ' . implode('; ', $model->getErrors()));
		$check($stored($id) === null, 'No disposable menu fixture remains');
	}

	$execution = $trashKey === null ? null : $store->one('execution', ['idempotency_key' => $trashKey]);

	if ($execution !== null && $execution['status'] === 'uncertain' && $id > 0 && $stored($id) === null)
	{
		(new Operations($store, new Envelope((string) $app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'],
			'partial', 'The owned menu trash fixture was independently inspected, removed through its native model, and confirmed absent. No mutation was replayed.', true);
		$check($store->one('execution', ['uuid' => $execution['uuid']])['status'] === 'reconciled'
			&& $store->one('lease', ['owner_uuid' => $execution['uuid']]) === null,
			'Only the inspected and removed fixture execution is reconciled and its lease released during cleanup');
	}

	if ($grantId !== null)
	{
		$client->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	}

	$client->disconnect();
}

echo Json::encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'storedMenuComponentIds' => 'verified',
	'trashVerification' => $trashVerification, 'trashContractResolved' => $trashVerification === 'verified']) . PHP_EOL;
