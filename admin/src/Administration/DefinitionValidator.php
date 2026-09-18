<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Administration;


use InvalidArgumentException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Validates editable catalogue records without executing their handler bindings.
 *
 * Relation checks use the same fixed structure as installation and runtime.
 * Handler availability remains a runtime registry decision, so installing a new
 * reviewed provider does not require adding its service key to an editor list.
 *
 * @since  0.1.0
 */
final class DefinitionValidator
{
	/**
	 * Configuration persistence, independent of HTTP or console identity.
	 *
	 * @var    StoreInterface
	 * @since  0.1.0
	 */
	private StoreInterface $store;

	/**
	 * Non-networking JSON Schema validator.
	 *
	 * @var    SchemaValidator
	 * @since  0.1.0
	 */
	private SchemaValidator $schemas;

	/**
	 * Inject persistence and schema validation.
	 *
	 * @param   StoreInterface   $store    Catalogue records.
	 * @param   SchemaValidator  $schemas  Inert schema evaluator.
	 * @since   0.1.0
	 */
	public function __construct(StoreInterface $store, SchemaValidator $schemas)
	{
		$this->store = $store;
		$this->schemas = $schemas;
	}

	/**
	 * Normalize and validate one complete record, including its relationships.
	 *
	 * @param   string               $entity  Fixed definition type.
	 * @param   array<string,mixed>   $record  Complete editable record.
	 * @return  array<string,mixed>  Normalized values with JSON strings preserved.
	 * @throws  InvalidArgumentException  When a value or relationship is invalid.
	 * @since   0.1.0
	 */
	public function validate(string $entity, array $record): array
	{
		if (!isset(Structure::definitions()[$entity]))
		{
			throw new InvalidArgumentException('Unknown catalogue definition type.');
		}

		$record = array_intersect_key($record, Structure::columns($entity));

		$name = trim((string) ($record['name'] ?? ''));
		$title = trim((string) ($record['title'] ?? ''));

		if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}\z/D', $name) !== 1
			|| $title === '' || mb_strlen($title) > 191 || preg_match('/[\x00-\x1f\x7f]/u', $title))
		{
			throw new InvalidArgumentException('Provide a stable identifier and a title of at most 191 characters.');
		}

		$record['name'] = $name;
		$record['title'] = $title;
		$other = $this->store->one($entity, ['name' => $name]);

		if ($other !== null && (int) $other['id'] !== (int) ($record['id'] ?? 0))
		{
			throw new InvalidArgumentException('A definition with this identifier already exists.');
		}

		if (!in_array((int) ($record['published'] ?? 0), [-2, 0, 1, 2], true)
			|| (int) ($record['access'] ?? 0) < 1)
		{
			throw new InvalidArgumentException('Select a valid publication state and viewing access level.');
		}

		foreach (Structure::columns($entity) as $field => $type)
		{
			if ($type === 'json')
			{
				$value = $record[$field] ?? '{}';
				$value = is_string($value) ? trim($value) : Json::encode((object) $value);
				$decoded = Json::decode($value, false, $field === 'document' ? 262144 : 1048576);

				if (!$decoded instanceof \stdClass)
				{
					throw new InvalidArgumentException($field . ' must contain a JSON object, not a scalar or list.');
				}

				$record[$field] = $value;
			}
			elseif (str_starts_with($type, 'ref:') || str_starts_with($type, 'optional:'))
			{
				$optional = str_starts_with($type, 'optional:');
				$id = filter_var($record[$field] ?? null, FILTER_VALIDATE_INT);

				if ($optional && in_array($record[$field] ?? null, [null, '', 0, '0'], true))
				{
					$record[$field] = null;
					continue;
				}

				$parentEntity = explode(':', $type, 2)[1];
				$parent = $id === false || $id < 1 ? null : $this->store->one($parentEntity, ['id' => $id]);

				if ($parent === null || (int) $parent['published'] === -2)
				{
					throw new InvalidArgumentException('Select an existing, non-trashed ' . $parentEntity . ' for ' . $field . '.');
				}

				if ($parentEntity !== 'provider' && (int) $parent['provider_id'] !== (int) ($record['provider_id'] ?? 0))
				{
					throw new InvalidArgumentException('Related actions and schemas must belong to the selected provider.');
				}

				$record[$field] = $id;
			}
			elseif ($type === 'name' && isset($record[$field]) && mb_strlen((string) $record[$field]) > 191)
			{
				throw new InvalidArgumentException($field . ' exceeds the database field length.');
			}
		}

		if ($entity === 'schema')
		{
			$this->schemas->document($record['document']);
		}
		elseif ($entity === 'provider' && preg_match('/\A(?:com_[a-z0-9_]+|[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*)\z/D', $record['extension']) !== 1)
		{
			throw new InvalidArgumentException('Use a component element or group/element plugin dependency.');
		}
		elseif ($entity === 'action' && !in_array($record['effect'] ?? '', ['read', 'write'], true))
		{
			throw new InvalidArgumentException('An action effect must be read or write.');
		}

		if (isset($record['risk']) && !in_array($record['risk'], ['read', 'sensitive-read', 'write', 'destructive', 'high', 'discovery'], true))
		{
			throw new InvalidArgumentException('Select a supported operation risk.');
		}

		if ($entity === 'resource' && !in_array($record['is_template'] ?? 0, [0, 1, '0', '1'], true))
		{
			throw new InvalidArgumentException('A resource template flag must be zero or one.');
		}

		if (isset($record['provider_id'], $record['id']) && (int) $record['id'] > 0)
		{
			$old = $this->store->one($entity, ['id' => (int) $record['id']]);

			if ($old !== null && (int) $old['provider_id'] !== (int) $record['provider_id'])
			{
				$this->requireUnreferenced($entity, (int) $record['id']);
			}
		}

		if (isset($record['handler']) && preg_match('/\A[a-z][a-z0-9_.-]{1,127}\z/D', $record['handler']) !== 1)
		{
			throw new InvalidArgumentException('Select a valid registered handler key, not a class or executable path.');
		}

		if ($entity === 'binding')
		{
			$this->binding($record);
		}

		if ($entity === 'resource' && (strlen($record['uri'] ?? '') > 2048
			|| preg_match('/\A[a-z][a-z0-9+.-]*:[^\s\x00-\x1f]+\z/iD', $record['uri'] ?? '') !== 1))
		{
			throw new InvalidArgumentException('Provide an absolute resource URI or URI template.');
		}

		if ($entity === 'target' && preg_match('/\A[a-zA-Z][a-zA-Z0-9_.:-]{0,190}\z/D', $record['command'] ?? '') !== 1)
		{
			throw new InvalidArgumentException('Provide a registered Joomla command name, not a shell expression.');
		}

		return $record;
	}

	/**
	 * Check whether a permanent deletion would leave dangling references.
	 *
	 * @param   string  $entity  Definition type.
	 * @param   int     $id      Existing primary key.
	 * @return  void
	 * @throws  InvalidArgumentException  When a dependent record exists.
	 * @since   0.1.0
	 */
	public function requireUnreferenced(string $entity, int $id): void
	{
		foreach (Structure::definitions() as $child => $fields)
		{
			foreach ($fields as $field => $type)
			{
				if (in_array($type, ['ref:' . $entity, 'optional:' . $entity], true)
					&& $this->store->one($child, [$field => $id]) !== null)
				{
					throw new InvalidArgumentException('Remove dependent ' . $child . ' definitions before permanently deleting this record.');
				}
			}
		}

		if (in_array($entity, ['action', 'binding'], true) && $this->store->one('plan', [$entity . '_id' => $id]) !== null)
		{
			throw new InvalidArgumentException('Execution plans still reference this definition. Unpublish it instead.');
		}
	}

	/**
	 * Enforce declarative binding boundaries without invoking an operation.
	 *
	 * @param   array<string,mixed>  $record  Normalized binding record.
	 * @return  void
	 * @throws  InvalidArgumentException  When the transport or mapping is unsafe.
	 * @since   0.1.0
	 */
	private function binding(array $record): void
	{
		$track = $record['track'] ?? '';
		$handler = $record['handler'];
		$config = Json::decode($record['configuration']);

		if (!in_array($track, ['api', 'cli'], true)
			|| (($handler === 'api.request') && $track !== 'api')
			|| ((str_starts_with($handler, 'native.') || $handler === 'jcb.command') && $track !== 'cli'))
		{
			throw new InvalidArgumentException('The binding handler cannot use this execution track.');
		}

		foreach (['php', 'sql', 'shell', 'class', 'url', 'baseUrl'] as $key)
		{
			if (array_key_exists($key, $config))
			{
				throw new InvalidArgumentException('Executable or cross-origin binding configuration is not permitted.');
			}
		}

		if ($handler === 'api.request')
		{
			if (!in_array($config['method'] ?? '', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
				|| preg_match('/\A\/v[1-9][0-9]*\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*)(?:\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*))*\/?\z/D', $config['route'] ?? '') !== 1)
			{
				throw new InvalidArgumentException('An API binding needs a supported method and canonical relative versioned route.');
			}

			preg_match_all('/:([A-Za-z][A-Za-z0-9_]*)/', $config['route'], $matches);
			$declared = [];

			foreach ($config['route_parameters'] ?? [] as $parameter)
			{
				if (!is_array($parameter) || !is_string($parameter['name'] ?? null)
					|| !in_array($parameter['kind'] ?? '', ['positive-integer', 'component-name', 'language-code', 'override-constant', 'adapter-id', 'media-path'], true))
				{
					throw new InvalidArgumentException('Declare each route parameter with its supported native type.');
				}

				$declared[] = $parameter['name'];
			}

			$actual = $matches[1];
			sort($actual);
			sort($declared);

			if ($actual !== $declared || count(array_unique($declared)) !== count($declared))
			{
				throw new InvalidArgumentException('Route placeholders and typed declarations must match exactly.');
			}
		}
	}
}
