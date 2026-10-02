<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
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
$app->bootComponent('com_joomengine_mcp');
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$store = new JoomlaStore($db);
$http = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
$prefix = 'mcp-verify-' . bin2hex(random_bytes(6));
$checks = 0;
$grantId = null;
$executions = [];
$callSequence = 1000;
$owned = ['categories' => [], 'content' => [], 'users' => [], 'modules' => []];
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
// Never print raw tool failures: a request can contain a password or capability.
$call = static function (string $name, array $arguments) use ($http, &$executions, &$callSequence, $check): array
{
	$id = $callSequence++;
	$body = Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call',
		'params' => ['name' => $name, 'arguments' => (object) $arguments]]);
	if ($name === 'joomla_action_write_plan' && is_float($arguments['input']['data']['groups'][0] ?? null))
	{
		$wire = Json::decode($body);
		$check(is_float($wire['params']['arguments']['input']['data']['groups'][0]),
			'Installed user plan retains an integral JSON number on the wire');
	}
	$exchange = $http->exchange($body);
	$response = $exchange['json'];
	if ($exchange['status'] !== 200 || !is_array($response) || ($response['id'] ?? null) !== $id)
	{
		throw new RuntimeException('Invalid disposable verification protocol response.');
	}
	$result = $response['result'] ?? [];
	$data = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
	if (is_string($data['executionId'] ?? null))
	{
		$executions[$data['executionId']] = true;
	}
	if (isset($response['error']) || ($result['isError'] ?? false))
	{
		$code = $data['error']['code'] ?? 'TOOL_FAILED';
		throw new RuntimeException('Disposable verification tool failed: ' . $name . ' (' . (is_string($code) ? $code : 'TOOL_FAILED') . ').');
	}
	return $data;
};
$rows = static function (string $table, string $field, string $value) use ($db): array
{
	return $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__' . $table))
		->where($db->quoteName($field) . ' = ' . $db->quote($value)))->loadAssocList();
};
$apply = static function (string $action, array $input, callable $inspect, string $label) use ($call, $store, $check): array
{
	$plan = $call('joomla_action_write_plan', ['action' => $action, 'transport' => 'api', 'idempotencyKey' => Json::uuid(), 'input' => $input]);
	$result = $call('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($result['verification']['status'] ?? '') === (empty($result['verification']['unobservableFields']) ? 'verified' : 'partial')
		&& ($result['verification']['differentFields'] ?? null) === [], $label . ' settles with truthful read-back and no differences');
	$inspect($result);
	$id = $result['executionId'];
	$execution = $store->one('execution', ['uuid' => $id]);
	$check(($execution['status'] ?? '') === 'completed' && $store->one('lease', ['owner_uuid' => $id]) === null,
		$label . ' completes its execution and releases the write lease');
	$audit = $store->find('audit', ['execution_uuid' => $id]);
	$replay = $call('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($replay['idempotentReplay'] ?? false) && ($replay['executionId'] ?? null) === $id
		&& ($replay['verification'] ?? null) === $result['verification']
		&& ($replay['mutation'] ?? null) === $result['mutation'], $label . ' replays the original result');
	$check($store->one('execution', ['uuid' => $id]) === $execution
		&& $store->find('audit', ['execution_uuid' => $id]) === $audit
		&& $store->one('lease', ['owner_uuid' => $id]) === null, $label . ' replay performs no new mutation or lease claim');
	$inspect($replay);
	return ['plan' => $plan, 'result' => $result];
};

try
{
	$http->initialize();
	$permission = $call('joomla_permission_request', ['toolsets' => ['content.write', 'structure.write', 'users.admin'], 'duration' => '30-minutes',
		'reason' => 'Disposable category, blocked-user and module verification contract tests']);
	$grant = $call('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'];
	$rootId = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__categories'))
		->where($db->quoteName('parent_id') . ' = 0')->where($db->quoteName('level') . ' = 0'))->loadResult();
	$check($rootId > 0, 'Resolve the installed category root');
	foreach (['content' => 'com_content', 'banners' => 'com_banners', 'contacts' => 'com_contact', 'newsfeeds' => 'com_newsfeeds'] as $component => $extension)
	{
		foreach ($component === 'content' ? ['params', 'omitted', 'metadata'] : ['params'] as $mode)
		{
			$alias = $prefix . '-' . $component . '-' . $mode;
			$data = ['title' => $alias, 'alias' => $alias, 'parent_id' => $rootId, 'description' => '', 'published' => 0, 'access' => 1, 'language' => '*'];
			if ($mode !== 'omitted')
			{
				$data[$mode] = new stdClass();
			}
			$inspect = static function (array $result) use ($rows, $alias, $extension, &$owned, $mode, $check): void
			{
				$found = $rows('categories', 'alias', $alias);
				$check(count($found) === 1 && $found[0]['extension'] === $extension, $extension . ' ' . $mode . ' preserves exactly one owned category');
				$owned['categories'][(int) $found[0]['id']] = $alias;
				$check((int) ($result['verification']['id'] ?? 0) === (int) $found[0]['id'], 'Category read-back identifies the persisted row');
				if ($mode === 'params')
				{
					$check(in_array($mode, $result['verification']['matchedFields'] ?? [], true)
						&& in_array($found[0][$mode], [null, '', '{}', '[]'], true), 'Empty category ' . $mode . ' matches its native empty representation');
				}
			};
			$created = $apply($component . '.categories.create', ['data' => $data], $inspect, $extension . ' ' . $mode . ' create');
			if ($mode === 'params')
			{
				$apply($component . '.categories.update', ['id' => (int) $created['result']['verification']['id'], 'data' => [$mode => new stdClass()]],
					$inspect, $extension . ' empty ' . $mode . ' update');
			}
		}
	}

	$categoryId = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))->where($db->quoteName('published') . ' = 1')
		->order($db->quoteName('id') . ' ASC'), 0, 1)->loadResult();
	$check($categoryId > 1, 'Resolve an installed article category without changing it');
	$alias = $prefix . '-article-empty';
	$bags = ['images' => new stdClass(), 'urls' => new stdClass(), 'metadata' => new stdClass(), 'attribs' => new stdClass()];
	$inspectArticle = static function (array $result) use ($rows, $alias, $categoryId, &$owned, $call, $check): void
	{
		$found = $rows('content', 'alias', $alias);
		$check(count($found) === 1 && (int) $found[0]['catid'] === $categoryId, 'Article empty bags preserve one owned native article');
		$id = (int) $found[0]['id'];
		$owned['content'][$id] = $alias;
		$check((int) ($result['verification']['id'] ?? 0) === $id && ($result['verification']['status'] ?? '') === 'partial',
			'Article read-back remains partial for its unobservable attributes');
		$api = $call('joomla_action_read', ['action' => 'content.articles.get', 'transport' => 'api', 'input' => ['id' => $id]])['response']['data']['data']['attributes'];
		foreach (['images', 'urls', 'metadata'] as $field)
		{
			$check(in_array($found[0][$field], [null, '', '{}', '[]'], true) && array_key_exists($field, $api) && $api[$field] === []
				&& in_array($field, $result['verification']['matchedFields'] ?? [], true), 'Empty article ' . $field . ' agrees with storage and native API read-back');
		}
		$check(in_array($found[0]['attribs'], [null, '', '{}', '[]'], true) && !array_key_exists('attribs', $api)
			&& in_array('attribs', $result['verification']['unobservableFields'] ?? [], true)
			&& !in_array('attribs', $result['verification']['matchedFields'] ?? [], true), 'Article attribs stays unobservable despite independent database inspection');
	};
	$created = $apply('content.articles.create', ['data' => ['title' => $alias, 'alias' => $alias, 'catid' => $categoryId,
		'introtext' => '<p>Disposable empty-configuration fixture.</p>', 'fulltext' => '', 'metadesc' => '', 'metakey' => '',
		'state' => 0, 'access' => 1, 'language' => '*'] + $bags],
		$inspectArticle, 'Article empty bags create');
	$apply('content.articles.update', ['id' => (int) $created['result']['verification']['id'], 'data' => $bags], $inspectArticle, 'Article empty bags update');

	$groups = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__usergroups'))
		->where($db->quoteName('title') . ' IN (' . $db->quote('Registered') . ', ' . $db->quote('Author') . ')'))->loadAssocList();
	$groupIds = array_column($groups, 'id', 'title');
	$check(isset($groupIds['Registered'], $groupIds['Author']), 'Resolve installed non-administrator group fixtures');
	foreach (['single' => [(int) $groupIds['Registered']], 'multiple' => [(int) $groupIds['Author'], (int) $groupIds['Registered']], 'integral-number' => [(float) $groupIds['Registered']]] as $mode => $membership)
	{
		$username = $prefix . '-' . $mode;
		$password = bin2hex(random_bytes(20)) . '!aA7';
		$data = ['name' => $username, 'username' => $username, 'email' => $username . '@example.invalid', 'password' => $password,
			'password2' => $password, 'groups' => $membership, 'block' => 1, 'sendEmail' => 0, 'requireReset' => 0];
		$apply('users.users.create', ['data' => $data], static function (array $result) use ($db, $rows, $username, $membership, &$owned, $check): void
		{
			$found = $rows('users', 'username', $username);
			$check(count($found) === 1 && (int) $found[0]['block'] === 1, 'User creation and replay preserve one blocked fixture');
			$id = (int) $found[0]['id'];
			$owned['users'][$id] = $username;
			$actual = array_map('intval', $db->setQuery($db->createQuery()->select($db->quoteName('group_id'))->from($db->quoteName('#__user_usergroup_map'))
				->where($db->quoteName('user_id') . ' = ' . $id))->loadColumn());
			$expected = array_map('intval', $membership);
			sort($actual, SORT_NUMERIC);
			sort($expected, SORT_NUMERIC);
			$check($actual === $expected && in_array('groups', $result['verification']['matchedFields'] ?? [], true), 'User membership agrees with independent database rows');
			$check(in_array('password', $result['verification']['unobservableFields'] ?? [], true)
				&& in_array('password2', $result['verification']['unobservableFields'] ?? [], true), 'Write-only credentials remain explicitly unobservable');
		}, 'User ' . $mode);
		unset($data, $password);
	}

	foreach (['site' => 0, 'administrator' => 1] as $client => $clientId)
	{
		foreach (['zero-empty' => 0, 'omitted-populated' => null, 'explicit-populated' => 37, 'omitted-empty' => null, 'zero-populated' => 0] as $mode => $ordering)
		{
			$title = $prefix . '-' . $client . '-' . $mode;
			$position = $prefix . '-' . $clientId . (in_array($mode, ['omitted-empty', 'zero-populated'], true) ? '-b' : '-a');
			$data = ['title' => $title, 'module' => 'mod_custom', 'content' => 'Disposable verification fixture', 'position' => $position,
				'published' => 0, 'access' => 1, 'showtitle' => 0, 'language' => '*', 'assigned' => [],
				'params' => ['layout' => '_:default', 'prepare_content' => 0, 'cache' => 0, 'cache_time' => 900]];
			if ($ordering !== null)
			{
				$data['ordering'] = $ordering;
			}
			$operation = $apply('modules.' . $client . '.create', ['data' => $data], static function (array $result) use ($rows, $title, $clientId, $position, $ordering, &$owned, $check): void
			{
				$found = $rows('modules', 'title', $title);
				$check(count($found) === 1 && (int) $found[0]['client_id'] === $clientId && $found[0]['position'] === $position,
					'Module create and replay preserve exact identity, client and position');
				$owned['modules'][(int) $found[0]['id']] = $title;
				$actual = (int) $found[0]['ordering'];
				$check($ordering === 37 ? $actual === 37 : $actual > 0, 'Module explicit or automatically assigned order is persisted');
				$check((int) ($result['verification']['id'] ?? 0) === (int) $found[0]['id'], 'Module read-back identifies the independently inspected row');
				if ($ordering !== 37)
				{
					$check(($result['verification']['nativeOrdering']['status'] ?? '') === 'verified'
						&& ($result['verification']['nativeOrdering']['value'] ?? null) === $actual
						&& !in_array('ordering', $result['verification']['matchedFields'] ?? [], true), 'Automatic module order reports the actual value without claiming zero persisted');
				}
			}, 'Module ' . $client . ' ' . $mode);
			$check($ordering === 37 ? !isset($operation['plan']['operation']['nativeOrdering'])
				: ($operation['plan']['operation']['nativeOrdering']['mode'] ?? '') === 'automatic', 'Module plan discloses automatic assignment before mutation');
		}
	}
}
finally
{
	// Locate only this run's exact markers, including a save that threw before
	// its result was returned. Never infer fixture ownership from a numeric ID.
	foreach (['content' => 'alias', 'categories' => 'alias', 'users' => 'username', 'modules' => 'title'] as $table => $field)
	{
		$found = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__' . $table))
			->where($db->quoteName($field) . ' LIKE ' . $db->quote($prefix . '-%')))->loadAssocList();
		foreach ($found as $row)
		{
			$id = (int) $row['id'];
			if ($id <= 1 || !str_starts_with($row[$field], $prefix . '-'))
			{
				throw new RuntimeException('Fixture cleanup ownership check failed.');
			}
			$ids = [$id];
			if ($table === 'users')
			{
				$user = $container->get(UserFactoryInterface::class)->loadUserById($id);
				$check((int) $user->block === 1 && $user->delete(), 'Remove the owned blocked user through Joomla');
				continue;
			}
			if ($table === 'categories')
			{
				$check((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__content') . ' WHERE catid = ' . $id)->loadResult() === 0
					&& (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__categories') . ' WHERE parent_id = ' . $id)->loadResult() === 0,
					'Category cleanup has no content or child dependencies');
				$extension = $row['extension'];
				$check(in_array($extension, ['com_content', 'com_banners', 'com_contact', 'com_newsfeeds'], true), 'Owned category has an expected extension');
				$app->getInput()->set('extension', $extension);
				$model = $app->bootComponent('com_categories')->getMVCFactory()->createModel('Category', 'Administrator', ['ignore_request' => true]);
				$model->setState('category.extension', $extension);
				$model->setState('category.component', $extension);
			}
			elseif ($table === 'content')
			{
				$model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);
			}
			else
			{
				$model = $app->bootComponent('com_modules')->getMVCFactory()->createModel('Module', 'Administrator', ['ignore_request' => true]);
			}
			$model->setCurrentUser($admin);
			$check($model->publish($ids, -2) && $model->delete($ids), 'Remove owned ' . $table . ' through the native lifecycle');
		}
		$check((int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__' . $table))
			->where($db->quoteName($field) . ' LIKE ' . $db->quote($prefix . '-%')))->loadResult() === 0, 'No owned ' . $table . ' remain');
	}
	foreach (array_keys($executions) as $uuid)
	{
		$execution = $store->one('execution', ['uuid' => $uuid]);
		if (($execution['status'] ?? '') === 'uncertain')
		{
			(new Operations($store, new Envelope($app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'], 'partial',
				'Disposable verification fixture was independently inspected and removed through its native model after the failed test.', true);
		}
	}
	if ($grantId !== null)
	{
		$call('joomla_permission_revoke', ['grantId' => $grantId]);
	}
	$http->disconnect();
}
echo Json::encode(['checks' => $checks, 'liveJoomla' => JVERSION, 'database' => $db->getServerType(),
	'verification' => 'four category contexts and article empty bags, group membership and module ordering; native storage, lease release and immutable replay']) . PHP_EOL;
