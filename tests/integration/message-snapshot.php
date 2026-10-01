<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
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
$factory = $app->bootComponent('com_messages')->getMVCFactory();
$prefix = 'mcp-message-' . bin2hex(random_bytes(6)) . '-';
$peerName = $prefix . 'recipient';
$peer = null;
$checks = 0;
$grantId = null;
$sequence = 1000;
$subjects = [];
$owned = [];
$keys = [];
$postDelete = [];
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}

	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};

// Do not use HttpFixture::tool/rpc error diagnostics here: a returned failure
// must never print an approval token, raw API error body or private message.
$call = static function (string $name, array $arguments, bool $allowError = false) use ($http, &$sequence): array
{
	$id = $sequence++;
	$response = $http->exchange(Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call',
		'params' => ['name' => $name, 'arguments' => (object) $arguments]]));
	$wire = $response['json'];

	if ($response['status'] !== 200 || !is_array($wire) || ($wire['id'] ?? null) !== $id || isset($wire['error']))
	{
		throw new RuntimeException('Invalid disposable message snapshot protocol response.');
	}

	$result = $wire['result'] ?? [];
	$data = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '{}', true);

	if (!is_array($data))
	{
		throw new RuntimeException('Invalid disposable message snapshot tool envelope.');
	}

	$isError = ($result['isError'] ?? false) === true;

	if (!$allowError && $isError)
	{
		$code = $data['error']['code'] ?? '';
		$code = is_string($code) && preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D', $code) === 1 ? $code : 'TOOL_FAILED';
		throw new RuntimeException('Disposable message snapshot tool failed: ' . $name . ' (' . $code . ').');
	}

	return ['data' => $data, 'isError' => $isError];
};
$stored = static function (int $id) use ($db): ?array
{
	$row = $db->setQuery($db->createQuery()->select($db->quoteName([
		'message_id', 'user_id_from', 'user_id_to', 'folder_id', 'date_time', 'state', 'priority', 'subject', 'message',
	]))->from($db->quoteName('#__messages'))->where($db->quoteName('message_id') . ' = ' . $id))->loadAssoc();

	return is_array($row) ? $row : null;
};
$create = static function (string $label, int $state, int $recipient) use ($factory, $admin, $prefix, &$subjects, &$owned, $check): int
{
	$subject = $prefix . $label;
	$subjects[$subject] = true;
	$table = $factory->createTable('Message', 'Administrator');
	$data = ['user_id_from' => (int) $admin->id, 'user_id_to' => $recipient, 'folder_id' => 0,
		'date_time' => Factory::getDate()->toSql(), 'state' => $state, 'priority' => 0,
		'subject' => $subject, 'message' => 'Disposable private-message snapshot fixture ' . $label];
	$check($table->bind($data) && $table->check() && $table->store(), 'Create the owned ' . $label . ' fixture through the native message table');
	$id = (int) $table->message_id;
	$check($id > 0, 'Native ' . $label . ' fixture has a persisted identity');
	$owned[$id] = $subject;

	return $id;
};
$change = static function (int $id, array $values) use ($factory, $admin, &$owned, $stored, $check): void
{
	$row = $stored($id);
	$check(isset($owned[$id]) && $row !== null && $row['subject'] === $owned[$id]
		&& (int) $row['user_id_from'] === (int) $admin->id, 'External-change fixture ownership is exact');
	$table = $factory->createTable('Message', 'Administrator');
	$check($table->load(['message_id' => $id, 'subject' => $owned[$id], 'user_id_from' => (int) $admin->id])
		&& $table->bind($values) && $table->check() && $table->store(), 'Apply the isolated external change through the native message table');
};
$plan = static function (int $id, bool $dryRun = false) use ($call, &$keys, $check): array
{
	$key = Json::uuid();
	$keys[$key] = $id;
	$result = $call('joomla_action_write_plan', ['action' => 'messages.messages.delete', 'transport' => 'api',
		'idempotencyKey' => $key, 'dryRun' => $dryRun, 'input' => ['id' => $id]])['data'];
	$snapshot = $result['operation']['snapshot'] ?? [];
	$check(($snapshot['contract'] ?? '') === 'joomla.message-owned-record.v1'
		&& ($snapshot['source'] ?? '') === 'native-owned-message-table' && ($snapshot['sideEffect'] ?? null) === false
		&& ($snapshot['readAction'] ?? '') === 'messages.messages.get', 'Message plan identifies its pure native owned-record precondition contract');
	$check($dryRun ? ($result['dryRun'] ?? false) && !isset($result['confirmationToken'])
		: is_string($result['confirmationToken'] ?? null), 'Message preview or approval retains its normal confirmation contract');

	return ['key' => $key, 'plan' => $result];
};
$nativeGet = static function (int $id) use ($base, $token): array
{
	$body = '';
	$handle = curl_init($base . '/api/index.php/v1/messages/' . $id);
	curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTPHEADER => ['Accept: application/vnd.api+json', 'X-Joomla-Token: ' . $token],
		CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int
		{
			if (strlen($body) + strlen($chunk) > 1048576)
			{
				return 0;
			}
			$body .= $chunk;

			return strlen($chunk);
		},
	]);

	try
	{
		if (curl_exec($handle) === false)
		{
			throw new RuntimeException('The independent native message GET did not complete.');
		}
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
	}
	finally
	{
		curl_close($handle);
	}

	return ['status' => $status, 'document' => json_decode($body, true)];
};
$reconcile = static function (string $key) use ($store, $app, $admin, &$keys, &$owned, $stored, $check): void
{
	$id = $keys[$key] ?? 0;
	$principal = hash('sha256', 'joomla:' . (int) $admin->id);
	$execution = $store->one('execution', ['idempotency_key' => $key, 'principal_key' => $principal]);

	if (($execution['status'] ?? '') !== 'uncertain')
	{
		return;
	}

	$check(isset($owned[$id]) && $stored($id) === null, 'Reconcile only an independently absent owned message fixture');
	$approved = $store->one('plan', ['uuid' => $execution['plan_uuid'], 'principal_key' => $principal, 'idempotency_key' => $key]);
	$check($approved !== null, 'Uncertain message execution belongs to this exact fixture approval');
	(new Operations($store, new Envelope((string) $app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'],
		'partial', 'The exact owned disposable message fixture was independently inspected and confirmed absent. Native deletion or scoped native cleanup removed it; no mutation was replayed.', true);
	$check(($store->one('execution', ['uuid' => $execution['uuid']])['status'] ?? '') === 'reconciled'
		&& $store->one('lease', ['owner_uuid' => $execution['uuid']]) === null, 'Release only the inspected fixture execution lease during cleanup');
};

try
{
	try
	{
		$http->initialize();
	}
	catch (Throwable)
	{
		throw new RuntimeException('Native MCP initialization failed for the disposable message snapshot fixture.');
	}
	$check((int) $admin->id > 0 && !$admin->guest && !$admin->block, 'Installed message fixture administrator is active');
	$permission = $call('joomla_permission_request', ['toolsets' => ['users.admin'], 'duration' => '30-minutes',
		'reason' => 'Disposable owned private-message precondition, recipient isolation and replay tests'])['data'];
	$grant = $call('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']])['data'];
	$grantId = $grant['id'] ?? $grant['grantId'] ?? null;
	$check($grantId !== null, 'Explicit private-message mutation grant persisted');
	$missingId = null;

	foreach ([0, 1, -2] as $state)
	{
		$id = $create('delete-state-' . $state, $state, (int) $admin->id);
		$before = $stored($id);
		$check($before !== null && (int) $before['state'] === $state, 'Independent table inspection confirms the requested initial message state');
		$dry = $plan($id, true);
		$check($stored($id) === $before && $store->one('execution', ['idempotency_key' => $dry['key']]) === null,
			'Dry planning preserves every persisted message field without an execution');
		$approval = $plan($id);
		$check($stored($id) === $before && $store->one('execution', ['idempotency_key' => $approval['key']]) === null,
			'Executable planning preserves every persisted message field without an execution');
		$wire = $call('joomla_write_apply', ['confirmationToken' => $approval['plan']['confirmationToken']], true);
		$result = $wire['data'];
		$execution = $store->one('execution', ['idempotency_key' => $approval['key']]);
		$check(($result['error']['code'] ?? '') !== 'PRECONDITION_CHANGED' && ($result['mutation']['status'] ?? null) === 204
			&& $execution !== null && ($result['executionId'] ?? null) === $execution['uuid'] && $stored($id) === null,
			'Unchanged state ' . $state . ' approval reaches native DELETE 204 and independent persisted absence');
		$missingId ??= $id;
		$probe = $nativeGet($id);
		$check(in_array($probe['status'], [404, 500], true), 'Independent deleted-message GET exposes the installed missing-item contract');
		$postDelete[(string) $state] = ['httpStatus' => $probe['status'], 'verification' => $result['verification']['status'] ?? null];

		if ($probe['status'] === 500)
		{
			$check($wire['isError'] && ($result['verification']['status'] ?? '') === 'uncertain'
				&& ($result['error']['code'] ?? '') === 'JOOMLA_API_ERROR' && ($result['error']['details']['httpStatus'] ?? null) === 500
				&& $execution['status'] === 'uncertain' && $store->one('lease', ['owner_uuid' => $execution['uuid']]) !== null,
				'Native post-delete HTTP 500 remains a retained uncertain execution, independently of successful planning');
		}
		else
		{
			$check(!$wire['isError'] && ($result['verification']['status'] ?? '') === 'verified'
				&& $execution['status'] === 'completed' && $store->one('lease', ['owner_uuid' => $execution['uuid']]) === null,
				'An installed native missing-item 404 truthfully settles deletion verification');
		}

		$audit = $store->find('audit', ['execution_uuid' => $execution['uuid']]);
		$repeat = $call('joomla_write_apply', ['confirmationToken' => $approval['plan']['confirmationToken']], true)['data'];
		$check(($repeat['idempotentReplay'] ?? false) && ($repeat['executionId'] ?? null) === $execution['uuid']
			&& ($repeat['mutation'] ?? null) === $result['mutation'] && ($repeat['verification'] ?? null) === $result['verification']
			&& ($repeat['error'] ?? null) === ($result['error'] ?? null) && $stored($id) === null
			&& $store->one('execution', ['uuid' => $execution['uuid']]) === $execution
			&& $store->find('audit', ['execution_uuid' => $execution['uuid']]) === $audit,
			'Replay retains the original result, absent row, execution and mutation audit without a second mutation');
		// An uncertain native read-back retains the installation lease. Reconcile
		// only this already inspected absent fixture before the next write case.
		$reconcile($approval['key']);
	}

	foreach (['state' => ['state' => 1], 'content' => ['message' => 'Externally changed disposable fixture content']] as $label => $values)
	{
		$id = $create('external-' . $label, 0, (int) $admin->id);
		$approval = $plan($id);
		$change($id, $values);
		$before = $stored($id);
		$leases = $store->find('lease');
		$result = $call('joomla_write_apply', ['confirmationToken' => $approval['plan']['confirmationToken']], true);
		$check($result['isError'] && ($result['data']['error']['code'] ?? '') === 'PRECONDITION_CHANGED'
			&& !isset($result['data']['executionId']) && $store->one('execution', ['idempotency_key' => $approval['key']]) === null
			&& $store->find('lease') === $leases && $stored($id) === $before,
			'A real external ' . $label . ' change invalidates approval before execution or mutation');
	}

	// A separate blocked, non-administrator account is owned by this run. No
	// token, login or elevated permission is created for the foreign recipient.
	$registered = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'))
		->where($db->quoteName('title') . ' = ' . $db->quote('Registered')), 0, 1)->loadResult();
	$check($registered > 0, 'Resolve a native non-administrator group for the recipient fixture');
	$peer = new User();
	$password = bin2hex(random_bytes(24)) . '!aA7';
	$data = ['name' => $peerName, 'username' => $peerName, 'email' => $peerName . '@example.invalid',
		'password' => $password, 'password2' => $password, 'groups' => [$registered], 'block' => 1, 'sendEmail' => 0];
	$check($peer->bind($data) && $peer->save() && (int) $peer->id > 0, 'Create the owned blocked foreign-recipient fixture through Joomla');
	unset($data, $password);
	$id = $create('external-recipient', 0, (int) $admin->id);
	$approval = $plan($id);
	$change($id, ['user_id_to' => (int) $peer->id]);
	$before = $stored($id);
	$leases = $store->find('lease');
	$refused = $call('joomla_write_apply', ['confirmationToken' => $approval['plan']['confirmationToken']], true);
	$foreign = $call('joomla_action_write_plan', ['action' => 'messages.messages.delete', 'transport' => 'api', 'dryRun' => true,
		'idempotencyKey' => Json::uuid(), 'input' => ['id' => $id]], true);
	$missing = $call('joomla_action_write_plan', ['action' => 'messages.messages.delete', 'transport' => 'api', 'dryRun' => true,
		'idempotencyKey' => Json::uuid(), 'input' => ['id' => $missingId]], true);
	$check($refused['isError'] && $foreign['isError'] && $missing['isError']
		&& ($refused['data']['error']['code'] ?? '') === 'DEFINITION_UNAVAILABLE'
		&& $refused['data']['error'] === ($foreign['data']['error'] ?? null)
		&& $foreign['data']['error'] === ($missing['data']['error'] ?? null)
		&& !isset($refused['data']['executionId']) && !isset($foreign['data']['confirmationToken'])
		&& $store->one('execution', ['idempotency_key' => $approval['key']]) === null && $store->find('lease') === $leases
		&& $stored($id) === $before, 'Recipient changes, foreign records and missing records retain a nondisclosing refusal before execution');

	$id = $create('public-get', 0, (int) $admin->id);
	$before = $stored($id);
	$first = $nativeGet($id);
	$after = $stored($id);
	$check($first['status'] === 200 && (string) ($first['document']['data']['id'] ?? '') === (string) $id
		&& (int) ($first['document']['data']['attributes']['state'] ?? -99) === 0
		&& $after !== null && (int) $after['state'] === 1
		&& array_diff_assoc($after, $before) === ['state' => $after['state']],
		'First public native GET returns the unread representation while persisting only read state');
	$second = $nativeGet($id);
	$check($second['status'] === 200 && (int) ($second['document']['data']['attributes']['state'] ?? -99) === 1
		&& $stored($id) === $after, 'Second public native GET returns read state without another stored change');

	$id = $create('mcp-public-get', 0, (int) $admin->id);
	$first = $call('joomla_action_read', ['action' => 'messages.messages.get', 'input' => ['id' => $id]])['data'];
	$check((int) ($first['response']['data']['data']['attributes']['state'] ?? -99) === 0 && (int) ($stored($id)['state'] ?? -99) === 1,
		'Public MCP message GET preserves native mark-read behavior independently of pure planning');
}
finally
{
	// Recover only exact subject markers registered before native store(), even
	// when a save completed before its result could be returned to this test.
	$found = $db->setQuery($db->createQuery()->select($db->quoteName(['message_id', 'user_id_from', 'subject']))
		->from($db->quoteName('#__messages'))->where($db->quoteName('subject') . ' LIKE ' . $db->quote($prefix . '%')))->loadAssocList();
	foreach ($found as $row)
	{
		$id = (int) $row['message_id'];
		$check($id > 0 && isset($subjects[$row['subject']]) && (int) $row['user_id_from'] === (int) $admin->id,
			'Cleanup matches an exact owned message subject and sender');
		$owned[$id] = $row['subject'];
		$table = $factory->createTable('Message', 'Administrator');
		$check($table->load(['message_id' => $id, 'subject' => $row['subject'], 'user_id_from' => (int) $admin->id]) && $table->delete($id)
			&& $stored($id) === null, 'Remove only the owned fixture through the native message table');
	}
	foreach (array_keys($keys) as $key)
	{
		$reconcile($key);
	}
	$peerRows = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'username', 'email', 'block']))->from($db->quoteName('#__users'))
		->where($db->quoteName('username') . ' = ' . $db->quote($peerName)))->loadAssocList();
	foreach ($peerRows as $row)
	{
		$check((int) $row['id'] > 0 && (int) $row['id'] !== (int) $admin->id && (int) $row['block'] === 1
			&& $row['email'] === $peerName . '@example.invalid', 'Cleanup identifies only the owned blocked recipient');
		$user = $container->get(UserFactoryInterface::class)->loadUserById((int) $row['id']);
		$check($user->delete(), 'Remove the owned blocked recipient through Joomla');
	}
	$check((int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__messages'))
		->where($db->quoteName('subject') . ' LIKE ' . $db->quote($prefix . '%')))->loadResult() === 0,
		'No disposable private-message rows remain');
	if ($grantId !== null)
	{
		$call('joomla_permission_revoke', ['grantId' => $grantId]);
	}
	$http->disconnect();
}

echo Json::encode(['checks' => $checks, 'liveJoomla' => JVERSION, 'database' => $db->getServerType(),
	'snapshotContract' => 'joomla.message-owned-record.v1', 'states' => [0, 1, -2], 'postDelete' => $postDelete,
	'verification' => 'Pure planning, unchanged native deletion, real external-change rejection, recipient isolation, public GET marking and replay']) . PHP_EOL;
