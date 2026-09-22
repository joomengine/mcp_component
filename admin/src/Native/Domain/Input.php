<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @git        JoomEngine MCP <https://github.com/joomengine/mcp_component>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSES/joomla-mcp.txt
 * @since      0.1.0
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Domain;


/**
 * Validate and bound native action input without executing supplied content.
 *
 * @since  0.1.0
 */
final class Input
{
	/**
	 * The maximum number of members in an input collection.
	 *
	 * @since  0.1.0
	 */
	private const MAX_COLLECTION_ITEMS = 1_000;

	/**
	 * The maximum depth of nested native input.
	 *
	 * @since  0.1.0
	 */
	private const MAX_NESTING_DEPTH = 6;

	/**
	 * The maximum byte length of an input string.
	 *
	 * @since  0.1.0
	 */
	private const MAX_VALUE_STRING_BYTES = 524_288;

	/**
	 * Reject fields outside the explicit input allowlist.
	 *
	 * @param   array<string, mixed>  $input    The input value.
	 * @param   list<string>          $allowed  The allowed value.
	 * @return  void
	 *
	 * @since  0.1.0
	 */
	public static function rejectUnknown(array $input, array $allowed): void
	{
		$unknown = array_diff(array_keys($input), $allowed);

		if ($unknown !== [])
		{
			throw new ActionException(
				'INVALID_INPUT',
				sprintf('Unknown input member "%s".', (string) reset($unknown)),
			);
		}
	}

	/**
	 * Read an integer constrained by its permitted inclusive bounds.
	 *
	 * @param   array<string, mixed>  $input    The input value.
	 * @param   string                $key      The key value.
	 * @param   int                   $default  The default value.
	 * @param   int                   $minimum  The minimum value.
	 * @param   int                   $maximum  The maximum value.
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	public static function integer(
		array $input,
		string $key,
		int $default,
		int $minimum,
		int $maximum,
	): int
	{
		$value = $input[$key] ?? $default;

		if (!is_int($value) || $value < $minimum || $value > $maximum)
		{
			throw new ActionException(
				'INVALID_INPUT',
				sprintf('Input "%s" must be an integer from %d to %d.', $key, $minimum, $maximum),
			);
		}

		return $value;
	}

	/**
	 * Read a bounded text value or its declared default.
	 *
	 * @param   array<string, mixed>  $input    The input value.
	 * @param   string                $key      The key value.
	 * @param   string                $default  The default value.
	 * @param   int                   $maximum  The maximum value.
	 * @return  string
	 *
	 * @since  0.1.0
	 */
	public static function text(array $input, string $key, string $default = '', int $maximum = 200): string
	{
		$value = $input[$key] ?? $default;

		if (!is_string($value) || strlen($value) > $maximum || str_contains($value, "\0"))
		{
			throw new ActionException(
				'INVALID_INPUT',
				sprintf('Input "%s" must be a string of at most %d bytes.', $key, $maximum),
			);
		}

		return $value;
	}

	/**
	 * Read a boolean using the documented native input conversions.
	 *
	 * @param   array<string, mixed>  $input    The input value.
	 * @param   string                $key      The key value.
	 * @param   bool                  $default  The default value.
	 * @return  bool
	 *
	 * @since  0.1.0
	 */
	public static function boolean(array $input, string $key, bool $default): bool
	{
		$value = $input[$key] ?? $default;

		if (!is_bool($value))
		{
			throw new ActionException('INVALID_INPUT', sprintf('Input "%s" must be a boolean.', $key));
		}

		return $value;
	}

	/**
	 * Read an object-shaped input map and validate its nested values.
	 *
	 * @param   array<string, mixed>  $input  The input value.
	 * @param   string                $key    The key value.
	 * @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	public static function object(array $input, string $key): array
	{
		$value = $input[$key] ?? null;

		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			throw new ActionException('INVALID_INPUT', sprintf('Input "%s" must be an object.', $key));
		}

		foreach (array_keys($value) as $member)
		{
			if (!is_string($member) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $member))
			{
				throw new ActionException('INVALID_INPUT', sprintf('Input "%s" contains an invalid member name.', $key));
			}
		}

		if (count($value) > self::MAX_COLLECTION_ITEMS)
		{
			throw new ActionException('INVALID_INPUT', sprintf('Input "%s" has too many members.', $key));
		}

		return $value;
	}

	/**
	 * Enforce collection, nesting and string limits on supplied data.
	 *
	 * @param   mixed   $value  The value value.
	 * @param   string  $key    The key value.
	 * @param   int     $depth  The depth value.
	 * @return  mixed
	 *
	 * @since  0.1.0
	 */
	public static function boundedValue(mixed $value, string $key, int $depth = 0): mixed
	{
		if ($value === null || is_bool($value) || is_int($value) || is_float($value))
		{
			return $value;
		}

		if (is_string($value))
		{
			if (strlen($value) > self::MAX_VALUE_STRING_BYTES || str_contains($value, "\0"))
			{
				throw new ActionException('INVALID_INPUT', sprintf('Input "%s" contains an invalid string value.', $key));
			}

			return $value;
		}

		if (!is_array($value) || $depth >= self::MAX_NESTING_DEPTH || count($value) > self::MAX_COLLECTION_ITEMS)
		{
			throw new ActionException('INVALID_INPUT', sprintf('Input "%s" contains an unsupported or oversized value.', $key));
		}

		$result = [];

		foreach ($value as $member => $nested)
		{
			if (!is_int($member) && (!is_string($member) || strlen($member) > 128 || str_contains($member, "\0")))
			{
				throw new ActionException('INVALID_INPUT', sprintf('Input "%s" contains an invalid nested member.', $key));
			}

			$result[$member] = self::boundedValue($nested, $key, $depth + 1);
		}

		return $result;
	}

	/**
	 * Read a value restricted to the declared finite choices.
	 *
	 * @param   array<string, mixed>  $input    The input value.
	 * @param   string                $key      The key value.
	 * @param   int|string            $default  The default value.
	 * @param   list<int|string>      $allowed  The allowed value.
	 * @return  int|string
	 *
	 * @since  0.1.0
	 */
	public static function choice(array $input, string $key, int|string $default, array $allowed): int|string
	{
		$value = $input[$key] ?? $default;

		if (!is_int($value) && !is_string($value) || !in_array($value, $allowed, true))
		{
			throw new ActionException(
				'INVALID_INPUT',
				sprintf('Input "%s" is not an allowed value.', $key),
			);
		}

		return $value;
	}
}
