<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

$root = dirname(__DIR__, 2);
$path = $root . '/tests/native/run.php';
$content = file_get_contents($path);

if ($content === false)
{
	throw new RuntimeException('The native test import is required first.');
}

$content = str_replace("dirname(__DIR__) . '/plugin/src/", "dirname(__DIR__, 2) . '/admin/src/Native/", $content);
$content = str_replace("dirname(__DIR__) . '/plugin'", "dirname(__DIR__, 2) . '/admin/src/Native'", $content);
$start = strpos($content, "test('manifests declare an installable package and Joomla console plugin'");
$end = strpos($content, "\nif (\$GLOBALS['failures'] > 0)");

if ($start === false || $end === false || $end <= $start)
{
	throw new RuntimeException('The pinned native test layout changed; review the migration.');
}

// The old package name/version assertion is replaced by the new plugin's real
// manifest/ZIP suite. Preserve all native action and protocol behavioural tests.
$replacement = <<<'PHP'
test('migrated native runtime is independent of the old plugin namespace', static function (): void {
    $root = dirname(__DIR__, 2) . '/admin/src/Native';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect(is_string($source), 'Cannot read migrated native source.');
        expect(!str_contains($source, 'VDM\\Plugin\\Console\\JoomlaMcp\\'), 'A native handler still requires the old plugin.');
    }
});

PHP;
$content = substr($content, 0, $start) . $replacement . substr($content, $end);
file_put_contents($path, $content);
$mapPath = $root . '/docs/migration/native-source-map.json';
$map = json_decode(file_get_contents($mapPath), true, 64, JSON_THROW_ON_ERROR);

foreach ($map as &$entry)
{
	$entry['targetSha256'] = hash_file('sha256', $root . '/' . $entry['target']);
}

unset($entry);
file_put_contents($mapPath, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Aligned native test paths; obsolete upstream package assertions are superseded by the new distribution suites.\n";
