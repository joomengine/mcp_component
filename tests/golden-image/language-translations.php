<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\JcbLanguageTranslation;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/integration/bootstrap.php';
require dirname(__DIR__) . '/integration/HttpFixture.php';
$app->bootComponent('com_joomengine_mcp');
$db = $container->get(DatabaseInterface::class);
$store = new JoomlaStore($db);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$model = $app->bootComponent('com_componentbuilder')->getMVCFactory()->createModel('Language_translation', 'Administrator', ['ignore_request' => true]);
$model->setCurrentUser($admin);
$source = 'MCP translation source ' . bin2hex(random_bytes(8)) . ' %s';
$id = 0;
$grantId = null;
$executionId = null;
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
	echo 'PASS ' . $label . PHP_EOL;
};
$base = (string) getenv('MCP_TEST_BASE_URL');
$token = trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE')));
$client = new HttpFixture($base, $token);
$stored = static function () use ($db, &$id): array
{
	return $db->setQuery($db->createQuery()->select($db->quoteName(['source', 'translation']))
		->from($db->quoteName('#__componentbuilder_language_translation'))->where($db->quoteName('id') . ' = ' . $id))->loadAssoc() ?: [];
};
try
{
	// Seed through the native administrator model. The API create form is the
	// separately documented upstream limitation and is not patched by this test.
	$check($model->save(['id' => 0, 'source' => $source, 'translation' => [], 'components' => [], 'modules' => [],
		'plugins' => [], 'params' => [], 'access' => 1, 'published' => 1]), 'Create an owned native translation fixture');
	$id = (int) $model->getState('language_translation.id');
	$check($id > 0 && $stored()['source'] === $source, 'Native fixture contains the exact immutable source');
	$provider = $store->one('provider', ['name' => 'jcb.installed']);
	$candidates = [];
	foreach ($store->find('binding', ['provider_id' => (int) $provider['id'], 'track' => 'api'], 10000) as $binding)
	{
		$config = Json::decode($binding['configuration']);
		if (JcbLanguageTranslation::preservesSource($config))
		{
			$candidates[] = ['binding' => $binding, 'config' => $config];
		}
	}
	$check(count($candidates) === 1, 'Installed route and native form select exactly one reviewed source-preserving adapter');
	$config = $candidates[0]['config'];
	$action = $store->one('action', ['id' => (int) $candidates[0]['binding']['action_id']]);
	$actionName = $action['name'];
	$translation = ['translation0' => ['language' => 'en-GB', 'translation' => 'Sent to %s']];
	$handle = curl_init($base . '/api/index.php' . str_replace(':id', (string) $id, $config['route']));
	curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_POSTFIELDS => Json::encode(['translation' => $translation]),
		CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/vnd.api+json', 'X-Joomla-Token: ' . $token],
		CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30]);
	try
	{
		$raw = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$check(is_string($raw) && $status === 500 && str_contains($raw, 'Invalid field'),
			'The exact unmodified native API reproduces disabled source validation before save');
	}
	finally
	{
		curl_close($handle);
	}
	$check($stored()['source'] === $source && empty(json_decode($stored()['translation'], true)), 'Rejected native PATCH has not changed the owned fixture');
	$client->initialize();
	$permission = $client->tool('joomla_permission_request', ['toolsets' => ['jcb.write'], 'duration' => '30-minutes',
		'reason' => 'Disposable installed JCB translation preservation regression']);
	$grant = $client->tool('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'];
	$plan = $client->tool('joomla_action_write_plan', ['action' => $actionName, 'transport' => 'api',
		'input' => ['id' => $id, 'data' => ['translation' => $translation]], 'idempotencyKey' => Json::uuid()]);
	$check($plan['operation']['preservedFields'] === ['source'], 'Authenticated MCP plan discloses source preservation');
	$wire = $client->rpc('tools/call', ['name' => 'joomla_write_apply', 'arguments' => ['confirmationToken' => $plan['confirmationToken']]]);
	$result = $wire['result']['structuredContent'] ?? [];
	$executionId = $result['executionId'] ?? null;
	$check(($result['verification']['status'] ?? '') === 'verified', 'Native MCP PATCH saves and independently verifies the translation and source');
	$check($stored()['source'] === $source && Json::canonical(json_decode($stored()['translation'], true)) === Json::canonical($translation),
		'Independent native database inspection confirms the translation and exact original source');
	$check($store->one('lease', ['owner_uuid' => $executionId]) === null, 'Verified native translation update releases its owned write lease');
	$replay = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
	$check($replay['idempotentReplay'] && $replay['executionId'] === $executionId, 'The native execution replays its original result without repeating the mutation');
}
finally
{
	if ($grantId !== null)
	{
		$client->tool('joomla_permission_revoke', ['grantId' => $grantId]);
	}
	$client->disconnect();
	if ($id > 0)
	{
		$ids = [$id];
		$check($model->delete($ids), 'Remove the owned translation through the native administrator model');
		$check($stored() === [], 'No translation fixture remains');
	}
	if ($executionId !== null)
	{
		$execution = $store->one('execution', ['uuid' => $executionId]);
		if (($execution['status'] ?? '') === 'uncertain')
		{
			(new Operations($store, new Envelope($app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'], 'partial',
				'Owned translation fixture was independently inspected and removed through the native model. The ambiguous mutation was not retried.', true);
		}
	}
}
echo Json::encode(['checks' => $checks, 'joomla' => JVERSION, 'nativeJcbApi' => true, 'database' => $db->getServerType()]) . PHP_EOL;
