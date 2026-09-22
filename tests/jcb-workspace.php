<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\Input\Input;
use Joomla\Registry\Registry;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CompiledArchives;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CompilerWorkspace;
use VDM\Component\JoomEngineMcp\Administrator\Job\Artifacts;
use VDM\Component\JoomEngineMcp\Administrator\Job\Storage;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;

$joomla = getenv('JOOMLA_SRC') ?: (getenv('JOOMLA_ROOT') ?: '');
$jcb = getenv('JCB_SRC') ?: $joomla;
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $joomla . '/administrator/components/com_joomengine_mcp/vendor/autoload.php';
require $joomla . '/libraries/vendor/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
spl_autoload_register(static function (string $class) use ($jcb): void
{
	$prefix = 'VDM\\Joomla\\';
	if (str_starts_with($class, $prefix))
	{
		$file = $jcb . '/libraries/vendor_jcb/VDM.Joomla/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file))
		{
			require $file;
		}
	}
});
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void
{
	if (!$ok)
	{
		throw new RuntimeException($label);
	}
	$checks++;
};
$base = sys_get_temp_dir() . '/mcp-compiler-workspace-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
mkdir($base . '/site', 0755);
mkdir($base . '/site/tmp', 0755);
$workspace = null;
try
{
	$owner = new Principal('joomla:17', 'api', [1]);
	$directory = Storage::directory($base, $base . '/site', 'test-secret', $owner->getId());
	$compiler = Storage::compilerDirectory($directory);
	$workspace = new CompilerWorkspace($compiler, $base . '/site');
	$path = $workspace->path();
	$check(!str_starts_with($path, $base . '/site/') && (fileperms($path) & 0777) === 0700,
		'Compiler output starts inside a random owner-private directory outside the web root.');
	$config = new Registry(['tmp_path' => $base . '/site/tmp']);
	$original = $config->get('tmp_path');
	$config->set('tmp_path', $path);
	$native = new VDM\Joomla\Componentbuilder\Compiler\Config(new Input(), new Registry(), $config);
	$check($native->tmp_path === $path, 'The actual pinned native compiler resolves the redirected Joomla temporary path.');
	$zipPath = $native->tmp_path . '/com_example.zip';
	$zip = new ZipArchive();
	$check($zip->open($zipPath, ZipArchive::CREATE) === true, 'A real generated ZIP can be written in the native private workspace.');
	$zip->addFromString('example.xml', '<extension type="component"><name>Example</name></extension>');
	$zip->close();
	$archives = new CompiledArchives($path, $compiler);
	$captured = $archives->capture([$zipPath], true);
	$check(count($captured) === 1 && !str_starts_with($captured[0]['path'], $path . '/')
		&& $captured[0]['staged'] === true, 'Verified output stages separately before native workspace cleanup.');
	$check(!file_exists($original . '/com_example.zip'), 'No predictable archive exists in the public Joomla temporary directory.');
	file_put_contents($base . '/outside-sentinel', 'keep');
	symlink($base . '/outside-sentinel', $path . '/linked-file');
	symlink($base . '/site', $path . '/linked-directory');
	mkdir($path . '/unpacked', 0755);
	file_put_contents($path . '/unpacked/installer.php', '<?php return true;');
	$config->set('tmp_path', $original);
	$workspace->close();
	$check(!file_exists($path) && file_get_contents($base . '/outside-sentinel') === 'keep'
		&& is_dir($base . '/site/tmp'), 'Workspace cleanup removes extracted files without following symbolic links.');
	$check($config->get('tmp_path') === $original && is_file($captured[0]['path']),
		'Native configuration is restored while the private archive remains available for its parent job.');
	$store = new MemoryStore();
	$job = Json::uuid();
	$store->insert('job', ['uuid' => $job, 'principal_key' => hash('sha256', $owner->getId())]);
	$retention = new Artifacts($store, $owner, $directory, [$compiler], static fn (): int => time());
	$retained = $retention->capture($job, $captured[0]);
	$check(!file_exists($captured[0]['path']) && !is_dir(dirname($captured[0]['path'])),
		'Durable parent retention removes its private staged source and empty staging directory.');
	$bytes = base64_decode($retention->read($retained['artifactId'])['data'], true);
	$check(is_string($bytes) && hash_equals($captured[0]['sha256'], hash('sha256', $bytes)),
		'Owned artifact bytes remain exact after all native and staging cleanup.');
	$rejected = false;
	try
	{
		new CompilerWorkspace($base . '/site/tmp', $base . '/site');
	}
	catch (OperationException $error)
	{
		$rejected = $error->getIdentifier() === 'JCB_WORKSPACE_STORAGE';
	}
	$check($rejected, 'A compiler workspace inside the Joomla web root is rejected.');
}
finally
{
	$workspace?->close();
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($items as $item)
	{
		$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($base);
}
echo 'Private native compiler workspace: ' . $checks . ' checks passed.' . PHP_EOL;
