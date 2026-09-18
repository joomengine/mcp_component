<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use Opis\JsonSchema\Validator;
use stdClass;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Validates inert, locally referenced JSON Schema and bounded action arguments.
 *
 * @since 0.1.0
 */
final class SchemaValidator
{
	/** @var Validator JSON Schema implementation with external resolution disabled. @since 0.1.0 */
	private Validator $validator;

	/** @var array<string,object> Bounded request-local parsed-schema cache. @since 0.1.0 */
	private array $schemas = [];

	/** @since 0.1.0 Construct the non-networking JSON Schema validator. */
	public function __construct()
	{
		$this->validator = new Validator(null, 1, true);
		$this->validator->loader()->setResolver(null);
	}

	/**
	 * Parse an inert schema, rejecting executable extensions and external references.
	 *
	 * @param string $document Persisted JSON Schema object.
	 * @return object Parsed schema with JSON object/list distinctions retained.
	 * @since 0.1.0
	 */
	public function document(string $document): object
	{
		$hash = hash('sha256', $document);

		if (isset($this->schemas[$hash]))
		{
			return $this->schemas[$hash];
		}

		$schema = Json::decode($document, false, 262144);

		if (!$schema instanceof stdClass)
		{
			throw new OperationException('INVALID_SCHEMA', 'An object-shaped JSON Schema document is required.');
		}

		$this->inspect($schema);

		try
		{
			$this->validator->loader()->loadObjectSchema($schema);
		}
		catch (Throwable)
		{
			throw new OperationException('INVALID_SCHEMA', 'The configured JSON Schema is not valid.');
		}

		if (count($this->schemas) >= 1000)
		{
			$this->schemas = [];
			$this->validator->loader()->clearCache();
		}

		return $this->schemas[$hash] = $schema;
	}

	/**
	 * Apply declared defaults and validate arguments without type coercion.
	 *
	 * @param array<string,mixed> $arguments Action or tool arguments.
	 * @param string $document Schema from the current authorized database record.
	 * @return array<string,mixed> Validated arguments with declared defaults.
	 * @since 0.1.0
	 */
	public function input(array $arguments, string $document): array
	{
		$schema = $this->document($document);

		if (($schema->type ?? null) !== 'object')
		{
			throw new OperationException('INVALID_SCHEMA', 'MCP input schemas must describe an object.');
		}

		$this->bounds($arguments);
		Json::encode((object) $arguments, 1048576);
		$data = $this->shape($arguments, $schema, true);

		try
		{
			$result = $this->validator->validate($data, $schema);
		}
		catch (Throwable)
		{
			throw new OperationException('INVALID_SCHEMA', 'The configured argument schema cannot be evaluated safely.');
		}

		if (!$result->isValid())
		{
			$error = $result->error();

			throw new OperationException('INVALID_INPUT', 'Arguments do not match the current action schema.', [
				'keyword' => $error?->keyword(), 'path' => $error?->data()->fullPath() ?? [],
			]);
		}

		return Json::decode(Json::encode($data));
	}

	/**
	 * Validate structured output without adding defaults or exposing returned secrets.
	 *
	 * @param array<string,mixed> $value Structured result.
	 * @param string $document Declared output schema.
	 * @return void
	 * @since 0.1.0
	 */
	public function output(array $value, string $document): void
	{
		$schema = $this->document($document);

		if (!$this->validator->validate($this->shape($value, $schema, false), $schema)->isValid())
		{
			throw new OperationException('OUTPUT_CONTRACT_FAILED', 'The operation result did not match its declared output schema.');
		}
	}

	/** @param mixed $node Schema subtree. @param int $depth Current depth. @return void @since 0.1.0 */
	private function inspect(mixed $node, int $depth = 0): void
	{
		if ($depth > 32)
		{
			throw new OperationException('INVALID_SCHEMA', 'The schema nesting limit was exceeded.');
		}

		if (!is_array($node) && !$node instanceof stdClass)
		{
			return;
		}

		foreach ((array) $node as $key => $value)
		{
			if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true)
				&& (!is_string($value) || !str_starts_with($value, '#')))
			{
				throw new OperationException('INVALID_SCHEMA', 'Schema references must stay inside the stored document.');
			}

			if (is_string($key) && str_starts_with($key, '$')
				&& !in_array($key, ['$schema', '$id', '$anchor', '$defs', '$ref', '$dynamicRef', '$dynamicAnchor', '$recursiveRef', '$recursiveAnchor', '$comment'], true))
			{
				throw new OperationException('INVALID_SCHEMA', 'Executable JSON Schema extensions are not allowed.');
			}

			$this->inspect($value, $depth + 1);
		}
	}

	/** @param mixed $value Untrusted input. @param int $depth Current depth. @return void @since 0.1.0 */
	private function bounds(mixed $value, int $depth = 0): void
	{
		if ($depth > 12 || (is_float($value) && !is_finite($value)))
		{
			throw new OperationException('INVALID_INPUT', 'The input nesting or numeric bounds were exceeded.');
		}

		if ($value instanceof stdClass)
		{
			$value = get_object_vars($value);
		}

		if (is_object($value) || is_resource($value))
		{
			throw new OperationException('INVALID_INPUT', 'Only JSON-compatible argument values are permitted.');
		}

		if (!is_array($value))
		{
			return;
		}

		if (count($value) > (array_is_list($value) ? 10000 : 512))
		{
			throw new OperationException('INVALID_INPUT', 'The input collection size limit was exceeded.');
		}

		foreach ($value as $key => $child)
		{
			if (is_string($key) && (strlen($key) > 255 || $key === '' || preg_match('/[\x00-\x1f]/', $key) === 1
				|| in_array($key, ['__proto__', 'prototype', 'constructor'], true)))
			{
				throw new OperationException('INVALID_INPUT', 'An input field name is not permitted.');
			}

			$this->bounds($child, $depth + 1);
		}
	}

	/** @param mixed $value JSON data. @param mixed $schema Matching schema node. @param bool $defaults Whether declared defaults apply. @return mixed Shape-preserving JSON data. @since 0.1.0 */
	private function shape(mixed $value, mixed $schema, bool $defaults): mixed
	{
		if (!is_array($value) && !$value instanceof stdClass)
		{
			return $value;
		}

		$isObject = $value instanceof stdClass || !array_is_list((array) $value) || (($schema->type ?? null) === 'object' && $value === []);
		$value = (array) $value;

		if ($isObject && $defaults)
		{
			foreach ((array) ($schema->properties ?? []) as $key => $property)
			{
				if (!array_key_exists($key, $value) && $property instanceof stdClass && property_exists($property, 'default'))
				{
					$value[$key] = $property->default;
				}
			}
		}

		foreach ($value as $key => $child)
		{
			$childSchema = $isObject ? ($schema->properties->{$key} ?? $schema->additionalProperties ?? null) : ($schema->items ?? null);
			$value[$key] = $this->shape($child, $childSchema, $defaults);
		}

		return $isObject ? (object) $value : $value;
	}
}
