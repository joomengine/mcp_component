<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use stdClass;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Expose stored schemas without losing JSON object, array or default types.
 *
 * Schema consumers use PHP array access, but associative JSON decoding erases
 * empty objects and object keys that happen to form a numeric sequence. Retain
 * those maps as objects while keeping ordinary non-empty schema maps as arrays.
 *
 * @since 1.0.0
 */
final class SchemaDocument
{
	/**
	 * Decode an object schema for discovery and SDK construction.
	 *
	 * @param string $document Current authorized stored schema.
	 * @return array<string,mixed> Root schema with exact nested JSON value shapes.
	 * @since 1.0.0
	 */
	public static function decode(string $document): array
	{
		$schema = Json::decode($document, false, 262144);

		if (!$schema instanceof stdClass)
		{
			throw new OperationException('INVALID_SCHEMA', 'An object-shaped JSON Schema document is required.');
		}

		return (array) self::maps($schema);
	}

	/**
	 * Convert maps for PHP access while preserving their encoded JSON kind.
	 *
	 * Lists are visited without changing their keys, order or empty-array shape.
	 * This applies equally to schema keywords, defaults, enums and examples; no
	 * keyword-based guessing can turn a legitimate array value into an object.
	 *
	 * @param mixed $value Typed value from non-associative JSON decoding.
	 * @return mixed Equivalent JSON value with usable PHP schema maps.
	 * @since 1.0.0
	 */
	private static function maps(mixed $value): mixed
	{
		$isObject = $value instanceof stdClass;

		if (!is_array($value) && !$isObject)
		{
			return $value;
		}

		$map = (array) $value;

		foreach ($map as $key => $child)
		{
			$map[$key] = self::maps($child);
		}

		return $isObject && array_is_list($map) ? (object) $map : $map;
	}
}
