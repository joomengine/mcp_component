<?php
/**
 * @package    JoomEngine.Mcp
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Read this component's local release metadata. */
function mcpReleaseXml(string $path): SimpleXMLElement
{
	$xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);

	if ($xml === false)
	{
		throw new RuntimeException('Invalid release XML: ' . $path);
	}

	return $xml;
}

/** Freeze the pending version or add its tag archive to the package update feed. */
function mcpRelease(array $arguments, string $root): void
{
	[$command, $version] = array_pad($arguments, 2, '');
	$version = preg_replace('/\Av/', '', $version);

	if (!in_array($command, ['prepare', 'feed'], true)
		|| preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) !== 1)
	{
		throw new RuntimeException('Usage: release.php prepare|feed VERSION');
	}

	$manifest = mcpReleaseXml($root . '/joomengine_mcp.xml');
	$writes = [];

	if ($command === 'prepare')
	{
		$changelog = mcpReleaseXml($root . '/changelog.xml');
		$pending = $changelog->xpath('changelog[version="[[[NEXT_VERSION]]]"]');
		$markdown = file_get_contents($root . '/CHANGELOG.md');

		if (version_compare($version, (string) $manifest->version, '<')
			|| count($changelog->xpath('changelog[version="' . $version . '"]')) !== 0
			|| count($pending) !== 1 || substr_count($markdown, '[[[NEXT_VERSION]]]') !== 1)
		{
			throw new RuntimeException('Choose an unreleased version at least as new as the manifest, with one pending section in both changelogs.');
		}

		$manifest->version = $version;
		$manifest->creationDate = gmdate('F Y');
		$writes['joomengine_mcp.xml'] = $manifest->asXML();
		$writes['changelog.xml'] = str_replace('[[[NEXT_VERSION]]]', $version, file_get_contents($root . '/changelog.xml'));
		$writes['CHANGELOG.md'] = str_replace('[[[NEXT_VERSION]]]', $version, $markdown);
	}
	else
	{
		$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml');
		$entry = $feed->update;
		$archive = 'https://github.com/joomengine/mcp_package/archive/refs/tags/v' . $version . '.zip';

		if (count($feed->update) !== 1 || (string) $entry->element !== 'pkg_joomengine_mcp'
			|| (string) $entry->type !== 'package' || version_compare($version, (string) $manifest->version, '>'))
		{
			throw new RuntimeException('Expected the current package update entry and a prepared component version.');
		}

		if ((string) $entry->version === $version && (string) $entry->downloads->downloadurl === $archive)
		{
			return;
		}

		$entry->version = $version;
		$entry->downloads->downloadurl = $archive;
		$entry->infourl = 'https://github.com/joomengine/mcp_package/tree/v' . $version;
		unset($entry->md5, $entry->sha256, $entry->sha384, $entry->sha512);
		$writes['joomengine_mcp_update_server.xml'] = $feed->asXML();
	}

	foreach ($writes as $path => $contents)
	{
		if (file_put_contents($root . '/' . $path, $contents) === false)
		{
			throw new RuntimeException('Cannot write release metadata: ' . $path);
		}
	}
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__)
{
	try
	{
		mcpRelease(array_slice($argv, 1), dirname(__DIR__));
		echo "Release metadata updated.\n";
	}
	catch (Throwable $error)
	{
		fwrite(STDERR, $error->getMessage() . "\n");
		exit(1);
	}
}
