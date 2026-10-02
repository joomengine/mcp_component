<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Access\Access;
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
$base = (string) getenv('MCP_TEST_BASE_URL');
$token = trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE')));
$http = new HttpFixture($base, $token);
$prefix = 'mcp-choice-' . bin2hex(random_bytes(6));
$checks = 0;
$grantId = null;
$executions = [];
$fixtures = ['entities' => [], 'fields' => [], 'groups' => []];
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
// Never print raw failure envelopes, submitted passwords or confirmation tokens.
$call = static function (string $name, array $arguments, bool $allowError = false) use ($http, &$executions): array
{
	$wire = $http->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
	$result = $wire['result'] ?? [];
	$data = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
	$error = isset($wire['error']) || ($result['isError'] ?? false);
	if (is_string($data['executionId'] ?? null))
	{
		$executions[$data['executionId']] = true;
	}
	if ($error && !$allowError)
	{
		throw new RuntimeException('Disposable custom-field tool failed: ' . $name . '.');
	}
	return ['data' => $data, 'isError' => $error];
};
$tool = static fn (string $name, array $arguments): array => $call($name, $arguments)['data'];
$rows = static function (string $table, array $where) use ($db): array
{
	$query = $db->createQuery()->select('*')->from($db->quoteName('#__' . $table));
	foreach ($where as $field => $value)
	{
		$query->where($db->quoteName($field) . ' = ' . $db->quote((string) $value));
	}
	return $db->setQuery($query)->loadAssocList();
};
$equivalent = static function (mixed $expected, mixed $actual) use (&$equivalent): bool
{
	if (is_array($expected))
	{
		if (!is_array($actual) || count($expected) !== count($actual))
		{
			return false;
		}
		foreach ($expected as $key => $value)
		{
			if (!array_key_exists($key, $actual) || !$equivalent($value, $actual[$key]))
			{
				return false;
			}
		}
		return true;
	}
	return is_scalar($expected) && is_scalar($actual) ? (string) $expected === (string) $actual : $expected === $actual;
};
// Read the actual native endpoint independently of the MCP read-back result.
$native = static function (string $route, int $id) use ($base, $token, $check): array
{
	$handle = curl_init($base . '/api/index.php/v1/' . $route . '/' . $id);
	curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45,
		CURLOPT_HTTPHEADER => ['Accept: application/vnd.api+json', 'X-Joomla-Token: ' . $token]]);
	try
	{
		$body = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
	}
	finally
	{
		curl_close($handle);
	}
	$check($body !== false && $status === 200, 'Independent native custom-field read succeeds');
	$document = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
	$check((int) ($document['data']['id'] ?? 0) === $id && is_array($document['data']['attributes'] ?? null),
		'Independent native read identifies the exact owned record');
	return $document['data']['attributes'];
};
$read = static function (string $action, int $id) use ($tool, $check): array
{
	$result = $tool('joomla_action_read', ['action' => $action . '.get', 'transport' => 'api', 'input' => ['id' => $id]]);
	$item = $result['response']['data']['data'] ?? [];
	$check((int) ($item['id'] ?? 0) === $id && is_array($item['attributes'] ?? null), 'MCP read returns the exact owned record');
	return $item['attributes'];
};
$apply = static function (string $action, array $input, string $status, callable $inspect) use ($tool, $store, $check): array
{
	$plan = $tool('joomla_action_write_plan', ['action' => $action, 'transport' => 'api', 'idempotencyKey' => Json::uuid(), 'input' => $input]);
	$result = $tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($result['verification']['status'] ?? '') === $status && ($result['verification']['differentFields'] ?? null) === [],
		$action . ' has the expected ' . $status . ' verification without differences');
	$inspect($result);
	$id = $result['executionId'];
	$execution = $store->one('execution', ['uuid' => $id]);
	$audit = $store->find('audit', ['execution_uuid' => $id]);
	$check(($execution['status'] ?? '') === 'completed' && $store->one('lease', ['owner_uuid' => $id]) === null,
		$action . ' completes and releases its lease');
	$replay = $tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($replay['idempotentReplay'] ?? false) && ($replay['executionId'] ?? null) === $id
		&& ($replay['mutation'] ?? null) === $result['mutation'] && ($replay['verification'] ?? null) === $result['verification'],
		$action . ' replays its original mutation and verification');
	$check($store->one('execution', ['uuid' => $id]) === $execution && $store->find('audit', ['execution_uuid' => $id]) === $audit
		&& $store->one('lease', ['owner_uuid' => $id]) === null, $action . ' replay leaves execution, audit and lease unchanged');
	$inspect($replay);
	return $result;
};
$create = static function (string $bucket, array $fixture, array $data, string $status, callable $inspect)
	use (&$fixtures, $rows, $apply, $check): int
{
	$check($rows($fixture['table'], $fixture['marker']) === [], 'Unique custom-field fixture marker is absent before create');
	// Record intent before mutation so cleanup also finds a create whose read-back fails.
	$fixtures[$bucket][] = $fixture;
	$result = $apply($fixture['action'] . '.create', ['data' => $data], $status,
		static function (array $result) use ($fixture, $rows, $inspect, $check): void
		{
			$found = $rows($fixture['table'], $fixture['marker']);
			$check(count($found) === 1 && (int) $found[0]['id'] === (int) ($result['verification']['id'] ?? 0),
				$fixture['action'] . ' create and replay preserve exactly one owned row');
			$inspect($result, $found[0]);
		});
	return (int) $result['verification']['id'];
};
$reconcile = static function (string $uuid, string $outcome, string $note) use ($app, $admin, $store, $check): void
{
	$execution = $store->one('execution', ['uuid' => $uuid]);
	$check(($execution['status'] ?? '') === 'uncertain', 'Rejected native option retains an explicitly uncertain execution');
	(new Operations($store, new Envelope($app->get('secret'))))->reconcile($admin, (int) $execution['id'],
		(int) $execution['version'], $outcome, $note, true);
	$check($store->one('lease', ['owner_uuid' => $uuid]) === null, 'Inspected negative fixture reconciliation releases its retained lease');
};

try
{
	$http->initialize();
	$permission = $tool('joomla_permission_request', ['toolsets' => ['content.write', 'structure.write', 'users.admin'],
		'duration' => '30-minutes', 'reason' => 'Disposable native custom-field choice verification matrix']);
	$grant = $tool('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'];
	$root = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__categories'))
		->where($db->quoteName('parent_id') . ' = 0')->where($db->quoteName('level') . ' = 0'))->loadResult();
	$registered = $rows('usergroups', ['title' => 'Registered']);
	$check($root > 0 && count($registered) === 1, 'Resolve native root and Registered group identities');
	$registeredId = (int) $registered[0]['id'];
	$check(!Access::checkGroup($registeredId, 'core.admin') && !Access::checkGroup($registeredId, 'core.login.admin'),
		'Disposable blocked user belongs only to a non-administrator group');
	$parents = [];
	foreach (['content' => 'com_content', 'contacts' => 'com_contact'] as $component => $extension)
	{
		$alias = $prefix . '-parent-' . $component;
		$parents[$component] = $create('entities', ['table' => 'categories', 'marker' => ['alias' => $alias, 'extension' => $extension],
			'action' => $component . '.categories', 'component' => 'com_categories', 'model' => 'Category', 'extension' => $extension],
			['title' => $alias, 'alias' => $alias, 'parent_id' => $root, 'description' => '', 'published' => 0, 'access' => 1, 'language' => '*', 'params' => new stdClass()],
			'verified', static function (array $result, array $row) use ($check): void
			{
				$check((int) $row['published'] === 0 && in_array('params', $result['verification']['matchedFields'] ?? [], true),
					'Support category remains unpublished with verified empty configuration');
			});
	}
	$options = ['options0' => ['name' => 'Alpha', 'value' => 'alpha'], 'options1' => ['name' => 'Beta', 'value' => 'beta'],
		'options2' => ['name' => 'Zero', 'value' => '0'], 'options3' => ['name' => 'One', 'value' => '1']];
	$types = ['text' => ['filter' => 'raw', 'maxlength' => 120],
		'list' => ['header' => 'Select', 'multiple' => 1, 'options' => $options], 'checkboxes' => ['options' => $options],
		'integer' => ['multiple' => 0, 'first' => 1, 'last' => 9, 'step' => 2]];
	$contexts = [
		'content-articles' => ['com_content.article', 'content.articles', 'content', 'com_content', 'Article', 'content/articles', 'fields/content/articles', 'fields/groups/content/articles'],
		'content-categories' => ['com_content.categories', 'content.categories', 'categories', 'com_categories', 'Category', 'content/categories', 'fields/content/categories', 'fields/groups/content/categories'],
		'contact' => ['com_contact.contact', 'contacts.contacts', 'contact_details', 'com_contact', 'Contact', 'contacts', 'fields/contacts/contact', 'fields/groups/contacts/contact'],
		'users' => ['com_users.user', 'users.users', 'users', 'com_users', 'User', 'users', 'fields/users', 'fields/groups/users'],
	];
	foreach ($contexts as $slug => [$context, $action, $table, $component, $model, $route, $fieldRoute, $groupRoute])
	{
		$title = $prefix . '-' . $slug . '-group';
		$groupData = ['title' => $title, 'description' => 'Disposable custom-field verification group', 'state' => 1, 'language' => '*', 'access' => 1, 'note' => '', 'ordering' => 0];
		$groupId = $create('groups', ['table' => 'fields_groups', 'marker' => ['title' => $title, 'context' => $context],
			'action' => 'field-groups.' . $slug, 'component' => 'com_fields', 'model' => 'Group', 'context' => $context], $groupData, 'verified',
			static function (array $result, array $row) use ($slug, $groupData, $context, $groupRoute, $read, $native, $equivalent, $check): void
			{
				$api = $read('field-groups.' . $slug, (int) $row['id']);
				$direct = $native($groupRoute, (int) $row['id']);
				foreach ($groupData + ['context' => $context] as $key => $value)
				{
					$check($equivalent($value, $row[$key] ?? null) && $equivalent($value, $api[$key] ?? null) && $equivalent($value, $direct[$key] ?? null),
						$slug . ' group ' . $key . ' agrees with native storage and both reads');
				}
			});
		$fields = [];
		foreach ($types as $type => $fieldparams)
		{
			$name = $prefix . '-' . $slug . '-' . $type;
			// Native API validation requires these editable non-null values and
			// nonempty parameter groups; this suite does not accommodate failed saves.
			$data = ['title' => $name, 'name' => $name, 'label' => $name, 'type' => $type, 'group_id' => $groupId,
				'state' => 1, 'required' => 0, 'language' => '*', 'access' => 1, 'default_value' => '',
				'description' => '', 'note' => '', 'ordering' => 0, 'only_use_in_subform' => 0,
				'params' => ['show_on' => ''], 'fieldparams' => $fieldparams];
			$id = $create('fields', ['table' => 'fields', 'marker' => ['name' => $name, 'context' => $context],
				'action' => 'fields.' . $slug, 'component' => 'com_fields', 'model' => 'Field', 'context' => $context], $data, 'partial',
				static function (array $result, array $row) use ($slug, $data, $context, $fieldRoute, $read, $native, $equivalent, $check): void
				{
					$api = $read('fields.' . $slug, (int) $row['id']);
					$direct = $native($fieldRoute, (int) $row['id']);
					foreach (['params', 'fieldparams'] as $key)
					{
						$row[$key] = json_decode($row[$key], true, 64, JSON_THROW_ON_ERROR);
					}
					foreach ($data + ['context' => $context] as $key => $value)
					{
						$check($equivalent($value, $row[$key] ?? null), $slug . ' definition stores exact native ' . $key);
						if ($key !== 'only_use_in_subform')
						{
							$check($equivalent($value, $api[$key] ?? null) && $equivalent($value, $direct[$key] ?? null),
								$slug . ' definition exposes exact native ' . $key);
						}
					}
					$check(($result['verification']['unobservableFields'] ?? null) === ['only_use_in_subform'],
						'Field definition keeps its API-omitted property explicitly unobservable');
				});
			$fields[$type] = ['id' => $id, 'name' => $name];
		}
		$marker = $prefix . '-' . $slug . '-entity';
		$data = match ($slug)
		{
			'content-articles' => ['title' => $marker, 'alias' => $marker, 'catid' => $parents['content'], 'introtext' => '<p>Disposable choices.</p>', 'fulltext' => '', 'metadesc' => '', 'metakey' => '', 'state' => 0, 'access' => 1, 'language' => '*'],
			'content-categories' => ['title' => $marker, 'alias' => $marker, 'parent_id' => $root, 'description' => '', 'published' => 0, 'access' => 1, 'language' => '*', 'params' => new stdClass()],
			'contact' => ['name' => $marker, 'alias' => $marker, 'catid' => $parents['contacts'], 'published' => 0, 'access' => 1, 'language' => '*'],
			'users' => ['name' => $marker, 'username' => $marker, 'email' => $marker . '@example.invalid', 'groups' => [$registeredId], 'block' => 1, 'sendEmail' => 0, 'requireReset' => 0],
		};
		if ($slug === 'users')
		{
			$data['password'] = $data['password2'] = bin2hex(random_bytes(20)) . '!aA7';
		}
		$values = static function (array $typed) use ($fields): array
		{
			$named = [];
			foreach ($typed as $type => $value)
			{
				$named[$fields[$type]['name']] = $value;
			}
			return $named;
		};
		$first = $values(['text' => 'first ' . $prefix, 'list' => ['alpha'], 'checkboxes' => ['beta', 'alpha'], 'integer' => '3']);
		$second = $values(['text' => 'updated ' . $prefix, 'list' => ['beta', 'alpha'], 'checkboxes' => ['beta'], 'integer' => '7']);
		$clear = $values(['text' => '', 'list' => [], 'checkboxes' => [], 'integer' => '']);
		$observe = static function (int $id, array $expected, ?array $result = null) use ($action, $route, $fields, $rows, $read, $native, $equivalent, $check): void
		{
			$api = $read($action, $id);
			$direct = $native($route, $id);
			foreach ($fields as $type => $field)
			{
				$wanted = $expected[$field['name']];
				$stored = array_column($rows('fields_values', ['field_id' => $field['id'], 'item_id' => $id]), 'value');
				$expectedStored = $wanted === '' || $wanted === [] ? [] : array_map('strval', (array) $wanted);
				sort($stored, SORT_STRING);
				sort($expectedStored, SORT_STRING);
				$check($stored === $expectedStored, $action . ' ' . $type . ' has exact native value rows');
				$expectedApi = in_array($type, ['list', 'checkboxes'], true)
					? array_intersect_key(['alpha' => 'Alpha', 'beta' => 'Beta', '0' => 'Zero', '1' => 'One'], array_fill_keys((array) $wanted, true)) : $wanted;
				$check(array_key_exists($field['name'], $api) && array_key_exists($field['name'], $direct)
					&& $equivalent($expectedApi, $api[$field['name']]) && $equivalent($expectedApi, $direct[$field['name']]),
					$action . ' ' . $type . ' exposes the actual native scalar or option-label map');
				if ($result !== null)
				{
					$check(in_array($field['name'], $result['verification']['matchedFields'] ?? [], true)
						&& !in_array($field['name'], $result['verification']['unobservableFields'] ?? [], true),
						$action . ' ' . $type . ' is explicitly verified');
				}
			}
		};
		$fixture = ['table' => $table, 'marker' => [$slug === 'users' ? 'username' : 'alias' => $marker],
			'action' => $action, 'component' => $component, 'model' => $model];
		if ($slug === 'content-categories')
		{
			$fixture['marker']['extension'] = $fixture['extension'] = 'com_content';
		}
		$id = $create('entities', $fixture, $data + $first, $slug === 'content-categories' ? 'verified' : 'partial',
			static function (array $result, array $row) use ($observe, $first, $slug, $registeredId, $rows, $check): void
			{
				$observe((int) $row['id'], $first, $result);
				if ($slug === 'users')
				{
					$groups = array_map('intval', array_column($rows('user_usergroup_map', ['user_id' => $row['id']]), 'group_id'));
					$check((int) $row['block'] === 1 && $groups === [$registeredId]
						&& in_array('password', $result['verification']['unobservableFields'] ?? [], true)
						&& in_array('password2', $result['verification']['unobservableFields'] ?? [], true),
						'Custom-field user remains blocked and Registered with unobservable credentials');
				}
			});
		unset($data);
		$apply($action . '.update', ['id' => $id, 'data' => ['com_fields' => $second]], 'verified',
			static fn (array $result) => $observe($id, $second, $result));
		$second = $values(['text' => 'updated ' . $prefix, 'list' => 1, 'checkboxes' => [0, 1], 'integer' => '7']);
		$apply($action . '.update', ['id' => $id, 'data' => $second], 'verified', static fn (array $result) => $observe($id, $second, $result));
		foreach (['list', 'checkboxes'] as $type)
		{
			$before = $rows($table, ['id' => $id]);
			$plan = $tool('joomla_action_write_plan', ['action' => $action . '.update', 'transport' => 'api', 'idempotencyKey' => Json::uuid(),
				'input' => ['id' => $id, 'data' => [$fields[$type]['name'] => ['absent-option']]]]);
			$rejected = $call('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']], true);
			$check($rejected['isError'] && ($rejected['data']['error']['code'] ?? '') === 'JOOMLA_API_ERROR'
				&& ($rejected['data']['error']['details']['httpStatus'] ?? 0) === 400, $action . ' rejects an invalid native ' . $type . ' option');
				$after = $rows($table, ['id' => $id]);
				$check(count($before) === 1 && count($after) === 1, 'Rejected option preserves the exact owned entity');
				// ApiController checks out an existing record before validating its
				// form. A rejected option can therefore retain this native metadata.
				foreach (['checked_out', 'checked_out_time'] as $column)
				{
					if (array_key_exists($column, $after[0]))
					{
						$check($column === 'checked_out' ? (int) $after[0][$column] === (int) $admin->id
							: is_string($after[0][$column]) && strtotime($after[0][$column]) !== false,
							'Rejected option retains only valid checkout metadata for the authenticated test user');
						unset($before[0][$column], $after[0][$column]);
					}
				}
				$check($after === $before, 'Rejected option leaves every native entity content field unchanged');
				$observe($id, $second);
				$reconcile($rejected['data']['executionId'], 'partial',
					'Installed negative fixture: native checkout metadata was inspected separately; every owned entity content field, custom-field value row and both API reads remain unchanged after option validation rejected the request. The original native error is retained.');
		}
		$apply($action . '.update', ['id' => $id, 'data' => $clear], 'verified', static fn (array $result) => $observe($id, $clear, $result));
	}
}
finally
{
	try
	{
		// Exact markers establish ownership even if an earlier create failed after save.
		// Delete entities before their definitions and groups using Joomla's native lifecycle.
		foreach (['entities', 'fields', 'groups'] as $bucket)
		{
			foreach (array_reverse($fixtures[$bucket]) as $fixture)
			{
				$found = $rows($fixture['table'], $fixture['marker']);
				foreach ($found as $row)
				{
					$id = (int) $row['id'];
					$check($id > 0 && str_starts_with((string) reset($fixture['marker']), $prefix . '-'), 'Cleanup owns the exact disposable identity');
					if ($fixture['table'] === 'users')
					{
						$user = $container->get(UserFactoryInterface::class)->loadUserById($id);
						$check((int) $user->block === 1 && $user->delete(), 'Remove the owned blocked custom-field user through Joomla');
					}
					else
					{
						$previousContext = $app->getInput()->get('context', '', 'RAW');
						$previousExtension = $app->getInput()->get('extension', '', 'RAW');
						$app->getInput()->set('context', $fixture['context'] ?? '');
						$app->getInput()->set('extension', $fixture['extension'] ?? '');
						try
						{
							$model = $app->bootComponent($fixture['component'])->getMVCFactory()->createModel($fixture['model'], 'Administrator', ['ignore_request' => true]);
							$model->setCurrentUser($admin);
							if (isset($fixture['context']))
							{
								[$component, $section] = explode('.', $fixture['context'], 2);
								$model->setState('filter.context', $fixture['context']);
								$model->setState('field.context', $fixture['context']);
								$model->setState('field.component', $component);
								$model->setState('field.section', $section);
							}
							if (isset($fixture['extension']))
							{
								$check($rows('content', ['catid' => $id]) === [] && $rows('contact_details', ['catid' => $id]) === []
									&& $rows('categories', ['parent_id' => $id]) === [], 'Owned category cleanup has no remaining entity or child dependencies');
								$model->setState('category.extension', $fixture['extension']);
								$model->setState('category.component', $fixture['extension']);
							}
							if ($bucket === 'groups')
							{
								$check($rows('fields', ['group_id' => $id]) === [], 'Owned field group has no remaining definitions');
							}
							$ids = [$id];
							$check($model->publish($ids, -2) && $model->delete($ids), 'Remove owned ' . $fixture['table'] . ' through the native lifecycle');
						}
						finally
						{
							$app->getInput()->set('context', $previousContext);
							$app->getInput()->set('extension', $previousExtension);
						}
					}
					$check($rows($fixture['table'], ['id' => $id]) === [], 'Owned native row is absent after cleanup');
					if ($fixture['table'] === 'categories')
					{
						// Match the exact removed fixture, including Joomla's retained
						// content-category alias for another extension's history.
						foreach ($rows('history', ['item_id' => 'com_content.category.' . $id]) as $version)
						{
							$data = Json::decode($version['version_data']);
							if ((int) ($data['id'] ?? 0) !== $id || ($data['title'] ?? null) !== $row['title'])
							{
								throw new RuntimeException('Owned category history cleanup identity mismatch.');
							}
							$historyTable = new \Joomla\CMS\Table\ContentHistory($db);
							$historyTable->setCurrentUser($admin);
							if (!$historyTable->delete((int) $version['version_id']))
							{
								throw new RuntimeException('Owned category history cleanup failed.');
							}
						}
					}
					if ($bucket === 'fields')
					{
						$check($rows('fields_values', ['field_id' => $id]) === [], 'No owned custom-field value rows remain');
					}
					if ((int) ($row['asset_id'] ?? 0) > 0)
					{
						$check($rows('assets', ['id' => $row['asset_id']]) === [], 'Owned native asset is absent after cleanup');
					}
				}
				$check($rows($fixture['table'], $fixture['marker']) === [], 'No row with the exact owned marker remains');
			}
		}
		foreach (array_keys($executions) as $uuid)
		{
			$execution = $store->one('execution', ['uuid' => $uuid]);
			if (($execution['status'] ?? '') === 'uncertain')
			{
				// Failure cleanup cannot turn the assertion that failed into a pass.
				(new Operations($store, new Envelope($app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'], 'partial',
					'Failed disposable custom-field fixture: exact owned records were independently inspected and removed through native Joomla models; no mutation was replayed.', true);
			}
			$check($store->one('lease', ['owner_uuid' => $uuid]) === null, 'No owned custom-field execution lease remains');
		}
	}
	finally
	{
		if ($grantId !== null)
		{
			$tool('joomla_permission_revoke', ['grantId' => $grantId]);
		}
		$http->disconnect();
	}
}
echo Json::encode(['checks' => $checks, 'liveJoomla' => JVERSION, 'database' => $db->getServerType(),
	'verification' => 'four native contexts; field groups and text/list/checkboxes/integer definitions; values, option maps, aliases, clears, rejection, replay and cleanup']) . PHP_EOL;
