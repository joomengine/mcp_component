<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Console\Bootstrap;

$joomla = getenv('JOOMLA_SRC') ?: (getenv('JOOMLA_ROOT') ?: '');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $joomla . '/administrator/components/com_joomengine_mcp/vendor/autoload.php';
$source = file_get_contents($joomla . '/cli/joomla.php');
if (preg_match('/^const JOOMLA_MINIMUM_PHP = [\x22\x27]([0-9.]+)[\x22\x27];$/m', $source, $native) !== 1)
{
	throw new RuntimeException('JOOMLA_SRC must provide the reviewed native CLI entry point.');
}
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void
{
	if (!$ok)
	{
		throw new RuntimeException($label);
	}
	$checks++;
};
Bootstrap::initialize($joomla);
$check(JOOMLA_MINIMUM_PHP === $native[1], 'The worker defines the actual installed Joomla CLI PHP requirement.');
Bootstrap::initialize($joomla);
$check(JOOMLA_MINIMUM_PHP === $native[1], 'Repeated bootstrap preserves the same native requirement.');

$root = sys_get_temp_dir() . '/mcp-native-bootstrap-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/cli', 0700);
$file = $root . '/cli/joomla.php';
$marker = $root . '/executed';
try
{
	$declaration = "const JOOMLA_MINIMUM_PHP = '" . $native[1] . "';";
	file_put_contents($file, '<?php ' . $declaration . ' file_put_contents(' . var_export($marker, true) . ', "must not execute");');
	Bootstrap::initialize($root);
	$check(!file_exists($marker), 'Reading the requirement never executes the native entry point.');

	foreach ([
		'<?php /* ' . $declaration . ' */',
		'<?php const JOOMLA_MINIMUM_PHP = PHP_VERSION;',
		'<?php ' . $declaration . ' ' . $declaration,
		'<?php ' . $declaration . str_repeat(' ', 65536),
		"<?php const JOOMLA_MINIMUM_PHP = '0.0.0';",
	] as $invalid)
	{
		file_put_contents($file, $invalid);
		$rejected = false;
		try
		{
			Bootstrap::initialize($root);
		}
		catch (RuntimeException)
		{
			$rejected = true;
		}
		$check($rejected, 'Missing, computed, ambiguous, oversized or conflicting requirements fail closed.');
	}
}
finally
{
	if (is_file($marker))
	{
		unlink($marker);
	}
	unlink($file);
	rmdir($root . '/cli');
	rmdir($root);
}

echo 'Native Joomla worker bootstrap: ' . $checks . ' checks passed.' . PHP_EOL;
