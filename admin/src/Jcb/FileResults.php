<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\DI\Container;
use Joomla\DI\ContainerResource;
use ReflectionMethod;
use ReflectionProperty;
use ZipArchive;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Independently compares native package file dependencies with fresh repository bytes.
 * Only instantiated native readers/writers and their configured repositories are used.
 * Archives are inspected without extracting them into the Joomla installation.
 *
 * @since 0.1.1
 */
final class FileResults
{
	/** @var int Bound on each file or compressed archive. @since 0.1.1 */
	private const MAX_FILE = 33554432;

	/** @var int Bound on all local and decompressed bytes in one inspection. @since 0.1.1 */
	private const MAX_BYTES = 134217728;

	/** @var int Running verification byte budget. @since 0.1.1 */
	private int $bytes = 0;

	/**
	 * @param array $dependencies Native file/folder dependency records, including recursive dependencies.
	 * @param Container $container Already-executed builder's native container.
	 * @param bool $push Whether to check all approved write branches or the selected read branch.
	 * @param object|null $repository Validated native import repository, if explicitly selected.
	 * @param array $retained Native get/init categories for deliberately retained local dependencies.
	 * @return array Complete flag and safe hash evidence; no paths, content or credentials.
	 * @since 0.1.1
	 */
	public function inspect(array $dependencies, Container $container, bool $push, ?object $repository = null, array $retained = []): array
	{
		$this->bytes = 0;
		$records = [];
		$failed = 0;
		$unverified = 0;
		$seen = [];
		$reads = 0;

		foreach ($dependencies as $dependency)
		{
			$dependency = (array) $dependency;

			if (!in_array($dependency['entity'] ?? '', ['file', 'folder'], true))
			{
				continue;
			}

			$identity = $dependency['entity'] . ':' . ($dependency['key'] ?? '');
			$fingerprint = hash('sha256', Json::canonical($dependency));

			if (isset($seen[$identity]) && hash_equals($seen[$identity], $fingerprint))
			{
				continue;
			}

			$proof = ['entity' => $dependency['entity'], 'key' => (string) ($dependency['key'] ?? '')];

			try
			{
				if (isset($seen[$identity]) || count($seen) >= 500)
				{
					throw new OperationException('JCB_VERIFY_LIMIT', 'The native file dependencies are conflicting or exceed the verification limit.');
				}

				$seen[$identity] = $fingerprint;
				$this->validate($dependency);
				$area = ucfirst($dependency['entity']);
				$direction = $push ? 'Set' : 'Get';
				$service = $this->existing($container, $area . '.Remote.' . $direction,
					'VDM\\Joomla\\Componentbuilder\\Package\\Remote\\' . $direction . $area);
				$normalize = $this->existing($container, 'Utilities.Normalize',
					'VDM\\Joomla\\Componentbuilder\\Utilities\\Normalize');
				$path = $this->localPath($normalize->full($dependency['value'], $dependency['target']));
				$local = $dependency['entity'] === 'folder' ? $this->folder($path, $push) : $this->file($path);

				if (!$push && ($retained[$dependency['key']] ?? null) === $dependency['value'])
				{
					$records[] = $proof + ['matches' => true, 'scope' => 'retained local dependency',
						'localHash' => hash('sha256', Json::canonical($local))];
					continue;
				}

				$base = 'VDM\\Joomla\\Abstraction\\Remote\\' . $direction;
				$grep = (new ReflectionProperty($base, 'grep'))->getValue($service);
				$git = (new ReflectionProperty('VDM\\Joomla\\Abstraction\\Grep', 'contents'))->getValue($grep);
				$repos = $push ? $service->repos : ($repository === null ? (array) $grep->paths : [$repository]);
				$targeted = 0;
				$found = false;

				foreach ($repos as $repo)
				{
					if ($push && (empty($repo->write_branch) || $repo->write_branch === 'default'
						|| !(new ReflectionMethod($service, 'targetRepo'))->invoke($service, (object) $dependency, $repo)))
					{
						continue;
					}

					if (++$reads > 500)
					{
						throw new OperationException('JCB_VERIFY_LIMIT', 'The native file repository reads exceed the verification limit.');
					}

					$targeted++;
					$branch = $push ? $repo->write_branch : ($repo->read_branch
						?? (new ReflectionProperty('VDM\\Joomla\\Abstraction\\Grep', 'branch_name'))->getValue($grep));
					$git->setTarget($repo->target ?? 'gitea');
					$grep->loadApi($git, $repo->base ?? null, $repo->token ?? null);

					try
					{
						// A fresh index preserves the native repository selection order, not a cached Grep result.
						$index = $git->get($repo->organisation, $repo->repository, $service->getIndexPath(), $branch);
						$entry = is_object($index) ? ($index->{$dependency['key']} ?? null) : null;

						if (!$push && !is_object($entry))
						{
							continue;
						}

						$expectedPath = (new ReflectionMethod($service, 'index_map_IndexSettingsPath'))->invoke($service, (object) $dependency);
						$remotePath = $push ? $expectedPath : ($entry->path ?? null);
						$this->relativePath($remotePath);
						$content = $this->content($git, $repo, $remotePath, $branch);
						$remote = $dependency['entity'] === 'folder' ? $this->archive($content) : hash('sha256', $content);
						$matches = is_object($entry) && (!$push || ($entry->path ?? null) === $expectedPath) && $local === $remote;
						$records[] = $proof + ['repository' => (string) ($repo->guid ?? ''),
							'branchHash' => hash('sha256', (string) $branch), 'matches' => $matches,
							'localHash' => hash('sha256', Json::canonical($local)),
							'remoteHash' => hash('sha256', Json::canonical($remote))];
						$failed += $matches ? 0 : 1;
						$found = true;
					}
					finally
					{
						$git->reset_();
					}

					if (!$push && $found)
					{
						break;
					}
				}

				if ($targeted === 0 || !$found)
				{
					$failed++;
					$records[] = $proof + ['matches' => false, 'reason' => 'JCB_VERIFY_FILE_MISSING'];
				}
			}
			catch (\Throwable $error)
			{
				$unverified++;
				$records[] = $proof + ['matches' => false, 'reason' => $error instanceof OperationException
					? $error->getIdentifier() : 'JCB_FILE_READBACK_UNAVAILABLE'];
			}
		}

		return ['records' => $records, 'failedCount' => $failed, 'unverifiedCount' => $unverified,
			'complete' => $failed === 0 && $unverified === 0];
	}

	/** @param Container $container Executed native registry. @param string $key Reviewed key. @param string $class Reviewed exact class. @return object Existing native instance. @since 0.1.1 */
	private function existing(Container $container, string $key, string $class): object
	{
		$resource = $container->getResource($key);
		$instance = $resource === null ? null : (new ReflectionProperty(ContainerResource::class, 'instance'))->getValue($resource);

		if (!is_object($instance) || get_class($instance) !== $class)
		{
			throw new OperationException('JCB_VERIFY_UNAVAILABLE', 'The native operation did not instantiate a reviewed file handler.');
		}

		return $instance;
	}

	/** @param array $dependency Native dependency. @return void @since 0.1.1 */
	private function validate(array $dependency): void
	{
		if (($dependency['table'] ?? null) !== 'file_system' || !is_string($dependency['key'] ?? null)
			|| preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}(?:\.[a-z0-9]{1,16})?\z/Di', $dependency['key']) !== 1
			|| !is_string($dependency['value'] ?? null) || strlen($dependency['value']) > 4096
			|| str_contains($dependency['value'], "\0") || str_contains($dependency['value'], '://')
			|| !in_array($dependency['target'] ?? null, ['custom', 'compiler', 'images', 'image', 'full'], true))
		{
			throw new OperationException('JCB_VERIFY_FILE_IDENTITY', 'A native file dependency has no bounded reviewed identity.');
		}
	}

	/** @param mixed $path Native normalized absolute path. @return string Confined existing path. @since 0.1.1 */
	private function localPath(mixed $path): string
	{
		$root = defined('JPATH_ROOT') ? realpath(JPATH_ROOT) : false;
		$real = is_string($path) ? realpath($path) : false;

		if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
			|| !is_readable($real) || is_link($path))
		{
			throw new OperationException('JCB_VERIFY_FILE_PATH', 'The native file dependency is not a readable item inside this installation.');
		}

		return $real;
	}

	/** @param mixed $path Repository/archive relative name. @return void @since 0.1.1 */
	private function relativePath(mixed $path): void
	{
		if (!is_string($path) || $path === '' || strlen($path) > 4096 || $path[0] === '/'
			|| str_contains($path, '\\') || preg_match('/[\x00-\x1f:\x7f]/', $path)
			|| preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)~', $path))
		{
			throw new OperationException('JCB_VERIFY_FILE_PATH', 'The native repository content has an unsafe relative path.');
		}
	}

	/** @param int $bytes Bytes about to be read. @return void @since 0.1.1 */
	private function budget(int $bytes): void
	{
		$this->bytes += max(0, $bytes);

		if ($bytes < 0 || $bytes > self::MAX_FILE || $this->bytes > self::MAX_BYTES)
		{
			throw new OperationException('JCB_VERIFY_LIMIT', 'The native file bytes exceed the independent verification budget.');
		}
	}

	/** @param string $path Confined local file. @return string SHA256 of actual bytes. @since 0.1.1 */
	private function file(string $path): string
	{
		if (!is_file($path) || is_link($path))
		{
			throw new OperationException('JCB_VERIFY_FILE_MISSING', 'The native file dependency is not a regular file.');
		}

		$stream = fopen($path, 'rb');

		if ($stream === false)
		{
			throw new OperationException('JCB_VERIFY_FILE_MISSING', 'The native file dependency cannot be read.');
		}

		try
		{
			$metadata = fstat($stream);

			if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000)
			{
				throw new OperationException('JCB_VERIFY_FILE_PATH', 'Only native regular file bytes can be verified.');
			}

			$this->budget($metadata['size']);
			$context = hash_init('sha256');
			$read = 0;

			while (!feof($stream))
			{
				$bytes = fread($stream, 65536);

				if ($bytes === false || ($bytes === '' && !feof($stream)))
				{
					throw new OperationException('JCB_VERIFY_FILE_MISSING', 'A native file dependency became unreadable.');
				}

				$read += strlen($bytes);

				if ($read > $metadata['size'])
				{
					throw new OperationException('JCB_VERIFY_FILE_CHANGED', 'The native file grew during bounded verification.');
				}

				hash_update($context, $bytes);
			}

			if ($read !== $metadata['size'])
			{
				throw new OperationException('JCB_VERIFY_FILE_CHANGED', 'The native file changed during verification.');
			}

			return hash_final($context);
		}
		finally
		{
			fclose($stream);
		}
	}

	/** @param string $path Confined local directory. @param bool $push Match native packaging exclusions. @return array File-name to hash map. @since 0.1.1 */
	private function folder(string $path, bool $push): array
	{
		if (!is_dir($path))
		{
			throw new OperationException('JCB_VERIFY_FILE_MISSING', 'The native folder dependency is not a directory.');
		}

		$files = [];
		$pending = [''];
		$entries = 0;

		while ($pending !== [])
		{
			$prefix = array_pop($pending);
			$names = scandir($path . '/' . $prefix);

			if ($names === false)
			{
				throw new OperationException('JCB_VERIFY_FILE_MISSING', 'A native folder dependency cannot be read.');
			}

			foreach ($names as $name)
			{
				if ($name === '.' || $name === '..' || ($push && (in_array($name, ['.svn', 'CVS', '.DS_Store', '__MACOSX'], true) || str_contains($name, '~'))))
				{
					continue;
				}

				if (++$entries > 10000)
				{
					throw new OperationException('JCB_VERIFY_LIMIT', 'The native folder has too many entries for bounded verification.');
				}

				$relative = $prefix . $name;
				$this->relativePath($relative);
				$full = $path . '/' . $relative;

				if (is_link($full))
				{
					throw new OperationException('JCB_VERIFY_FILE_PATH', 'Symbolic links cannot prove native folder equivalence.');
				}

				if (is_dir($full))
				{
					$pending[] = $relative . '/';
				}
				else
				{
					$files[$relative] = $this->file($full);
				}
			}
		}

		ksort($files, SORT_STRING);
		return $files;
	}

	/** @param object $git Existing native Contents adapter. @param object $repo Existing approved repo. @param string $path Relative content path. @param string|null $branch Native branch. @return string Bounded exact remote bytes. @since 0.1.1 */
	private function content(object $git, object $repo, string $path, ?string $branch): string
	{
		$metadata = $git->metadata($repo->organisation, $repo->repository, $path, $branch);

		if (!is_object($metadata) || !is_int($metadata->size ?? null) || ($metadata->type ?? 'file') !== 'file')
		{
			throw new OperationException('JCB_VERIFY_FILE_MISSING', 'The native repository file has no bounded metadata.');
		}

		$this->budget($metadata->size);
		$content = ($metadata->encoding ?? null) === 'base64' && is_string($metadata->content ?? null)
			? base64_decode(str_replace(["\r", "\n"], '', $metadata->content), true)
			: $git->get($repo->organisation, $repo->repository, $path, $branch);

		if (!is_string($content) || strlen($content) !== $metadata->size)
		{
			throw new OperationException('JCB_VERIFY_FILE_CONTENT', 'Exact native repository bytes could not be obtained.');
		}

		return $content;
	}

	/** @param string $content Fresh bounded archive bytes. @return array Entry-name to hash map without extraction. @since 0.1.1 */
	private function archive(string $content): array
	{
		if (!class_exists(ZipArchive::class))
		{
			throw new OperationException('JCB_VERIFY_UNAVAILABLE', 'ZIP inspection is unavailable.');
		}

		$temporary = tempnam(sys_get_temp_dir(), 'mcp-verify-');

		if ($temporary === false)
		{
			throw new OperationException('JCB_VERIFY_UNAVAILABLE', 'A private archive inspection file could not be created.');
		}

		$zip = new ZipArchive();
		$opened = false;

		try
		{
			chmod($temporary, 0600);

			if (file_put_contents($temporary, $content) !== strlen($content) || $zip->open($temporary, ZipArchive::RDONLY) !== true)
			{
				throw new OperationException('JCB_VERIFY_FILE_CONTENT', 'The native folder content is not a readable ZIP archive.');
			}

			$opened = true;

			if ($zip->numFiles > 10000)
			{
				throw new OperationException('JCB_VERIFY_LIMIT', 'The native archive has too many entries.');
			}

			$files = [];
			$names = [];

			for ($index = 0; $index < $zip->numFiles; $index++)
			{
				$entry = $zip->statIndex($index);
				$name = $entry['name'] ?? null;
				$this->relativePath($name);

				if (isset($names[$name]))
				{
					throw new OperationException('JCB_VERIFY_FILE_CONTENT', 'Duplicate archive entries cannot prove native folder equivalence.');
				}

				$names[$name] = true;
				$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes);

				if ($operatingSystem === 3 && (($attributes >> 16) & 0170000) === 0120000)
				{
					throw new OperationException('JCB_VERIFY_FILE_PATH', 'Symbolic archive links cannot prove native folder equivalence.');
				}

				if (str_ends_with($name, '/'))
				{
					continue;
				}

				$this->budget((int) ($entry['size'] ?? -1));
				$bytes = $zip->getFromIndex($index);

				if (!is_string($bytes) || strlen($bytes) !== $entry['size'])
				{
					throw new OperationException('JCB_VERIFY_FILE_CONTENT', 'A native archive entry could not be read.');
				}

				$files[$name] = hash('sha256', $bytes);
			}

			ksort($files, SORT_STRING);
			return $files;
		}
		finally
		{
			if ($opened)
			{
				$zip->close();
			}

			unlink($temporary);
		}
	}
}
