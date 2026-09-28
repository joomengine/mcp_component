<?php
/**
 * @package    JoomEngine.Mcp
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Exercise component release metadata without publishing or building packages. */
require dirname(__DIR__) . '/tools/release.php';
$root = sys_get_temp_dir() . '/mcp-release-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}

	$checks++;
};

try
{
	$pending = '<changelog><element>com_joomengine_mcp</element><type>component</type>'
		. '<version>[[[NEXT_VERSION]]]</version><fix><item>Preserve existing releases.</item></fix></changelog>';
	$metadata = [
		'joomengine_mcp.xml' => '<extension type="component"><version>1.0.0</version><creationDate>January 2026</creationDate></extension>',
		'.octojpack' => '{"package":{"version_id":"com_joomengine_mcp"}}',
		'changelog.xml' => '<changelogs>' . $pending . '</changelogs>',
		'CHANGELOG.md' => "# Changelog\n\n## [[[NEXT_VERSION]]]\n\n### Fix\n\n- Preserve updates.\n",
		'joomengine_mcp_update_server.xml' => file_get_contents(dirname(__DIR__) . '/joomengine_mcp_update_server.xml'),
	];

	foreach ($metadata as $path => $contents)
	{
		file_put_contents($root . '/' . $path, $contents);
	}

	foreach (['01.1.0', '1.0', '1.0.0-beta', '1.0.0;false', '0.9.0'] as $invalid)
	{
		$rejected = false;

		try
		{
			mcpRelease(['prepare', $invalid], $root);
		}
		catch (RuntimeException)
		{
			$rejected = true;
		}

		$check($rejected, 'Invalid or older versions must fail.');
	}

	foreach ($metadata as $path => $contents)
	{
		$check(file_get_contents($root . '/' . $path) === $contents, 'Rejected releases must leave metadata unchanged.');
	}

	mcpRelease(['prepare', 'v1.1.0'], $root);
	$manifest = mcpReleaseXml($root . '/joomengine_mcp.xml');
	$check((string) $manifest->version === '1.1.0', 'Component receives the selected version.');
	$check((string) $manifest->creationDate === gmdate('F Y'), 'Release refreshes the manifest date.');
	$check((string) mcpReleaseXml($root . '/changelog.xml')->changelog->version === '1.1.0'
		&& str_contains(file_get_contents($root . '/CHANGELOG.md'), '## 1.1.0'), 'Both changelogs freeze the pending version.');
	$check(file_get_contents($root . '/.octojpack') === $metadata['.octojpack'], 'Package configuration stays independent of release metadata.');
	$check(file_get_contents($root . '/joomengine_mcp_update_server.xml') === $metadata['joomengine_mcp_update_server.xml'],
		'Preparing a tag does not advertise its download before the tag exists.');
	mcpRelease(['feed', '1.1.0'], $root);
	$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml');
	$check((string) $feed->update->downloads->downloadurl === 'https://github.com/joomengine/mcp_package/archive/refs/tags/v1.1.0.zip',
		'Update uses the immutable package tag ZIP.');
	$check((string) $feed->update->client === 'site' && (string) $feed->update->element === 'pkg_joomengine_mcp' && (string) $feed->update->type === 'package' && (string) $feed->update->php_minimum === '8.3.0'
		&& (string) $feed->update->targetplatform['version'] === '6\\.[1-9][0-9]*', 'Update carries supported Joomla/PHP versions.');
	$check(!isset($feed->update->sha512), 'OctoShoom supplies the checksum.');
	$feed->update->addChild('sha512', str_repeat('a', 128));
	$feed->asXML($root . '/joomengine_mcp_update_server.xml');
	$hashedFeed = file_get_contents($root . '/joomengine_mcp_update_server.xml');
	mcpRelease(['feed', '1.1.0'], $root);
	$check(file_get_contents($root . '/joomengine_mcp_update_server.xml') === $hashedFeed, 'Existing feed entries and hashes stay unchanged.');

	$history = file_get_contents($root . '/changelog.xml');
	file_put_contents($root . '/changelog.xml', str_replace('<changelogs>', '<changelogs>' . $pending, $history));
	file_put_contents($root . '/CHANGELOG.md', $metadata['CHANGELOG.md'] . "\n## 1.1.0\n\n- Preserve updates.\n");
	mcpRelease(['prepare', '1.2.0'], $root);
	mcpRelease(['feed', '1.2.0'], $root);
	$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml');
	$check(count($feed->update) === 1 && (string) $feed->update->version === '1.2.0' && !isset($feed->update->sha512),
		'Next package release replaces the current feed entry and clears the previous archive checksum.');
	$check((string) $feed->update->downloads->downloadurl === 'https://github.com/joomengine/mcp_package/archive/refs/tags/v1.2.0.zip', 'The next download stays in the package repository.');
	$check((string) mcpReleaseXml($root . '/changelog.xml')->changelog[1]->version === '1.1.0', 'Next release retains the previous changelog.');

	echo json_encode(['checks' => $checks, 'metadataTransitions' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
}
finally
{
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST) as $file)
	{
		$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}

	rmdir($root);
}
