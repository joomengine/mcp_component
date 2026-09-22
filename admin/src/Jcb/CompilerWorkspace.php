<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Private native compiler scratch space, separate from retained archive staging.
 *
 * @since 0.1.1
 */
final class CompilerWorkspace
{
	/** @var string|null Random directory created exclusively by this instance. @since 0.1.1 */
	private ?string $directory = null;

	/** @param string $parent Reviewed private owner storage. @param string $webRoot Installed Joomla public root. @since 0.1.1 */
	public function __construct(string $parent, string $webRoot)
	{
		$base = realpath($parent);
		$web = realpath($webRoot);
		if ($base === false || $web === false || !is_dir($base) || !is_writable($base) || is_link($parent)
			|| $base === $web || str_starts_with($base, $web . DIRECTORY_SEPARATOR))
		{
			throw new OperationException('JCB_WORKSPACE_STORAGE', 'Compiler workspace storage must be writable and outside the Joomla web root.');
		}
		$path = $base . DIRECTORY_SEPARATOR . 'joomengine-mcp-compile-' . bin2hex(random_bytes(16));
		if (!mkdir($path, 0700))
		{
			throw new OperationException('JCB_WORKSPACE_STORAGE', 'The private compiler workspace could not be created.');
		}
		$this->directory = $path;
		if (!chmod($path, 0700))
		{
			$this->close();
			throw new OperationException('JCB_WORKSPACE_STORAGE', 'The private compiler workspace could not be secured.');
		}
	}

	/** @return string Existing native temporary path. @since 0.1.1 */
	public function path(): string
	{
		if ($this->directory === null)
		{
			throw new OperationException('JCB_WORKSPACE_STORAGE', 'The compiler workspace has already been closed.');
		}
		return $this->directory;
	}

	/** @return void Remove only this instance's scratch tree without following symbolic links. @since 0.1.1 */
	public function close(): void
	{
		if ($this->directory === null || !is_dir($this->directory) || is_link($this->directory))
		{
			return;
		}
		try
		{
			$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory,
				FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($items as $item)
			{
				$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
			}
			if (@rmdir($this->directory))
			{
				$this->directory = null;
			}
		}
		catch (\Throwable)
		{
			// Cleanup cannot invalidate already-verified retained archives. A failed
			// cleanup leaves only a random owner-private directory outside the site.
		}
	}

	/** @since 0.1.1 */
	public function __destruct()
	{
		$this->close();
	}
}
