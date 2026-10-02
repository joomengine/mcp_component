<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use stdClass;
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

	/** @var array<string,string> Native routes whose list/checkbox API values are reviewed. @since 1.0.5 */
	private const ROUTES = [
		'content.articles' => '/v1/content/articles',
		'content.categories' => '/v1/content/categories',
		'contacts.contacts' => '/v1/contacts',
		'users.users' => '/v1/users',
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
				$group = $attributes['group_id'] ?? null;

				if ((!is_int($group) && !is_string($group)) || preg_match('/\A[0-9]+\z/D', (string) $group) !== 1)
				{
					throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'Custom field discovery returned an invalid field group identity.');
				}

				if (($attributes['context'] ?? null) !== $context['context'] || !in_array($attributes['state'] ?? null, [1, '1'], true)
					|| in_array($attributes['only_use_in_subform'] ?? null, [true, 1, '1'], true)
					|| (isset($attributes['access']) && !in_array((int) $attributes['access'], $viewLevels, true))
					|| ((int) $group !== 0 && (!in_array($attributes['group_state'] ?? null, [1, '1'], true)
						|| (isset($attributes['group_access']) && !in_array((int) $attributes['group_access'], $viewLevels, true)))))
				{
					continue;
				}

				if (is_string($name) && empty($context['nested']) && (array_key_exists($name, $core) || in_array($name, self::RESERVED, true)))
				{
					throw new OperationException('CUSTOM_FIELDS_UNAVAILABLE', 'A published custom field collides with a core or reserved Joomla form key. Rename that field before planning custom values.');
				}

				if (!is_string($name) || strlen($name) > 255 || preg_match('/\A[\p{L}\p{N}\p{M}_-]+\z/uD', $name) !== 1
					|| preg_match('/\A[0-9]+\z/D', $name) === 1
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

				if (in_array($type, ['list', 'checkboxes'], true))
				{
					$params = $attributes['fieldparams'] ?? null;
					$options = is_array($params) || $params instanceof stdClass ? ((array) $params)['options'] ?? null : null;
					$choices = self::choices($options);

					// Missing, inherited or malformed options cannot relax strict read-back.
					// Keep discovery usable for all other published custom field values.
					if ($choices !== null)
					{
						$fields[$name]['choices'] = $choices;
					}
				}
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

	/**
	 * Compare a frozen selection against Joomla's native value-to-label API map.
	 *
	 * ListPlugin and Checkboxes::beforePrepareField expose option values as keys
	 * and the unmodified names from FieldsListPlugin::getOptionsFromField as labels.
	 * No catalogue read or option discovery is permitted during this comparison.
	 *
	 * @param array $resolved Authorized write definition and frozen custom fields.
	 * @param array $read Authorized independent native item read definition.
	 * @param string $field Requested field name.
	 * @param mixed $desired Approved selection value or list of values.
	 * @param mixed $observed Independently read API value-to-label map.
	 * @return ?bool Exact native selection comparison, or null for strict fallback.
	 * @since 1.0.5
	 */
	public static function compare(array $resolved, array $read, string $field, mixed $desired, mixed $observed): ?bool
	{
		$context = self::verificationContext($resolved, $read);
		$metadata = $resolved['custom_fields'] ?? [];

		if ($context === null || ($metadata['status'] ?? '') !== 'resolved' || ($metadata['transport'] ?? '') !== 'api'
			|| ($metadata['context'] ?? '') !== $context['context'] || ($metadata['sourceAction'] ?? '') !== $context['sourceAction']
			|| ($metadata['nested'] ?? null) !== $context['nested'] || !is_array($metadata['fields'] ?? null))
		{
			return null;
		}

		$definition = null;

		foreach ($metadata['fields'] as $candidate)
		{
			if (!is_array($candidate) || ($candidate['name'] ?? null) !== $field)
			{
				continue;
			}

			if ($definition !== null)
			{
				return false;
			}

			$definition = $candidate;
		}

		if ($definition === null || ($definition['context'] ?? '') !== $context['context']
			|| !is_string($definition['id'] ?? null) || preg_match('/\A[1-9][0-9]*\z/D', $definition['id']) !== 1
			|| !in_array($definition['type'] ?? '', ['list', 'checkboxes'], true) || !array_key_exists('choices', $definition))
		{
			return null;
		}

		$choices = self::choices($definition['choices']);

		if ($choices === null || (!is_array($observed) && !$observed instanceof stdClass))
		{
			return false;
		}

		$options = array_column($choices, 'name', 'value');
		$selected = is_string($desired) || is_int($desired) || is_float($desired) ? ($desired === '' ? [] : [$desired]) : $desired;

		if (!is_array($selected) || !array_is_list($selected))
		{
			return false;
		}

		$expected = [];

		foreach ($selected as $value)
		{
			$value = self::choiceValue($value);

			if ($value === null || !array_key_exists($value, $options) || array_key_exists($value, $expected))
			{
				return false;
			}

			$expected[$value] = $options[$value];
		}

		// PHP converts canonical integer option keys to integers. Native JSON can
		// therefore be a label list for keys 0..n, or an object for other keys.
		// Preserve those exact keys and label types; never treat labels as values.
		$actual = $observed instanceof stdClass ? get_object_vars($observed) : $observed;
		ksort($expected, SORT_STRING);
		ksort($actual, SORT_STRING);

		return $expected === $actual;
	}

	/** @param mixed $options Native subform option rows. @return ?array Unique option value/name pairs, or null for an unsupported definition. @since 1.0.5 */
	private static function choices(mixed $options): ?array
	{
		if (!is_array($options) && !$options instanceof stdClass)
		{
			return null;
		}

		$choices = [];
		$values = [];

		foreach ($options as $option)
		{
			$option = $option instanceof stdClass ? get_object_vars($option) : $option;
			$raw = is_array($option) ? ($option['value'] ?? null) : null;
			$value = is_string($raw) || is_int($raw) ? self::choiceValue($raw) : null;

			if ($value === null || !is_string($option['name'] ?? null) || array_key_exists($value, $values))
			{
				return null;
			}

			$values[$value] = true;
			$choices[] = ['value' => $value, 'name' => $option['name']];
		}

		return $choices;
	}

	/** @param mixed $value Native selection identity. @return ?string Exact text identity, including finite safe numbers accepted by Joomla's OptionsRule. @since 1.0.5 */
	private static function choiceValue(mixed $value): ?string
	{
		return is_string($value) || ((is_int($value) || (is_float($value) && is_finite($value)))
			&& $value >= -9007199254740991 && $value <= 9007199254740991)
			? (string) $value : null;
	}

	/** @param array $resolved Authorized write. @param array $read Authorized read. @return ?array Exact reviewed native context. @since 1.0.5 */
	private static function verificationContext(array $resolved, array $read): ?array
	{
		if (!preg_match('/\A(.+)\.(create|update)\z/D', $resolved['action']['name'] ?? '', $parts)
			|| !isset(self::ROUTES[$parts[1]]))
		{
			return null;
		}

		$binding = $resolved['binding'] ?? [];
		$config = $binding['configuration'] ?? [];
		$readBinding = $read['binding'] ?? [];
		$readConfig = $readBinding['configuration'] ?? [];
		$route = self::ROUTES[$parts[1]];

		return ($binding['track'] ?? '') === 'api' && ($binding['handler'] ?? '') === 'api.request'
			&& ($config['operation'] ?? '') === $parts[2] && ($config['route'] ?? '') === $route . ($parts[2] === 'update' ? '/:id' : '')
			&& ($config['method'] ?? '') === ($parts[2] === 'create' ? 'POST' : 'PATCH')
			&& ($config['read_action'] ?? '') === $parts[1] . '.get' && ($config['authentication'] ?? '') === 'joomla-api-token'
			&& ($config['response_shape'] ?? '') === 'json-api'
			&& ($read['action']['name'] ?? '') === $parts[1] . '.get' && ($readBinding['track'] ?? '') === 'api'
			&& ($readBinding['handler'] ?? '') === 'api.request' && ($readConfig['method'] ?? '') === 'GET'
			&& ($readConfig['operation'] ?? '') === 'get' && ($readConfig['route'] ?? '') === $route . '/:id'
			&& ($readConfig['authentication'] ?? '') === 'joomla-api-token' && ($readConfig['response_shape'] ?? '') === 'json-api'
			&& empty($readConfig['select_fields']) ? self::CONTEXTS[$parts[1]] : null;
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
