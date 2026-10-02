<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use HashContext;
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
		return self::encode(self::normalise($value));
	}

	/**
	 * Hash canonical inventory JSON without materializing an aggregate response.
	 *
	 * Individual values and nesting remain bounded; callers must separately bound
	 * inventory cardinality. Ordinary request and result encoding limits are unchanged.
	 *
	 * @param mixed $value JSON-compatible observed inventory.
	 * @return string SHA-256 of exactly the canonical JSON bytes.
	 * @since 1.0.6
	 */
	public static function canonicalHash(mixed $value): string
	{
		$context = hash_init('sha256');
		$bytes = 0;
		self::hashValue($context, $value, 0, $bytes);

		return hash_final($context);
	}

	/** @param HashContext $context Incremental digest. @param mixed $value JSON value. @param int $depth Bounded nesting. @param int $bytes Canonical aggregate bytes. @return void @since 1.0.6 */
	private static function hashValue(HashContext $context, mixed $value, int $depth, int &$bytes): void
	{
		if ($depth > 64)
		{
			throw new OperationException('RESULT_INVALID', 'The inventory exceeds the supported JSON nesting depth.');
		}

		$isObject = $value instanceof stdClass;
		if ($isObject)
		{
			$value = get_object_vars($value);
		}

		if (!is_array($value))
		{
			self::hashChunk($context, self::encode($value), $bytes);
			return;
		}

		$isList = !$isObject && array_is_list($value);
		if (!$isList)
		{
			ksort($value, SORT_STRING);
		}

		self::hashChunk($context, $isList ? '[' : '{', $bytes);
		$separator = '';
		foreach ($value as $key => $child)
		{
			self::hashChunk($context, $separator, $bytes);
			if (!$isList)
			{
				self::hashChunk($context, self::encode((string) $key) . ':', $bytes);
			}
			self::hashValue($context, $child, $depth + 1, $bytes);
			$separator = ',';
		}
		self::hashChunk($context, $isList ? ']' : '}', $bytes);
	}

	/** @param HashContext $context Incremental digest. @param string $chunk Exact canonical bytes. @param int $bytes Aggregate byte count. @return void @since 1.0.6 */
	private static function hashChunk(HashContext $context, string $chunk, int &$bytes): void
	{
		$bytes += strlen($chunk);
		if ($bytes > 67108864)
		{
			throw new OperationException('RESULT_TOO_LARGE', 'The canonical inventory exceeds its bounded aggregate size.');
		}
		hash_update($context, $chunk);
	}

	/**
	 * Expose ordinary JSON maps to PHP while retaining ambiguous object shapes.
	 *
	 * @param mixed $value JSON decoded with object/list distinctions retained.
	 * @return mixed Named maps as arrays; empty and numeric-only objects as stdClass.
	 * @since 0.1.2
	 */
	public static function native(mixed $value): mixed
	{
		$isObject = $value instanceof stdClass;

		if (!is_array($value) && !$isObject)
		{
			return $value;
		}

		$members = (array) $value;

		foreach ($members as $key => $child)
		{
			$members[$key] = self::native($child);
		}

		return $isObject && array_is_list($members) ? (object) $members : $members;
	}

	/** @param mixed $item JSON-compatible value. @return mixed Canonically ordered value without a cyclic recursive closure. @since 0.1.1 */
	private static function normalise(mixed $item): mixed
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
			$child = self::normalise($child);
		}

		unset($child);

		return $isList ? $item : (object) $item;
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
