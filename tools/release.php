<?php
/**
 * @package    JoomEngine.Mcp
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Read local release metadata without resolving external XML entities. */
function mcpReleaseXml(string $path, string $rootName): DOMDocument
{
	$document = new DOMDocument('1.0', 'utf-8');
	$document->preserveWhiteSpace = false;
	$document->formatOutput = true;

	if (!is_file($path) || !$document->load($path, LIBXML_NONET)
		|| $document->doctype !== null || $document->documentElement->nodeName !== $rootName)
	{
		throw new RuntimeException('Invalid release XML: ' . $path);
	}

	return $document;
}

/** Append escaped text to an XML element. */
function mcpReleaseAppend(DOMNode $parent, string $name, string $value): DOMElement
{
	$node = $parent->ownerDocument->createElement($name);
	$node->appendChild($parent->ownerDocument->createTextNode($value));
	$parent->appendChild($node);

	return $node;
}

/** Require a single metadata value, preventing ambiguous manifests and feeds. */
function mcpReleaseValue(DOMDocument $document, string $expression): string
{
	$nodes = (new DOMXPath($document))->query($expression);

	if ($nodes === false || $nodes->length !== 1)
	{
		throw new RuntimeException('Expected exactly one XML value: ' . $expression);
	}

	return $nodes->item(0)->textContent;
}

/** Change one existing metadata value. */
function mcpReleaseSet(DOMDocument $document, string $expression, string $value): void
{
	mcpReleaseValue($document, $expression);
	$node = (new DOMXPath($document))->query($expression)->item(0);

	while ($node->firstChild !== null)
	{
		$node->removeChild($node->firstChild);
	}

	$node->appendChild($document->createTextNode($value));
}

/** Prepare version metadata, append a tag update, or verify OctoShoom's published checksum. */
function mcpRelease(array $arguments, string $root): void
{
	[$command, $version, $repository, $branch] = array_pad($arguments, 4, '');
	$version = preg_replace('/\Av/', '', $version);

	if (!in_array($command, ['prepare', 'verify-tag', 'feed', 'verify-hash'], true)
		|| preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) !== 1
		|| preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D', $repository) !== 1
		|| $branch === '' || preg_match('/[\x00-\x20\x7f?*\[\\\\~^:]/', $branch)
		|| str_contains($branch, '..') || str_contains($branch, '@{'))
	{
		throw new RuntimeException('Usage: release.php prepare|verify-tag|feed|verify-hash VERSION OWNER/REPOSITORY BRANCH [ARCHIVE]');
	}

	$tag = 'v' . $version;
	$manifest = mcpReleaseXml($root . '/joomengine_mcp.xml', 'extension');
	$type = $manifest->documentElement->getAttribute('type');
	$isComponent = $type === 'component';

	if (!$isComponent && ($type !== 'plugin' || $manifest->documentElement->getAttribute('group') !== 'console'))
	{
		throw new RuntimeException('This release helper supports the MCP component or console plugin only.');
	}

	$element = $isComponent ? 'com_joomengine_mcp' : 'joomengine_mcp';
	$changelogName = $isComponent ? 'changelog.xml' : 'joomengine_mcp_changelog.xml';
	$changelog = mcpReleaseXml($root . '/' . $changelogName, 'changelogs');
	$feed = mcpReleaseXml($root . '/joomengine_mcp_update_server.xml', 'updates');
	$encodedBranch = implode('/', array_map('rawurlencode', explode('/', $branch)));
	$rawBase = 'https://raw.githubusercontent.com/' . $repository . '/' . $encodedBranch . '/';
	$archiveUrl = 'https://github.com/' . $repository . '/archive/refs/tags/' . $tag . '.zip';
	$currentVersion = mcpReleaseValue($manifest, '/extension/version');
	$entryPath = '/updates/update[version="' . $version . '"]';
	$entryNodes = (new DOMXPath($feed))->query($entryPath);
	$writes = [];

	if ($command === 'prepare')
	{
		$markdown = file_get_contents($root . '/CHANGELOG.md');
		$pending = (new DOMXPath($changelog))->query('/changelogs/changelog[version="[[[NEXT_VERSION]]]"]');
		$existing = (new DOMXPath($changelog))->query('/changelogs/changelog[version="' . $version . '"]');

		if (version_compare($version, $currentVersion, '<') || $existing->length !== 0 || $pending->length !== 1
			|| substr_count($markdown, '[[[NEXT_VERSION]]]') !== 1
			|| preg_match('/^## \[\[\[NEXT_VERSION\]\]\]/m', $markdown) !== 1)
		{
			throw new RuntimeException('Choose an unreleased version at least as new as the manifest and provide exactly one pending changelog section in both files.');
		}

		$pendingEntry = $pending->item(0);
		$pendingQuery = new DOMXPath($changelog);

		if ($pendingQuery->query('element[text()="' . $element . '"]', $pendingEntry)->length !== 1
			|| $pendingQuery->query('type[text()="' . $type . '"]', $pendingEntry)->length !== 1
			|| $pendingQuery->query('security/item|fix/item|language/item|addition/item|change/item|remove/item|note/item', $pendingEntry)->length === 0
			|| (!$isComponent && $pendingQuery->query('folder[text()="console"]', $pendingEntry)->length !== 1))
		{
			throw new RuntimeException('Pending Joomla changelog must identify this extension and contain categorized changes.');
		}

		mcpReleaseSet($manifest, '/extension/version', $version);
		mcpReleaseSet($manifest, '/extension/creationDate', gmdate('F Y'));
		mcpReleaseSet($manifest, '/extension/updateservers/server', $rawBase . 'joomengine_mcp_update_server.xml');
		mcpReleaseSet($manifest, '/extension/changelogurl', $rawBase . $changelogName);
		mcpReleaseSet($changelog, '/changelogs/changelog/version[text()="[[[NEXT_VERSION]]]"]', $version);
		$writes['joomengine_mcp.xml'] = $manifest->saveXML();
		$writes[$changelogName] = $changelog->saveXML();
		$writes['CHANGELOG.md'] = str_replace('[[[NEXT_VERSION]]]', $version, $markdown);

		if ($isComponent)
		{
			$routingPath = 'plugins/webservices/joomengine_mcp/joomengine_mcp.xml';
			$routing = mcpReleaseXml($root . '/' . $routingPath, 'extension');
			mcpReleaseSet($routing, '/extension/version', $version);
			mcpReleaseSet($routing, '/extension/creationDate', gmdate('F Y'));
			$configuration = json_decode(file_get_contents($root . '/.octojpack'), true, 64, JSON_THROW_ON_ERROR);
			$configuration['package']['version'] = $version;
			$writes[$routingPath] = $routing->saveXML();
			$writes['.octojpack'] = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		}
	}
	elseif ($command === 'verify-tag')
	{
		$record = '/changelogs/changelog[version="' . $version . '"]';

		if ($currentVersion !== $version || mcpReleaseValue($changelog, $record . '/element') !== $element
			|| mcpReleaseValue($changelog, $record . '/type') !== $type
			|| (!$isComponent && mcpReleaseValue($changelog, $record . '/folder') !== 'console')
			|| mcpReleaseValue($manifest, '/extension/updateservers/server') !== $rawBase . 'joomengine_mcp_update_server.xml'
			|| mcpReleaseValue($manifest, '/extension/changelogurl') !== $rawBase . $changelogName
			|| !preg_match('/^## ' . preg_quote($version, '/') . '(?:\s|$)/m', file_get_contents($root . '/CHANGELOG.md')))
		{
			throw new RuntimeException('The existing tag does not contain the requested released extension metadata.');
		}

		if ($isComponent)
		{
			$routing = mcpReleaseXml($root . '/plugins/webservices/joomengine_mcp/joomengine_mcp.xml', 'extension');
			$config = json_decode(file_get_contents($root . '/.octojpack'), true, 64, JSON_THROW_ON_ERROR);

			if (mcpReleaseValue($routing, '/extension/version') !== $version || ($config['package']['version'] ?? '') !== $version)
			{
				throw new RuntimeException('The tag contains inconsistent routing plugin or OctoJPack versions.');
			}
		}
	}
	else
	{
		if ($entryNodes->length > 1 || version_compare($version, $currentVersion, '>'))
		{
			throw new RuntimeException('Duplicate update versions or update newer than the prepared manifest.');
		}

		if ($entryNodes->length === 1)
		{
			if (mcpReleaseValue($feed, $entryPath . '/element') !== $element
				|| mcpReleaseValue($feed, $entryPath . '/type') !== $type
				|| mcpReleaseValue($feed, $entryPath . '/downloads/downloadurl') !== $archiveUrl
				|| (!$isComponent && mcpReleaseValue($feed, $entryPath . '/folder') !== 'console'))
			{
				throw new RuntimeException('Existing update identity or tag URL differs; refusing to overwrite it.');
			}
		}
		elseif ($command === 'feed')
		{
			$entry = $feed->createElement('update');
			$feed->documentElement->insertBefore($entry, $feed->documentElement->firstChild);

			foreach (['name' => $isComponent ? 'JoomEngine MCP' : 'JoomEngine MCP Console',
				'description' => $isComponent ? 'JoomEngine MCP component.' : 'JoomEngine MCP console plugin.',
				'element' => $element, 'type' => $type, 'version' => $version] as $name => $value)
			{
				mcpReleaseAppend($entry, $name, $value);
			}

			mcpReleaseAppend($entry, $isComponent ? 'client' : 'folder', $isComponent ? '1' : 'console');
			$download = mcpReleaseAppend(mcpReleaseAppend($entry, 'downloads', ''), 'downloadurl', $archiveUrl);
			$download->setAttribute('type', 'full');
			$download->setAttribute('format', 'zip');
			mcpReleaseAppend(mcpReleaseAppend($entry, 'tags', ''), 'tag', 'stable');
			$platform = mcpReleaseAppend($entry, 'targetplatform', '');
			$platform->setAttribute('name', 'joomla');
			$platform->setAttribute('version', '6\\.[1-9][0-9]*');
			mcpReleaseAppend($entry, 'php_minimum', '8.3.0');
			mcpReleaseAppend($entry, 'detailsurl', 'https://github.com/' . $repository . '/tree/' . $tag);
			mcpReleaseAppend($entry, 'changelogurl', $rawBase . $changelogName);
			$writes['joomengine_mcp_update_server.xml'] = $feed->saveXML();
		}

		if ($command === 'verify-hash')
		{
			$archive = $arguments[4] ?? '';
			$hash = strtolower(mcpReleaseValue($feed, $entryPath . '/sha512'));

			if (!is_file($archive) || preg_match('/\A[a-f0-9]{128}\z/D', $hash) !== 1
				|| !hash_equals($hash, hash_file('sha512', $archive)))
			{
				throw new RuntimeException('OctoShoom has not published a matching SHA-512 for the immutable tag archive.');
			}
		}
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
		echo "Release metadata verified.\n";
	}
	catch (Throwable $error)
	{
		fwrite(STDERR, $error->getMessage() . "\n");
		exit(1);
	}
}
