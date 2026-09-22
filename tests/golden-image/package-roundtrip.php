<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Native JCB package roundtrip through the installed MCP console or HTTPS bridge.
 * The external repository protocol is disposable; JCB services are not replaced.
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/integration/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
require __DIR__ . '/JcbStdioFixture.php';
require __DIR__ . '/RepositoryFixture.php';

$db = $container->get(DatabaseInterface::class);
$track = getenv('MCP_TEST_JCB_TRANSPORT') ?: 'cli';
$repository = new RepositoryFixture();
$client = null;
$guid = Json::uuid();
$repoGuid = Json::uuid();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void
{
	if (!$ok)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
$table = static fn (string $name): string => $db->quoteName('#__componentbuilder_' . $name);
$read = static function (string $name, string $key) use ($db, $table, $guid): ?array
{
	return $db->setQuery('SELECT * FROM ' . $table($name) . ' WHERE ' . $db->quoteName($key)
		. ' = ' . $db->quote($guid))->loadAssoc() ?: null;
};
$delete = static function () use ($db, $table, $guid): void
{
	foreach (['component_updates' => 'joomla_component', 'joomla_component' => 'guid'] as $name => $key)
	{
		$db->setQuery('DELETE FROM ' . $table($name) . ' WHERE ' . $db->quoteName($key) . ' = ' . $db->quote($guid))->execute();
	}
};
$setDescription = static function (string $description) use ($db, $table, $guid): void
{
	$db->setQuery('UPDATE ' . $table('joomla_component') . ' SET ' . $db->quoteName('short_description')
		. ' = ' . $db->quote($description) . ' WHERE guid = ' . $db->quote($guid))->execute();
};
$savedRepositories = $db->setQuery('SELECT id, published FROM ' . $table('repository') . ' WHERE target = 4')->loadAssocList();
$evidence = ['track' => $track, 'operations' => [], 'requests' => []];
$lastConfirmation = null;
$execute = static function (string $operation, array $options = [], bool $success = true) use (&$client, $track, $guid, $check, &$evidence, &$lastConfirmation): array
{
	$action = 'jcb.' . $operation . '.joomla_component';
	$plan = $client->tool('joomla_action_write_plan', ['action' => $action, 'transport' => $track,
		'idempotencyKey' => Json::uuid(), 'input' => ['options' => ['items' => $guid] + $options]]);
	$apply = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$lastConfirmation = $plan['confirmationToken'];
	$job = $apply['job']['jobId'] ?? null;
	$check(is_string($job), 'Actual native ' . $operation . ' starts a durable owned job');
	$deadline = microtime(true) + 180;
	do
	{
		$result = $client->tool('joomla_job_status', ['jobId' => $job]);
		if (!in_array($result['status'], ['queued', 'running', 'cancelling'], true))
		{
			break;
		}
		usleep(200000);
	}
	while (microtime(true) < $deadline);
	$evidence['operations'][$operation][] = ['jobId' => $job, 'status' => $result['status'],
		'verification' => $result['result']['verification'] ?? null];
	$completed = $result['status'] === 'completed' && ($result['result']['verification']['status'] ?? '') === 'verified';
	if ($success && !$completed)
	{
		if ($track === 'cli')
		{
			$native = $result['result']['mutation'] ?? [];
			fwrite(STDERR, json_encode(['nativeExitCode' => $native['exitCode'] ?? null,
				'stdout' => substr((string) ($native['stdout'] ?? ''), 0, 8192),
				'stderr' => substr((string) ($native['stderr'] ?? ''), 0, 8192)], JSON_THROW_ON_ERROR) . PHP_EOL);
		}
		fwrite(STDERR, json_encode(['operation' => $operation, 'status' => $result['status'],
			'verification' => $result['result']['verification'] ?? null,
			'readBack' => $result['result']['mutation']['package']['remoteReadBack'] ?? null,
			'error' => $result['result']['error']['code'] ?? null], JSON_THROW_ON_ERROR) . PHP_EOL);
	}
	$check($success ? $completed : (!$completed && in_array($result['status'], ['partial', 'uncertain'], true)),
		$success ? 'Actual native ' . $operation . ' independently verifies completion'
			: 'Native remote failure remains partial or uncertain despite the command exit status');
	return $result;
};

try
{
	$check(in_array($track, ['cli', 'api'], true), 'Package roundtrip uses a real authority track');
	// The normal core repository is overridden by its native organisation/repository
	// key. Every other package repository is disabled only inside this disposable DB.
	$db->setQuery('UPDATE ' . $table('repository') . ' SET published = 0 WHERE target = 4')->execute();
	$row = (object) ['guid' => $repoGuid, 'system_name' => 'MCP disposable loopback packages', 'type' => 1,
		'base' => $repository->url(), 'organisation' => 'joomla', 'repository' => 'packages', 'target' => 4,
		'read_branch' => 'fixture', 'write_branch' => 'fixture', 'access_repo' => 0, 'token' => '', 'username' => '',
		'author_name' => 'MCP disposable test', 'author_email' => 'mcp@example.test', 'published' => 1, 'ordering' => 99999];
	$db->insertObject('#__componentbuilder_repository', $row, 'id');
	foreach (['index/joomla-component.json', 'index/component-updates.json'] as $index)
	{
		$repository->write($index, '{}');
	}
	$component = (object) ['guid' => $guid, 'name' => 'McpPackageFixture', 'name_code' => 'mcppackagefixture',
		'system_name' => 'MCP Package Fixture', 'short_description' => 'Native package revision one',
		'description' => 'Disposable acceptance definition', 'component_version' => '1.0.0', 'add_powers' => 0,
		'preferred_joomla_version' => 6, 'author' => 'MCP Test', 'email' => 'mcp@example.test',
		'website' => 'https://example.test', 'companyname' => 'MCP Test', 'license' => 'GNU/GPL',
		'copyright' => 'Disposable fixture', 'published' => 1, 'params' => '{}'];
	$db->insertObject('#__componentbuilder_joomla_component', $component, 'id');
	$childPayload = json_encode(['version_update0' => ['version' => '1.0.1', 'sql' => '',
		'url' => 'https://example.invalid/disposable.zip']], JSON_THROW_ON_ERROR);
	$child = (object) ['joomla_component' => $guid, 'version_update' => $childPayload, 'published' => 1, 'params' => '{}'];
	$db->insertObject('#__componentbuilder_component_updates', $child, 'id');
	$client = new JcbStdioFixture();
	if ($track === 'api')
	{
		$request = $client->tool('joomla_permission_request', ['toolsets' => ['jcb.execute'], 'duration' => '30-minutes',
			'reason' => 'Disposable native package roundtrip against the local repository fixture']);
		$client->tool('joomla_permission_approve', ['requestId' => $request['requestId'], 'acknowledgement' => $request['acknowledgement']]);
	}

	$execute('push');
	$index = json_decode($repository->read('index/joomla-component.json'), true, 64, JSON_THROW_ON_ERROR);
	$path = $index[$guid]['settings'] ?? null;
	$check(is_string($path), 'Native push publishes the component in its real package index');
	$remote = json_decode($repository->read($path), true, 64, JSON_THROW_ON_ERROR);
	$check(($remote['guid'] ?? '') === $guid && ($remote['short_description'] ?? '') === 'Native package revision one',
		'Independent repository read-back matches the pushed component bytes');
	$childIndex = json_decode($repository->read('index/component-updates.json'), true, 64, JSON_THROW_ON_ERROR);
	$check(isset($childIndex[$guid]), 'Native dependency queue publishes the linked component update record');
	$childRemote = json_decode($repository->read($childIndex[$guid]['settings']), true, 64, JSON_THROW_ON_ERROR);
	$check(($childRemote['joomla_component'] ?? '') === $guid && !empty($remote['@dependencies']),
		'Published parent and child retain their native dependency relation');

	$delete();
	$execute('get');
	$check(($read('joomla_component', 'guid')['short_description'] ?? '') === 'Native package revision one',
		'Native get imports a missing component from the controlled remote');
	$check($read('component_updates', 'joomla_component') !== null,
		'Native get drains dependency imports and persists the linked child');
	$delete();
	$execute('init', ['repo' => $repoGuid]);
	$check(($read('joomla_component', 'guid')['short_description'] ?? '') === 'Native package revision one'
		&& $read('component_updates', 'joomla_component') !== null,
		'Native init with explicit repository imports the component and dependency');

	$remote['short_description'] = 'Native package revision two';
	$repository->write($path, json_encode($remote, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	$execute('pull', ['repo' => $repoGuid]);
	$check(($read('joomla_component', 'guid')['short_description'] ?? '') === 'Native package revision two',
		'Native pull replaces the existing local definition with changed remote bytes');
	$setDescription('Local modification to discard');
	$execute('reset');
	$check(($read('joomla_component', 'guid')['short_description'] ?? '') === 'Native package revision two',
		'Native reset discards the local modification and restores remote bytes');

	$setDescription('Native package revision three');
	$execute('push');
	$updated = json_decode($repository->read($path), true, 64, JSON_THROW_ON_ERROR);
	$check(($updated['short_description'] ?? '') === 'Native package revision three',
		'Native push updates existing remote content using its expected blob SHA');

	$setDescription('Native write deliberately rejected');
	$beforeFailure = $repository->fingerprint();
	$repository->rejectWrites(true);
	$failed = $execute('push', [], false);
	$check(hash_equals($beforeFailure, $repository->fingerprint())
		&& ($read('joomla_component', 'guid')['short_description'] ?? '') === 'Native write deliberately rejected',
		'Independent inspection proves the rejected push changed no remote bytes or local source');
	$requestCount = count($repository->requests());
	$replay = $client->tool('joomla_write_apply', ['confirmationToken' => $lastConfirmation]);
	$store = new JoomlaStore($db);
	$jobs = $store->find('job', ['execution_uuid' => $failed['executionId']], 10);
	$check(($replay['idempotentReplay'] ?? false) === true && $replay['executionId'] === $failed['executionId']
		&& count($jobs) === 1 && $jobs[0]['uuid'] === $failed['jobId'] && count($repository->requests()) === $requestCount,
		'Replaying failed confirmation returns the same execution and cannot perform another native write');
	$execution = $store->one('execution', ['uuid' => $failed['executionId']]);
	$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
	(new Operations($store, new Envelope((string) $app->get('secret'))))->reconcile($admin,
		(int) $execution['id'], (int) $execution['version'], 'verified_no_effect',
		'Disposable repository rejected all writes; independently compared complete repository bytes and the unchanged local source before reconciliation.', true);
	$check($client->tool('joomla_job_status', ['jobId' => $failed['jobId']])['status'] === 'reconciled'
		&& $store->one('lease', ['owner_uuid' => $failed['executionId']]) === null,
		'Authorized reconciliation records inspected remote failure and releases its exact retained lease');
	$requests = $repository->requests();
	$methods = array_unique(array_column($requests, 'method'));
	$check(in_array('GET', $methods, true) && in_array('POST', $methods, true) && in_array('PUT', $methods, true),
		'Roundtrip traverses the real native HTTP reads, creates and updates');
	$check(array_unique(array_column($requests, 'branch')) === ['fixture'],
		'Every native request preserves the configured package branch');
	$evidence['requests'] = $requests;
	echo json_encode(['checks' => $checks, 'evidence' => $evidence], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
finally
{
	if ($client !== null)
	{
		$client->disconnect();
	}
	$delete();
	$db->setQuery('DELETE FROM ' . $table('repository') . ' WHERE guid = ' . $db->quote($repoGuid))->execute();
	foreach ($savedRepositories as $saved)
	{
		$db->setQuery('UPDATE ' . $table('repository') . ' SET published = ' . (int) $saved['published']
			. ' WHERE id = ' . (int) $saved['id'])->execute();
	}
	$repository->close();
}
