<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/** Build the native Joomla package using reviewed metadata and locked sources. */
require_once __DIR__ . '/archive.php';
$root = dirname(__DIR__);
$source = realpath($argv[1] ?? '');
$revision = $argv[2] ?? '';
$pluginRevision = $argv[3] ?? '';
$modified = $argv[4] ?? '';
$config = json_decode(file_get_contents($root . '/.octojpack'), true, 64, JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root . '/distribution.lock.json'), true, 64, JSON_THROW_ON_ERROR);
$manifest = simplexml_load_file($root . '/joomengine_mcp.xml', SimpleXMLElement::class, LIBXML_NONET);
$version = (string) $manifest->version;
$metadata = $config['package'];

if ($source === false || preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1
	|| preg_match('/\A[a-f0-9]{40}\z/D', $pluginRevision) !== 1
	|| !in_array($modified, ['0', '1'], true)
	|| ($lock['schemaVersion'] ?? null) !== 1 || ($lock['console']['ref'] ?? '') !== $pluginRevision
	|| ($lock['console']['repository'] ?? '') !== 'joomengine/mcp_plugin'
	|| ($metadata['version'] ?? '') !== $version || ($metadata['package_name'] ?? '') !== 'pkg_joomengine_mcp'
	|| ($metadata['version_id'] ?? '') !== 'com_joomengine_mcp'
	|| preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version) !== 1)
{
	throw new RuntimeException('Invalid distribution version, package identity or immutable source lock.');
}

$plugin = simplexml_load_file($source . '/joomengine_mcp.xml', SimpleXMLElement::class, LIBXML_NONET);
$pluginVersion = (string) $plugin->version;

if ((string) $plugin['type'] !== 'plugin' || (string) $plugin['group'] !== 'console'
	|| (string) $plugin->namespace !== 'VDM\\Plugin\\Console\\JoomEngineMcp'
	|| $pluginVersion !== $lock['console']['version'])
{
	throw new RuntimeException('The locked console plugin identity or version does not match its manifest.');
}

$componentName = 'com_joomengine_mcp-' . $version . '.zip';
$consoleName = 'plg_console_joomengine_mcp-' . $pluginVersion . '.zip';
$packageName = 'pkg_joomengine_mcp-' . $version . '.zip';
$component = $root . '/build/' . $componentName;
$console = $root . '/build/' . $consoleName;

if (!is_file($component) || !copy($source . '/build/' . $consoleName, $console)
	|| !copy($source . '/build/' . $consoleName . '.sha256', $console . '.sha256'))
{
	throw new RuntimeException('Build both immutable extension ZIPs before assembling the distribution.');
}

$stage = $root . '/build/package';

if (!is_dir($stage) && !mkdir($stage, 0755, true))
{
	throw new RuntimeException('Cannot create package staging directory.');
}

$document = new DOMDocument('1.0', 'utf-8');
$document->formatOutput = true;
$extension = $document->createElement('extension');
$extension->setAttribute('type', 'package');
$extension->setAttribute('method', 'upgrade');
$document->appendChild($extension);
$append = static function (DOMNode $parent, string $name, string $value) use ($document): DOMElement
{
	$element = $document->createElement($name);
	$element->appendChild($document->createTextNode($value));
	$parent->appendChild($element);

	return $element;
};

foreach (['name' => $metadata['name'], 'packagename' => $metadata['code_name'], 'version' => $version,
	'author' => $metadata['author'], 'authorEmail' => $metadata['author_email'], 'authorUrl' => $metadata['author_url'],
	'copyright' => $metadata['copyright'], 'license' => $metadata['license'], 'description' => $metadata['description'],
	'packager' => $config['global']['packager'], 'packagerurl' => $config['global']['packager_url'],
	'blockChildUninstall' => 'true'] as $name => $value)
{
	$append($extension, $name, $value);
}

$files = $append($extension, 'files', '');
$files->setAttribute('folder', 'packages');

foreach ($config['files'] as $definition)
{
	$name = $definition['type'] === 'component' ? $componentName : $consoleName;
	$file = $append($files, 'file', $name);
	$file->setAttribute('type', $definition['type']);
	$file->setAttribute('id', $definition['id']);

	if (isset($definition['group']))
	{
		$file->setAttribute('group', $definition['group']);
	}
}

$servers = $append($extension, 'updateservers', '');
$server = $append($servers, 'server', $metadata['update_servers']);
$server->setAttribute('type', 'extension');
$server->setAttribute('priority', '1');
$server->setAttribute('name', 'JoomEngine MCP package updates');
$languages = $append($extension, 'languages', '');
$languages->setAttribute('folder', 'language');
$archiveFiles = ['pkg_joomengine_mcp.xml' => $stage . '/pkg_joomengine_mcp.xml',
	'LICENSE' => $root . '/LICENSE', 'packages/' . $componentName => $component, 'packages/' . $consoleName => $console];

foreach ($config['languages'] as $language)
{
	if ($language['tag'] !== 'en-GB' || !in_array($language['ini'], ['ini', 'sys.ini'], true))
	{
		throw new RuntimeException('Unsupported package language configuration.');
	}

	$name = 'en-GB/pkg_joomengine_mcp.' . $language['ini'];
	$path = $stage . '/' . basename($name);
	$entry = $append($languages, 'language', $name);
	$entry->setAttribute('tag', 'en-GB');
	file_put_contents($path, $language['key'] . '="' . $language['value'] . '"' . "\n");
	$archiveFiles['language/' . $name] = $path;
}

if ($document->save($stage . '/pkg_joomengine_mcp.xml') === false)
{
	throw new RuntimeException('Cannot write native package manifest.');
}

archiveDistribution($archiveFiles, $root . '/build/' . $packageName);
$provenance = ['schemaVersion' => 1, 'version' => $version, 'sources' => [
	'component' => ['repository' => 'joomengine/mcp_component', 'ref' => $revision, 'version' => $version,
		'workingTreeModified' => $modified === '1'],
	'console' => $lock['console']], 'archives' => []];

foreach ([$componentName, $consoleName, $packageName] as $name)
{
	$provenance['archives'][$name] = ['sha256' => hash_file('sha256', $root . '/build/' . $name),
		'bytes' => filesize($root . '/build/' . $name)];
}

file_put_contents($root . '/build/distribution.json', json_encode($provenance,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo $root . '/build/' . $packageName . "\n";
