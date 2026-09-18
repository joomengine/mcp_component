<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Table\Asset;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$factory = $app->bootComponent('com_joomengine_mcp')->getMVCFactory();
$store = new JoomlaStore($db);
$client = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
$prefix = 'fixture.' . bin2hex(random_bytes(6));
$ids = [];
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
/** Resolve native MVC afresh so a previous validation error cannot contaminate an edit. */
$model = static function (string $entity) use ($factory, $admin)
{
	$model = $factory->createModel(ucfirst($entity), 'Administrator', ['ignore_request' => true]);
	$model->setCurrentUser($admin);
	return $model;
};
/** Require a persisted native model save; tool assertions always go through MCP below. */
$save = static function (string $entity, array $data) use ($model, $check, $store): array
{
	$editor = $model($entity);
	$check($editor->save($data), 'Native ' . $entity . ' save: ' . implode('; ', $editor->getErrors()));
	$id = (int) $editor->getState($entity . '.id');
	$row = $store->one($entity, ['id' => $id]);
	$check($id > 0 && $row !== null, 'Persisted ' . $entity . ' read-back');
	return $row;
};
$base = ['id' => 0, 'published' => 1, 'access' => 1, 'ordering' => 0, 'version' => 1, 'params' => '{}'];
try
{
	$client->initialize();
	foreach (['provider', 'schema', 'action', 'binding', 'tool', 'resource', 'prompt', 'target'] as $entity)
	{
		$values = match ($entity)
		{
			'provider' => ['extension' => 'com_content', 'description' => 'Disposable definition owner', 'definition' => '{}'],
			'schema' => ['document' => '{"type":"object","properties":{},"additionalProperties":false}'],
			'action' => ['description' => 'List actual Joomla articles', 'domain' => 'content', 'toolset' => 'content.read', 'effect' => 'read', 'risk' => 'read', 'definition' => '{}'],
			'binding' => ['action_id' => $ids['action'], 'track' => 'api', 'handler' => 'api.request',
				'configuration' => $store->one('binding', ['name' => 'content.articles.list.api'])['configuration'], 'definition' => '{}'],
			'tool' => ['description' => 'An administrator-defined live Joomla API tool', 'handler' => 'action.read',
				'configuration' => json_encode(['action' => $prefix . '.action'], JSON_THROW_ON_ERROR), 'definition' => '{}'],
			'resource' => ['uri' => $prefix . '://text', 'mime_type' => 'text/plain', 'is_template' => 0,
				'description' => 'Database text resource', 'handler' => 'resource.text', 'configuration' => '{"text":"Stored fixture text"}', 'definition' => '{}'],
			'prompt' => ['description' => 'Database prompt', 'configuration' => '{"messages":[{"role":"user","text":"Stored fixture prompt"}]}', 'definition' => '{}'],
			'target' => ['command' => 'list', 'risk' => 'read', 'status' => 'read-only', 'description' => 'Native Joomla listing', 'definition' => '{}'],
		};
		if ($entity !== 'provider')
		{
			$values['provider_id'] = $ids['provider'];
		}
		if (in_array($entity, ['action', 'binding', 'tool', 'prompt'], true))
		{
			$values['input_schema_id'] = $ids['schema'];
		}
		if (in_array($entity, ['action', 'binding', 'tool'], true))
		{
			$values['output_schema_id'] = null;
		}
		$row = $save($entity, $base + ['name' => $prefix . '.' . $entity, 'title' => 'Fixture ' . $entity] + $values);
		$ids[$entity] = (int) $row['id'];
		$asset = new Asset($db);
		$check($asset->load((int) $row['asset_id']) && $asset->name === 'com_joomengine_mcp.' . $entity . '.' . $ids[$entity], 'Native ' . $entity . ' asset identity');
		$changed = $save($entity, ['id' => $ids[$entity], 'version' => (int) $row['version'], 'title' => 'Updated fixture ' . $entity]);
		$check((int) $changed['version'] === (int) $row['version'] + 1 && $changed['title'] === 'Updated fixture ' . $entity, 'Revision-checked ' . $entity . ' update persisted');
		$stale = $model($entity);
		$check(!$stale->save(['id' => $ids[$entity], 'version' => (int) $row['version'], 'title' => 'Stale edit']), 'Stale ' . $entity . ' edit denied');
		$check($store->one($entity, ['id' => $ids[$entity]])['title'] === 'Updated fixture ' . $entity, 'Stale ' . $entity . ' edit has no side effects');
	}

	$names = array_column($client->rpc('tools/list')['result']['tools'], 'name');
	$check(in_array($prefix . '.tool', $names, true), 'Administrator-created tool appears in real MCP discovery');
	$result = $client->tool($prefix . '.tool');
	$check(($result['response']['status'] ?? null) === 200, 'Administrator-created binding executes the actual Joomla API through MCP');
	$text = $client->rpc('resources/read', ['uri' => $prefix . '://text']);
	$check(($text['result']['contents'][0]['text'] ?? null) === 'Stored fixture text', 'Administrator-created resource is returned by MCP');
	$prompt = $client->rpc('prompts/get', ['name' => $prefix . '.prompt']);
	$check(($prompt['result']['messages'][0]['content']['text'] ?? null) === 'Stored fixture prompt', 'Administrator-created prompt is returned by MCP');

	$pks = [$ids['provider']];
	$check($model('provider')->publish($pks, 0), 'Unpublish provider through native administrator model');
	$names = array_column($client->rpc('tools/list')['result']['tools'], 'name');
	$check(!in_array($prefix . '.tool', $names, true), 'Provider publication change immediately removes its tools from MCP');
	$denied = $client->rpc('tools/call', ['name' => $prefix . '.tool', 'arguments' => (object) []]);
	$check(isset($denied['error']) || ($denied['result']['isError'] ?? false), 'Direct MCP invocation cannot bypass provider publication');
	$check($model('provider')->publish($pks, 1), 'Republish provider');
	$check(($client->tool($prefix . '.tool')['response']['status'] ?? 0) === 200, 'Republished tool executes without restarting the MCP session');

	$pks = [$ids['schema']];
	$check($model('schema')->publish($pks, -2), 'Trash schema while dependents remain');
	$check(!$model('schema')->delete($pks), 'Permanent deletion rejects referenced schema');
	$check($store->one('schema', ['id' => $ids['schema']]) !== null, 'Rejected deletion retains schema and asset');
	$check($model('schema')->publish($pks, 1), 'Restore fixture schema');
	$seed = json_decode(file_get_contents(JPATH_COMPONENT . '/data/catalogue-seed.json'), true, 128, JSON_THROW_ON_ERROR);
	(new SeedUpdater($store))->apply($seed);
	$check(($client->tool($prefix . '.tool')['response']['status'] ?? 0) === 200, 'Core seed upgrade preserves the executable administrator-owned integration');
}
finally
{
	foreach (array_reverse($ids, true) as $entity => $id)
	{
		$pks = [$id];
		$editor = $model($entity);
		$check($editor->publish($pks, -2) && $editor->delete($pks), 'Native fixture cleanup ' . $entity . ': ' . implode('; ', $editor->getErrors()));
		$check($store->one($entity, ['id' => $id]) === null, 'No ' . $entity . ' fixture record remains');
	}
	$client->disconnect();
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'administratorToMcp' => true], JSON_THROW_ON_ERROR) . "\n";
