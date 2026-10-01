<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;

require __DIR__ . '/bootstrap.php';
$base = (string) getenv('MCP_TEST_BASE_URL');

if (preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~D', $base) !== 1)
{
	throw new RuntimeException('Use an explicit loopback browser fixture.');
}

$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$app->bootComponent('com_joomengine_mcp');
$store = new JoomlaStore($db);
$cookie = tempnam(sys_get_temp_dir(), 'mcp-catalogue-limits-');
chmod($cookie, 0600);
$marker = 'cataloguelimits' . bin2hex(random_bytes(8));
$definitions = array_keys(Structure::definitions());
$limits = [5, 10, 15, 20, 25, 30, 50, 100, 200, 500];
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

/** Request the real installed administrator form using a private fixture session. */
$request = static function (string $query = '', ?array $post = null) use ($base, $cookie): string
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

		if (!is_string($html) || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200)
		{
			throw new RuntimeException('Administrator catalogue page-limit HTTP request failed.');
		}

		return $html;
	}
	finally
	{
		curl_close($handle);
	}
};

/** Parse HTML without requesting external entities. */
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

/** Read successful controls to submit the actual rendered native form. */
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

/** Verify the selected label, bounded options, persisted row IDs, total and offset. */
$page = static function (string $html, array $all, int $offset, int $limit, string $label) use ($document, $fields, $limits, $check): array
{
	$check(!str_contains($html, 'name="passwd"'), $label . ' authenticated administrator response');
	$xpath = $document($html);
	$form = $xpath->query('//form[@id="adminForm"]')->item(0);
	$check($form instanceof DOMElement, $label . ' native form');
	$values = $fields($xpath, $form);
	$options = [];
	$selected = [];

	foreach ($xpath->query('.//select[@name="list[limit]"]/option', $form) as $option)
	{
		$value = (int) $option->getAttribute('value');
		$options[] = $value;
		$check(trim($option->textContent) === (string) $value, $label . ' truthful numeric option ' . $value);

		if ($option->hasAttribute('selected'))
		{
			$selected[] = $value;
		}
	}

	$check($options === $limits && $selected === [$limit], $label . ' explicit bounded options and exactly one effective selection');
	$check((int) ($values['list[limit]'] ?? -1) === $limit && (int) ($values['limitstart'] ?? -1) === $offset,
		$label . ' submitted selection and offset match the effective page');
	$actual = [];

	foreach ($xpath->query('//table[@id="definitionList"]/tbody//input[@name="cid[]"]') as $input)
	{
		$actual[] = (int) $input->getAttribute('value');
	}

	$check($actual === array_slice($all, $offset, $limit), $label . ' exact persisted row IDs');
	$counter = $xpath->query('.//nav[contains(concat(" ", normalize-space(@class), " "), " pagination__wrapper ")]/div[1]', $form)->item(0);
	$check($counter instanceof DOMElement
		&& preg_match('/([0-9]+)\s*-\s*([0-9]+)\s*\/\s*([0-9]+)/', $counter->textContent, $matches) === 1
		&& (int) $matches[3] === count($all) && (int) $matches[2] === min($offset + $limit, count($all)),
		$label . ' rendered filtered total and last row');

	return ['xpath' => $xpath, 'form' => $form, 'fields' => $values, 'ids' => $actual];
};

/** Follow the offset actually supplied by a native pagination control. */
$navigate = static function (string $query, array $current, string $button) use ($request, $check): string
{
	$icons = ['Next' => 'icon-angle-right', 'Previous' => 'icon-angle-left', 'Start' => 'icon-angle-double-left', 'End' => 'icon-angle-double-right'];
	$link = $current['xpath']->query('.//a[@onclick][.//span[contains(concat(" ", normalize-space(@class), " "), " '
		. $icons[$button] . ' ")]]', $current['form'])->item(0);
	$check($link instanceof DOMElement && preg_match('/limitstart\.value\s*=\s*(\d+)/', $link->getAttribute('onclick'), $matches) === 1,
		'Native ' . $button . ' control exposes its submitted offset');

	return $request($query, array_replace($current['fields'], ['limitstart' => $matches[1]]));
};

try
{
	$check($admin->id > 0 && $admin->authorise('core.admin', 'com_joomengine_mcp'), 'Catalogue page-limit administrator identity');

	// Owned inert rows cover every catalogue type, even when no seed is installed.
	// No handler, action, native mutation or worker is dispatched by these definitions.
	foreach ($definitions as $entity)
	{
		$template = [];

		foreach (Structure::columns($entity) as $name => $type)
		{
			if ($name === 'id')
			{
				continue;
			}

			$template[$name] = match (true)
			{
				$type === 'ref:provider' => $ids['provider'][0],
				$type === 'ref:schema' => $ids['schema'][0],
				$type === 'ref:action' => $ids['action'][0],
				str_starts_with($type, 'optional:') || in_array($type, ['date', 'nullable_int'], true) => null,
				in_array($type, ['int', 'published', 'access'], true) => 0,
				$type === 'version' => 1,
				$type === 'json' => '{}',
				default => '',
			};
		}

		for ($index = 0; $index < 521; $index++)
		{
			$row = array_replace($template, ['name' => $marker . '.' . $entity . '.' . sprintf('%03d', $index),
				'title' => $marker . ($index < 45 ? ' narrow ' : ' wide ') . sprintf('%03d', $index),
				'published' => $index < 5 ? 0 : 1, 'access' => $index < 5 ? 2 : 1, 'customized' => 1]);

			if ($entity !== 'provider')
			{
				$row['provider_id'] = $ids['provider'][$index < 5 ? 1 : 0];
			}

			if ($entity === 'resource')
			{
				$row['uri'] = 'joomla://catalogue-limits/' . $marker . '/' . $index;
			}

			$ids[$entity][] = $store->insert($entity, $row);
		}

		$check(count(array_unique($ids[$entity])) === 521, 'Create 521 owned ' . $entity . ' page-limit rows');
	}

	$login = [];

	foreach ($document($request())->query('//input[@type="hidden"]') as $input)
	{
		$login[$input->getAttribute('name')] = $input->getAttribute('value');
	}

	$html = $request('', $login + ['username' => 'mcp_test_admin', 'passwd' => 'Disposable!McpFixture321']);
	$check(!str_contains($html, 'name="passwd"'), 'Real administrator session authentication for page limits');

	foreach ($definitions as $entity)
	{
		$query = '?option=com_joomengine_mcp&view=' . $entity . 's';
		$initial = ['filter[search]' => $marker, 'filter[published]' => '', 'filter[access]' => '',
			'filter[provider_id]' => '', 'list[limit]' => 20, 'list[fullordering]' => 'a.id ASC', 'limitstart' => 0];
		$current = $page($request($query . '&' . http_build_query($initial)), $ids[$entity], 0, 20, $entity . ' initial page');

		foreach ($limits as $limit)
		{
			$current = $page($request($query, array_replace($current['fields'], ['list[limit]' => $limit, 'limitstart' => 0])),
				$ids[$entity], 0, $limit, $entity . ' submitted option ' . $limit);
		}

		$last = $page($navigate($query, $current, 'Next'), $ids[$entity], 500, 500, $entity . ' maximum next page');
		$current = $page($navigate($query, $last, 'Previous'), $ids[$entity], 0, 500, $entity . ' maximum previous page');
		$page($navigate($query, $current, 'End'), $ids[$entity], 500, 500, $entity . ' maximum end page');

		foreach ([0 => 500, 750 => 500, 37 => 30, -5 => 5] as $requested => $effective)
		{
			$current = $page($request($query, array_replace($current['fields'], ['list[limit]' => $requested, 'limitstart' => 0])),
				$ids[$entity], 0, $effective, $entity . ' normalized request ' . $requested);
			$current = $page($request($query), $ids[$entity], 0, $effective, $entity . ' retained normalized request ' . $requested);
		}

		$current = $page($request($query, array_replace($current['fields'], ['list[limit]' => 750, 'limitstart' => 750])),
			$ids[$entity], 500, 500, $entity . ' oversized offset aligns to the effective maximum');
		$current = $page($request($query, array_replace($current['fields'], ['list[limit]' => 0])),
			$ids[$entity], 0, 500, $entity . ' legacy All resets an existing page to the bounded first page');

		// Every filter runs against the explicit maximum and resets a stale valid offset.
		$filterCases = ['filter[published]' => '1', 'filter[access]' => '1'];

		if ($entity !== 'provider')
		{
			$filterCases['filter[provider_id]'] = (string) $ids['provider'][0];
		}

		foreach ($filterCases as $name => $value)
		{
			$current = $page($request($query, array_replace($current['fields'], $initial, ['list[limit]' => 500])),
				$ids[$entity], 0, 500, $entity . ' reset for ' . $name);
			$current = $page($navigate($query, $current, 'Next'), $ids[$entity], 500, 500, $entity . ' stale page for ' . $name);
			$subset = array_slice($ids[$entity], 5);
			$current = $page($request($query, array_replace($current['fields'], [$name => $value])),
				$subset, 0, 500, $entity . ' changed ' . $name);
			$page($navigate($query, $current, 'Next'), $subset, 500, 500, $entity . ' unchanged ' . $name);
		}

		foreach ([$marker . ' missing' => [], 'id:' . $ids[$entity][25] => [$ids[$entity][25]],
			$marker . ' narrow' => array_slice($ids[$entity], 0, 45)] as $search => $subset)
		{
			$current = $page($request($query, array_replace($current['fields'], $initial,
				['filter[search]' => $search, 'list[limit]' => 500, 'limitstart' => 500])),
				$subset, 0, 500, $entity . ' maximum filtered total ' . count($subset));
		}

		$subset = array_slice($ids[$entity], 0, 45);
		$current = $page($request($query, array_replace($current['fields'], ['list[limit]' => 20, 'limitstart' => 0])),
			$subset, 0, 20, $entity . ' filtered first page');
		$current = $page($navigate($query, $current, 'Next'), $subset, 20, 20, $entity . ' filtered next page');
		$current = $page($navigate($query, $current, 'End'), $subset, 40, 20, $entity . ' filtered end page');
		$current = $page($navigate($query, $current, 'Previous'), $subset, 20, 20, $entity . ' filtered previous page');
		$page($navigate($query, $current, 'Start'), $subset, 0, 20, $entity . ' filtered start page');
	}
}
finally
{
	try
	{
		foreach (array_reverse($definitions) as $entity)
		{
			if (isset($ids[$entity]))
			{
				$store->remove($entity, ['id' => ['in', $ids[$entity]]]);
				$check($store->find($entity, ['id' => ['in', $ids[$entity]]], 1) === [], 'Read back cleanup of owned ' . $entity . ' page-limit rows');
			}
		}
	}
	finally
	{
		unlink($cookie);
	}
}

echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(),
	'liveAdministratorHttp' => true, 'javascriptInteraction' => false, 'definitionViews' => count($definitions)], JSON_THROW_ON_ERROR) . PHP_EOL;
