<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Validate actual distributable contents rather than a manifest-only source tree.
 *
 * This test does not claim live installation; tests/integration installs the ZIP.
 */
$root = dirname(__DIR__);
$xml = simplexml_load_file($root . '/joomengine_mcp.xml', SimpleXMLElement::class, LIBXML_NONET);
if ($xml === false || (string) $xml['type'] !== 'component' || (string) $xml->element !== 'com_joomengine_mcp'
	|| (string) $xml->namespace !== 'VDM\Component\JoomEngineMcp')
{
	throw new RuntimeException('Invalid native component manifest.');
}
$version = (string) $xml->version;
$path = $root . '/build/com_joomengine_mcp-' . $version . '.zip';
$zip = new ZipArchive();
if ($zip->open($path) !== true)
{
	throw new RuntimeException('Build the actual component ZIP before package validation.');
}
$checks = 0;
foreach (['joomengine_mcp.xml', 'Joomengine_mcpInstallerScript.php', 'admin/services/provider.php', 'admin/access.xml',
	'admin/config.xml', 'admin/vendor/autoload.php', 'admin/vendor/mcp/sdk/src/Server.php', 'admin/data/catalogue-seed.json',
	'admin/cli/job.php', 'admin/cli/jcb.php',
	'api/src/Controller/McpController.php', 'plugins/webservices/joomengine_mcp/joomengine_mcp.xml',
	'media/joomla.asset.json', 'admin/language/en-GB/com_joomengine_mcp.ini'] as $entry)
{
	if ($zip->locateName($entry) === false)
	{
		throw new RuntimeException('Package dependency is absent: ' . $entry);
	}
	$checks++;
}
foreach (['provider', 'schema', 'action', 'binding', 'tool', 'resource', 'prompt', 'target'] as $entity)
{
	foreach (['admin/forms/' . $entity . '.xml', 'admin/forms/filter_' . $entity . 's.xml',
		'admin/src/Table/' . ucfirst($entity) . 'Table.php', 'admin/src/Model/' . ucfirst($entity) . 'Model.php',
		'admin/src/View/' . ucfirst($entity) . '/HtmlView.php', 'admin/tmpl/' . $entity . '/edit.php'] as $entry)
	{
		if ($zip->locateName($entry) === false)
		{
			throw new RuntimeException('Package administration source is missing: ' . $entry);
		}
		$checks++;
	}
}
for ($i = 0; $i < $zip->numFiles; $i++)
{
	$name = $zip->getNameIndex($i);
	if (str_starts_with($name, '/') || str_contains($name, '../') || str_starts_with($name, '.git/')
		|| str_starts_with($name, 'tests/') || str_starts_with($name, 'libraries/src/'))
	{
		throw new RuntimeException('Unexpected or unsafe distributable path.');
	}
}
$zip->close();
$feed = simplexml_load_file($root . '/joomengine_mcp_update_server.xml', SimpleXMLElement::class, LIBXML_NONET);
if ($feed === false)
{
	throw new RuntimeException('Update metadata must be valid XML.');
}

if (($argv[1] ?? '') === '--distribution')
{
	$lock = json_decode(file_get_contents($root . '/distribution.lock.json'), true, 64, JSON_THROW_ON_ERROR);
	$provenance = json_decode(file_get_contents($root . '/build/distribution.json'), true, 64, JSON_THROW_ON_ERROR);
	$config = json_decode(file_get_contents($root . '/.octojpack'), true, 64, JSON_THROW_ON_ERROR);
	$package = new ZipArchive();
	$packagePath = $root . '/build/pkg_joomengine_mcp-' . $version . '.zip';

	if ($package->open($packagePath) !== true || $provenance['sources']['console'] !== $lock['console']
		|| $provenance['version'] !== $version || $config['package']['version'] !== $version)
	{
		throw new RuntimeException('Distribution source, version or archive does not match the lock.');
	}

	$manifest = simplexml_load_string((string) $package->getFromName('pkg_joomengine_mcp.xml'), SimpleXMLElement::class, LIBXML_NONET);

	if ($manifest === false || (string) $manifest['type'] !== 'package' || (string) $manifest->version !== $version
		|| (string) $manifest->packagename !== 'joomengine_mcp' || (string) $manifest->blockChildUninstall !== 'true'
		|| count($manifest->files->file) !== 2)
	{
		throw new RuntimeException('Native package manifest is invalid.');
	}

	$expected = ['com_joomengine_mcp-' . $version . '.zip', 'plg_console_joomengine_mcp-' . $lock['console']['version'] . '.zip'];

	foreach ($manifest->files->file as $index => $entry)
	{
		$name = (string) $entry;
		$hash = $provenance['archives'][$name]['sha256'] ?? '';

		if ($name !== array_shift($expected) || !hash_equals(hash_file('sha256', $root . '/build/' . $name), $hash)
			|| !hash_equals(hash('sha256', (string) $package->getFromName('packages/' . $name)), $hash))
		{
			throw new RuntimeException('Package install order or embedded extension contents do not match the verified distribution.');
		}
		$checks++;
	}

	foreach ($provenance['archives'] as $name => $details)
	{
		$archive = $root . '/build/' . $name;
		if (!hash_equals(hash_file('sha256', $archive), $details['sha256']) || filesize($archive) !== $details['bytes']
			|| trim(file_get_contents($archive . '.sha256')) !== $details['sha256'] . '  ' . $name)
		{
			throw new RuntimeException('A distribution archive or checksum does not match its provenance.');
		}
		$checks++;
	}

	$package->close();
	$checks += 2;
}
echo json_encode(['checks' => $checks, 'archive' => basename($path), 'sha256' => hash_file('sha256', $path),
	'nativeInstallation' => 'covered separately by installed integration tests'], JSON_THROW_ON_ERROR) . "\n";
