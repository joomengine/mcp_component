<?php
/**
 * @package    JoomEngine.Mcp
 * @created    30 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Table\Asset;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;

require __DIR__ . '/bootstrap.php';
$base = (string) getenv('MCP_TEST_BASE_URL');

if (preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~D', $base) !== 1)
{
	throw new RuntimeException('Use an explicit loopback browser fixture.');
}

$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$factory = $app->bootComponent('com_joomengine_mcp')->getMVCFactory();
$store = new JoomlaStore($db);
$cookie = tempnam(sys_get_temp_dir(), 'mcp-browser-pages-');
chmod($cookie, 0600);
$marker = 'browserpages' . bin2hex(random_bytes(8));
$ids = [];
$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($name);
	}

	$checks++;
	echo 'PASS ' . $name . PHP_EOL;
};

/** Request the installed administrator application using its real session cookie. */
$request = static function (string $query = '', ?array $post = null) use ($base, $cookie): array
{
	$handle = curl_init($base . '/administrator/index.php' . $query);
	curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
		CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 30]);

	if ($post !== null)
	{
		curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	try
	{
		$html = curl_exec($handle);

		if (!is_string($html))
		{
			throw new RuntimeException('Browser pagination fixture did not receive a response.');
		}

		return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $html];
	}
	finally
	{
		curl_close($handle);
	}
};

/** Parse the generated HTML without making any external entity request. */
$document = static function (string $html): DOMXPath
{
	$previous = libxml_use_internal_errors(true);

	try
	{
		$dom = new DOMDocument();
		$dom->loadHTML($html, LIBXML_NONET);

		return new DOMXPath($dom);
	}
	finally
	{
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
	}
};

/** Read successful native form controls, preserving their bracketed HTTP names. */
$fields = static function (DOMXPath $xpath, DOMNode $form): array
{
	$values = [];

	foreach ($xpath->query('.//input[@name and not(@disabled)]', $form) as $input)
	{
		if (!in_array(strtolower($input->getAttribute('type')), ['checkbox', 'radio', 'submit', 'button'], true))
		{
			$values[$input->getAttribute('name')] = $input->getAttribute('value');
		}
	}

	foreach ($xpath->query('.//select[@name and not(@disabled)]', $form) as $select)
	{
		$option = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
		$values[$select->getAttribute('name')] = $option?->getAttribute('value') ?? '';
	}

	return $values;
};
$query = '?option=com_joomengine_mcp&view=providers';

/** Check server-rendered offsets and exact IDs independently of pagination labels. */
$page = static function (array $response, int $offset, int $limit, array $expected, string $name) use ($document, $fields, $check): array
{
	[$status, $html] = $response;
	$check($status === 200 && !str_contains($html, 'name="passwd"'), $name . ' real authenticated administrator response');
	$xpath = $document($html);
	$form = $xpath->query('//form[@id="adminForm"]')->item(0);
	$check($form instanceof DOMElement, $name . ' native adminForm rendered');
	$values = $fields($xpath, $form);
	$check(isset($values['filter[search]'], $values['list[limit]'], $values['list[fullordering]'], $values['limitstart']),
		$name . ' native nested filters, list options and pagination offset rendered');
	$check((int) $values['limitstart'] === $offset && (int) $values['list[limit]'] === $limit,
		$name . ' server-rendered offset ' . $offset . ' and page size ' . $limit);
	$actual = [];

	foreach ($xpath->query('//table[@id="definitionList"]/tbody//input[@name="cid[]"]') as $input)
	{
		$actual[] = (int) $input->getAttribute('value');
	}

	$check($actual === $expected, $name . ' exact persisted provider IDs match the requested page');

	return ['xpath' => $xpath, 'form' => $form, 'fields' => $values, 'ids' => $actual];
};

/** Submit the offset from a rendered native pagination button with every unchanged form filter/list option. */
$navigate = static function (array $current, string $button) use ($request, $query, $check): array
{
	$icons = ['Next' => 'icon-angle-right', 'Previous' => 'icon-angle-left', 'Start' => 'icon-angle-double-left', 'End' => 'icon-angle-double-right'];
	$selector = './/a[@onclick][.//span[contains(concat(" ", normalize-space(@class), " "), " ' . $icons[$button] . ' ")]]';
	$link = $current['xpath']->query($selector, $current['form'])->item(0);
	$check($link instanceof DOMElement && preg_match('/limitstart\.value\s*=\s*(\d+)/', $link->getAttribute('onclick'), $matches) === 1,
		'Native ' . $button . ' pagination control exposes its actual submission offset');
	$values = $current['fields'];
	$values['limitstart'] = $matches[1];

	return $request($query, $values);
};

try
{
	$check($admin->id > 0 && $admin->authorise('core.admin', 'com_joomengine_mcp'), 'Native browser pagination fixture administrator');

	for ($number = 1; $number <= 65; $number++)
	{
		$model = $factory->createModel('Provider', 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$data = ['id' => 0, 'name' => 'fixture.' . $marker . '.' . sprintf('%03d', $number),
			'title' => $marker . ($number <= 45 ? ' narrow ' : ' wide ') . sprintf('%03d', $number),
			'published' => 1, 'access' => 1, 'ordering' => 0, 'extension' => 'com_content',
			'description' => 'Disposable browser pagination fixture', 'definition' => '{}', 'params' => '{}', 'version' => 1];

		if (!$model->save($data))
		{
			throw new RuntimeException('Native provider pagination fixture creation failed: ' . (string) $model->getError());
		}

		$ids[] = (int) $model->getState('provider.id');
	}

	$check(count(array_unique($ids)) === 65 && min($ids) > 0, 'Create 65 isolated providers and their assets through native administrator models');
	[$status, $html] = $request();
	$check($status === 200 && str_contains($html, 'name="passwd"'), 'Native Joomla administrator login form');
	$login = [];

	foreach ($document($html)->query('//input[@type="hidden"]') as $input)
	{
		$login[$input->getAttribute('name')] = $input->getAttribute('value');
	}

	[$status, $html] = $request('', $login + ['username' => 'mcp_test_admin', 'passwd' => 'Disposable!McpFixture321']);
	$check($status === 200 && !str_contains($html, 'name="passwd"'), 'Native administrator session authentication for pagination');
	$initial = ['filter[search]' => $marker, 'list[limit]' => 20, 'list[fullordering]' => 'a.name ASC', 'limitstart' => 0];
	$first = $page($request($query . '&' . http_build_query($initial)), 0, 20, array_slice($ids, 0, 20), 'Initial GET page');
	$check(($first['fields']['filter[published]'] ?? null) === '' && ($first['fields']['filter[access]'] ?? null) === '',
		'Native browser pagination resubmits unchanged blank status and access filters');
	$second = $page($navigate($first, 'Next'), 20, 20, array_slice($ids, 20, 20), 'Next to page 2');
	$third = $page($navigate($second, 'Next'), 40, 20, array_slice($ids, 40, 20), 'Next to page 3');
	$check(array_intersect($first['ids'], $second['ids']) === [] && array_intersect($second['ids'], $third['ids']) === []
		&& array_intersect($first['ids'], $third['ids']) === [], 'Distinct provider records across native pagination submissions');
	$second = $page($navigate($third, 'Previous'), 20, 20, array_slice($ids, 20, 20), 'Previous to page 2');
	$first = $page($navigate($second, 'Start'), 0, 20, array_slice($ids, 0, 20), 'Start to page 1');
	$last = $page($navigate($first, 'End'), 60, 20, array_slice($ids, 60, 20), 'End to the partial final page');
	$third = $page($navigate($last, 'Previous'), 40, 20, array_slice($ids, 40, 20), 'Previous from final page');

	// Forty is still a valid offset for the 45-row subset: this proves a real filter reset, not out-of-range normalization.
	$changed = $third['fields'];
	$changed['filter[search]'] = $marker . ' narrow';
	$narrow = $page($request($query, $changed), 0, 20, array_slice($ids, 0, 20), 'Changed search resets the submitted offset 40');
	$check($narrow['fields']['filter[search]'] === $marker . ' narrow', 'Server persists the genuine changed nested search filter');
	$changed = $narrow['fields'];
	$changed['filter[search]'] = $marker;
	$changed['list[limit]'] = 10;
	$changed['limitstart'] = 0;
	$small = $page($request($query, $changed), 0, 10, array_slice($ids, 0, 10), 'Changed search and page size with explicit browser reset');
	$small = $page($navigate($small, 'Next'), 10, 10, array_slice($ids, 10, 10), 'Next with changed page size');
	$page($navigate($small, 'End'), 60, 10, array_slice($ids, 60, 10), 'End with changed page size');
}
finally
{
	try
	{
		if ($ids !== [])
		{
			$model = $factory->createModel('Provider', 'Administrator', ['ignore_request' => true]);
			$model->setCurrentUser($admin);
			$pks = $ids;
			$check($model->publish($pks, -2) && $model->delete($pks), 'Native cleanup deletes only this browser fixture providers and their assets');

			foreach ($ids as $id)
			{
				$asset = new Asset($db);

				if ($store->one('provider', ['id' => $id]) !== null || $asset->loadByName('com_joomengine_mcp.provider.' . $id))
				{
					throw new RuntimeException('Browser pagination fixture cleanup read-back failed.');
				}
			}

			$check(true, 'Browser pagination fixture cleanup database read-back');
		}
	}
	finally
	{
		unlink($cookie);
	}
}

echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'liveBrowser' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
