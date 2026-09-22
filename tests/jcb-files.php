<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\DI\Container;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\FileResults;

$joomla = getenv('JOOMLA_SRC') ?: (getenv('JOOMLA_ROOT') ?: '');
$jcb = getenv('JCB_SRC') ?: $joomla;
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $joomla . '/administrator/components/com_joomengine_mcp/vendor/autoload.php';

if (!is_file($joomla . '/libraries/vendor/autoload.php') || !is_dir($jcb . '/libraries/vendor_jcb/VDM.Joomla'))
{
	throw new RuntimeException('JCB_SRC and JOOMLA_SRC must select actual pinned JCB and Joomla source installations.');
}

require $joomla . '/libraries/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($jcb): void
{
	foreach (['VDM\\Joomla\\Gitea\\' => 'VDM.Joomla.Gitea', 'VDM\\Joomla\\' => 'VDM.Joomla'] as $prefix => $directory)
	{
		if (str_starts_with($class, $prefix))
		{
			$file = $jcb . '/libraries/vendor_jcb/' . $directory . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

			if (is_file($file))
			{
				require $file;
			}

			return;
		}
	}
});

$root = sys_get_temp_dir() . '/mcp-file-proof-' . bin2hex(random_bytes(8));
mkdir($root, 0700);

foreach (['ROOT', 'SITE', 'BASE', 'CACHE', 'CONFIGURATION', 'INSTALLATION', 'LIBRARIES', 'PLUGINS', 'THEMES'] as $constant)
{
	define('JPATH_' . $constant, $root);
}

define('JPATH_ADMINISTRATOR', $root . '/administrator');

/** Native repository adapter with only the external read boundary replaced. */
final class FileProofContents extends VDM\Joomla\Gitea\Repository\Contents
{
	public array $indexes = [];
	public array $content = [];
	public array $reads = [];
	public int $resets = 0;

	public function __construct()
	{
	}

	public function setTarget(string $target): self
	{
		return $this;
	}

	public function load_(?string $url = null, ?string $token = null, bool $backup = true): void
	{
	}

	public function reset_(): void
	{
		$this->resets++;
	}

	public function get(string $owner, string $repo, string $filepath, ?string $ref = null)
	{
		$this->reads[] = [$repo, $filepath, $ref];
		return $filepath === 'index/file_folder.json' ? ($this->indexes[$repo . ':' . $ref] ?? null)
			: ($this->content[$repo . ':' . $ref . ':' . $filepath] ?? null);
	}

	public function metadata(string $owner, string $repo, string $filepath, ?string $ref = null): null|array|object
	{
		$this->reads[] = [$repo, $filepath, $ref];
		$bytes = $this->content[$repo . ':' . $ref . ':' . $filepath] ?? null;
		return $bytes === null ? null : (object) ['type' => 'file', 'size' => strlen($bytes), 'encoding' => 'base64', 'content' => base64_encode($bytes)];
	}
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}

	$checks++;
};
$set = static function (object $object, string $property, mixed $value): void
{
	(new ReflectionProperty($object, $property))->setValue($object, $value);
};
$make = static function (string $class): object
{
	return (new ReflectionClass($class))->newInstanceWithoutConstructor();
};
$normalizer = new VDM\Joomla\Componentbuilder\Utilities\Normalize();
$repository = (object) ['guid' => 'repo-one', 'organisation' => 'test', 'repository' => 'one', 'read_branch' => 'read-feature', 'write_branch' => 'write-feature'];
$second = (object) ['guid' => 'repo-two', 'organisation' => 'test', 'repository' => 'two', 'read_branch' => 'read-second', 'write_branch' => 'write-second'];
$git = new FileProofContents();
$container = new Container();
$container->set('Utilities.Normalize', $normalizer, true);

foreach (['File', 'Folder'] as $area)
{
	$config = $make('VDM\\Joomla\\Componentbuilder\\Package\\' . $area . '\\Remote\\Config');
	$grep = $make('VDM\\Joomla\\Componentbuilder\\Package\\GrepContent');
	$set($grep, 'contents', $git);
	$set($grep, 'config', $config);
	$grep->path = null;
	$grep->paths = [$repository, $second];

	foreach (['Get', 'Set'] as $direction)
	{
		$service = $make('VDM\\Joomla\\Componentbuilder\\Package\\Remote\\' . $direction . $area);
		$set($service, 'config', $config);
		$set($service, 'grep', $grep);

		if ($direction === 'Set')
		{
			$service->repos = [$repository, $second];
		}

		$container->set($area . '.Remote.' . $direction, $service, true);
		$container->get($area . '.Remote.' . $direction);
	}
}

$container->get('Utilities.Normalize');
$verifier = new FileResults();

try
{
	$check($verifier->inspect([], $container, true)['complete'], 'No file dependencies require no fabricated file proof.');
	mkdir($root . '/images', 0700);
	file_put_contents($root . '/images/example.json', '{"native":"exact file bytes"}');
	$normalized = $normalizer->path('images/example.json', 'images');
	$file = ['entity' => 'file', 'table' => 'file_system', 'key' => $normalized['key'], 'value' => $normalized['path'], 'target' => 'images'];
	$path = 'src/file_folder/' . $file['key'];
	$index = (object) [$file['key'] => (object) ['guid' => $file['key'], 'path' => $path]];

	foreach ([$repository, $second] as $repo)
	{
		foreach (['read_branch', 'write_branch'] as $branch)
		{
			$git->indexes[$repo->repository . ':' . $repo->{$branch}] = $index;
			$git->content[$repo->repository . ':' . $repo->{$branch} . ':' . $path] = file_get_contents($root . '/images/example.json');
		}
	}

	$result = $verifier->inspect([$file], $container, true);
	$check($result['complete'] && count($result['records']) === 2, 'Push independently reads exact file bytes on every native write branch.');
	$check(array_column($git->reads, 2) === ['write-feature', 'write-feature', 'write-second', 'write-second'], 'Native write branches remain unmodified during verification.');
	$check($git->resets === 2, 'Every native remote session is reset after its fresh reads.');
	$git->content['two:write-second:' . $path] = 'different bytes';
	$result = $verifier->inspect([$file], $container, true);
	$check(!$result['complete'] && $result['failedCount'] === 1, 'One divergent write repository cannot claim complete success.');
	$git->reads = [];
	$result = $verifier->inspect([$file], $container, false, $second);
	$check($result['complete'] && array_column($git->reads, 2) === ['read-second', 'read-second'], 'Import verification honors the explicitly selected repository and read branch.');
	$default = clone $second;
	$default->read_branch = 'default';
	$git->indexes['two:'] = $index;
	$git->content['two::' . $path] = file_get_contents($root . '/images/example.json');
	$git->reads = [];
	$result = $verifier->inspect([$file], $container, false, $default);
	$check($result['complete'] && array_column($git->reads, 2) === [null, null] && $default->read_branch === 'default',
		'Explicit native default branches are normalized without mutating the approved repository object.');
	$git->indexes['one:read-feature'] = (object) [];
	$git->reads = [];
	$result = $verifier->inspect([$file], $container, false);
	$check($result['complete'] && array_column($git->reads, 2) === ['read-feature', 'read-second', 'read-second'], 'Import follows configured repository order with fresh indexes.');
	$git->content['two:read-second:' . $path] = 'deliberately divergent remote content';
	$git->reads = [];
	$result = $verifier->inspect([$file], $container, false, $second, [$file['key'] => $file['value']]);
	$check($result['complete'] && $git->reads === [] && $result['records'][0]['scope'] === 'retained local dependency',
		'Get/init verifies deliberately retained local files without requiring unrelated remote equality.');
	$result = $verifier->inspect([$file], $container, false, $second);
	$check(!$result['complete'] && $result['failedCount'] === 1, 'Forced import still requires remote equality for the same divergent bytes.');
	$git->content['two:read-second:' . $path] = file_get_contents($root . '/images/example.json');
	$lazy = new Container();
	$constructed = false;
	$lazy->share('File.Remote.Set', static function () use (&$constructed): object { $constructed = true; return new stdClass(); });
	$result = $verifier->inspect([$file], $lazy, true);
	$check(!$result['complete'] && $result['unverifiedCount'] === 1 && !$constructed, 'Missing native handlers stay unverified without constructing mutable services.');

	mkdir($root . '/images/folder', 0700);
	file_put_contents($root . '/images/folder/code.php', '<?php echo "inert bytes";');
	file_put_contents($root . '/images/folder/.hidden', 'included native hidden file');
	file_put_contents($root . '/images/folder/backup~', 'excluded native backup');
	$normalized = $normalizer->path('images/folder', 'images');
	$folder = ['entity' => 'folder', 'table' => 'file_system', 'key' => $normalized['key'], 'value' => $normalized['path'], 'target' => 'images'];
	$folderPath = 'src/file_folder/' . $folder['key'];
	$zipPath = $root . '/fixture.zip';
	$zip = new ZipArchive();
	$zip->open($zipPath, ZipArchive::CREATE);
	$zip->addFile($root . '/images/folder/code.php', 'code.php');
	$zip->addFile($root . '/images/folder/.hidden', '.hidden');
	$zip->close();
	$zipBytes = file_get_contents($zipPath);

	foreach ([$repository, $second] as $repo)
	{
		$git->indexes[$repo->repository . ':' . $repo->write_branch] = (object) [$folder['key'] => (object) ['path' => $folderPath]];
		$git->content[$repo->repository . ':' . $repo->write_branch . ':' . $folderPath] = $zipBytes;
	}

	$result = $verifier->inspect([$folder], $container, true);
	$check($result['complete'], 'Folder proof compares decompressed member hashes with native exclusions without executing source.');
	file_put_contents($root . '/images/folder/code.php', 'changed source');
	$result = $verifier->inspect([$folder], $container, true);
	$check(!$result['complete'] && $result['failedCount'] === 2, 'Folder content changes invalidate every divergent repository proof.');
	$zip->open($zipPath, ZipArchive::OVERWRITE);
	$zip->addFromString('../escape.txt', 'must never extract');
	$zip->close();
	$git->content['one:write-feature:' . $folderPath] = file_get_contents($zipPath);
	$result = $verifier->inspect([$folder], $container, true);
	$check(!$result['complete'] && $result['unverifiedCount'] === 1 && !file_exists(dirname($root) . '/escape.txt'), 'Unsafe archive paths are rejected without extraction.');

	$bad = $file;
	$bad['value'] = '../outside';
	$result = $verifier->inspect([$bad], $container, false);
	$check(!$result['complete'] && $result['unverifiedCount'] === 1, 'Native path reconstruction cannot authorize files outside Joomla.');
	$check($verifier->inspect([$file, $file], $container, false, $second)['complete'], 'Repeated identical native dependencies are verified once.');

	// Exercise the exact native method called by GetContent::reset(). Native
	// writers provide the actual index; only external repository I/O is replaced.
	foreach ([[$file, '{"native":"exact file bytes"}'], [$folder, $zipBytes]] as [$dependency, $remoteBytes])
	{
		$area = ucfirst($dependency['entity']);
		$writer = $container->get($area . '.Remote.Set');
		$reader = $container->get($area . '.Remote.Get');
		$nativeIndex = $writer->getIndexItem((object) $dependency);
		$check(array_keys($nativeIndex) === ['name', 'path', 'guid']
			&& !isset($nativeIndex['value'], $nativeIndex['target']),
			$area . ' native writer index omits the restoration path and target consumed by reset.');
		$nativeRepo = clone $second;
		unset($nativeRepo->index);
		$git->indexes['two:read-second'] = (object) [$dependency['key'] => (object) $nativeIndex];
		$git->content['two:read-second:' . $nativeIndex['path']] = $remoteBytes;
		$grep = (new ReflectionProperty($reader, 'grep'))->getValue($reader);
		$set($grep, 'entity', 'file_system');
		$set($grep, 'tracker', new VDM\Joomla\Componentbuilder\Package\Dependency\Tracker());
		$set($reader, 'normalize', $normalizer);
		$set($reader, 'messages', new VDM\Joomla\Componentbuilder\Package\MessageBus());
		$set($reader, 'tracker', new VDM\Joomla\Componentbuilder\Package\Dependency\Tracker());
		$nativePath = $normalizer->full($dependency['value'], $dependency['target']);
		$observedFile = $dependency['entity'] === 'file' ? $nativePath : $nativePath . '/code.php';
		file_put_contents($observedFile, 'locally divergent native ' . $dependency['entity']);
		$before = hash_file('sha256', $observedFile);
		$restored = $reader->item($dependency['key'], ['remote'], $nativeRepo);
		$check($restored === false && hash_file('sha256', $observedFile) === $before,
			$area . ' native reset item leaves divergent local bytes untouched with its writer-generated index.');
		$result = $verifier->inspect([$dependency], $container, false, $second);
		$check(!$result['complete'] && $result['failedCount'] === 1,
			$area . ' native reset failure cannot become a verified MCP completion.');

		// Adding only the missing native metadata makes the same real store path
		// restore the file/archive. No upstream implementation is changed.
		$git->indexes['two:read-second'] = (object) [$dependency['key'] => (object) ($nativeIndex
			+ ['value' => $dependency['value'], 'target' => $dependency['target']])];
		unset($nativeRepo->index);
		$set($reader, 'tracker', new VDM\Joomla\Componentbuilder\Package\Dependency\Tracker());
		$restored = $reader->item($dependency['key'], ['remote'], $nativeRepo);
		$check($restored === true && hash_file('sha256', $observedFile) !== $before,
			$area . ' native reset item restores bytes once its index supplies value and target.');
		$check($verifier->inspect([$dependency], $container, false, $second)['complete'],
			$area . ' independently restored native bytes satisfy the same MCP read-back verifier.');
	}

	echo 'Native JCB file verification: ' . $checks . ' checks passed.' . PHP_EOL;
}
finally
{
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($items as $item)
	{
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($root);
}
