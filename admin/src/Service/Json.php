<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use JsonException;
use stdClass;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Bounded JSON, stable fingerprints and cryptographically random state identifiers.
 *
 * @since 0.1.0
 */
final class Json
{
	/**
	 * Decode bounded persisted JSON without PHP object deserialization.
	 *
	 * @param string $value UTF-8 JSON text.
	 * @param bool $associative Whether JSON objects should become arrays.
	 * @param int $maximum Maximum encoded byte count.
	 * @return mixed Decoded JSON value.
	 * @since 0.1.0
	 */
	public static function decode(string $value, bool $associative = true, int $maximum = 8388608): mixed
	{
		if (strlen($value) > $maximum)
		{
			throw new OperationException('JSON_TOO_LARGE', 'The JSON value exceeds the allowed size.');
		}

		try
		{
			return json_decode($value, $associative, 64, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			throw new OperationException('INVALID_JSON', 'A valid bounded UTF-8 JSON value is required.');
		}
	}

	/**
	 * Encode a bounded value; never replace encoding errors with empty success.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @param int $maximum Maximum encoded bytes.
	 * @return string UTF-8 JSON.
	 * @since 0.1.0
	 */
	public static function encode(mixed $value, int $maximum = 8388608): string
	{
		try
		{
			$text = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		}
		catch (JsonException)
		{
			throw new OperationException('RESULT_INVALID', 'The result could not be represented safely as JSON.');
		}

		if (strlen($text) > $maximum)
		{
			throw new OperationException('RESULT_TOO_LARGE', 'The result exceeds the allowed size.');
		}

		return $text;
	}

	/**
	 * Canonicalize map ordering while retaining list order and JSON value types.
	 *
	 * @param mixed $value Value to bind into an approval or idempotency record.
	 * @return string Stable JSON serialization.
	 * @since 0.1.0
	 */
	public static function canonical(mixed $value): string
	{
		$normalise = static function (mixed $item) use (&$normalise): mixed
		{
			$isObject = $item instanceof stdClass;

			if ($isObject)
			{
				$item = get_object_vars($item);
			}

			if (!is_array($item))
			{
				return $item;
			}

			$isList = !$isObject && array_is_list($item);

			if (!$isList)
			{
				ksort($item, SORT_STRING);
			}

			foreach ($item as &$child)
			{
				$child = $normalise($child);
			}

			unset($child);

			return $isList ? $item : (object) $item;
		};

		return self::encode($normalise($value));
	}

	/** @return string Random RFC 4122 version-4 identifier. @since 0.1.0 */
	public static function uuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 15) | 64);
		$bytes[8] = chr((ord($bytes[8]) & 63) | 128);
		$hex = bin2hex($bytes);

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
	}

	/**
	 * Validate external UUIDs before durable-state lookup.
	 *
	 * @param mixed $value Candidate identifier.
	 * @return string Normalized identifier.
	 * @since 0.1.0
	 */
	public static function requireUuid(mixed $value): string
	{
		if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $value) !== 1)
		{
			throw new OperationException('INVALID_IDENTIFIER', 'A valid UUID is required.');
		}

		return strtolower($value);
	}
}
