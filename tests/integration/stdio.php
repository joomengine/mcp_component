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
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require __DIR__ . '/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
require __DIR__ . '/StdioFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$client = new StdioFixture();
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
$title = 'MCP stdio persisted fixture ' . bin2hex(random_bytes(6));
$articleId = null;
try
{
	$names = array_map(static fn ($tool): string => $tool->name, $client->sdk()->listTools()->tools);
	$check(in_array('joomla_action_write_plan', $names, true), 'Real installed stdio discovery exposes confirmed native writes');
	$system = $client->tool('joomla_action_read', ['action' => 'system.info', 'transport' => 'cli']);
	$check(($system['response']['data']['joomlaVersion'] ?? '') === JVERSION, 'MCP stdio dispatches actual installed Joomla system handler');
	$category = (int) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__categories') . ' WHERE extension = ' . $db->quote('com_content') . ' AND published = 1 ORDER BY id', 0, 1)->loadResult();
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'content.articles.create', 'transport' => 'cli',
		'idempotencyKey' => Json::uuid(), 'input' => ['data' => ['title' => $title, 'catid' => $category, 'introtext' => '<p>Written through MCP stdio.</p>', 'language' => '*']]]);
	$check(is_string($plan['confirmationToken'] ?? null), 'Native write preflight returns an executable principal-bound plan');
	$check(!(bool) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult(), 'MCP planning never persists an article');
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$articleId = (int) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult();
	$check($articleId > 0 && ($result['verification']['status'] ?? '') === 'verified', 'MCP stdio creates and independently verifies a persisted Joomla article');
	$replayed = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check($replayed['idempotentReplay'] === true, 'Replayed stdio confirmation does not execute a second mutation');
	$check((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult() === 1, 'Replay has exactly one persisted article');
	$read = $client->tool('joomla_action_read', ['action' => 'content.articles.get', 'transport' => 'cli', 'input' => ['id' => $articleId]]);
	$check(($read['response']['data']['item']['title'] ?? '') === $title, 'Separate MCP read returns persisted native article');
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'content.articles.update', 'transport' => 'cli', 'idempotencyKey' => Json::uuid(),
		'input' => ['id' => $articleId, 'data' => ['title' => $title . ' updated']]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($result['verification']['status'] ?? '') === 'verified', 'MCP stdio update is independently read back');
	$check($db->setQuery('SELECT title FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId)->loadResult() === $title . ' updated', 'Native update persists its exact requested title');
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'content.articles.state', 'transport' => 'cli', 'idempotencyKey' => Json::uuid(),
		'input' => ['id' => $articleId, 'state' => -2]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($result['verification']['status'] ?? '') === 'verified', 'MCP explicitly trashes the article before permanent deletion');
	$plan = $client->tool('joomla_action_write_plan', ['action' => 'content.articles.delete', 'transport' => 'cli', 'idempotencyKey' => Json::uuid(), 'input' => ['id' => $articleId]]);
	$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check(($result['verification']['status'] ?? '') === 'verified' && !$db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId)->loadResult(), 'MCP stdio permanent deletion is proved through persisted absence');
	$client->sdk()->ping();
	$check($client->sdk()->isConnected(), 'Protocol framing remains usable after consecutive native operations');
}
finally
{
	$client->disconnect();
	$articleId ??= (int) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE title = ' . $db->quote($title))->loadResult();
	if ($articleId > 0 && $db->setQuery('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId)->loadResult())
	{
		$model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$pks = [$articleId];
		$check($model->publish($pks, -2) && $model->delete($pks), 'Remove failed stdio fixture through native Joomla model');
	}
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'actualMcpStdio' => true], JSON_THROW_ON_ERROR) . "\n";
