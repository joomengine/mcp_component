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
			|| count($pending) !== 1 || substr_count($markdown, '[[[NEXT_VERSION]]]') !== 1)
		{
			throw new RuntimeException('Choose an unreleased version at least as new as the manifest, with one pending section in both changelogs.');
		}

		$routingPath = 'plugins/webservices/joomengine_mcp/joomengine_mcp.xml';
		$routing = mcpReleaseXml($root . '/' . $routingPath);
		$manifest->version = $routing->version = $version;
		$manifest->creationDate = $routing->creationDate = gmdate('F Y');
		$writes['joomengine_mcp.xml'] = $manifest->asXML();
		$writes[$routingPath] = $routing->asXML();
		$writes['changelog.xml'] = str_replace('[[[NEXT_VERSION]]]', $version, file_get_contents($root . '/changelog.xml'));
		$writes['CHANGELOG.md'] = str_replace('[[[NEXT_VERSION]]]', $version, $markdown);
	}
	else
	{
		$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml');
		$existing = $feed->xpath('update[version="' . $version . '"]');
		$archive = 'https://github.com/joomengine/mcp_component/archive/refs/tags/v' . $version . '.zip';

		if (count($existing) > 1 || version_compare($version, (string) $manifest->version, '>'))
		{
			throw new RuntimeException('Duplicate update versions or update newer than the prepared manifest.');
		}

		if ($existing)
		{
			if ((string) $existing[0]->element !== 'com_joomengine_mcp'
				|| (string) $existing[0]->type !== 'component'
				|| (string) $existing[0]->downloads->downloadurl !== $archive)
			{
				throw new RuntimeException('Existing update identity or tag URL differs; refusing to overwrite it.');
			}

			return;
		}

		$entry = $feed->addChild('update');

		foreach (['name' => 'JoomEngine MCP', 'description' => 'JoomEngine MCP component.',
			'element' => 'com_joomengine_mcp', 'type' => 'component', 'version' => $version, 'client' => '1'] as $name => $value)
		{
			$entry->addChild($name, $value);
		}

		$download = $entry->addChild('downloads')->addChild('downloadurl', $archive);
		$download->addAttribute('type', 'full');
		$download->addAttribute('format', 'zip');
		$entry->addChild('tags')->addChild('tag', 'stable');
		$platform = $entry->addChild('targetplatform');
		$platform->addAttribute('name', 'joomla');
		$platform->addAttribute('version', '6\\.[1-9][0-9]*');
		$entry->addChild('php_minimum', '8.3.0');
		$entry->addChild('detailsurl', 'https://github.com/joomengine/mcp_component/tree/v' . $version);
		$entry->addChild('changelogurl', 'https://raw.githubusercontent.com/joomengine/mcp_component/main/changelog.xml');
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
