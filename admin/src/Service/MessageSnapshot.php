<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Closure;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Explicit native owned-message preconditions, never public API read-back.
 *
 * @since 1.0.1
 */
final class MessageSnapshot
{
	/** @var string Versioned component-owned planning contract. @since 1.0.1 */
	public const CONTRACT = 'joomla.message-owned-record.v1';
	/** @var Closure(int,PrincipalInterface):array Authenticated native table reader. @since 1.0.1 */
	private Closure $load;

	/** @param callable $load Reviewed native owned-record reader. @since 1.0.1 */
	public function __construct(callable $load)
	{
		$this->load = Closure::fromCallable($load);
	}

	/**
	 * Require an explicit contract and the complete untouched local API bindings.
	 *
	 * Unknown customization retains the original configured read path. A URL or
	 * familiar action name alone never selects a server-native read primitive.
	 *
	 * @param array $write Authorized write resolution.
	 * @param array $read Authorized configured read dependency.
	 * @param array $provider Actual authorized provider from the catalogue.
	 * @return bool Whether both declarations support this planning contract.
	 * @since 1.0.1
	 */
	public static function supports(array $write, array $read, array $provider): bool
	{
		$name = $write['action']['name'] ?? '';
		$operation = match ($name)
		{
			'messages.messages.update' => 'update',
			'messages.messages.delete' => 'delete',
			default => null,
		};

		if ($operation === null || ($provider['name'] ?? '') !== 'joomla.core'
			|| ($provider['extension'] ?? '') !== 'com_joomengine_mcp'
			|| ($read['action']['name'] ?? '') !== 'messages.messages.get'
			|| ($write['action']['effect'] ?? '') !== 'write' || ($read['action']['effect'] ?? '') !== 'read')
		{
			return false;
		}

		foreach ([$provider, $write['action'], $write['binding'], $read['action'], $read['binding']] as $row)
		{
			if (!empty($row['customized']) || ($row !== $provider && (int) ($row['provider_id'] ?? 0) !== (int) ($provider['id'] ?? -1)))
			{
				return false;
			}
		}

		return self::binding($write['binding'], $name, $operation)
			&& self::binding($read['binding'], 'messages.messages.get', 'get');
	}

	/** @param array $binding Effective binding. @param string $name Action. @param string $operation Native operation. @return bool Exact reviewed configuration. @since 1.0.1 */
	private static function binding(array $binding, string $name, string $operation): bool
	{
		if (($binding['name'] ?? '') !== $name . '.api' || ($binding['handler'] ?? '') !== 'api.request'
			|| ($binding['track'] ?? '') !== 'api'
			|| ($binding['definition']['source']['repository'] ?? '') !== 'joomla/joomla-cms'
			|| ($binding['definition']['acl']['component'] ?? '') !== 'com_messages')
		{
			return false;
		}

		$expected = [
			'method' => $operation === 'get' ? 'GET' : ($operation === 'update' ? 'PATCH' : 'DELETE'),
			'route' => '/v1/messages/:id',
			'route_parameters' => [['name' => 'id', 'kind' => 'positive-integer', 'required' => true, 'maximumLength' => 16]],
			'paginated' => false, 'body_policy' => $operation === 'update' ? 'required' : 'none', 'operation' => $operation,
			'body_defaults' => [], 'query_defaults' => [], 'preserve_fields' => [], 'derived_fields' => [],
			'authentication' => 'joomla-api-token', 'response_shape' => 'json-api', 'source_gate' => null,
			'mutation_rule' => null, 'snapshot_contract' => self::CONTRACT,
		];

		if ($operation !== 'get')
		{
			$expected['read_action'] = 'messages.messages.get';
		}

		$config = $binding['configuration'] ?? [];

		// These two empty maps may be decoded either as empty objects or arrays.
		foreach (['body_defaults', 'query_defaults'] as $key)
		{
			if (isset($config[$key]) && (array) $config[$key] === [])
			{
				$config[$key] = [];
			}
		}

		return Json::canonical($config) === Json::canonical($expected);
	}

	/**
	 * Capture only persisted fields under the actual API recipient identity.
	 *
	 * @param array $input Validated message arguments.
	 * @param PrincipalInterface $principal Actual request authority.
	 * @param string $readRevision Authorized read dependency revision.
	 * @return array Private plan precondition, not an API response or deletion proof.
	 * @since 1.0.1
	 */
	public function capture(array $input, PrincipalInterface $principal, string $readRevision): array
	{
		$id = $input['id'] ?? null;

		if (!is_int($id) || $id < 1 || $principal->isLocal() || $principal->getTrack() !== 'api'
			|| preg_match('/\Ajoomla:([1-9][0-9]*)\z/D', $principal->getId(), $owner) !== 1)
		{
			throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested message snapshot is unavailable.');
		}

		$row = ($this->load)($id, $principal);

		if (!self::sameId($row['message_id'] ?? null, $id) || !self::sameId($row['user_id_to'] ?? null, (int) $owner[1]))
		{
			throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested message snapshot is unavailable.');
		}

		$item = ['id' => $id];

		foreach (['user_id_from', 'user_id_to', 'folder_id', 'date_time', 'state', 'priority', 'subject', 'message'] as $field)
		{
			if (!array_key_exists($field, $row) || (!is_scalar($row[$field]) && $row[$field] !== null))
			{
				throw new OperationException('SNAPSHOT_UNAVAILABLE', 'The native message snapshot does not satisfy its persisted-field contract.');
			}

			$item[$field] = $row[$field];
		}

		return ['item' => $item, 'etag' => null, 'snapshotContract' => self::CONTRACT, 'readRevision' => $readRevision];
	}

	/** @param mixed $value Native table identifier. @param int $expected Exact authorized ID. @return bool Identity agrees without broad numeric coercion. @since 1.0.1 */
	private static function sameId(mixed $value, int $expected): bool
	{
		return $value === $expected || $value === (string) $expected;
	}
}
