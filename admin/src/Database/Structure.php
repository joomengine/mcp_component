<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Database;


use InvalidArgumentException;


/**
 * Portable persistence structure shared by Joomla tables, queries and packaging.
 *
 * @since 0.1.0
 */
final class Structure
{
	/** @return array<string,array<string,string>> Editable configuration structures, not runtime capabilities. @since 0.1.0 */
	public static function definitions(): array
	{
		return [
			'provider' => ['extension' => 'name', 'description' => 'text', 'definition' => 'json'],
			'schema' => ['provider_id' => 'ref:provider', 'document' => 'json'],
			'action' => [
				'provider_id' => 'ref:provider', 'input_schema_id' => 'ref:schema',
				'output_schema_id' => 'optional:schema', 'description' => 'text',
				'domain' => 'name', 'toolset' => 'name', 'effect' => 'name', 'risk' => 'name', 'definition' => 'json',
			],
			'binding' => [
				'provider_id' => 'ref:provider', 'action_id' => 'ref:action',
				'input_schema_id' => 'ref:schema', 'output_schema_id' => 'optional:schema',
				'track' => 'name', 'handler' => 'name', 'configuration' => 'json', 'definition' => 'json',
			],
			'tool' => [
				'provider_id' => 'ref:provider', 'input_schema_id' => 'ref:schema',
				'output_schema_id' => 'optional:schema', 'description' => 'text',
				'handler' => 'name', 'configuration' => 'json', 'definition' => 'json',
			],
			'resource' => [
				'provider_id' => 'ref:provider', 'uri' => 'uri', 'mime_type' => 'name',
				'is_template' => 'int', 'description' => 'text', 'handler' => 'name',
				'configuration' => 'json', 'definition' => 'json',
			],
			'prompt' => [
				'provider_id' => 'ref:provider', 'input_schema_id' => 'ref:schema',
				'description' => 'text', 'configuration' => 'json', 'definition' => 'json',
			],
			'target' => [
				'provider_id' => 'ref:provider', 'command' => 'name', 'risk' => 'name',
				'status' => 'name', 'description' => 'text', 'definition' => 'json',
			],
		];
	}

	/** @return array<string,array<string,string>> Durable principal-bound execution structures. @since 0.1.0 */
	public static function state(): array
	{
		return [
			'permission_request' => [
				'uuid' => 'uuid', 'principal_key' => 'hash', 'scope_json' => 'json',
				'duration' => 'name', 'reason' => 'text', 'acknowledgement' => 'text',
				'status' => 'name', 'expires_at' => 'bigint', 'created_at' => 'bigint', 'version' => 'version',
			],
			'grant' => [
				'uuid' => 'uuid', 'principal_key' => 'hash', 'scope_json' => 'json',
				'duration' => 'name', 'request_uuid' => 'uuid', 'remaining_uses' => 'int',
				'revoked' => 'int', 'expires_at' => 'bigint', 'created_at' => 'bigint', 'version' => 'version',
			],
			'plan' => [
				'uuid' => 'uuid', 'principal_key' => 'hash', 'action_id' => 'int', 'binding_id' => 'int',
				'revision' => 'hash', 'token_hash' => 'hash', 'fingerprint' => 'hash',
				'input_cipher' => 'text', 'preview_json' => 'json', 'grant_uuid' => 'uuid',
				'idempotency_key' => 'uuid', 'status' => 'name', 'expires_at' => 'bigint',
				'created_at' => 'bigint', 'version' => 'version',
			],
			'execution' => [
				'uuid' => 'uuid', 'principal_key' => 'hash', 'plan_uuid' => 'uuid',
				'idempotency_key' => 'uuid', 'fingerprint' => 'hash', 'status' => 'name',
				'result_cipher' => 'text', 'created_at' => 'bigint', 'updated_at' => 'bigint', 'version' => 'version',
			],
			'lease' => ['resource_key' => 'hash', 'owner_uuid' => 'uuid', 'expires_at' => 'bigint'],
			'session' => [
				'uuid' => 'uuid', 'principal_key' => 'hash', 'data_cipher' => 'text',
				'expires_at' => 'bigint', 'version' => 'version',
			],
			'audit' => [
				'uuid' => 'uuid', 'principal_key' => 'hash', 'actor_id' => 'int',
				'event' => 'name', 'action_name' => 'name', 'plan_uuid' => 'uuid',
				'execution_uuid' => 'uuid', 'outcome' => 'name', 'metadata' => 'json', 'created_at' => 'bigint',
			],
		];
	}

	/** @return array<string,string> Joomla editable-row metadata and seed ownership markers. @since 0.1.0 */
	public static function common(): array
	{
		return [
			'asset_id' => 'int', 'name' => 'name', 'title' => 'name', 'published' => 'published',
			'access' => 'access', 'ordering' => 'int', 'checked_out' => 'nullable_int',
			'checked_out_time' => 'date', 'created' => 'date', 'created_by' => 'int',
			'modified' => 'date', 'modified_by' => 'int', 'version' => 'version',
			'params' => 'json', 'seed_revision' => 'hash', 'seed_hash' => 'hash', 'customized' => 'int',
		];
	}

	/** @param string $entity Reviewed entity name. @return array<string,string> Columns and portable types. @throws InvalidArgumentException Unknown entity. @since 0.1.0 */
	public static function columns(string $entity): array
	{
		$definitions = self::definitions();

		if (isset($definitions[$entity]))
		{
			return ['id' => 'pk'] + self::common() + $definitions[$entity];
		}

		$state = self::state();

		if (!isset($state[$entity]))
		{
			throw new InvalidArgumentException('Unknown MCP persistence entity.');
		}

		return ['id' => 'pk'] + $state[$entity];
	}

	/** @param string $entity Reviewed entity. @return string Portable Joomla table placeholder. @since 0.1.0 */
	public static function table(string $entity): string
	{
		self::columns($entity);

		return '#__joomengine_mcp_' . $entity;
	}
}
