<?php
/**
 * @package    JoomEngine.Mcp
 * @created    28 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Verify the source tree, or an unmodified Git source ZIP, is directly installable.
 *
 * No dependencies are downloaded and no release/package archive is constructed.
 * The administrator payload is relocated as Joomla does during installation and
 * its autoloader is exercised in a fresh PHP process without the source checkout.
 */
$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/mcp-source-install-' . bin2hex(random_bytes(8));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}

	$checks++;
};
$copy = static function (string $from, string $to) use (&$copy): void
{
	if (is_link($from))
	{
		throw new RuntimeException('Installable files must not depend on symbolic links: ' . $from);
	}

	if (is_dir($from))
	{
		if (!is_dir($to) && !mkdir($to, 0755, true))
		{
			throw new RuntimeException('Cannot create temporary installation directory.');
		}

		foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry)
		{
			$copy($entry->getPathname(), $to . '/' . $entry->getFilename());
		}
	}
	elseif (!is_file($from) || !copy($from, $to))
	{
		throw new RuntimeException('Cannot copy a required installation file: ' . $from);
	}
};
$remove = static function (string $path) use (&$remove): void
{
	if (is_dir($path) && !is_link($path))
	{
		foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry)
		{
			$remove($entry->getPathname());
		}

		rmdir($path);
	}
	else
	{
		unlink($path);
	}
};
mkdir($temporary, 0700, true);

try
{
	if (isset($argv[1]))
	{
		$zip = new ZipArchive();
		$check($zip->open($argv[1]) === true, 'Cannot open the Git source ZIP.');

		for ($index = 0; $index < $zip->numFiles; $index++)
		{
			$name = $zip->getNameIndex($index);
			$check(!str_starts_with($name, '/') && !str_contains($name, '../') && !str_contains($name, '\\'), 'Unsafe source ZIP path.');
		}

		$check($zip->extractTo($temporary . '/source'), 'Cannot extract the Git source ZIP.');
		$zip->close();
		$candidates = array_merge(glob($temporary . '/source/joomengine_mcp.xml'), glob($temporary . '/source/*/joomengine_mcp.xml'));
		$check(count($candidates) === 1, 'A source ZIP must contain one component manifest at its root or GitHub wrapper directory.');
		$source = dirname($candidates[0]);
	}

	$manifest = simplexml_load_file($source . '/joomengine_mcp.xml', SimpleXMLElement::class, LIBXML_NONET);
	$check($manifest !== false && (string) $manifest['type'] === 'component'
		&& (string) $manifest->element === 'com_joomengine_mcp'
		&& (string) $manifest->namespace === 'VDM\\Component\\JoomEngineMcp', 'Invalid native component manifest.');
	$check(is_file($source . '/' . (string) $manifest->scriptfile), 'The native installer script is missing.');

	foreach ([$manifest->files, $manifest->api->files, $manifest->media, $manifest->administration->files, $manifest->administration->languages] as $files)
	{
		$folder = $source . '/' . (string) $files['folder'];

		foreach ($files->children() as $entry)
		{
			$path = $folder . '/' . (string) $entry;
			$check($entry->getName() === 'folder' ? is_dir($path) : is_file($path), 'Manifest source is missing: ' . $path);
		}
	}

	foreach (['admin/autoload.php', 'admin/vendor/autoload.php', 'admin/data/catalogue-seed.json', 'admin/LICENSE'] as $file)
	{
		$check(is_file($source . '/' . $file), 'Required runtime source is missing: ' . $file);
	}
	$check(!is_dir($source . '/plugins'), 'Plugins belong in their own repositories, not the component source ZIP.');

	$check(file_get_contents($source . '/LICENSE') === file_get_contents($source . '/admin/LICENSE'), 'The installed license differs from the repository license.');
	$seed = json_decode(file_get_contents($source . '/admin/data/catalogue-seed.json'), true, 512, JSON_THROW_ON_ERROR);
	$check(!empty($seed['entities']['tool']) && !empty($seed['entities']['provider']), 'The installer catalogue seed is empty.');
	$lock = json_decode(file_get_contents($source . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
	$installed = json_decode(file_get_contents($source . '/admin/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
	$expected = array_column($lock['packages'], 'version', 'name');
	$actual = array_column($installed['packages'], 'version', 'name');
	ksort($expected);
	ksort($actual);
	$check($expected === $actual && $installed['dev'] === false, 'Committed production dependencies must exactly match composer.lock without development packages.');

	$target = $temporary . '/joomla/administrator/components/com_joomengine_mcp';
	mkdir($target, 0755, true);

	foreach ($manifest->administration->files->children() as $entry)
	{
		$copy($source . '/admin/' . (string) $entry, $target . '/' . (string) $entry);
	}

	// Joomla also copies the native component manifest into its administrator root.
	$copy($source . '/joomengine_mcp.xml', $target . '/joomengine_mcp.xml');

	$code = <<<'CHECK'
$loader = require $argv[1] . '/autoload.php';
foreach (['Mcp\\Server', 'Nyholm\\Psr7\\Factory\\Psr17Factory', 'Nyholm\\Psr7Server\\ServerRequestCreator',
	'Opis\\JsonSchema\\Validator', 'Http\\Client\\Curl\\Client',
	'VDM\\Component\\JoomEngineMcp\\Administrator\\Service\\Json',
	'VDM\\Component\\JoomEngineMcp\\Administrator\\Database\\Structure'] as $class)
{
	if (!class_exists($class) || !str_starts_with((new ReflectionClass($class))->getFileName(), $argv[1] . '/'))
	{
		throw new RuntimeException('The installed autoloader cannot resolve its own runtime: ' . $class);
	}
}
$seed = VDM\Component\JoomEngineMcp\Administrator\Service\Json::decode(file_get_contents($argv[1] . '/data/catalogue-seed.json'), maximum: 16777216);
if (empty($seed['entities']['tool']))
{
	throw new RuntimeException('The relocated runtime cannot read its installer catalogue.');
}
if (VDM\Component\JoomEngineMcp\Administrator\Service\ComponentVersion::get() !== $argv[2])
{
	throw new RuntimeException('The relocated runtime did not read its native manifest version.');
}
echo "Relocated runtime and production dependencies loaded successfully.\n";
CHECK;
	foreach ([(string) $manifest->version, '123.456.789'] as $expectedVersion)
	{
		// A fresh process must follow a later native manifest, without regenerated PHP.
		$installedManifest = simplexml_load_file($target . '/joomengine_mcp.xml');
		$installedManifest->version = $expectedVersion;
		$installedManifest->asXML($target . '/joomengine_mcp.xml');
		$process = proc_open([PHP_BINARY, '-r', $code, $target, $expectedVersion], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target);
		$check(is_resource($process), 'Cannot launch the relocated runtime verification.');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$check(proc_close($process) === 0, 'The relocated runtime failed: ' . $error);
		echo $output;
	}
	echo json_encode(['checks' => $checks, 'source' => isset($argv[1]) ? basename($argv[1]) : 'checkout',
		'nativeInstallation' => 'covered separately by installed integration tests'], JSON_THROW_ON_ERROR) . "\n";
}
finally
{
	$remove($temporary);
}
