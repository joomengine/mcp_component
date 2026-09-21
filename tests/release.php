<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Exercise release validation with actual ZIP bytes and explicit synthetic metadata. */
$root = dirname(__DIR__);
$version = (string) simplexml_load_file($root . '/joomengine_mcp.xml')->version;
$directory = $root . '/build/release-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$base = 'https://github.com/joomengine/mcp_component/releases/';
$release = ['tag_name' => 'v' . $version, 'draft' => false, 'prerelease' => false,
	'html_url' => $base . 'tag/v' . $version, 'published_at' => '2026-09-21T00:00:00Z', 'assets' => []];
$checks = 0;

foreach (['com_joomengine_mcp', 'pkg_joomengine_mcp'] as $element)
{
	foreach (['.zip', '.zip.sha256'] as $suffix)
	{
		$name = $element . '-' . $version . $suffix;
		if (!is_file($root . '/build/' . $name) || !copy($root . '/build/' . $name, $directory . '/' . $name))
		{
			throw new RuntimeException('Build the complete distribution before release validation.');
		}
		$release['assets'][] = ['name' => $name, 'state' => 'uploaded', 'size' => filesize($directory . '/' . $name),
			'browser_download_url' => $base . 'download/v' . $version . '/' . $name,
			'digest' => 'sha256:' . hash_file('sha256', $directory . '/' . $name)];
	}
}

$run = static function (array $metadata, bool $success) use ($root, $directory, &$checks): void
{
	file_put_contents($directory . '/release.json', json_encode($metadata, JSON_THROW_ON_ERROR));
	$arguments = [PHP_BINARY];

	if (is_string(php_ini_loaded_file()))
	{
		$arguments = array_merge($arguments, ['-c', php_ini_loaded_file()]);
	}

	$arguments = array_merge($arguments, ['-d', 'extension_dir=' . ini_get('extension_dir'),
		$root . '/tools/update-feeds.php', $directory . '/release.json', $directory, $directory . '/feeds']);
	$process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'],
		1 => ['file', $directory . '/stdout.log', 'w'], 2 => ['file', $directory . '/stderr.log', 'w']], $pipes);

	if (!is_resource($process) || (proc_close($process) === 0) !== $success)
	{
		throw new RuntimeException('Release publication validation returned the wrong result: '
			. file_get_contents($directory . '/stderr.log'));
	}

	$checks++;
};

try
{
	$run($release, true);

	foreach (['joomengine_mcp_update_server.xml' => 'component', 'pkg_joomengine_mcp_update_server.xml' => 'package'] as $name => $type)
	{
		$feed = simplexml_load_file($directory . '/feeds/' . $name, SimpleXMLElement::class, LIBXML_NONET);
		$archive = $directory . '/' . (string) $feed->update->element . '-' . $version . '.zip';

		if ((string) $feed->update->type !== $type || (string) $feed->update->version !== $version
			|| (string) $feed->update->sha256 !== hash_file('sha256', $archive))
		{
			throw new RuntimeException('The verified update feed does not describe the real archive.');
		}
		$checks++;
	}

	foreach (['draft' => true, 'prerelease' => true, 'tag_name' => 'v0.0.0',
		'published_at' => null, 'html_url' => 'https://example.invalid/release'] as $key => $value)
	{
		$invalid = $release;
		$invalid[$key] = $value;
		$run($invalid, false);
	}

	foreach (['state' => 'new', 'size' => 0, 'browser_download_url' => 'https://example.invalid/archive.zip',
		'digest' => 'sha256:' . str_repeat('0', 64)] as $key => $value)
	{
		$invalid = $release;
		$invalid['assets'][0][$key] = $value;
		$run($invalid, false);
	}

	$invalid = $release;
	array_pop($invalid['assets']);
	$run($invalid, false);
	file_put_contents($directory . '/com_joomengine_mcp-' . $version . '.zip', 'tampered archive');
	$run($release, false);

	echo json_encode(['checks' => $checks, 'metadata' => 'synthetic; no release published',
		'archives' => 'actual built distribution ZIPs'], JSON_THROW_ON_ERROR) . "\n";
}
finally
{
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST) as $file)
	{
		$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}
	rmdir($directory);
}
