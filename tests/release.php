<?php
/**
 * @package    JoomEngine.Mcp
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Exercise release transitions without network, publishing tags, or building packages. */
require dirname(__DIR__) . '/tools/release.php';
$directory = sys_get_temp_dir() . '/mcp-release-' . bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}

	$checks++;
};
$reject = static function (array $arguments, string $root) use ($check): void
{
	$before = [];

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file)
	{
		$before[$file->getPathname()] = hash_file('sha256', $file->getPathname());
	}

	$rejected = false;

	try
	{
		mcpRelease($arguments, $root);
	}
	catch (RuntimeException)
	{
		$rejected = true;
	}

	$check($rejected, 'Invalid release operation must fail.');

	foreach ($before as $path => $hash)
	{
		$check(hash_file('sha256', $path) === $hash, 'Rejected operation must not change existing metadata.');
	}
};

try
{
	foreach (['component', 'plugin'] as $type)
	{
		$root = $directory . '/' . $type;
		mkdir($root . '/plugins/webservices/joomengine_mcp', 0700, true);
		$element = $type === 'component' ? 'com_joomengine_mcp' : 'joomengine_mcp';
		$folder = $type === 'plugin' ? '<folder>console</folder>' : '';
		$changelogName = $type === 'component' ? 'changelog.xml' : 'joomengine_mcp_changelog.xml';
		$pending = '<changelog><element>' . $element . '</element><type>' . $type . '</type>' . $folder
			. '<version>[[[NEXT_VERSION]]]</version><fix><item>Preserve existing releases &amp; user changes.</item></fix></changelog>';
		file_put_contents($root . '/joomengine_mcp.xml', '<extension type="' . $type . '" group="console">'
			. '<version>1.0.0</version><creationDate>January 2026</creationDate>'
			. '<updateservers><server>https://example.invalid/old.xml</server></updateservers>'
			. '<changelogurl>https://example.invalid/old-changelog.xml</changelogurl></extension>');
		file_put_contents($root . '/plugins/webservices/joomengine_mcp/joomengine_mcp.xml',
			'<extension type="plugin" group="webservices"><version>1.0.0</version><creationDate>January 2026</creationDate></extension>');
		file_put_contents($root . '/.octojpack', '{"package":{"version":"1.0.0"},"repository":{"owner":"[[[PACKAGE_OWNER]]]"}}');
		file_put_contents($root . '/' . $changelogName, '<changelogs>' . $pending . '</changelogs>');
		file_put_contents($root . '/CHANGELOG.md', "# Changelog\n\n## [[[NEXT_VERSION]]]\n\n### Fixed\n\n- Preserve updates.\n");
		file_put_contents($root . '/joomengine_mcp_update_server.xml', '<updates/>');
		$repository = 'test-owner/' . $type;
		$branch = $type === 'component' ? 'stable/6.x' : 'stable/release&next#1';
		$encodedBranch = $type === 'component' ? 'stable/6.x' : 'stable/release%26next%231';

		foreach (['01.1.0', '1.0', '1.0.0-beta', '1.0.0;false', '0.9.0'] as $invalid)
		{
			$reject(['prepare', $invalid, $repository, $branch], $root);
		}

		$reject(['prepare', '1.1.0', 'invalid repository', $branch], $root);
		$reject(['prepare', '1.1.0', $repository, "main\ninjected"], $root);
		$initialFeed = file_get_contents($root . '/joomengine_mcp_update_server.xml');
		mcpRelease(['prepare', 'v1.1.0', $repository, $branch], $root);
		$manifest = mcpReleaseXml($root . '/joomengine_mcp.xml', 'extension');
		$check(mcpReleaseValue($manifest, '/extension/version') === '1.1.0', 'Release normalizes the v prefix.');
		$check(mcpReleaseValue($manifest, '/extension/creationDate') === gmdate('F Y'), 'Release refreshes the manifest date.');
		$check(mcpReleaseValue($manifest, '/extension/updateservers/server')
			=== 'https://raw.githubusercontent.com/' . $repository . '/' . $encodedBranch . '/joomengine_mcp_update_server.xml',
			'Repository and branch determine the live feed URL.');
		$check(!str_contains(file_get_contents($root . '/CHANGELOG.md'), '[[[NEXT_VERSION]]]')
			&& !str_contains(file_get_contents($root . '/' . $changelogName), '[[[NEXT_VERSION]]]'),
			'Both pending changelog sections are frozen into the release.');
		$check(file_get_contents($root . '/joomengine_mcp_update_server.xml') === $initialFeed,
			'Preparing a tag does not advertise its download before the tag exists.');
		mcpRelease(['verify-tag', '1.1.0', $repository, $branch], $root);
		$checks++;
		$reject(['verify-tag', '1.0.0', $repository, $branch], $root);
		$reject(['prepare', '1.1.0', $repository, $branch], $root);
		mcpRelease(['feed', '1.1.0', $repository, $branch], $root);
		$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml', 'updates');
		$check(mcpReleaseValue($feed, '/updates/update/downloads/downloadurl')
			=== 'https://github.com/' . $repository . '/archive/refs/tags/v1.1.0.zip', 'Update uses the immutable repository tag ZIP.');
		$check((new DOMXPath($feed))->query('/updates/update/sha512')->length === 0,
			'Only OctoShoom supplies the release checksum.');
		$check(mcpReleaseValue($feed, '/updates/update/' . ($type === 'component' ? 'client' : 'folder'))
			=== ($type === 'component' ? '1' : 'console'), 'Update preserves Joomla extension identity.');
		$archive = $root . '/archive.zip';
		file_put_contents($archive, 'Synthetic archive bytes for checksum comparison only.');
		$reject(['verify-hash', '1.1.0', $repository, $branch, $archive], $root);
		mcpReleaseAppend($feed->documentElement->firstChild, 'sha512', hash_file('sha512', $archive));
		$feed->save($root . '/joomengine_mcp_update_server.xml');
		mcpRelease(['verify-hash', '1.1.0', $repository, $branch, $archive], $root);
		$checks++;
		$hashedFeed = file_get_contents($root . '/joomengine_mcp_update_server.xml');
		mcpRelease(['feed', '1.1.0', $repository, $branch], $root);
		$check(file_get_contents($root . '/joomengine_mcp_update_server.xml') === $hashedFeed,
			'Retry preserves published feed bytes including the OctoShoom hash.');
		file_put_contents($archive, 'Tampered archive');
		$reject(['verify-hash', '1.1.0', $repository, $branch, $archive], $root);
		$reject(['feed', '1.1.0', 'different-owner/' . $type, $branch], $root);
		$reject(['feed', '2.0.0', $repository, $branch], $root);

		$changelog = file_get_contents($root . '/' . $changelogName);
		file_put_contents($root . '/' . $changelogName, str_replace('<changelogs>', '<changelogs>' . $pending, $changelog));
		$markdown = file_get_contents($root . '/CHANGELOG.md');
		file_put_contents($root . '/CHANGELOG.md', str_replace('# Changelog', "# Changelog\n\n## [[[NEXT_VERSION]]]\n\n- Next changes.", $markdown));
		mcpRelease(['prepare', '1.2.0', $repository, $branch], $root);
		mcpRelease(['feed', '1.2.0', $repository, $branch], $root);
		$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml', 'updates');
		$check((new DOMXPath($feed))->query('/updates/update')->length === 2, 'Next release retains previous update entries.');
		$check(strlen(mcpReleaseValue($feed, '/updates/update[version="1.1.0"]/sha512')) === 128,
			'Next release retains the previous immutable checksum.');
		$latestFeed = file_get_contents($root . '/joomengine_mcp_update_server.xml');
		mcpRelease(['feed', '1.1.0', $repository, $branch], $root);
		$check(file_get_contents($root . '/joomengine_mcp_update_server.xml') === $latestFeed,
			'Retrying an older published tag never rolls back a newer feed entry.');
		$duplicate = $feed->documentElement->lastChild->cloneNode(true);
		$feed->documentElement->appendChild($duplicate);
		$feed->save($root . '/joomengine_mcp_update_server.xml');
		$reject(['feed', '1.1.0', $repository, $branch], $root);
	}

	echo json_encode(['checks' => $checks, 'metadataTransitions' => 'passed',
		'publication' => 'not run; no network or package generation'], JSON_THROW_ON_ERROR) . "\n";
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
