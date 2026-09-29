<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Resolve site-owned custom field names without opening arbitrary Joomla form keys.
 *
 * Metadata is deliberately request-local. The caller freezes the returned snapshot
 * in the encrypted approval payload and never rediscovers it during application.
 *
 * @since 1.0.0
 */
final class CustomFields
{
	/** @var array<string,array{context:string,sourceAction:string,nested:bool}> Reviewed Joomla API contracts. @since 1.0.0 */
	private const CONTEXTS = [
		'content.articles' => ['context' => 'com_content.article', 'sourceAction' => 'fields.content-articles.list', 'nested' => false],
		'content.categories' => ['context' => 'com_content.categories', 'sourceAction' => 'fields.content-categories.list', 'nested' => true],
		'contacts.contacts' => ['context' => 'com_contact.contact', 'sourceAction' => 'fields.contact.list', 'nested' => false],
		'users.users' => ['context' => 'com_users.user', 'sourceAction' => 'fields.users.list', 'nested' => false],
	];

	/** @var string[] Runtime, controller and identity properties cannot become custom input. @since 1.0.0 */
	private const RESERVED = [
		'__proto__', 'prototype', 'constructor', 'com_fields', 'id', 'asset_id', 'extension',
		'client_id', 'component', 'option', 'task', 'view', 'layout', 'format', 'controller',
		'model', 'method', 'path', 'url', 'token', 'jform', 'rules', 'created', 'created_by',
		'modified', 'modified_by', 'checked_out', 'checked_out_time', 'version', 'hits',
		'dryRun', '_edgeConfirmed', 'action', 'transport', 'etag', 'idempotencyKey',
	];

	/** @param array<string,mixed> $resolved Authorized binding. @return ?array Reviewed API context, if supported. @since 1.0.0 */
	public static function context(array $resolved): ?array
	{
		if (($resolved['binding']['track'] ?? '') !== 'api' || ($resolved['binding']['handler'] ?? '') !== 'api.request'
			|| !preg_match('/\A(.+)\.(create|update)\z/D', $resolved['action']['name'] ?? '', $parts))
		{
			return null;
		}

		return self::CONTEXTS[$parts[1]] ?? null;
	}

	/** @param array<string,mixed> $input Supplied action input. @param array $schema Static schema. @return bool Whether runtime names are needed. @since 1.0.0 */
	public static function needed(array $input, array $schema): bool
	{
		return is_array($input['data'] ?? null)
			&& array_diff(array_keys($input['data']), array_keys($schema['properties']['data']['properties'] ?? [])) !== [];
	}

	/**
	 * Read a complete bounded collection through the existing authorized read action.
	 *
	 * Pagination follows offsets, never response links or caller-selected routes.
	 * Incomplete, ambiguous or malformed discovery cannot expand the write schema.
	 *
	 * @param array $context Reviewed context and source action.
	 * @param array $schema Existing administrator-owned write schema.
	 * @param callable $read Authorized action reader.
	 * @param int $limit Configured page size, already bounded by the runtime.
	 * @param int[] $viewLevels Actual principal's viewing levels.
	 * @return array Frozen non-secret custom field metadata.
	 * @since 1.0.0
	 */
	public static function discover(array $context, array $schema, callable $read, int $limit, array $viewLevels): array
	{
		$limit = max(1, min(100, $limit));
		$offset = 0;
		$fields = [];
		$identities = [];
		$core = $schema['properties']['data']['properties'] ?? [];

		for ($page = 0; $page < 100; $page++)
		{
			try
			{
				$response = $read($context['sourceAction'], ['offset' => $offset, 'limit' => $limit]);
			}
			catch (OperationException)
			{
				throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'The authorized custom field catalogue is unavailable. Enable and authorize its fields list action before planning custom values.');
			}
			$document = $response['response']['data'] ?? null;
			$items = is_array($document) ? ($document['data'] ?? null) : null;

			if (!is_array($items) || !array_is_list($items) || count($items) > $limit || $offset + count($items) > 1000)
			{
				throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field discovery did not return a complete bounded collection.');
			}

			foreach ($items as $item)
			{
				$attributes = is_array($item) ? ($item['attributes'] ?? null) : null;
				$id = is_array($item) ? ($item['id'] ?? $attributes['id'] ?? null) : null;

				if (!is_array($attributes) || (!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $id)
					|| isset($identities[(string) $id]))
				{
					throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field discovery returned malformed or repeated identities.');
				}

				$identities[(string) $id] = true;
				$name = $attributes['name'] ?? null;
				$type = $attributes['type'] ?? null;

				if (($attributes['context'] ?? null) !== $context['context'] || !in_array($attributes['state'] ?? null, [1, '1'], true)
					|| in_array($attributes['only_use_in_subform'] ?? null, [true, 1, '1'], true)
					|| (isset($attributes['access']) && !in_array((int) $attributes['access'], $viewLevels, true))
					|| (!empty($attributes['group_id']) && (!in_array($attributes['group_state'] ?? null, [1, '1'], true)
						|| (isset($attributes['group_access']) && !in_array((int) $attributes['group_access'], $viewLevels, true)))))
				{
					continue;
				}

				if (is_string($name) && empty($context['nested']) && (array_key_exists($name, $core) || in_array($name, self::RESERVED, true)))
				{
					throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'A published custom field collides with a core or reserved Joomla form key. Rename that field before planning custom values.');
				}

				if (!is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,254}\z/D', $name) !== 1
					|| in_array($name, self::RESERVED, true) || array_key_exists($name, $core))
				{
					continue;
				}

				if (!is_string($type) || preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $type) !== 1)
				{
					throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field discovery returned an invalid field type.');
				}

				if (isset($fields[$name]))
				{
					throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field names are ambiguous in the selected context.');
				}

				$fields[$name] = ['id' => (string) $id, 'name' => $name, 'type' => $type, 'context' => $context['context']];
			}

			$offset += count($items);
			$total = $document['meta']['total-pages'] ?? null;
			$hasNext = !empty($document['links']['next']);

			if ($total !== null && (!is_int($total) || $total < 0 || $total > 100))
			{
				throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field discovery returned an invalid page count.');
			}

			if ((count($items) < $limit || $total !== null) && !$hasNext && ($total === null || $page + 1 >= $total))
			{
				ksort($fields, SORT_STRING);

				return $context + ['status' => 'resolved', 'transport' => 'api', 'fields' => array_values($fields)];
			}

			if ($items === [])
			{
				throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field pagination ended before the collection was complete.');
			}
		}

		throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field discovery exceeded its bounded collection limit.');
	}

	/** @param array $input Raw input. @param array $metadata Frozen metadata. @return array Input with one unambiguous flat field map. @since 1.0.0 */
	public static function normalize(array $input, array $metadata): array
	{
		if (!is_array($input['data'] ?? null) || !array_key_exists('com_fields', $input['data']))
		{
			return $input;
		}

		$alias = $input['data']['com_fields'];
		$names = array_column($metadata['fields'], 'name');

		if (!is_array($alias) || $alias === [] || array_is_list($alias))
		{
			throw new OperationException('INVALID_INPUT', 'com_fields must be a non-empty object of discovered custom field names.');
		}

		unset($input['data']['com_fields']);

		foreach ($alias as $name => $value)
		{
			$name = (string) $name;
			if (!in_array($name, $names, true) || array_key_exists($name, $input['data']))
			{
				throw new OperationException('INVALID_INPUT', 'Custom field aliases must use discovered names exactly once.');
			}

			$input['data'][$name] = $value;
		}

		return $input;
	}

	/** @param array $schema Reviewed static schema. @param array $metadata Frozen metadata. @param bool $alias Include public alias documentation. @return array Strict extended schema. @since 1.0.0 */
	public static function schema(array $schema, array $metadata, bool $alias = false): array
	{
		$properties = [];

		foreach ($metadata['fields'] as $field)
		{
			$properties[$field['name']] = ['not' => ['type' => 'null'],
				'description' => 'Published Joomla custom field (' . $field['type'] . '); Joomla validates its value. Clear with an empty string or array, not null.'];
		}

		$schema['properties']['data']['properties'] = ($schema['properties']['data']['properties'] ?? []) + $properties;

		if ($alias)
		{
			$schema['properties']['data']['properties']['com_fields'] = ['type' => 'object', 'minProperties' => 1,
				'properties' => (object) $properties, 'additionalProperties' => false,
				'description' => 'Optional alias for discovered custom fields. Do not repeat a field at the top level.'];
		}

		return $schema;
	}

	/** @param array $input Approved input. @param array $metadata Frozen metadata. @return void Reject Joomla's silent top-level null no-op. @since 1.0.0 */
	public static function validateValues(array $input, array $metadata): void
	{
		foreach ($metadata['fields'] as $field)
		{
			if (array_key_exists($field['name'], $input['data'] ?? []) && $input['data'][$field['name']] === null)
			{
				throw new OperationException('INVALID_INPUT', 'Clear a custom field with an empty string or array; null is not a Joomla API field value.');
			}
		}
	}

	/** @param array $input Normalized approved input. @param array $metadata Frozen metadata. @return array Joomla-controller-compatible input. @since 1.0.0 */
	public static function wire(array $input, array $metadata): array
	{
		if (empty($metadata['nested']))
		{
			return $input;
		}

		foreach ($metadata['fields'] as $field)
		{
			$name = $field['name'];

			if (array_key_exists($name, $input['data'] ?? []))
			{
				$input['data']['com_fields'][$name] = $input['data'][$name];
				unset($input['data'][$name]);
			}
		}

		return $input;
	}
}
