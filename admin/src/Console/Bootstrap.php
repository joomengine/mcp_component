<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Console;


/**
 * Loads the installed CLI entry point's PHP requirement without executing it.
 *
 * @since 0.1.1
 */
final class Bootstrap
{
	/**
	 * Define the native installer constant omitted by a standalone worker entry point.
	 *
	 * @param string $root Fixed installed Joomla root, never a request-selected path.
	 * @return void
	 * @since 0.1.1
	 */
	public static function initialize(string $root): void
	{
		$file = $root . '/cli/joomla.php';
		$source = is_readable($file) ? file_get_contents($file, false, null, 0, 65537) : false;

		if (!is_string($source) || strlen($source) > 65536)
		{
			throw new \RuntimeException('The installed Joomla CLI entry point is unavailable or exceeds its bootstrap limit.');
		}

		$tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool =>
			!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$versions = [];
		foreach ($tokens as $index => $token)
		{
			if (!is_array($token) || $token[0] !== T_CONST
				|| ($tokens[$index + 1][0] ?? null) !== T_STRING
				|| ($tokens[$index + 1][1] ?? null) !== 'JOOMLA_MINIMUM_PHP')
			{
				continue;
			}

			$literal = $tokens[$index + 3] ?? null;
			if (($tokens[$index + 2] ?? null) !== '=' || !is_array($literal)
				|| $literal[0] !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$index + 4] ?? null) !== ';'
				|| preg_match('/\A([\x22\x27])([0-9]+\.[0-9]+\.[0-9]+)\1\z/D', $literal[1], $match) !== 1)
			{
				throw new \RuntimeException('The installed Joomla CLI PHP requirement is not a reviewed literal constant.');
			}
			$versions[] = $match[2];
		}

		if (count($versions) !== 1 || (defined('JOOMLA_MINIMUM_PHP') && JOOMLA_MINIMUM_PHP !== $versions[0]))
		{
			throw new \RuntimeException('The installed Joomla CLI PHP requirement is missing, ambiguous or inconsistent.');
		}
		if (version_compare(PHP_VERSION, $versions[0], '<'))
		{
			throw new \RuntimeException('This PHP runtime does not meet the installed Joomla CLI requirement.');
		}
		defined('JOOMLA_MINIMUM_PHP') || define('JOOMLA_MINIMUM_PHP', $versions[0]);
	}
}
