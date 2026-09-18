<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

require __DIR__ . '/bootstrap.php';
$base = (string) getenv('MCP_TEST_BASE_URL');
if (preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~D', $base) !== 1)
{
	throw new RuntimeException('Use an explicit loopback browser fixture.');
}
$cookie = tempnam(sys_get_temp_dir(), 'mcp-browser-');
chmod($cookie, 0600);
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
/** Perform an administrator browser request only against this loopback fixture. */
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
			throw new RuntimeException('Browser fixture did not receive a response.');
		}
		return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $html];
	}
	finally
	{
		curl_close($handle);
	}
};
/** Read native hidden input fields using the actual generated administrator form. */
$inputs = static function (string $html): array
{
	$previous = libxml_use_internal_errors(true);
	try
	{
		$document = new DOMDocument();
		$document->loadHTML($html, LIBXML_NONET);
		$values = [];
		foreach ((new DOMXPath($document))->query('//input[@type="hidden"]') as $input)
		{
			$values[$input->getAttribute('name')] = $input->getAttribute('value');
		}
		return $values;
	}
	finally
	{
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
	}
};
try
{
	[$status, $html] = $request();
	$check($status === 200 && str_contains($html, 'name="passwd"'), 'Native Joomla administrator login form');
	$hidden = $inputs($html);
	[$status, $html] = $request('', $hidden + ['username' => 'mcp_test_admin', 'passwd' => 'Disposable!McpFixture321']);
	$check($status === 200 && !str_contains($html, 'name="passwd"'), 'Native administrator session authentication');
	foreach (['provider', 'schema', 'action', 'binding', 'tool', 'resource', 'prompt', 'target'] as $entity)
	{
		[$status, $html] = $request('?option=com_joomengine_mcp&view=' . $entity . 's');
		$check($status === 200 && str_contains($html, 'id="adminForm"') && str_contains($html, 'name="boxchecked"'), 'Rendered native ' . $entity . ' list');
		[$status, $html] = $request('?option=com_joomengine_mcp&task=' . $entity . '.add');
		$check($status === 200 && str_contains($html, 'name="jform[name]"') && str_contains($html, 'name="jform[version]"'), 'Rendered native ' . $entity . ' XML edit form');
		$hidden = $inputs($html);
		$token = array_filter($hidden, static fn (string $value, string $key): bool => preg_match('/\A[0-9a-f]{32}\z/D', $key) === 1 && $value === '1', ARRAY_FILTER_USE_BOTH);
		[$status] = $request('?option=com_joomengine_mcp', ['task' => $entity . '.cancel', 'jform' => ['id' => 0]] + $token);
		$check($status === 200, 'Native cancel ' . $entity);
	}
	foreach (['audit', 'grant', 'execution'] as $kind)
	{
		[$status, $html] = $request('?option=com_joomengine_mcp&view=operations&kind=' . $kind);
		$check($status === 200 && !str_contains($html, 'name="passwd"'), 'Rendered native operations ' . $kind);
	}
	[$status, $html] = $request('?option=com_config&view=component&component=com_joomengine_mcp');
	$check($status === 200 && str_contains($html, 'api_base'), 'Native component configuration and ACL form');
	[$status, $html] = $request('?option=com_joomengine_mcp', ['task' => 'provider.save', 'jform' => ['id' => 0, 'title' => 'No CSRF fixture']]);
	$db = $container->get(\Joomla\Database\DatabaseInterface::class);
	$rows = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__joomengine_mcp_provider'))
		->where($db->quoteName('title') . ' = ' . $db->quote('No CSRF fixture')))->loadResult();
	$check($rows === 0 && (in_array($status, [303, 403], true) || ($status === 200 && stripos($html, 'token') !== false)),
		'Native CSRF rejection leaves persisted catalogue unchanged');
}
finally
{
	unlink($cookie);
}
echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'liveBrowser' => true], JSON_THROW_ON_ERROR) . "\n";
