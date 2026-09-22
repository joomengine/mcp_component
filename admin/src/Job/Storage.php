<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Job;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Resolves the same private owner boundary in HTTP, dispatch and native workers.
 * Native compilation and retained artifacts never use the public Joomla tree.
 *
 * @since 0.1.1
 */
final class Storage
{
	/**
	 * Resolve and secure the existing installation/principal/OS-owner partition.
	 *
	 * @param string $configured Trusted administrator-selected parent or empty default.
	 * @param string $root Installed Joomla web root.
	 * @param string $secret Native installation secret.
	 * @param string $principal Authenticated original principal identity.
	 * @return string Canonical existing private directory, mode 0700.
	 * @since 0.1.1
	 */
	public static function directory(string $configured, string $root, string $secret, string $principal): string
	{
		$parent = $configured !== '' ? $configured : sys_get_temp_dir();
		if ($configured !== '' && (!self::absolute($configured) || self::hasSymlink($configured)))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The private storage parent cannot contain a symbolic link or relative path.');
		}

		$base = realpath($parent);
		$site = realpath($root);
		if ($base === false || $site === false || !is_dir($base) || !is_dir($site) || !is_writable($base)
			|| $base === $site || str_starts_with($base, rtrim($site, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The private storage parent must be writable and outside the Joomla web root.');
		}

		$owner = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'local';
		$directory = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'joomengine-mcp-' . hash('sha256',
			$site . "\0" . $secret . "\0" . $principal . "\0" . $owner);

		return self::secure($directory);
	}

	/**
	 * Create the fixed private root for generated workspaces and staged archives.
	 *
	 * @param string $directory Resolved owner directory from directory().
	 * @return string Existing compiler directory, mode 0700.
	 * @since 0.1.1
	 */
	public static function compilerDirectory(string $directory): string
	{
		if (!self::absolute($directory) || self::hasSymlink($directory))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The compiler owner directory cannot contain a symbolic link or relative path.');
		}

		$directory = self::secure($directory);

		return self::secure($directory . DIRECTORY_SEPARATOR . 'compiler');
	}

	/** @param string $path Trusted absolute directory. @return string Canonical secured directory. @since 0.1.1 */
	private static function secure(string $path): string
	{
		if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The private storage directory could not be created safely.');
		}

		$canonical = realpath($path);
		if ($canonical === false || $canonical !== $path || !is_writable($path)
			|| (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) || !chmod($path, 0700))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The private storage directory is not controlled by this worker owner.');
		}

		return $canonical;
	}

	/** @param string $path Configuration path. @return bool Safe absolute path spelling. @since 0.1.1 */
	private static function absolute(string $path): bool
	{
		return str_starts_with($path, DIRECTORY_SEPARATOR) && strlen($path) <= 4096
			&& preg_match('/[\x00-\x1f\x7f]|(?:^|\/)\.\.?(?:\/|$)/', $path) !== 1;
	}

	/** @param string $path Absolute existing parent. @return bool Any symbolic-link path component. @since 0.1.1 */
	private static function hasSymlink(string $path): bool
	{
		$ancestor = rtrim($path, DIRECTORY_SEPARATOR);
		while ($ancestor !== '' && dirname($ancestor) !== $ancestor)
		{
			if (is_link($ancestor))
			{
				return true;
			}
			$ancestor = dirname($ancestor);
		}

		return false;
	}
}
