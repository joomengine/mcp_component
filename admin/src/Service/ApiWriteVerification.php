<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use stdClass;


/**
 * Field-specific postconditions for reviewed native Joomla API representations.
 *
 * These contracts do not change approved input or general JSON equality. A
 * customized binding cannot inherit a native exception from its action name alone.
 *
 * @since 1.0.1
 */
final class ApiWriteVerification
{
	/** @var array<string,string> Native resource paths with reviewed field contracts. @since 1.0.1 */
	private const ROUTES = [
		'banners.categories' => '/v1/banners/categories',
		'contacts.categories' => '/v1/contacts/categories',
		'newsfeeds.categories' => '/v1/newsfeeds/categories',
		'content.categories' => '/v1/content/categories',
		'content.articles' => '/v1/content/articles',
		'users.users' => '/v1/users',
		'modules.site' => '/v1/modules/site',
		'modules.administrator' => '/v1/modules/administrator',
	];

	/** @var array<string,string[]> Reviewed fields exposed by Joomla as empty Registry arrays. @since 1.0.5 */
	private const EMPTY_REGISTRIES = [
		'banners.categories' => ['params'],
		'contacts.categories' => ['params'],
		'newsfeeds.categories' => ['params'],
		'content.categories' => ['params'],
		'content.articles' => ['images', 'urls', 'metadata'],
	];

	/**
	 * Compare only a reviewed field; null preserves the general strict comparison.
	 *
	 * @param array $resolved Authorized write definition.
	 * @param array $read Authorized independent read definition.
	 * @param string $field Requested field name.
	 * @param mixed $desired Approved field value.
	 * @param mixed $observed Independently read field value.
	 * @return ?bool Contract comparison, or null when no exception applies.
	 * @since 1.0.1
	 */
	public static function compare(array $resolved, array $read, string $field, mixed $desired, mixed $observed): ?bool
	{
		$base = self::resource($resolved);

		if ($base === null || !self::nativeRead($read, $base))
		{
			return null;
		}

		if (in_array($field, self::EMPTY_REGISTRIES[$base] ?? [], true)
			&& $desired instanceof stdClass && (array) $desired === [])
		{
			// Joomla's empty Registry is exposed as [] by these native API fields.
			return $observed === [] || ($observed instanceof stdClass && (array) $observed === []);
		}

		if ($base === 'users.users' && $field === 'groups')
		{
			$expected = self::memberships($desired);
			$actual = self::memberships($observed);

			return $expected !== null && $actual !== null && $expected === $actual;
		}

		if (in_array($base, ['modules.site', 'modules.administrator'], true) && $field === 'ordering'
			&& ($resolved['binding']['configuration']['operation'] ?? '') === 'create')
		{
			$expected = self::integral($desired);
			$actual = self::integral($observed);

			// JSON Schema integer includes integral JSON numbers such as 1.0.
			return $expected !== null && $actual !== null && $expected === $actual;
		}

		return null;
	}

	/**
	 * Disclose native assignment without predicting the next position-wide order.
	 *
	 * @param array $resolved Authorized write definition.
	 * @param array $input Validated approved input.
	 * @return ?array Automatic ordering preview, otherwise null.
	 * @since 1.0.1
	 */
	public static function automaticOrdering(array $resolved, array $input): ?array
	{
		$base = self::resource($resolved);

		if (!in_array($base, ['modules.site', 'modules.administrator'], true)
			|| ($resolved['binding']['configuration']['operation'] ?? '') !== 'create'
			|| array_key_exists('ordering', (array) ($resolved['binding']['configuration']['body_defaults'] ?? []))
			|| (array_key_exists('ordering', $input['data'] ?? []) && self::integral($input['data']['ordering']) !== 0))
		{
			return null;
		}

		return ['mode' => 'automatic', 'reason' => 'Zero or omitted ordering lets Joomla assign the next order for the module position. The assigned value is verified independently after creation.'];
	}

	/**
	 * Verify the native assignment against the exact independently read module.
	 *
	 * @param array $resolved Authorized write definition.
	 * @param array $read Authorized independent read definition.
	 * @param mixed $id Expected created module identifier.
	 * @param array $mutation Native mutation item.
	 * @param array $record Independent read-back item.
	 * @return array Observed generated value, never a claim that requested zero persisted.
	 * @since 1.0.1
	 */
	public static function verifyOrdering(array $resolved, array $read, mixed $id, array $mutation, array $record): array
	{
		$base = self::resource($resolved);
		$expectedId = self::identifier($id);
		$assigned = self::integral($mutation['ordering'] ?? null);
		$observed = self::integral($record['ordering'] ?? null);

		if (!in_array($base, ['modules.site', 'modules.administrator'], true) || !self::nativeRead($read, $base)
			|| $expectedId === null || self::identifier($mutation['id'] ?? null) !== $expectedId
			|| self::identifier($record['id'] ?? null) !== $expectedId)
		{
			return ['mode' => 'automatic', 'status' => 'uncertain', 'reason' => 'The native mutation and independent read-back must identify the same created module.'];
		}

		if ($assigned === null || $observed === null || $assigned !== $observed)
		{
			return ['mode' => 'automatic', 'status' => 'uncertain', 'reason' => 'The native assigned order was unavailable or changed during independent read-back.'];
		}

		return ['mode' => 'automatic', 'status' => 'verified', 'value' => $observed,
			'reason' => 'The native assigned order agrees with an independent read of the created module.'];
	}

	/** @param array $resolved Authorized write definition. @return ?string Native create/update resource with an exact reviewed binding. @since 1.0.1 */
	private static function resource(array $resolved): ?string
	{
		if (!preg_match('/\A(.+)\.(create|update)\z/D', $resolved['action']['name'] ?? '', $parts)
			|| !isset(self::ROUTES[$parts[1]]))
		{
			return null;
		}

		$binding = $resolved['binding'] ?? [];
		$config = $binding['configuration'] ?? [];
		$route = self::ROUTES[$parts[1]] . ($parts[2] === 'update' ? '/:id' : '');

		return ($binding['track'] ?? '') === 'api' && ($binding['handler'] ?? '') === 'api.request'
			&& ($config['operation'] ?? '') === $parts[2] && ($config['route'] ?? '') === $route
			&& ($config['method'] ?? '') === ($parts[2] === 'create' ? 'POST' : 'PATCH')
			&& ($config['read_action'] ?? '') === $parts[1] . '.get'
			&& ($config['authentication'] ?? '') === 'joomla-api-token' ? $parts[1] : null;
	}

	/** @param array $read Authorized read definition. @param string $base Reviewed resource. @return bool Whether this is its native independent item read. @since 1.0.1 */
	private static function nativeRead(array $read, string $base): bool
	{
		$binding = $read['binding'] ?? [];
		$config = $binding['configuration'] ?? [];

		return ($read['action']['name'] ?? '') === $base . '.get' && ($binding['track'] ?? '') === 'api'
			&& ($binding['handler'] ?? '') === 'api.request' && ($config['method'] ?? '') === 'GET'
			&& ($config['route'] ?? '') === self::ROUTES[$base] . '/:id'
			&& ($config['authentication'] ?? '') === 'joomla-api-token' && empty($config['select_fields']);
	}

	/** @param mixed $value Native ID list or ID-keyed membership map. @return ?int[] Sorted unique exact IDs, or null for malformed membership data. @since 1.0.1 */
	private static function memberships(mixed $value): ?array
	{
		if (!is_array($value) && !$value instanceof stdClass)
		{
			return null;
		}

		$list = is_array($value) && array_is_list($value);
		$members = [];

		foreach ((array) $value as $key => $member)
		{
			$id = self::integral($member);

			if ($id === null || $id < 1 || (!$list && self::identifier($key) !== $id))
			{
				return null;
			}

			$members[$id] = $id;
		}

		$members = array_values($members);
		sort($members, SORT_NUMERIC);

		return $members;
	}

	/** @param mixed $value Native identifier. @return ?int Exact positive safe integer. @since 1.0.1 */
	private static function identifier(mixed $value): ?int
	{
		$integer = self::integer($value);

		return $integer !== null && $integer > 0 ? $integer : null;
	}

	/** @param mixed $value Native integral number for field-specific contracts. @return ?int Exact safe integer value. @since 1.0.1 */
	private static function integral(mixed $value): ?int
	{
		if (is_float($value) && is_finite($value) && floor($value) === $value
			&& $value >= -9007199254740991 && $value <= 9007199254740991)
		{
			return (int) $value;
		}

		return self::integer($value);
	}

	/** @param mixed $value Native integer or canonical decimal string. @return ?int Exact safe integer, without coercing booleans or fractions. @since 1.0.1 */
	private static function integer(mixed $value): ?int
	{
		if ((is_int($value) || (is_string($value) && preg_match('/\A(?:0|-?[1-9][0-9]{0,15})\z/D', $value) === 1))
			&& (float) $value >= -9007199254740991 && (float) $value <= 9007199254740991)
		{
			return (int) $value;
		}

		return null;
	}
}
