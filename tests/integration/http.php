<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
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
$check = static function (bool $value, string $name) use (&$checks): void
{
	if (!$value)
	{
		throw new RuntimeException($name);
	}

	$checks++;
	echo 'PASS ' . $name . "\n";
};
$uuid = static fn (): string => Json::uuid();
$articleId = null;
$title = 'MCP installed fixture ' . bin2hex(random_bytes(6));
$grantId = null;
try
{
	$initial = $client->initialize();
	$check($initial['protocolVersion'] === '2025-11-25', 'Real Joomla token authentication and SDK handshake');
	$tools = $client->rpc('tools/list')['result']['tools'];
	$check(in_array('joomla_action_read', array_column($tools, 'name'), true), 'Database-backed installed tool discovery');
	$check($client->rpc('ping')['result'] === [], 'Native SDK ping');
	$check((new HttpFixture($base, ''))->exchange('{"jsonrpc":"2.0","id":1,"method":"initialize"}')['status'] === 401, 'Missing Joomla token denied');
	$check((new HttpFixture($base, 'invalid-fixture-token'))->exchange('{}')['status'] === 401, 'Invalid Joomla token denied');
	$check($client->exchange('{}', ['Origin' => 'https://untrusted.example'])['status'] === 403, 'Unapproved Origin denied');
	$malformed = $client->exchange('{');
	$check(($malformed['json']['error']['code'] ?? 0) === -32700, 'Malformed JSON rejected without HTML output');
	$missing = $client->rpc('tools/call', ['name' => 'not_a_registered_tool', 'arguments' => (object) []]);
	$check(isset($missing['error']) || ($missing['result']['isError'] ?? false), 'Unknown tools cannot bypass database discovery');

	$category = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))->where($db->quoteName('published') . ' = 1')->order($db->quoteName('id') . ' ASC'), 0, 1)->loadResult();
	$check($category > 0, 'Actual installed article category selected');
	$permission = $client->tool('joomla_permission_request', ['toolsets' => ['content.write'], 'duration' => '30-minutes', 'reason' => 'Explicit disposable integration test CRUD']);
	$grant = $client->tool('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'] ?? null;
	$check($grantId !== null, 'Native principal-bound explicit grant persisted');
	$plan = $client->tool('joomla_content_article_create_plan', ['idempotencyKey' => $uuid(), 'data' => [
		'title' => $title, 'catid' => $category, 'articletext' => '<p>Installed MCP persisted content.</p>', 'state' => 0, 'language' => '*',
	]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$articleId = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__content'))
		->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult();
	$check($articleId > 0, 'HTTP create persisted a real Joomla article');
	$check(in_array($result['verification']['status'], ['verified', 'partial'], true)
		&& in_array('title', $result['verification']['matchedFields'], true), 'Independent native API read-back confirms observable create fields');
	$createdRow = $db->setQuery('SELECT catid, introtext FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId)->loadAssoc();
	$check((int) $createdRow['catid'] === $category && $createdRow['introtext'] === '<p>Installed MCP persisted content.</p>',
		'Category and article body verified in persisted native fields (API represents them differently)');
	$repeat = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check($repeat['idempotentReplay'] === true, 'Repeated application returns recorded result without a second mutation');
	$count = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__content'))->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult();
	$check($count === 1, 'Idempotency proved against persisted Joomla rows');
	$read = $client->tool('joomla_content_article_get', ['id' => $articleId]);
	$check(str_contains(json_encode($read, JSON_THROW_ON_ERROR), $title), 'Created article visible through native Joomla API');

	$updatedTitle = $title . ' updated';
	$plan = $client->tool('joomla_content_article_update_plan', ['id' => $articleId, 'idempotencyKey' => $uuid(), 'data' => ['title' => $updatedTitle]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$persistedTitle = $db->setQuery($db->createQuery()->select($db->quoteName('title'))->from($db->quoteName('#__content'))->where($db->quoteName('id') . ' = ' . $articleId))->loadResult();
	$check($persistedTitle === $updatedTitle && $result['verification']['status'] === 'verified', 'HTTP update and preserved-form field read-back');
	// Joomla's DELETE contract requires an explicitly trashed record; trashing is
	// a separately confirmed state edit, not a hidden side effect of deletion.
	$trash = $client->tool('joomla_content_article_update_plan', ['id' => $articleId, 'idempotencyKey' => $uuid(), 'data' => ['state' => -2]]);
	$trashed = $client->tool('joomla_write_apply', ['confirmationToken' => $trash['confirmationToken']]);
	$check($trashed['verification']['status'] === 'verified', 'Explicit trash state confirmed before permanent deletion');
	$plan = $client->tool('joomla_content_article_delete_plan', ['id' => $articleId, 'idempotencyKey' => $uuid()]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$row = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__content'))->where($db->quoteName('id') . ' = ' . $articleId))->loadAssoc();
	$check(($row === null || $row === false || (int) $row['state'] === -2) && $result['verification']['status'] === 'verified', 'HTTP delete verified persisted Joomla deletion/trash semantics');
	$revoked = $client->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	$check($revoked['revoked'] === true, 'Principal can revoke its persisted grant');
	$grantId = null;
}
finally
{
	// Native deletion is also used for failure cleanup; no fixture article remains.
	if ($articleId === null)
	{
		$articleId = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__content'))->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult();
	}

	if ($articleId > 0)
	{
		$model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$pks = [$articleId];
		$exists = $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId)->loadResult();
		if ($exists && (!$model->publish($pks, -2) || !$model->delete($pks)))
		{
			throw new RuntimeException('Disposable article cleanup failed: ' . implode('; ', $model->getErrors()));
		}
		$check(!$db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId)->loadResult(), 'No article test data remains');
	}

	if ($grantId !== null)
	{
		$client->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	}

	$client->disconnect();
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'liveHttp' => true], JSON_THROW_ON_ERROR) . "\n";
