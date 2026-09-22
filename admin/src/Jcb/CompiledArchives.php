<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use ZipArchive;


/**
 * Verifies native ZIP results and preserves them before native installer cleanup.
 * The capture root is installed Joomla configuration, never a command argument.
 *
 * @since 0.1.0
 */
final class CompiledArchives
{
	/** @var string Configured compiler output root. @since 0.1.0 */
	private string $root;
	/** @var string Reviewed private parent of archives awaiting durable retention. @since 0.1.1 */
	private string $stagingRoot;
	/** @var ?string Private staging directory when installation consumes the ZIP. @since 0.1.0 */
	private ?string $directory = null;
	/** @var array<string,array> Verified outputs keyed by native path. @since 0.1.0 */
	private array $captured = [];

	/** @param string $root Private native compiler tmp_path. @param string|null $stagingRoot Separate private parent when compiler scratch is removed. @since 0.1.0 */
	public function __construct(string $root, ?string $stagingRoot = null)
	{
		$real = realpath($root);
		$staging = realpath($stagingRoot ?? $root);

		if ($real === false || !is_dir($real) || !is_writable($real)
			|| $staging === false || !is_dir($staging) || !is_writable($staging))
		{
			throw new OperationException('JCB_ARTIFACT_ROOT', 'The native compiler output directory is unavailable.');
		}

		$this->root = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$this->stagingRoot = rtrim($staging, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Validate actual compiler return paths; never glob a shared temporary folder.
	 *
	 * @param array $paths Fixed native compiler outputPaths.
	 * @param bool $preserve Copy before native --install deletes its package.
	 * @return array Private descriptors for subsequent owned artifact ingestion.
	 * @since 0.1.0
	 */
	public function capture(array $paths, bool $preserve = false): array
	{
		if (count($paths) > 32)
		{
			throw new OperationException('JCB_ARTIFACT_LIMIT', 'The compiler returned more than 32 archives.');
		}

		foreach (array_unique($paths) as $path)
		{
			if (!is_string($path))
			{
				throw new OperationException('JCB_ARTIFACT_INVALID', 'A compiler archive must have a native file path.');
			}

			if (isset($this->captured[$path]))
			{
				continue;
			}

			$real = is_string($path) && !is_link($path) ? realpath($path) : false;
			$size = $real === false ? false : filesize($real);

			if ($real === false || !str_starts_with($real, $this->root) || !is_file($real) || !is_readable($real)
				|| strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'zip' || $size === false || $size < 1 || $size > 134217728)
			{
				throw new OperationException('JCB_ARTIFACT_MISSING', 'A compiler archive is missing or outside its reviewed output boundary.');
			}

			$zip = new ZipArchive();

			if ($zip->open($real, ZipArchive::CHECKCONS) !== true)
			{
				throw new OperationException('JCB_ARTIFACT_INVALID', 'The compiler output is not a valid ZIP archive.');
			}

			$entries = $zip->numFiles;
			$zip->close();

			if ($entries < 1)
			{
				throw new OperationException('JCB_ARTIFACT_INVALID', 'The compiler returned an empty archive.');
			}

			$hash = hash_file('sha256', $real);
			$target = $real;

			if (!is_string($hash))
			{
				throw new OperationException('JCB_ARTIFACT_CHANGED', 'The compiler output cannot be hashed.');
			}

			if ($preserve)
			{
				if ($this->directory === null)
				{
					$this->directory = $this->stagingRoot . 'joomengine-mcp-jcb-' . bin2hex(random_bytes(16));

					if (!mkdir($this->directory, 0700))
					{
						throw new OperationException('JCB_ARTIFACT_STORAGE', 'A private compiler staging directory could not be created.');
					}
				}

				$target = $this->directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';

				if (!copy($real, $target) || !chmod($target, 0600) || filesize($target) !== $size
					|| !is_string($hash) || !hash_equals($hash, (string) hash_file('sha256', $target)))
				{
					@unlink($target);
					throw new OperationException('JCB_ARTIFACT_CHANGED', 'The compiler output changed while it was being preserved.');
				}
			}

			$this->captured[$path] = ['path' => $target, 'name' => basename($real), 'size' => $size,
				'sha256' => $hash, 'staged' => $preserve];
		}

		return array_values($this->captured);
	}
}
