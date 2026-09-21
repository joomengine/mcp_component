<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Generate feeds from verified, downloaded assets of an already published release. */
$root = dirname(__DIR__);
$metadata = $argv[1] ?? '';
$directory = realpath($argv[2] ?? '');
$output = $argv[3] ?? $root . '/build/release-feeds';
$version = (string) simplexml_load_file($root . '/joomengine_mcp.xml')->version;

if (PHP_SAPI !== 'cli' || !is_file($metadata) || $directory === false
	|| preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version) !== 1)
{
	throw new RuntimeException('Supply release JSON and downloaded archives for a stable manifest version.');
}

$release = json_decode(file_get_contents($metadata), true, 64, JSON_THROW_ON_ERROR);
$tag = 'v' . $version;
$base = 'https://github.com/joomengine/mcp_component/releases/';

if (($release['tag_name'] ?? '') !== $tag || ($release['draft'] ?? true) || ($release['prerelease'] ?? true)
	|| ($release['html_url'] ?? '') !== $base . 'tag/' . $tag || empty($release['published_at']))
{
	throw new RuntimeException('Update metadata requires the matching published stable release.');
}

$assets = [];

foreach ($release['assets'] ?? [] as $asset)
{
	$assets[$asset['name']] = $asset;
}

$documents = [];

foreach (['com_joomengine_mcp' => 'component', 'pkg_joomengine_mcp' => 'package'] as $element => $type)
{
	$name = $element . '-' . $version . '.zip';
	$archive = $directory . '/' . $name;
	$url = $base . 'download/' . $tag . '/' . $name;

	foreach ([$name, $name . '.sha256'] as $asset)
	{
		if (!is_file($directory . '/' . $asset) || ($assets[$asset]['state'] ?? '') !== 'uploaded'
			|| ($assets[$asset]['browser_download_url'] ?? '') !== $base . 'download/' . $tag . '/' . $asset
			|| (int) ($assets[$asset]['size'] ?? -1) !== filesize($directory . '/' . $asset))
		{
			throw new RuntimeException('The immutable archive or checksum is missing or does not match published metadata.');
		}
	}

	$checksum = hash_file('sha256', $archive);
	$digest = $assets[$name]['digest'] ?? null;

	if (!hash_equals($checksum . '  ' . $name, trim(file_get_contents($archive . '.sha256')))
		|| ($digest !== null && !hash_equals('sha256:' . $checksum, $digest)))
	{
		throw new RuntimeException('The downloaded archive does not match its published checksum or digest.');
	}

	$zip = new ZipArchive();

	if ($zip->open($archive) !== true)
	{
		throw new RuntimeException('The published extension is not a ZIP archive.');
	}

	$manifestName = $type === 'component' ? 'joomengine_mcp.xml' : 'pkg_joomengine_mcp.xml';
	$manifest = simplexml_load_string((string) $zip->getFromName($manifestName), SimpleXMLElement::class, LIBXML_NONET);
	$zip->close();

	if ($manifest === false || (string) $manifest['type'] !== $type || (string) $manifest->version !== $version
		|| ($type === 'component' && (string) $manifest->element !== $element)
		|| ($type === 'package' && 'pkg_' . (string) $manifest->packagename !== $element))
	{
		throw new RuntimeException('The downloaded extension identity or version does not match the release.');
	}

	$document = new DOMDocument('1.0', 'utf-8');
	$document->formatOutput = true;
	$updates = $document->createElement('updates');
	$document->appendChild($updates);
	$update = $document->createElement('update');
	$updates->appendChild($update);
	$append = static function (DOMNode $parent, string $name, string $value) use ($document): DOMElement
	{
		$node = $document->createElement($name);
		$node->appendChild($document->createTextNode($value));
		$parent->appendChild($node);

		return $node;
	};

	foreach (['name' => 'JoomEngine MCP ' . $type, 'description' => 'JoomEngine MCP server distribution.',
		'element' => $element, 'type' => $type, 'version' => $version, 'client' => $type === 'component' ? '1' : '0',
		'sha256' => $checksum, 'php_minimum' => '8.3.0', 'detailsurl' => $release['html_url']] as $key => $value)
	{
		$append($update, $key, $value);
	}

	$download = $append($append($update, 'downloads', ''), 'downloadurl', $url);
	$download->setAttribute('type', 'full');
	$download->setAttribute('format', 'zip');
	$append($append($update, 'tags', ''), 'tag', 'stable');
	$platform = $append($update, 'targetplatform', '');
	$platform->setAttribute('name', 'joomla');
	$platform->setAttribute('version', '6\\.[1-9][0-9]*');
	$documents[$type === 'component' ? 'joomengine_mcp_update_server.xml' : 'pkg_joomengine_mcp_update_server.xml'] = $document;
}

if (!is_dir($output) && !mkdir($output, 0755, true))
{
	throw new RuntimeException('Cannot create the verified feed output directory.');
}

foreach ($documents as $name => $document)
{
	if ($document->save($output . '/' . $name) === false)
	{
		throw new RuntimeException('Cannot save verified update feed.');
	}
}

echo 'Verified component and package update feeds for ' . $tag . "\n";
