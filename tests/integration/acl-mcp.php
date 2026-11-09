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
/** Report only bounded field names/statuses, never submitted content or credentials. */
$verificationDetails = static function (array $result): string
{
	$verification = $result['verification'] ?? [];

	return json_encode(array_intersect_key($verification, array_flip(['status', 'differentFields', 'unobservableFields'])), JSON_THROW_ON_ERROR);
};
$groups = [];
$users = [];
$tokens = [];
$clients = [];
$originalRules = [];
$levelId = null;
$toolId = null;
$created = [];
$bannerCategory = null;
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
	// Plain text isolates ACL from the Public-child group's native HTML filtering.
	$input = ['idempotencyKey' => Json::uuid(), 'data' => ['title' => $title, 'catid' => $category, 'articletext' => 'Least privilege', 'language' => '*']];
	$executionCount = count($store->find('execution'));
	$planCount = count($store->find('plan'));
	$rejected = $clients['a']->rpc('tools/call', ['name' => 'joomla_content_article_create_plan', 'arguments' => $input]);
	$diagnostic = json_decode($rejected['result']['content'][0]['text'] ?? '{}', true);
	$check($denied($rejected) && ($diagnostic['error']['code'] ?? '') === 'NATIVE_CREATE_DENIED'
		&& ($diagnostic['error']['details']['action'] ?? '') === 'core.create', 'Native create denial identifies the missing permission during planning');
	$check(count($store->find('execution')) === $executionCount && count($store->find('plan')) === $planCount, 'Denied native creation creates no execution or confirmation plan');
	$check(!(bool) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult(), 'Target ACL denial proved by absence of persisted Joomla content');

	$rules('com_content', ['core.create' => [(string) $ids['a'] => true]]);
	$restricted = $container->get(UserFactoryInterface::class)->loadUserById((int) $users['a']->id);
	$check(!$restricted->authorise('core.admin') && !$restricted->authorise('core.admin', 'com_content'), 'Create fixture has neither Super User nor content administration authority');
	$plan = $clients['a']->tool('joomla_content_article_create_plan', $input);
	$check($denied($clients['b']->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $plan['confirmationToken']]])), 'Another authenticated user cannot apply this confirmation token');
	$created[] = ['com_content', 'Article', 'content', 'title', $title];
	$result = $clients['a']->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	// Joomla 6.1.3 and 6.2 both expose category as a relationship and omit the
	// articletext editor alias. Require those exact unobservable fields; inspect
	// the actual stored category and split body below instead of pretending full API verification.
	$unobservable = $result['verification']['unobservableFields'] ?? [];
	sort($unobservable, SORT_STRING);
	$check(($result['verification']['status'] ?? '') === 'partial'
		&& $unobservable === ['articletext', 'catid']
		&& ($result['verification']['differentFields'] ?? []) === []
		&& array_diff(['title', 'language'], $result['verification']['matchedFields'] ?? []) === [],
		'Restricted API user creates an article with every observable field verified: ' . $verificationDetails($result));
	$row = $db->setQuery('SELECT id, created_by, catid, introtext, ' . $db->quoteName('fulltext') . ' FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadAssoc();
	$check((int) ($row['created_by'] ?? 0) === (int) $users['a']->id && (int) ($row['catid'] ?? 0) === $category
		&& ($row['introtext'] ?? null) === 'Least privilege' && ($row['fulltext'] ?? null) === '',
		'Native database confirms restricted article ownership, category and exact body');
	$execution = $store->one('execution', ['uuid' => $result['executionId']]);
	$check(($execution['status'] ?? '') === 'completed' && $store->one('lease', ['owner_uuid' => $result['executionId']]) === null,
		'Settled restricted creation completes its execution and releases its lease');
	$replayed = $clients['a']->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check($replayed['idempotentReplay'] === true && (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult() === 1, 'Restricted create replay does not create another article');

	$rules('com_content', ['core.manage' => [(string) $ids['a'] => false]]);
	$rejected = $clients['a']->rpc('tools/call', ['name' => 'joomla_content_article_create_plan', 'arguments' => $input + ['dryRun' => true]]);
	$diagnostic = json_decode($rejected['result']['content'][0]['text'] ?? '{}', true);
	$check($denied($rejected) && ($diagnostic['error']['details']['action'] ?? '') === 'core.manage', 'Create permission alone cannot replace Access Administration Interface');
	$rules('com_content', ['core.manage' => [(string) $ids['a'] => true]]);
	$rules('com_content.category.' . $category, ['core.create' => [(string) $ids['a'] => false]]);
	$rejected = $clients['a']->rpc('tools/call', ['name' => 'joomla_content_article_create_plan', 'arguments' => $input + ['dryRun' => true]]);
	$diagnostic = json_decode($rejected['result']['content'][0]['text'] ?? '{}', true);
	$check($denied($rejected) && ($diagnostic['error']['details']['asset'] ?? '') === 'com_content.category.' . $category, 'Component create permission cannot bypass the selected category deny');
	$rules('com_content.category.' . $category, ['core.create' => [(string) $ids['a'] => true]]);
	$revokedTitle = 'MCP revoked create ' . $suffix;
	$revoked = $clients['a']->tool('joomla_content_article_create_plan', ['idempotencyKey' => Json::uuid(), 'data' => ['title' => $revokedTitle, 'catid' => $category]]);
	$executionCount = count($store->find('execution'));
	$rules('com_content', ['core.create' => [(string) $ids['a'] => false]]);
	$rejected = $clients['a']->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $revoked['confirmationToken']]]);
	$check($denied($rejected) && count($store->find('execution')) === $executionCount, 'Apply rechecks native ACL before claiming an approved operation');
	$check(!(bool) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($revokedTitle))->loadResult(), 'Revoked create never reaches the native mutation');
	$rules('com_content', ['core.create' => [(string) $ids['a'] => true]]);

	$app->getInput()->set('extension', 'com_banners');
	$categoryModel = $app->bootComponent('com_categories')->getMVCFactory()->createModel('Category', 'Administrator', ['ignore_request' => true]);
	$categoryModel->setCurrentUser($admin);
	$categoryModel->setState('category.extension', 'com_banners');
	$categoryModel->setState('category.component', 'com_banners');
	$check($categoryModel->save(['title' => 'MCP banner ACL ' . $suffix, 'alias' => 'mcp-banner-acl-' . $suffix,
		'extension' => 'com_banners', 'parent_id' => 1, 'published' => 1, 'access' => 1, 'language' => '*']), 'Create disposable native banner category');
	$bannerCategory = (int) $categoryModel->getState('category.id');
	$rules('com_banners', ['core.manage' => [(string) $ids['a'] => true], 'core.create' => [(string) $ids['a'] => true]]);
	$bannerTitle = 'MCP restricted banner ' . $suffix;
	$created[] = ['com_banners', 'Banner', 'banners', 'name', $bannerTitle];
	$plan = $clients['a']->tool('joomla_action_write_plan', ['action' => 'banners.banners.create', 'idempotencyKey' => Json::uuid(),
		'input' => ['data' => ['name' => $bannerTitle, 'catid' => $bannerCategory, 'language' => '*', 'type' => 1, 'custombannercode' => 'Least privilege']]]);
	$result = $clients['a']->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($result['verification']['status'] ?? '') === 'verified', 'Restricted API user creates and verifies a banner without Super Users: ' . $verificationDetails($result));
	$row = $db->setQuery('SELECT id, created_by FROM ' . $db->quoteName('#__banners') . ' WHERE name = ' . $db->quote($bannerTitle))->loadAssoc();
	$check((int) ($row['created_by'] ?? 0) === (int) $users['a']->id, 'Banner is owned by the authenticated restricted user');
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
	foreach (array_reverse($created) as [$component, $modelName, $table, $field, $value])
	{
		$pks = array_map('intval', $db->setQuery('SELECT id FROM ' . $db->quoteName('#__' . $table) . ' WHERE ' . $db->quoteName($field) . ' = ' . $db->quote($value))->loadColumn());
		if ($pks !== [])
		{
			$model = $app->bootComponent($component)->getMVCFactory()->createModel($modelName, 'Administrator', ['ignore_request' => true]);
			$model->setCurrentUser($admin);
			$check($model->publish($pks, -2) && $model->delete($pks), 'Remove restricted create fixture through native ' . $modelName);
		}
	}
	if ($bannerCategory !== null)
	{
		$pks = [$bannerCategory];
		$check($categoryModel->publish($pks, -2) && $categoryModel->delete($pks), 'Remove native banner category fixture');
	}
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
