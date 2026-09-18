<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Access\Access;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\Table\Usergroup;
use Joomla\CMS\Table\ViewLevel;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$factory = $app->bootComponent('com_joomengine_mcp')->getMVCFactory();
$store = new JoomlaStore($db);
$base = (string) getenv('MCP_TEST_BASE_URL');
$suffix = bin2hex(random_bytes(6));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void
{
	if (!$ok)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . "\n";
};
/** Observe protocol errors without accepting a false success result. */
$denied = static fn (array $result): bool => isset($result['error']) || ($result['result']['isError'] ?? false) === true;
$groups = [];
$users = [];
$tokens = [];
$clients = [];
$originalRules = [];
$levelId = null;
$toolId = null;
$plugin = new Extension($db);
$check($plugin->load(['type' => 'plugin', 'folder' => 'user', 'element' => 'token']), 'Native token configuration exists');
$originalTokenParams = $plugin->params;
/** Apply native rules while preserving all pre-existing fixture policy. */
$rules = static function (string $name, array $permissions) use ($db, &$originalRules): void
{
	$asset = new Asset($db);
	if (!$asset->loadByName($name))
	{
		throw new RuntimeException('Missing native asset ' . $name);
	}
	$originalRules[$name] ??= $asset->rules;
	$current = json_decode($asset->rules, true, 64, JSON_THROW_ON_ERROR);
	foreach ($permissions as $action => $values)
	{
		$current[$action] = array_replace($current[$action] ?? [], $values);
	}
	$asset->rules = json_encode($current, JSON_THROW_ON_ERROR);
	if (!$asset->store())
	{
		throw new RuntimeException('Could not store disposable native ACL.');
	}
	Access::clearStatics();
};
try
{
	foreach (['a', 'b'] as $label)
	{
		$group = new Usergroup($db);
		$group->parent_id = 1;
		$group->title = 'MCP fixture ' . $label . ' ' . $suffix;
		$check($group->check() && $group->store(), 'Native fixture group ' . $label);
		$groups[$label] = $group;
		$user = new User();
		$password = bin2hex(random_bytes(24));
		$userData = ['name' => 'MCP reader ' . $label, 'username' => 'mcp_' . $label . '_' . $suffix,
			'email' => 'mcp-' . $label . '-' . $suffix . '@example.test', 'password' => $password, 'password2' => $password,
			'groups' => [(int) $group->id], 'block' => 0];
		$check($user->bind($userData) && $user->save(), 'Native non-administrator account ' . $label);
		$users[$label] = $user;
		$seed = random_bytes(32);
		foreach (['joomlatoken.token' => base64_encode($seed), 'joomlatoken.enabled' => '1'] as $key => $value)
		{
			$db->setQuery($db->createQuery()->delete($db->quoteName('#__user_profiles'))->where('user_id = ' . (int) $user->id)->where('profile_key = ' . $db->quote($key)))->execute();
			$profile = (object) ['user_id' => (int) $user->id, 'profile_key' => $key, 'profile_value' => $value, 'ordering' => 1];
			$db->insertObject('#__user_profiles', $profile);
		}
		$tokens[$label] = base64_encode('sha256:' . (int) $user->id . ':' . hash_hmac('sha256', $seed, $app->get('secret')));
	}
	$ids = array_map(static fn (Usergroup $group): int => (int) $group->id, $groups);
	$allow = array_fill_keys(array_values($ids), true);
	$rules('root.1', ['core.login.api' => $allow]);
	$rules('com_joomengine_mcp', ['mcp.access' => $allow, 'mcp.execute' => $allow, 'mcp.write' => $allow, 'mcp.approve' => $allow]);
	$rules('com_content', ['core.manage' => $allow, 'core.create' => array_fill_keys(array_values($ids), false)]);
	$params = new Registry($originalTokenParams);
	$params->set('allowedUserGroups', array_values(array_unique(array_merge((array) $params->get('allowedUserGroups', [8]), array_values($ids)))));
	$plugin->params = (string) $params;
	$check($plugin->store(), 'Native token plugin explicitly allows both fixture groups');
	foreach ($users as $label => $user)
	{
		$clients[$label] = new HttpFixture($base, $tokens[$label]);
		$check($clients[$label]->initialize()['protocolVersion'] === '2025-11-25', 'Real Joomla token authenticates restricted account ' . $label);
		$result = $clients[$label]->tool('joomla_action_read', ['action' => 'content.articles.list']);
		$check(($result['response']['status'] ?? 0) === 200, 'Restricted account can read native content through MCP ' . $label);
	}

	$level = new ViewLevel($db);
	$level->title = 'MCP fixture view level ' . $suffix;
	$level->rules = json_encode([$ids['a']], JSON_THROW_ON_ERROR);
	$check($level->check() && $level->store(), 'Native viewing level created independently from group IDs');
	$levelId = (int) $level->id;
	$check($levelId !== $ids['a'], 'Fixture exercises distinct group and viewing-level identifiers');
	$editor = $factory->createModel('Tool', 'Administrator', ['ignore_request' => true]);
	$editor->setCurrentUser($admin);
	$template = $store->one('tool', ['name' => 'joomla_content_articles_list']);
	$template['id'] = 0;
	$template['name'] = 'fixture.restricted.' . $suffix;
	$template['title'] = 'Restricted MCP fixture';
	$template['access'] = $levelId;
	$template['version'] = 1;
	$check($editor->save($template), 'Native administrator creates view-level-restricted tool');
	$toolId = (int) $editor->getState('tool.id');
	$check(in_array($template['name'], array_column($clients['a']->rpc('tools/list')['result']['tools'], 'name'), true), 'Authorized viewing level discloses its tool');
	$check(!in_array($template['name'], array_column($clients['b']->rpc('tools/list')['result']['tools'], 'name'), true), 'Different viewing level cannot discover the tool');
	$check($denied($clients['b']->rpc('tools/call', ['name' => $template['name'], 'arguments' => (object) []])), 'Direct invocation cannot bypass viewing-level isolation');
	$check(($clients['a']->tool($template['name'])['response']['status'] ?? 0) === 200, 'Authorized account executes the row-defined tool');
	$rules('com_joomengine_mcp.tool.' . $toolId, ['mcp.execute' => [(string) $ids['a'] => false]]);
	$check(!in_array($template['name'], array_column($clients['a']->rpc('tools/list')['result']['tools'], 'name'), true), 'Explicit row asset deny removes discovery despite allowed view level');
	$check($denied($clients['a']->rpc('tools/call', ['name' => $template['name'], 'arguments' => (object) []])), 'Row asset deny is rechecked on direct calls in the same session');

	$grantRequest = $clients['a']->tool('joomla_permission_request', ['toolsets' => ['content.write'], 'duration' => '30-minutes', 'reason' => 'Disposable cross-principal test']);
	$check($denied($clients['b']->rpc('tools/call', ['name' => 'joomla_permission_approve', 'arguments' => ['requestId' => $grantRequest['requestId'], 'acknowledgement' => $grantRequest['acknowledgement']]])), 'Another authenticated user cannot approve this permission request');
	$grant = $clients['a']->tool('joomla_permission_approve', ['requestId' => $grantRequest['requestId'], 'acknowledgement' => $grantRequest['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'];
	$category = (int) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__categories') . ' WHERE extension = ' . $db->quote('com_content') . ' AND published = 1 ORDER BY id', 0, 1)->loadResult();
	$title = 'MCP denied create ' . $suffix;
	$plan = $clients['a']->tool('joomla_content_article_create_plan', ['idempotencyKey' => Json::uuid(), 'data' => ['title' => $title, 'catid' => $category, 'articletext' => '<p>Must not persist</p>', 'language' => '*']]);
	$check($denied($clients['b']->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $plan['confirmationToken']]])), 'Another authenticated user cannot apply this confirmation token');
	$applied = $clients['a']->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $plan['confirmationToken']]]);
	$check($denied($applied), 'Denied native mutation is an MCP error, never success');
	$result = $applied['result']['structuredContent'];
	$check(isset($result['error']) && ($result['verification']['status'] ?? '') === 'uncertain', 'MCP grant cannot override native Joomla create denial');
	$check(!(bool) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult(), 'Target ACL denial proved by absence of persisted Joomla content');
	$execution = $store->one('execution', ['uuid' => $result['executionId']]);
	(new Operations($store, new Envelope((string) $app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'], 'verified_no_effect', 'Native API returned 403 and the test verified that no requested article exists.', true);
	$check($store->one('execution', ['id' => (int) $execution['id']])['status'] === 'reconciled'
		&& $store->one('lease', ['owner_uuid' => $execution['uuid']]) === null, 'Administrator reconciliation records inspected effects and releases only that execution lease');
	$clients['a']->tool('joomla_permission_revoke', ['grantId' => $grantId]);

	$bearer = (new HttpFixture($base, ''))->exchange('{"jsonrpc":"2.0","id":91,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"bearer-fixture","version":"1"}}}', ['Authorization' => 'Bearer ' . $tokens['a']]);
	$check($bearer['status'] === 200, 'Native Authorization Bearer authentication is supported');
	$stolen = $clients['b']->exchange('{"jsonrpc":"2.0","id":92,"method":"ping"}', ['Mcp-Session-Id' => $bearer['headers']['mcp-session-id'], 'MCP-Protocol-Version' => '2025-11-25']);
	$check($stolen['status'] !== 200 || isset($stolen['json']['error']), 'MCP session identifiers do not transfer between authenticated principals');
	$db->setQuery('UPDATE ' . $db->quoteName('#__user_profiles') . ' SET profile_value = ' . $db->quote('0') . ' WHERE user_id = ' . (int) $users['a']->id . ' AND profile_key = ' . $db->quote('joomlatoken.enabled'))->execute();
	$check($clients['a']->exchange('{"jsonrpc":"2.0","id":93,"method":"ping"}')['status'] === 401, 'Native token revocation is enforced on the next existing-session request');
	$users['b'] = $container->get(UserFactoryInterface::class)->loadUserById((int) $users['b']->id);
	$users['b']->block = 1;
	$check($users['b']->save(), 'Block second native fixture account: ' . $users['b']->getError());
	$check($clients['b']->exchange('{"jsonrpc":"2.0","id":94,"method":"ping"}')['status'] === 401, 'Blocked native account cannot reuse its MCP session');
}
finally
{
	foreach ($originalRules as $name => $value)
	{
		$asset = new Asset($db);
		if ($asset->loadByName($name))
		{
			$asset->rules = $value;
			$check($asset->store(), 'Restore native ACL ' . $name);
		}
	}
	if ($toolId !== null)
	{
		$editor = $factory->createModel('Tool', 'Administrator', ['ignore_request' => true]);
		$editor->setCurrentUser($admin);
		$pks = [$toolId];
		$check($editor->publish($pks, -2) && $editor->delete($pks), 'Remove restricted tool fixture');
	}
	if ($levelId !== null)
	{
		$level = new ViewLevel($db);
		$check($level->delete($levelId), 'Remove viewing-level fixture');
	}
	$plugin->params = $originalTokenParams;
	$check($plugin->store(), 'Restore native token plugin settings');
	foreach ($users as $user)
	{
		$check($user->delete(), 'Remove native user fixture');
	}
	foreach ($groups as $group)
	{
		$check($group->delete((int) $group->id), 'Remove native group fixture');
	}
	Access::clearStatics();
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'realHttpAcl' => true], JSON_THROW_ON_ERROR) . "\n";
