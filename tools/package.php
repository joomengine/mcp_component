<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Stage and archive the component using its native manifest and resolved vendor tree.
 *
 * Run via tools/build.sh. Source and packaged PHP autoload roots are intentionally
 * different; Composer regenerates the staged map before this script archives it.
 */
$root = dirname(__DIR__);
$stage = $root . '/build/component';
$mode = $argv[1] ?? '';

/** Copy only regular source files; reject links instead of packaging outside data. */
$copy = static function (string $source, string $target) use (&$copy): void
{
	if (is_link($source))
	{
		throw new RuntimeException('Package source must not contain symlinks: ' . $source);
	}

	if (is_dir($source))
	{
		if (!is_dir($target) && !mkdir($target, 0755, true))
		{
			throw new RuntimeException('Cannot create package directory.');
		}

		foreach (scandir($source) as $name)
		{
			if ($name !== '.' && $name !== '..')
			{
				$copy($source . '/' . $name, $target . '/' . $name);
			}
		}
	}
	elseif (!is_file($source) || !copy($source, $target))
	{
		throw new RuntimeException('Cannot copy package source: ' . $source);
	}
};

if ($mode === '--stage')
{
	if (is_dir($stage))
	{
		throw new RuntimeException('The stage already exists; tools/build.sh must create a fresh stage.');
	}

	mkdir($stage, 0755, true);

	foreach (['admin', 'api', 'site', 'media', 'plugins'] as $folder)
	{
		$copy($root . '/' . $folder, $stage . '/' . $folder);
	}

	foreach (['joomengine_mcp.xml', 'Joomengine_mcpInstallerScript.php', 'LICENSE'] as $file)
	{
		$copy($root . '/' . $file, $stage . '/' . $file);
	}

	$copy($root . '/LICENSE', $stage . '/admin/LICENSE');
	$copy($root . '/vendor', $stage . '/admin/vendor');
	mkdir($stage . '/admin/data', 0755, true);
	$copy($root . '/data/catalogue-seed.json', $stage . '/admin/data/catalogue-seed.json');
	$composer = json_decode(file_get_contents($root . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
	$composer['autoload']['psr-4'] = ['VDM\\Component\\JoomEngineMcp\\Administrator\\' => 'src/'];
	unset($composer['autoload-dev'], $composer['scripts']);
	$composer['version'] = (string) simplexml_load_file($root . '/joomengine_mcp.xml')->version;
	file_put_contents($stage . '/admin/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
elseif ($mode === '--archive')
{
	$manifest = simplexml_load_file($stage . '/joomengine_mcp.xml');
	$version = (string) $manifest->version;

	if (preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?\z/D', $version) !== 1)
	{
		throw new RuntimeException('The manifest has an invalid release version.');
	}

	$target = $root . '/build/com_joomengine_mcp-' . $version . '.zip';
	$zip = new ZipArchive();

	if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
	{
		throw new RuntimeException('Cannot create the component archive.');
	}

	$files = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)) as $file)
	{
		if ($file->isLink() || !$file->isFile())
		{
			throw new RuntimeException('Only regular files may enter the package.');
		}

		$name = substr($file->getPathname(), strlen($stage) + 1);
		$files[$name] = $file->getPathname();
	}

	ksort($files, SORT_STRING);
	foreach ($files as $name => $path)
	{
		$zip->addFile($path, $name);
		$zip->setMtimeName($name, 946684800);
		$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
	}

	if (!$zip->close())
	{
		throw new RuntimeException('The component archive could not be finalized.');
	}

	file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n");
	echo $target . "\n";
}
else
{
	throw new RuntimeException('Use --stage or --archive through tools/build.sh.');
}
