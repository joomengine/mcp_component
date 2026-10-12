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

/** Freeze the pending version or add its tag archive to the component update feed. */
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
			|| preg_match('/^## ' . preg_quote($version, '/') . '(?:\s|$)/m', $markdown) === 1)
		{
			throw new RuntimeException('Choose an unreleased version at least as new as the manifest.');
		}

		$xmlContents = file_get_contents($root . '/changelog.xml');
		$xmlMarkers = substr_count($xmlContents, '[[[NEXT_VERSION]]]');
		$markdownMarkers = substr_count($markdown, '[[[NEXT_VERSION]]]');

		if ($xmlMarkers === 0 && $markdownMarkers === 0)
		{
			$note = 'Maintenance release with no additional changelog entries.';
			$xmlContents = preg_replace('/(<changelogs\b[^>]*>)/', '$1' . "\n  <changelog>\n"
				. "    <element>com_joomengine_mcp</element>\n    <type>component</type>\n"
				. '    <version>' . $version . "</version>\n    <note><item>" . $note
				. "</item></note>\n  </changelog>", $xmlContents, 1);
			$markdown = preg_replace('/\A(# Changelog[^\r\n]*\r?\n)/', '$1' . "\n## " . $version
				. "\n\n### Note\n\n- " . $note . "\n", $markdown, 1, $insertions);

			if ($changelog->getName() !== 'changelogs' || $insertions !== 1)
			{
				throw new RuntimeException('Expected the native XML changelogs root and Markdown Changelog heading.');
			}
		}
		elseif (count($pending) !== 1 || $xmlMarkers !== 1 || $markdownMarkers !== 1
			|| preg_match('/^## \[\[\[NEXT_VERSION\]\]\]\s*$/m', $markdown) !== 1)
		{
			throw new RuntimeException('Pending changes require one version section in both changelogs.');
		}

		$manifest->version = $version;
		$manifest->creationDate = gmdate('F Y');
		$writes['joomengine_mcp.xml'] = $manifest->asXML();
		$writes['changelog.xml'] = str_replace('[[[NEXT_VERSION]]]', $version, $xmlContents);
		$writes['CHANGELOG.md'] = str_replace('[[[NEXT_VERSION]]]', $version, $markdown);
	}
	else
	{
		$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml');
		$entry = $feed->update;
		$archive = 'https://github.com/joomengine/mcp_component/archive/refs/tags/v' . $version . '.zip';

		if (count($feed->update) !== 1 || (string) $entry->element !== 'com_joomengine_mcp'
			|| (string) $entry->type !== 'component' || (string) $entry->client !== 'administrator'
			|| version_compare($version, (string) $manifest->version, '>'))
		{
			throw new RuntimeException('Expected the current component update entry and a prepared component version.');
		}

		if ((string) $entry->version === $version && (string) $entry->downloads->downloadurl === $archive)
		{
			return;
		}

		$entry->version = $version;
		$entry->downloads->downloadurl = $archive;
		$entry->infourl = 'https://github.com/joomengine/mcp_component/tree/v' . $version;
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
