<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CompiledArchives;

$joomla = getenv('JOOMLA_SRC') ?: (getenv('JOOMLA_ROOT') ?: '');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $joomla . '/administrator/components/com_joomengine_mcp/vendor/autoload.php';
if (!is_file($joomla . '/libraries/vendor/autoload.php'))
{
	throw new RuntimeException('JOOMLA_SRC must select the actual Joomla source installation.');
}
require $joomla . '/libraries/vendor/autoload.php';
$root = sys_get_temp_dir() . '/mcp-native-permissions-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$original = umask();
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}
	$checks++;
};
$mode = static function (string $path): int
{
	clearstatcache(true, $path);
	return fileperms($path) & 0777;
};
try
{
	Folder::create($root . '/source');
	file_put_contents($root . '/source/entry.php', '<?php return true;');
	chmod($root . '/source/entry.php', 0644);
	foreach ([0022 => 0644, 0027 => 0640] as $mask => $expected)
	{
		umask($mask);
		$directory = $root . '/native-' . $mask;
		Folder::copy($root . '/source', $directory);
		File::copy($root . '/source/entry.php', $root . '/file-' . $mask . '.php');
		$check($mode($directory) === 0755 && $mode($directory . '/entry.php') === $expected
			&& $mode($root . '/file-' . $mask . '.php') === $expected && umask() === $mask,
			'Actual Joomla installer copy primitives preserve the hosting process file policy.');

		$source = $root . '/compiler-' . $mask . '.zip';
		$zip = new ZipArchive();
		$check($zip->open($source, ZipArchive::CREATE) === true, 'Create a real compiler archive.');
		$zip->addFromString('extension.xml', '<extension type="component"/>');
		$zip->close();
		$archive = (new CompiledArchives($root))->capture([$source], true)[0];
		$check($mode(dirname($archive['path'])) === 0700 && $mode($archive['path']) === 0600,
			'Private compiler preservation remains private under a normal native installation mask.');
		$check(hash_equals(hash_file('sha256', $source), hash_file('sha256', $archive['path'])),
			'Permission boundaries preserve the actual archive bytes.');
	}
}
finally
{
	umask($original);
	Folder::delete($root);
}
echo 'Native Joomla copy and private archive permissions: ' . $checks . ' checks passed.' . PHP_EOL;
