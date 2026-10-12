<?php
/**
 * @package    JoomEngine.Mcp
 * @created    12 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaModelProvider;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
require __DIR__ . '/StdioFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$models = new JoomlaModelProvider($app);
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
$prefix = 'MCP associations ' . bin2hex(random_bytes(6));
$createdLanguages = [];
$executions = [];
$clients = [];
$grantId = null;
$plugin = $db->setQuery($db->createQuery()->select($db->quoteName(['extension_id', 'enabled', 'params']))
	->from($db->quoteName('#__extensions'))->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
	->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
	->where($db->quoteName('element') . ' = ' . $db->quote('languagefilter')))->loadAssoc();
$check(is_array($plugin), 'Native language-filter plugin is available for the disposable multilingual fixture');
$pluginState = static function (int $enabled, string $params) use ($db, $plugin): void
{
	$db->setQuery($db->createQuery()->update($db->quoteName('#__extensions'))
		->set($db->quoteName('enabled') . ' = ' . $enabled)->set($db->quoteName('params') . ' = ' . $db->quote($params))
		->where($db->quoteName('extension_id') . ' = ' . (int) $plugin['extension_id']))->execute();
	Factory::getCache('com_plugins')->clean();
};
$itemId = static function (string $table, string $title) use ($db): int
{
	return (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__' . $table))
		->where($db->quoteName('title') . ' = ' . $db->quote($title)))->loadResult();
};
$membership = static function (string $context, string $table, int $id) use ($db): array
{
	$key = $db->setQuery($db->createQuery()->select($db->quoteName('key'))->from($db->quoteName('#__associations'))
		->where($db->quoteName('context') . ' = ' . $db->quote($context))->where($db->quoteName('id') . ' = ' . $id))->loadResult();
	if ($key === null)
	{
		return [];
	}
	$rows = $db->setQuery($db->createQuery()->select($db->quoteName(['m.id', 'm.language']))
		->from($db->quoteName('#__' . $table, 'm'))->join('INNER', $db->quoteName('#__associations', 'a'),
			$db->quoteName('m.id') . ' = ' . $db->quoteName('a.id'))
		->where($db->quoteName('a.context') . ' = ' . $db->quote($context))
		->where($db->quoteName('a.key') . ' = ' . $db->quote($key))->where($db->quoteName('m.id') . ' <> ' . $id))->loadAssocList();
	$result = [];
	foreach ($rows as $row)
	{
		$result[$row['language']] = (int) $row['id'];
	}
	ksort($result, SORT_STRING);
	return $result;
};
$invoke = static function (object $client, string $tool, array $arguments, bool $allowError = false) use (&$executions): array
{
	if ($client instanceof HttpFixture)
	{
		$wire = $client->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]);
		$result = $wire['result'] ?? [];
		if (isset($wire['error']))
		{
			throw new RuntimeException('Association fixture JSON-RPC request failed.');
		}
	}
	else
	{
		$result = json_decode(json_encode($client->sdk()->callTool($tool, $arguments), JSON_THROW_ON_ERROR), true, 128, JSON_THROW_ON_ERROR);
	}
	$data = $result['structuredContent'] ?? [];
	if (isset($data['executionId']))
	{
		$executions[$data['executionId']] = true;
	}
	if (!$allowError && ($result['isError'] ?? false))
	{
		throw new RuntimeException('Association fixture ' . $tool . ' failed: ' . Json::encode([
			'code' => $data['error']['code'] ?? null, 'verification' => $data['verification'] ?? null]));
	}
	return $data;
};
try
{
	// Enable before a native save imports plugins into Joomla's process-static registry.
	$params = (new Registry($plugin['params']))->toArray();
	$params['item_associations'] = 1;
	$pluginState(1, Json::encode($params));
	foreach (['fr-FR' => ['French', 'Français', 'fr_fr'], 'de-DE' => ['German', 'Deutsch', 'de_de']] as $code => [$title, $native, $image])
	{
		$existing = (int) $db->setQuery($db->createQuery()->select($db->quoteName('lang_id'))->from($db->quoteName('#__languages'))
			->where($db->quoteName('lang_code') . ' = ' . $db->quote($code)))->loadResult();
		if ($existing > 0)
		{
			continue;
		}
		$language = $models->administrator('com_languages', 'Language');
		$language->setCurrentUser($admin);
		$check($language->save(['lang_code' => $code, 'title' => $title, 'title_native' => $native, 'sef' => strtolower(substr($code, 0, 2)),
			'image' => $image, 'description' => '', 'metadesc' => '', 'sitename' => '', 'published' => 1, 'access' => 1, 'ordering' => 2]),
			'Create native content language ' . $code);
		$createdLanguages[] = (int) $language->getState('language.id');
	}
	$app->bootComponent('com_joomengine_mcp');
	$store = new JoomlaStore($db);
	$http = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
	$http->initialize();
	$clients['api'] = $http;
	$clients['cli'] = new StdioFixture();
	$permission = $invoke($http, 'joomla_permission_request', ['toolsets' => ['structure.write', 'content.write'],
		'duration' => '30-minutes', 'reason' => 'Disposable multilingual native storage verification']);
	$grant = $invoke($http, 'joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'];
	$menutype = $db->setQuery($db->createQuery()->select($db->quoteName('menutype'))->from($db->quoteName('#__menu_types'))
		->where($db->quoteName('client_id') . ' = 0')->order($db->quoteName('id') . ' ASC'), 0, 1)->loadResult();
	$category = (int) $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))->where($db->quoteName('published') . ' = 1')
		->order($db->quoteName('id') . ' ASC'), 0, 1)->loadResult();
	$menuData = static fn (string $title, string $language): array => ['title' => $title, 'alias' => 'mcp-assoc-' . bin2hex(random_bytes(6)),
		'menutype' => $menutype, 'type' => 'url', 'link' => 'https://example.test/' . $language, 'published' => 0,
		'parent_id' => 1, 'browserNav' => 0, 'access' => 1, 'template_style_id' => 0, 'home' => 0, 'language' => $language];
	$apply = static function (object $client, string $track, string $action, array $input, bool $allowError = false) use ($invoke, $check): array
	{
		$plan = $invoke($client, 'joomla_action_write_plan', ['action' => $action, 'transport' => $track,
			'idempotencyKey' => Json::uuid(), 'input' => $input]);
		$result = $invoke($client, 'joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']], $allowError);
		if (!$allowError)
		{
			$check(($result['verification']['status'] ?? '') === 'verified', $track . ' ' . $action . ' independently verifies the complete approved data');
		}
		$replay = $invoke($client, 'joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']], $allowError);
		$check(($replay['idempotentReplay'] ?? false) && ($replay['executionId'] ?? null) === ($result['executionId'] ?? null)
			&& ($replay['verification'] ?? null) === ($result['verification'] ?? null), 'Association write replay retains its original durable result');
		return $result;
	};
	foreach ($clients as $track => $client)
	{
		$targets = [];
		foreach (['fr-FR', 'de-DE'] as $code)
		{
			$title = $prefix . ' ' . $track . ' target ' . $code;
			$model = $models->administrator('com_menus', 'Item');
			$model->setCurrentUser($admin);
			$model->setState('item.client_id', 0);
			$check($model->save($menuData($title, $code)), 'Create native site menu target ' . $track . ' ' . $code);
			$targets[$code] = $itemId('menu', $title);
		}
		$title = $prefix . ' ' . $track . ' source';
		$apply($client, $track, 'menus.site-items.create', ['data' => $menuData($title, 'en-GB') + ['associations' => (object) ['fr-FR' => $targets['fr-FR']]]]);
		$id = $itemId('menu', $title);
		$check($id > 0 && $membership('com_menus.item', 'menu', $id) === ['fr-FR' => $targets['fr-FR']], 'Menu creation persists native association rows through ' . $track);
		$check($membership('com_menus.item', 'menu', $targets['fr-FR']) === ['en-GB' => $id], 'Menu creation persists the reciprocal language group through ' . $track);
		foreach ([['title' => $title . ' renamed'], ['associations' => (object) ['de-DE' => $targets['de-DE']]],
			['title' => $title . ' replaced'], ['associations' => (object) []], ['title' => $title . ' cleared']] as $data)
		{
			$before = $membership('com_menus.item', 'menu', $id);
			$apply($client, $track, 'menus.site-items.update', ['id' => $id, 'data' => $data]);
			$expected = array_key_exists('associations', $data) ? (array) $data['associations'] : $before;
			$check($membership('com_menus.item', 'menu', $id) === $expected, 'Menu update preserves omission and honors replacement or clear through ' . $track);
			$read = $invoke($client, 'joomla_action_read', ['action' => 'menus.site-items.get', 'transport' => $track, 'input' => ['id' => $id]]);
			$observed = $track === 'cli' ? ($read['response']['data']['item']['associations'] ?? null)
				: ($read['response']['data']['data']['attributes']['associations'] ?? null);
			$check(is_array($observed) && array_map('intval', $observed) === $expected, 'Separate menu read returns current associations through ' . $track);
		}
		$check($membership('com_menus.item', 'menu', $targets['fr-FR']) === []
			&& $membership('com_menus.item', 'menu', $targets['de-DE']) === [], 'Explicit clear leaves neither former menu target associated through ' . $track);
	}

	$articleTargetTitle = $prefix . ' article French';
	$model = $models->administrator('com_content', 'Article');
	$model->setCurrentUser($admin);
	$check($model->save(['title' => $articleTargetTitle, 'alias' => 'mcp-assoc-' . bin2hex(random_bytes(6)), 'catid' => $category,
		'language' => 'fr-FR', 'introtext' => '<p>Association target.</p>', 'state' => 0]), 'Create native French article target');
	$articleTarget = $itemId('content', $articleTargetTitle);
	$articleTitle = $prefix . ' article English';
	$apply($http, 'api', 'content.articles.create', ['data' => ['title' => $articleTitle, 'catid' => $category,
		'language' => 'en-GB', 'articletext' => '<p>Association source.</p>', 'associations' => (object) ['fr-FR' => $articleTarget]]]);
	$articleId = $itemId('content', $articleTitle);
	$check($articleId > 0 && $membership('com_content.item', 'content', $articleId) === ['fr-FR' => $articleTarget],
		'Article association API creation matches native persisted multilingual membership');
	$apply($http, 'api', 'content.articles.update', ['id' => $articleId, 'data' => ['associations' => (object) ['fr-FR' => $articleTarget]]]);
	$check($membership('com_content.item', 'content', $articleId) === ['fr-FR' => $articleTarget], 'Explicit article API association update matches independent native storage');
	$apply($http, 'api', 'content.articles.update', ['id' => $articleId, 'data' => ['title' => $articleTitle . ' renamed']]);
	$check($membership('com_content.item', 'content', $articleId) === ['fr-FR' => $articleTarget], 'An unrelated API article edit preserves the stored language group');
	$clear = $apply($http, 'api', 'content.articles.update', ['id' => $articleId, 'data' => ['associations' => (object) []]], true);
	$actual = $membership('com_content.item', 'content', $articleId);
	$check(($actual === [] && ($clear['verification']['status'] ?? '') === 'verified')
		|| ($actual === ['fr-FR' => $articleTarget] && ($clear['verification']['status'] ?? '') !== 'verified'),
		'Article empty-map outcome truthfully reflects whether the installed core actually cleared its association group');
	$pluginState(0, $plugin['params']);
	$singleTitle = $prefix . ' disabled menu';
	$apply($http, 'api', 'menus.site-items.create', ['data' => $menuData($singleTitle, '*')]);
	$singleId = $itemId('menu', $singleTitle);
	$check($singleId > 0, 'Single-language API menu creation remains available with associations disabled');
	$apply($http, 'api', 'menus.site-items.update', ['id' => $singleId, 'data' => ['title' => $singleTitle . ' renamed']]);
	$check($itemId('menu', $singleTitle . ' renamed') === $singleId, 'An ordinary API menu edit remains available with association support disabled');
}
finally
{
	if ($grantId !== null && isset($clients['api']))
	{
		$invoke($clients['api'], 'joomla_permission_revoke', ['grantId' => $grantId]);
	}
	foreach ($clients as $client)
	{
		$client->disconnect();
	}
	foreach (['menu' => ['com_menus', 'Item'], 'content' => ['com_content', 'Article']] as $table => [$component, $modelName])
	{
		$ids = array_map('intval', $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__' . $table))
			->where($db->quoteName('title') . ' LIKE ' . $db->quote($prefix . '%')))->loadColumn());
		if ($ids !== [])
		{
			$model = $models->administrator($component, $modelName);
			$model->setCurrentUser($admin);
			$check($model->publish($ids, -2) && $model->delete($ids), 'Remove owned ' . $table . ' association fixtures through native Joomla models');
		}
		$check((int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__' . $table))
			->where($db->quoteName('title') . ' LIKE ' . $db->quote($prefix . '%')))->loadResult() === 0, 'No owned association ' . $table . ' fixture remains');
	}
	if (isset($store))
	{
		foreach (array_keys($executions) as $uuid)
		{
			$execution = $store->one('execution', ['uuid' => $uuid]);
			if (($execution['status'] ?? '') === 'uncertain')
			{
				(new Operations($store, new Envelope($app->get('secret'))))->reconcile($admin, (int) $execution['id'], (int) $execution['version'], 'partial',
					'Owned multilingual fixtures were independently inspected and removed through native models. No ambiguous mutation was retried.', true);
			}
		}
	}
	if ($createdLanguages !== [])
	{
		$check($models->administrator('com_languages', 'Language')->delete($createdLanguages), 'Remove owned multilingual content languages');
	}
	$pluginState((int) $plugin['enabled'], $plugin['params']);
}
echo Json::encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(),
	'liveApi' => true, 'livePersistentCli' => true, 'nativeAssociationStorage' => true]) . PHP_EOL;
