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
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
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
$store = new JoomlaStore($db);
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
$verifyPage = static function (array $page, int $offset, int $limit, string $label) use ($check): void
{
	$metadata = $page['page'];
	$check($metadata['offset'] === $offset && $metadata['limit'] === $limit
		&& $metadata['count'] === count($page['items']) && $metadata['count'] <= $limit
		&& $metadata['count'] <= max(0, $metadata['total'] - $offset), $label . ' consistent zero-based page metadata');
};
$actions = [
	'banners.categories.list', 'contacts.categories.list', 'content.categories.list',
	'extensions.installed.list', 'extensions.list', 'extensions.update-sites.list',
	'menus.site-items.list', 'menus.site.list', 'modules.administrator.list', 'modules.site.list',
	'newsfeeds.categories.list', 'templates.administrator-styles.list', 'templates.site-styles.list',
];
$client = null;
try
{
	$client = new StdioFixture();
	foreach ($actions as $name)
	{
		// Compare both paths under the installed contract, including reviewed
		// runtime additions. The immutable migration snapshot intentionally
		// predates those fields and is not the installed action definition.
		$binding = $store->one('binding', ['name' => $name . '.cli']);
		$check($binding !== null && $binding['track'] === 'cli' && (int) $binding['published'] === 1,
			$name . ' has an enabled installed native binding');
		$binding['configuration'] = json_decode($binding['configuration'], true, 128, JSON_THROW_ON_ERROR);
		$action = $factory->create($binding);
		$initial = $action->execute(['offset' => 0, 'limit' => 5]);
		$verifyPage($initial, 0, 5, $name . ' native initial');
		if (in_array($name, ['modules.site.list', 'modules.administrator.list'], true))
		{
			$check($initial['items'] !== [], $name . ' has native module fixtures to verify');
			foreach ($initial['items'] as $item)
			{
				$check(array_key_exists('publish_up', $item) && array_key_exists('publish_down', $item),
					$name . ' exposes both native publishing dates');
			}
		}
		$total = $initial['page']['total'];
		foreach ([[0, 1], [1, 1], [max(0, $total - 1), 3], [$total, 1], [$total + 3, 2], [0, 5]] as [$offset, $limit])
		{
			$page = $action->execute(['offset' => $offset, 'limit' => $limit]);
			$verifyPage($page, $offset, $limit, $name . ' native offset ' . $offset);
			$check($page['page']['total'] === $total, $name . ' unchanged native total');
			if ($offset + $limit <= count($initial['items']) || $offset === 0)
			{
				$check($page['items'] === array_slice($initial['items'], $offset, $limit), $name . ' native records match the requested initial-page slice');
			}
			$wire = $client->tool('joomla_action_read', ['action' => $name, 'transport' => 'cli', 'input' => ['offset' => $offset, 'limit' => $limit]]);
			$wire = $wire['response']['data'];
			$verifyPage($wire, $offset, $limit, $name . ' persisted stdio offset ' . $offset);
			$check($wire === $page, $name . ' independent MCP client agrees with native adapter');
		}
		$emptyInput = ['offset' => 0, 'limit' => 2, 'search' => 'mcp-no-match-' . bin2hex(random_bytes(8))];
		$empty = $action->execute($emptyInput);
		$check($empty['items'] === [] && $empty['page']['total'] === 0, $name . ' empty filtered dataset is a valid successful page');
	}
}
finally
{
	$client?->disconnect();
}

echo json_encode(['passed' => $checks, 'joomla' => JVERSION, 'nativeActions' => count($actions), 'liveJoomla' => true], JSON_PRETTY_PRINT) . PHP_EOL;
