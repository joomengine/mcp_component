<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Archive regular files with stable ordering, timestamps and UNIX permissions.
 *
 * @param array<string,string> $files Archive names mapped to local source files.
 * @param string $target Output ZIP path.
 * @return void
 * @since 0.1.1
 */
function archiveDistribution(array $files, string $target): void
{
	$zip = new ZipArchive();

	if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
	{
		throw new RuntimeException('Cannot create distribution archive.');
	}

	ksort($files, SORT_STRING);

	foreach ($files as $name => $path)
	{
		if (str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')
			|| is_link($path) || !is_file($path)
			|| !$zip->addFile($path, $name) || !$zip->setMtimeName($name, 946684800)
			|| !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16))
		{
			throw new RuntimeException('Unsafe or unreadable distribution file: ' . $name);
		}
	}

	if (!$zip->close())
	{
		throw new RuntimeException('Cannot finalize distribution archive.');
	}

	if (file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n") === false)
	{
		throw new RuntimeException('Cannot write distribution checksum.');
	}
}
