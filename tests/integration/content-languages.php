<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Handler\NativeFactory;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaModelProvider;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaNativeOperations;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/StdioFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$app->bootComponent('com_joomengine_mcp');
$models = new JoomlaModelProvider($app);
$factory = new NativeFactory($models, new JoomlaNativeOperations($app), $app);
$definitions = json_decode(file_get_contents(dirname(__DIR__, 2) . '/data/upstream-native.json'), true, 128, JSON_THROW_ON_ERROR)['actions'];
$binding = array_values(array_filter($definitions, static fn (array $definition): bool => $definition['descriptor']['name'] === 'languages.content.list'))[0];
$action = $factory->create($binding);
$orders = $action->descriptor()->inputSchema['properties']['order']['enum'];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
	echo 'PASS ' . $message . PHP_EOL;
};
$setup = [
	'contentLanguages' => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__languages'))->loadResult(),
	'languageExtensions' => $db->setQuery('SELECT element FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('language'))->loadColumn(),
	'languageFilterEnabled' => (int) $db->setQuery('SELECT enabled FROM ' . $db->quoteName('#__extensions') . ' WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('languagefilter'))->loadResult(),
];
$verify = static function (string $label) use ($action, $orders, $check): void
{
	$client = new StdioFixture();
	try
	{
		foreach (array_merge([null], $orders) as $order)
		{
			$input = ['offset' => 0, 'limit' => 10];
			if ($order !== null)
			{
				$input['order'] = $order;
			}
			$initial = $action->execute($input);
			$check($initial['page']['count'] === count($initial['items']) && $initial['page']['total'] >= count($initial['items']), $label . ' order ' . ($order ?? 'default') . ' succeeds through the native model');
			foreach ($initial['items'] as $item)
			{
				$check($item['id'] === $item['lang_id'], $label . ' compatibility id equals the physical language identifier');
			}
			foreach ([[0, 1], [1, 1], [$initial['page']['total'], 1], [$initial['page']['total'] + 2, 2], [0, 10]] as [$offset, $limit])
			{
				$pageInput = array_replace($input, ['offset' => $offset, 'limit' => $limit]);
				$page = $action->execute($pageInput);
				$check($page['items'] === array_slice($initial['items'], $offset, $limit)
					&& $page['page'] === ['offset' => $offset, 'limit' => $limit, 'count' => count($page['items']), 'total' => $initial['page']['total']], $label . ' language ordering preserves exact slices and end boundaries');
				$wire = $client->tool('joomla_action_read', ['action' => 'languages.content.list', 'transport' => 'cli', 'input' => $pageInput]);
				$check($wire['response']['data'] === $page, $label . ' independent persisted stdio client agrees with native language records');
			}
		}
		$emptyInput = ['offset' => 0, 'limit' => 2, 'search' => 'mcp-language-no-match-' . bin2hex(random_bytes(6)), 'order' => 'id'];
		$empty = $action->execute($emptyInput);
		$check($empty['items'] === [] && $empty['page']['total'] === 0, $label . ' empty content-language search succeeds');
	}
	finally
	{
		$client->disconnect();
	}
};
$created = [];
$initial = $action->execute(['offset' => 0, 'limit' => 100]);
$existingCodes = array_column($initial['items'], 'lang_code');
try
{
	$verify('fresh installation');
	foreach (['fr-FR' => ['French', 'Français', 'fr_fr'], 'de-DE' => ['German', 'Deutsch', 'de_de']] as $code => [$title, $native, $image])
	{
		if (in_array($code, $existingCodes, true))
		{
			continue;
		}
		$model = $models->administrator('com_languages', 'Language');
		$check($model->save(['lang_code' => $code, 'title' => $title, 'title_native' => $native, 'sef' => strtolower(substr($code, 0, 2)), 'image' => $image,
			'description' => '', 'metadesc' => '', 'sitename' => '', 'published' => 1, 'access' => 1, 'ordering' => count($created) + 2]), 'Create deliberate additional content language through Joomla LanguageModel');
		$id = (int) $db->setQuery('SELECT lang_id FROM ' . $db->quoteName('#__languages') . ' WHERE lang_code = ' . $db->quote($code))->loadResult();
		$check($id > 0, 'Native language save persisted the controlled multilingual fixture');
		$created[] = $id;
	}
	$check($action->execute(['limit' => 100])['page']['total'] >= 3, 'Deliberate content-language fixture contains multiple native records');
	$verify('multiple configured content languages');
}
finally
{
	if ($created !== [])
	{
		$check($models->administrator('com_languages', 'Language')->delete($created), 'Clean up only the deliberately created content languages');
	}
}
$check($action->execute(['limit' => 100]) === $initial, 'Content-language fixture cleanup restores the original dataset');
echo json_encode(['passed' => $checks, 'joomla' => JVERSION, 'initialSetup' => $setup, 'liveJoomla' => true], JSON_PRETTY_PRINT) . PHP_EOL;
