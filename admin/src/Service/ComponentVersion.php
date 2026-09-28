<?php
/**
 * @package    JoomEngine.Mcp
 * @created    28 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use RuntimeException;
use SimpleXMLElement;


/**
 * Reads the release identity from Joomla's native component manifest.
 *
 * @since 0.1.1
 */
final class ComponentVersion
{
	/** @var ?string Manifest version cached for the current PHP process. @since 0.1.1 */
	private static ?string $version = null;

	/**
	 * Resolve the same manifest before and after Joomla installs the administrator files.
	 *
	 * Joomla copies the component manifest alongside the installed administrator
	 * payload. In a source checkout it lives immediately above the admin directory.
	 *
	 * @return string Released component version; never a guessed or stale fallback.
	 * @throws RuntimeException When the native component manifest is missing or invalid.
	 * @since 0.1.1
	 */
	public static function get(): string
	{
		if (self::$version !== null)
		{
			return self::$version;
		}

		$administrator = dirname(__DIR__, 2);
		$path = $administrator . '/joomengine_mcp.xml';

		if (!is_file($path) && basename($administrator) === 'admin')
		{
			$path = dirname($administrator) . '/joomengine_mcp.xml';
		}

		if (!is_file($path))
		{
			throw new RuntimeException('The JoomEngine MCP component manifest is missing.');
		}

		$previous = libxml_use_internal_errors(true);

		try
		{
			$manifest = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		$version = $manifest === false ? '' : trim((string) $manifest->version);

		if ($manifest === false || $manifest->getName() !== 'extension'
			|| (string) $manifest['type'] !== 'component' || (string) $manifest->element !== 'com_joomengine_mcp'
			|| count($manifest->version) !== 1
			|| preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) !== 1)
		{
			throw new RuntimeException('The JoomEngine MCP component manifest has an invalid release version.');
		}

		self::$version = $version;

		return self::$version;
	}
}
