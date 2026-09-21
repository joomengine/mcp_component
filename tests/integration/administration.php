<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require __DIR__ . '/bootstrap.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$component = $app->bootComponent('com_joomengine_mcp');
$factory = $component->getMVCFactory();
$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($name);
	}

	$checks++;
	echo 'PASS ' . $name . "\n";
};

$check($admin->id > 0 && $admin->authorise('core.admin'), 'Native administrator identity');
$store = new JoomlaStore($db);
$ids = [];
$jobFixtures = [];
try
{
	foreach (['provider', 'schema', 'action', 'binding', 'tool', 'resource', 'prompt', 'target'] as $entity)
	{
		$model = $factory->createModel(ucfirst($entity), 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$check($model !== null, 'Concrete native ' . $entity . ' model');
		$form = $model->getForm([], false);
		$check($form !== false && $form->getField('name') !== false && $form->getField('rules') !== false, 'Native XML form ' . $entity);
		$table = $factory->createTable(ucfirst($entity), 'Administrator');
		$check($table !== null, 'Native table ' . $entity);
		$list = $factory->createModel(ucfirst($entity . 's'), 'Administrator', ['ignore_request' => true]);
		$list->setCurrentUser($admin);
		$list->setState('filter.published', '');
		$list->setState('list.limit', 10);
		$check(is_array($list->getItems()), 'Real paginated database list ' . $entity);
	}

	$provider = $factory->createModel('Provider', 'Administrator', ['ignore_request' => true]);
	$provider->setCurrentUser($admin);
	$data = ['id' => 0, 'name' => 'fixture.provider.' . bin2hex(random_bytes(4)), 'title' => 'Fixture provider', 'published' => 1,
		'access' => 1, 'ordering' => 0, 'extension' => 'com_content', 'description' => 'Disposable administrator fixture', 'definition' => '{}', 'params' => '{}', 'version' => 1];
	$check($provider->save($data), 'Native provider save: ' . implode('; ', $provider->getErrors()));
	$id = (int) $provider->getState('provider.id');
	$ids['provider'] = $id;
	$row = $store->one('provider', ['id' => $id]);
	$check($id > 0 && $row !== null && $row['title'] === 'Fixture provider', 'Persisted provider read-back');
	$asset = new Asset($db);
	$check($asset->loadByName('com_joomengine_mcp.provider.' . $id) && (int) $asset->id === (int) $row['asset_id'], 'Native nested-set asset persisted with row');
	$check($provider->save(['id' => $id, 'version' => (int) $row['version'], 'title' => 'Changed fixture provider']), 'Native revision-checked edit');
	$fresh = $store->one('provider', ['id' => $id]);
	$check((int) $fresh['version'] === (int) $row['version'] + 1 && $fresh['title'] === 'Changed fixture provider', 'Revision and data persisted atomically');
	$stale = $factory->createModel('Provider', 'Administrator', ['ignore_request' => true]);
	$stale->setCurrentUser($admin);
	$check(!$stale->save(['id' => $id, 'version' => (int) $row['version'], 'title' => 'Stale overwrite']), 'Stale edit refused');
	$check($store->one('provider', ['id' => $id])['title'] === 'Changed fixture provider', 'Rejected edit leaves persisted row unchanged');

	$schema = $factory->createModel('Schema', 'Administrator', ['ignore_request' => true]);
	$schema->setCurrentUser($admin);
	$check($schema->save(['id' => 0, 'name' => 'fixture.schema.' . bin2hex(random_bytes(4)), 'title' => 'Fixture schema', 'provider_id' => $id,
		'published' => 1, 'access' => 1, 'ordering' => 0, 'version' => 1, 'document' => '{"type":"object","properties":{}}', 'params' => '{}']), 'Native schema relationship save');
	$ids['schema'] = (int) $schema->getState('schema.id');
	$schemaRow = $store->one('schema', ['id' => $ids['schema']]);
	$child = new Asset($db);
	$child->load((int) $schemaRow['asset_id']);
	$check((int) $child->parent_id === (int) $asset->id, 'Native child asset parent follows provider');

	$seed = json_decode(file_get_contents(JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/data/catalogue-seed.json'), true, 128, JSON_THROW_ON_ERROR);
	$counts = (new SeedUpdater($store))->apply($seed);
	$check($store->one('provider', ['id' => $id])['title'] === 'Changed fixture provider', 'Seed update preserves administrator definitions');
	$check($counts['added'] === 0, 'Seed application is idempotent');

	$guest = new \Joomla\CMS\User\User();
	$denied = $factory->createModel('Provider', 'Administrator', ['ignore_request' => true]);
	$denied->setCurrentUser($guest);
	$check(!$denied->save($data) && $denied->getItem($id) === false, 'Unauthorized model reads and writes are denied');

	foreach (['job', 'artifact'] as $kind)
	{
		$operationsModel = $factory->createModel('Operations', 'Administrator', ['ignore_request' => true]);
		$operationsModel->setCurrentUser($admin);
		$operationsModel->setState('filter.kind', $kind);
		$operationsModel->setState('list.limit', 10);
		$check(is_array($operationsModel->getItems()), 'Native bounded administrator ' . $kind . ' metadata list');
	}

	$envelope = new Envelope((string) $app->get('secret'));
	$operations = new Operations($store, $envelope);
	$fixture = static function (string $status) use ($store, &$jobFixtures, $admin): array
	{
		$now = time();
		$owner = hash('sha256', 'joomla:' . $admin->id);
		$executionId = Json::uuid();
		$planId = Json::uuid();
		$jobId = Json::uuid();
		$jobFixtures[] = ['job' => $jobId, 'execution' => $executionId, 'plan' => $planId];
		$store->insert('plan', ['uuid' => $planId, 'principal_key' => $owner, 'action_id' => 1, 'binding_id' => 1,
			'revision' => hash('sha256', 'administrator-fixture'), 'token_hash' => hash('sha256', random_bytes(32)),
			'fingerprint' => hash('sha256', $executionId), 'input_cipher' => '', 'preview_json' => '{}', 'grant_uuid' => '',
			'idempotency_key' => Json::uuid(), 'status' => 'executing', 'expires_at' => $now + 300, 'created_at' => $now, 'version' => 1]);
		$store->insert('execution', ['uuid' => $executionId, 'principal_key' => $owner, 'plan_uuid' => $planId,
			'idempotency_key' => Json::uuid(), 'fingerprint' => hash('sha256', $executionId), 'status' => 'running',
			'result_cipher' => '', 'created_at' => $now, 'updated_at' => $now, 'version' => 1]);
		$store->insert('lease', ['resource_key' => hash('sha256', 'administrator-fixture:' . $executionId),
			'owner_uuid' => $executionId, 'expires_at' => $now + 120]);
		$id = $store->insert('job', ['uuid' => $jobId, 'principal_key' => $owner, 'principal_id' => 'joomla:' . $admin->id,
			'track' => 'api', 'action_name' => 'fixture.administration', 'execution_uuid' => $executionId,
			'token_hash' => hash('sha256', random_bytes(32)), 'payload_cipher' => '', 'result_cipher' => '', 'status' => $status,
			'progress' => 0, 'message' => 'Administrator cancellation fixture; no native mutation is dispatched.', 'cancel_requested' => 0,
			'worker_uuid' => $status === 'running' ? Json::uuid() : '', 'lease_until' => $status === 'running' ? $now + 120 : 0,
			'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now + 300, 'version' => 1]);

		return $store->one('job', ['id' => $id]);
	};
	$queued = $fixture('queued');
	$deniedCancellation = false;

	try
	{
		$operations->cancelJob($guest, (int) $queued['id'], 1);
	}
	catch (RuntimeException $error)
	{
		$deniedCancellation = $error->getCode() === 403;
	}

	$check($deniedCancellation && $store->one('job', ['id' => (int) $queued['id']])['status'] === 'queued', 'Unauthorized job cancellation cannot change persisted state');
	$operations->cancelJob($admin, (int) $queued['id'], 1);
	$cancelledJob = $store->one('job', ['id' => (int) $queued['id']]);
	$cancelledExecution = $store->one('execution', ['uuid' => $queued['execution_uuid']]);
	$outcome = Json::decode($envelope->decrypt($cancelledExecution['result_cipher'], 'execution:' . $queued['principal_key'] . ':' . $queued['execution_uuid']));
	$check($cancelledJob['status'] === 'cancelled' && $cancelledJob['token_hash'] === '' && $cancelledExecution['status'] === 'completed'
		&& $outcome['verification']['effects'] === 'not-started', 'Queued administrator cancellation persists a known non-executed outcome');
	$check($store->one('lease', ['owner_uuid' => $queued['execution_uuid']]) === null, 'Queued cancellation releases only the observed job execution lease');
	$staleCancellation = false;

	try
	{
		$operations->cancelJob($admin, (int) $queued['id'], 1);
	}
	catch (RuntimeException $error)
	{
		$staleCancellation = $error->getCode() === 409;
	}

	$check($staleCancellation, 'Stale administrator job changes fail optimistic concurrency checks');
	$running = $fixture('running');
	$operations->cancelJob($admin, (int) $running['id'], 1);
	$requested = $store->one('job', ['id' => (int) $running['id']]);
	$check($requested['status'] === 'running' && (int) $requested['cancel_requested'] === 1
		&& $store->one('execution', ['uuid' => $running['execution_uuid']])['status'] === 'running'
		&& $store->one('lease', ['owner_uuid' => $running['execution_uuid']]) !== null, 'Running administrator cancellation requests stop without asserting rollback or releasing a live lease');
}
finally
{
	foreach ($jobFixtures as $fixture)
	{
		$store->remove('audit', ['execution_uuid' => $fixture['execution']]);
		$store->remove('job', ['uuid' => $fixture['job']]);
		$store->remove('lease', ['owner_uuid' => $fixture['execution']]);
		$store->remove('execution', ['uuid' => $fixture['execution']]);
		$store->remove('plan', ['uuid' => $fixture['plan']]);
	}

	foreach (array_reverse($ids, true) as $entity => $id)
	{
		$model = $factory->createModel(ucfirst($entity), 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$pks = [$id];
		$check($model->publish($pks, -2), 'Native trash ' . $entity);
		$check($model->delete($pks), 'Native permanent delete ' . $entity . ': ' . implode('; ', $model->getErrors()));
		$check($store->one($entity, ['id' => $id]) === null, 'Cleanup read-back ' . $entity);
	}
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'live' => true], JSON_THROW_ON_ERROR) . "\n";
