<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use stdClass;


/**
 * Compare approved menu item params with Joomla's native item representation.
 *
 * Joomla's menu item model merges the request variables of a component link
 * into the params it returns, although they are not stored in the params
 * column. Only those observed request variables may be added to the read-back;
 * every requested key must still be present with the same value.
 *
 * @since [[[NEXT_VERSION]]]
 */
final class MenuItemParams
{
	/**
	 * Compare params for an exact reviewed native menu write and item read pair.
	 *
	 * @param array $write Authorized write definition.
	 * @param array $read Independent authorized item read.
	 * @param mixed $desired Approved params value.
	 * @param mixed $observed Independently read params value.
	 * @param array $record Independently read menu item.
	 * @return ?bool Null preserves strict comparison for unrelated bindings and values.
	 * @since [[[NEXT_VERSION]]]
	 */
	public static function compare(array $write, array $read, mixed $desired, mixed $observed, array $record): ?bool
	{
		if (!self::supports($write, $read) || !self::bag($desired))
		{
			return null;
		}

		if (!self::bag($observed))
		{
			return false;
		}

		$expected = (array) $desired;
		$actual = (array) $observed;
		$request = self::request($record);

		foreach ($expected as $key => $value)
		{
			if (!array_key_exists($key, $actual) || !self::value($value, $actual[$key]))
			{
				return false;
			}
		}

		foreach (array_diff_key($actual, $expected) as $key => $value)
		{
			// Only a value Joomla merged from the stored link may be added.
			if (!array_key_exists($key, $request) || Json::canonical($request[$key]) !== Json::canonical($value))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Accept only the reviewed native API and core-entity menu item bindings.
	 *
	 * @param array $write Native write.
	 * @param array $read Native read.
	 * @return bool Whether both bindings are the reviewed menu item pair.
	 * @since [[[NEXT_VERSION]]]
	 */
	public static function supports(array $write, array $read): bool
	{
		if (preg_match('/\Amenus\.(site|administrator)-items\.(create|update)\z/D', $write['action']['name'] ?? '', $parts) !== 1
			|| ($read['action']['name'] ?? '') !== 'menus.' . $parts[1] . '-items.get')
		{
			return false;
		}

		$entity = 'menus.' . $parts[1] . '-items';
		$client = $parts[1] === 'site' ? 0 : 1;
		$operation = $parts[2];
		$binding = $write['binding'] ?? [];
		$item = $read['binding'] ?? [];
		$config = $binding['configuration'] ?? [];
		$readConfig = $item['configuration'] ?? [];

		if (($binding['track'] ?? '') === 'api')
		{
			$route = '/v1/menus/' . $parts[1] . '/items';

			return ($item['track'] ?? '') === 'api' && ($binding['handler'] ?? '') === 'api.request'
				&& ($item['handler'] ?? '') === 'api.request' && ($config['operation'] ?? '') === $operation
				&& ($config['method'] ?? '') === ($operation === 'create' ? 'POST' : 'PATCH')
				&& ($config['route'] ?? '') === $route . ($operation === 'update' ? '/:id' : '')
				&& ($config['read_action'] ?? '') === $entity . '.get'
				&& ($config['authentication'] ?? '') === 'joomla-api-token'
				&& ($config['body_defaults']['client_id'] ?? null) === $client
				&& ($readConfig['authentication'] ?? '') === 'joomla-api-token'
				&& ($readConfig['method'] ?? '') === 'GET' && ($readConfig['route'] ?? '') === $route . '/:id'
				&& empty($readConfig['select_fields']);
		}

		if (($binding['track'] ?? '') !== 'cli' || ($item['track'] ?? '') !== 'cli'
			|| ($binding['handler'] ?? '') !== 'native.core-entity' || ($item['handler'] ?? '') !== 'native.core-entity'
			|| ($config['operation'] ?? '') !== $operation || ($readConfig['operation'] ?? '') !== 'get')
		{
			return false;
		}

		foreach ([$config['entity'] ?? [], $readConfig['entity'] ?? []] as $definition)
		{
			if (($definition['id'] ?? '') !== $entity || ($definition['component'] ?? '') !== 'com_menus'
				|| ($definition['itemModel'] ?? '') !== 'Item' || ($definition['defaults']['client_id'] ?? null) !== $client)
			{
				return false;
			}
		}

		return array_diff(['type', 'link', 'params'], $readConfig['entity']['readFields'] ?? []) === [];
	}

	/**
	 * Parse the link query exactly as Joomla's menu item model does for components.
	 *
	 * @param array $record Independently read menu item.
	 * @return array Request variables Joomla merged into the returned params.
	 * @since [[[NEXT_VERSION]]]
	 */
	private static function request(array $record): array
	{
		$link = $record['link'] ?? null;

		if (($record['type'] ?? null) !== 'component' || !is_string($link) || strlen($link) > 2048)
		{
			return [];
		}

		$query = parse_url($link, PHP_URL_QUERY);
		$request = [];

		if (is_string($query) && $query !== '')
		{
			parse_str($query, $request);
		}

		return $request;
	}

	/**
	 * Compare one requested param, accepting only exact decimal integer spelling.
	 *
	 * The menu form filters many params to integers, so "0" and 0 carry the same
	 * stored value. Other scalar, list and object differences remain different.
	 *
	 * @param mixed $desired Approved param value.
	 * @param mixed $observed Read-back param value.
	 * @return bool Whether the requested param holds.
	 * @since [[[NEXT_VERSION]]]
	 */
	private static function value(mixed $desired, mixed $observed): bool
	{
		if (Json::canonical($desired) === Json::canonical($observed))
		{
			return true;
		}

		if (!(is_int($desired) && is_string($observed)) && !(is_string($desired) && is_int($observed)))
		{
			return false;
		}

		$expected = self::integer($desired);

		return $expected !== null && $expected === self::integer($observed);
	}

	/** @param mixed $value Native params value. @return bool Whether it is a key/value bag. @since [[[NEXT_VERSION]]] */
	private static function bag(mixed $value): bool
	{
		return $value instanceof stdClass || (is_array($value) && ($value === [] || !array_is_list($value)));
	}

	/** @param mixed $value Integer or canonical decimal string. @return ?int Exact safe integer. @since [[[NEXT_VERSION]]] */
	private static function integer(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value >= -9007199254740991 && $value <= 9007199254740991 ? $value : null;
		}

		return is_string($value) && preg_match('/\A(?:0|-?[1-9][0-9]{0,15})\z/D', $value) === 1
			&& (float) $value >= -9007199254740991 && (float) $value <= 9007199254740991 ? (int) $value : null;
	}
}
