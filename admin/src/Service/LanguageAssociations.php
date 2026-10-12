<?php
/**
 * @package    JoomEngine.Mcp
 * @created    12 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use stdClass;


/**
 * Exact language-to-record postconditions for reviewed native association fields.
 *
 * @since [[[NEXT_VERSION]]]
 */
final class LanguageAssociations
{
	/** @var array<string,string> Reviewed native association API routes. */
	public const RESOURCES = [
		'menus.site-items' => '/v1/menus/site/items',
		'content.articles' => '/v1/content/articles',
	];

	/** @return array Bounded native language-to-positive-record selection. */
	public static function schema(): array
	{
		return ['type' => 'object', 'maxProperties' => 100,
			'propertyNames' => ['type' => 'string', 'maxLength' => 50, 'pattern' => '^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$'],
			'additionalProperties' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647],
			'description' => 'Language code to associated record ID. An empty object requests no associations; native Joomla validation and independent read-back determine whether the request was applied.'];
	}

	/** @param mixed $value Native language map. @return ?array<string,int> Exact sorted map, or null for malformed data. */
	public static function map(mixed $value): ?array
	{
		if ((!is_array($value) && !$value instanceof stdClass) || count((array) $value) > 100
			|| (is_array($value) && $value !== [] && array_is_list($value)))
		{
			return null;
		}

		$result = [];
		foreach ((array) $value as $language => $id)
		{
			if (!is_string($language) || strlen($language) > 50
				|| preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*\z/D', $language) !== 1
				|| ($id = ApiWriteVerification::identity($id, 'integer')) === null || $id > 2147483647)
			{
				return null;
			}
			$result[$language] = $id;
		}
		ksort($result, SORT_STRING);

		return $result;
	}

	/** @param array $write Authorized write. @param array $read Independent read. @param mixed $desired Approved map. @param mixed $observed Saved map. @return ?bool Null for unrelated bindings. */
	public static function compare(array $write, array $read, mixed $desired, mixed $observed): ?bool
	{
		if (!self::supports($write, $read))
		{
			return null;
		}

		$expected = self::map($desired);
		$actual = self::map($observed);

		return $expected !== null && $actual !== null && $expected === $actual;
	}

	/**
	 * Resolve only reviewed native representations of association membership.
	 * Missing evidence stays unobservable; malformed or contradictory evidence is
	 * retained as null so that an approved association cannot be falsely verified.
	 *
	 * @param array $write Authorized write.
	 * @param array $read Independent read.
	 * @param array $response Original independent API response.
	 * @param array $record Flattened native attributes.
	 * @return array Attributes with an independently observed association map.
	 */
	public static function observe(array $write, array $read, array $response, array $record): array
	{
		if (!self::supports($write, $read) || ($read['binding']['track'] ?? '') !== 'api')
		{
			return $record;
		}

		$document = $response['data'] ?? [];
		$resource = is_array($document) ? ($document['data'] ?? []) : [];
		if (($read['action']['name'] ?? '') === 'menus.site-items.get')
		{
			$id = ApiWriteVerification::identity($record['id'] ?? null, 'integer');
			$language = $record['language'] ?? null;
			$associations = $record['associations'] ?? null;
			$map = is_array($associations) || $associations instanceof stdClass ? (array) $associations : null;
			if (is_array($resource) && ($resource['type'] ?? null) === 'items'
				&& $id !== null && ApiWriteVerification::identity($resource['id'] ?? null, 'integer') === $id
				&& is_array($resource['attributes'] ?? null)
				&& ApiWriteVerification::identity($resource['attributes']['id'] ?? null, 'integer') === $id
				&& in_array($record['client_id'] ?? null, [0, '0'], true) && is_string($language)
				&& $map !== null && count($map) <= 101 && self::map([$language => $map[$language] ?? null]) === [$language => $id])
			{
				// PostgreSQL's bound ID becomes a string, so Joomla's strict self-ID
				// exclusion can return the source item in its own association map.
				// Only its independently identified language and ID may be removed.
				unset($map[$language]);
				$map = self::map($map);
				if ($map !== null)
				{
					$record['associations'] = $map;
				}
			}

			return $record;
		}
		$relationships = is_array($resource) ? ($resource['relationships'] ?? []) : [];
		if (!is_array($relationships) || !array_key_exists('languageAssociations', $relationships))
		{
			return $record;
		}

		$relation = $relationships['languageAssociations'];
		$links = is_array($relation) ? ($relation['data'] ?? null) : null;
		$type = $resource['type'] ?? null;
		$map = [];
		$seen = [];
		$sourceLanguage = null;
		if (!is_array($links) || !array_is_list($links) || count($links) > 101 || $type !== 'articles')
		{
			$record['associations'] = null;
			return $record;
		}

		foreach ($links as $link)
		{
			$id = is_array($link) ? ApiWriteVerification::identity($link['id'] ?? null, 'integer') : null;
			if ($id === null || ($link['type'] ?? null) !== $type || isset($seen[$id]))
			{
				$record['associations'] = null;
				return $record;
			}
			$seen[$id] = true;
			$matches = [];
			foreach (is_array($document['included'] ?? null) ? $document['included'] : [] as $included)
			{
				if (is_array($included) && ($included['type'] ?? null) === $type
					&& ApiWriteVerification::identity($included['id'] ?? null, 'integer') === $id)
				{
					$matches[] = $included;
				}
			}
			if (ApiWriteVerification::identity($resource['id'] ?? null, 'integer') === $id)
			{
				$language = $record['language'] ?? null;
				if (ApiWriteVerification::identity($record['id'] ?? null, 'integer') !== $id
					|| !is_array($resource['attributes'] ?? null)
					|| ApiWriteVerification::identity($resource['attributes']['id'] ?? null, 'integer') !== $id
					|| !is_string($language) || self::map([$language => $id]) === null
					|| ($resource['attributes']['language'] ?? null) !== $language || isset($map[$language]) || count($matches) > 1
					|| ($matches !== [] && (!is_array($matches[0]['attributes'] ?? null)
						|| ApiWriteVerification::identity($matches[0]['attributes']['id'] ?? null, 'integer') !== $id
						|| ($matches[0]['attributes']['language'] ?? null) !== $language)))
				{
					$record['associations'] = null;
					return $record;
				}

				// The JSON:API document omits its own primary resource from included.
				// Its validated identity and language prove only this self linkage.
				$sourceLanguage = $language;
				continue;
			}
			if (count($matches) !== 1 || !is_array($matches[0]['attributes'] ?? null)
				|| !is_string($matches[0]['attributes']['language'] ?? null)
				|| (array_key_exists('id', $matches[0]['attributes'])
					&& ApiWriteVerification::identity($matches[0]['attributes']['id'], 'integer') !== $id)
				|| $sourceLanguage === $matches[0]['attributes']['language']
				|| isset($map[$matches[0]['attributes']['language']]))
			{
				$record['associations'] = null;
				return $record;
			}
			$map[$matches[0]['attributes']['language']] = $id;
		}

		$map = self::map($map);
		$record['associations'] = array_key_exists('associations', $record)
			&& self::map($record['associations']) !== $map ? null : $map;

		return $record;
	}

	/** @param array $write Native write. @param array $read Native read. @return bool Exact reviewed API or native menu pair. */
	public static function supports(array $write, array $read): bool
	{
		if (preg_match('/\A(.+)\.(create|update)\z/D', $write['action']['name'] ?? '', $parts) !== 1
			|| !isset(self::RESOURCES[$parts[1]]) || ($read['action']['name'] ?? '') !== $parts[1] . '.get')
		{
			return false;
		}

		$binding = $write['binding'] ?? [];
		$item = $read['binding'] ?? [];
		$config = $binding['configuration'] ?? [];
		$readConfig = $item['configuration'] ?? [];
		if (($binding['track'] ?? '') === 'api')
		{
			return ($item['track'] ?? '') === 'api' && ($binding['handler'] ?? '') === 'api.request'
				&& ($item['handler'] ?? '') === 'api.request' && ($config['operation'] ?? '') === $parts[2]
				&& ($config['method'] ?? '') === ($parts[2] === 'create' ? 'POST' : 'PATCH')
				&& ($config['route'] ?? '') === self::RESOURCES[$parts[1]] . ($parts[2] === 'update' ? '/:id' : '')
				&& ($config['read_action'] ?? '') === $parts[1] . '.get'
				&& ($config['authentication'] ?? '') === 'joomla-api-token'
				&& ($readConfig['authentication'] ?? '') === 'joomla-api-token'
				&& ($readConfig['method'] ?? '') === 'GET' && ($readConfig['route'] ?? '') === self::RESOURCES[$parts[1]] . '/:id'
				&& empty($readConfig['select_fields'])
				&& ($parts[1] !== 'menus.site-items' || ($config['body_defaults']['client_id'] ?? null) === 0);
		}

		if ($parts[1] !== 'menus.site-items' || ($binding['track'] ?? '') !== 'cli' || ($item['track'] ?? '') !== 'cli'
			|| ($binding['handler'] ?? '') !== 'native.core-entity' || ($item['handler'] ?? '') !== 'native.core-entity'
			|| ($config['operation'] ?? '') !== $parts[2] || ($readConfig['operation'] ?? '') !== 'get')
		{
			return false;
		}
		foreach ([$config['entity'] ?? [], $readConfig['entity'] ?? []] as $entity)
		{
			if (($entity['id'] ?? '') !== $parts[1] || ($entity['component'] ?? '') !== 'com_menus'
				|| ($entity['itemModel'] ?? '') !== 'Item' || ($entity['defaults']['client_id'] ?? null) !== 0
				|| !in_array('associations', $entity['readFields'] ?? [], true)
				|| !in_array('associations', $entity['writeFields'] ?? [], true))
			{
				return false;
			}
		}

		return true;
	}
}
