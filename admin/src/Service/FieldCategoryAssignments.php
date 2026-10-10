<?php
/**
 * @package    JoomEngine.Mcp
 * @created    10 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


/**
 * Reviewed Joomla field category memberships and native sentinel semantics.
 * Only approved input can select the subform-only None override.
 *
 * @since [[[NEXT_VERSION]]]
 */
final class FieldCategoryAssignments
{
	/** @var array<string,array{context:string,route:string}> Fixed core field contexts. */
	public const CONTEXTS = [
		'fields.content-articles' => ['context' => 'com_content.article', 'route' => '/v1/fields/content/articles'],
		'fields.content-categories' => ['context' => 'com_content.categories', 'route' => '/v1/fields/content/categories'],
		'fields.contact' => ['context' => 'com_contact.contact', 'route' => '/v1/fields/contacts/contact'],
		'fields.contact-mail' => ['context' => 'com_contact.mail', 'route' => '/v1/fields/contacts/mail'],
		'fields.contact-categories' => ['context' => 'com_contact.categories', 'route' => '/v1/fields/contacts/categories'],
		'fields.users' => ['context' => 'com_users.user', 'route' => '/v1/fields/users'],
	];

	/** @return array Typed new form key; existing fields retain their contracts. */
	public static function schema(): array
	{
		return ['type' => 'array', 'maxItems' => 1000,
			'items' => ['type' => 'integer', 'minimum' => -1, 'maximum' => 2147483647],
			'description' => 'Native category selection: positive category IDs, [0] or [] for All, [-1] for None. Joomla forces None when only_use_in_subform is enabled; contexts without categories remain subject to native form validation.'];
	}

	/**
	 * Compare category memberships for an exact reviewed write and item-read pair.
	 * @param array $write Authorized write definition.
	 * @param array $read Independent authorized item read.
	 * @param mixed $desired Approved category selection.
	 * @param mixed $observed Native category selection.
	 * @param array $approved Approved full data, never an observed subform flag.
	 * @return ?bool Null preserves strict comparison for unrelated bindings.
	 */
	public static function compare(array $write, array $read, mixed $desired, mixed $observed, array $approved): ?bool
	{
		if (!self::supports($write, $read))
		{
			return null;
		}

		$expected = self::members($desired, true);
		$actual = self::members($observed, false);
		if ($expected === null || $actual === null)
		{
			return false;
		}

		if (in_array($approved['only_use_in_subform'] ?? null, [1, '1', true], true))
		{
			$expected = [-1];
		}

		return $expected === $actual;
	}

	/** @param array $write Write definition. @param array $read Item read. @return bool Reviewed pair. */
	private static function supports(array $write, array $read): bool
	{
		if (preg_match('/\\A(.+)\\.(create|update)\\z/D', $write['action']['name'] ?? '', $parts) !== 1
			|| !isset(self::CONTEXTS[$parts[1]]) || ($read['action']['name'] ?? '') !== $parts[1] . '.get')
		{
			return false;
		}

		$contract = self::CONTEXTS[$parts[1]];
		$binding = $write['binding'] ?? [];
		$item = $read['binding'] ?? [];
		$config = $binding['configuration'] ?? [];
		$readConfig = $item['configuration'] ?? [];
		if (($binding['track'] ?? '') === 'api')
		{
			return ($item['track'] ?? '') === 'api' && ($binding['handler'] ?? '') === 'api.request'
				&& ($item['handler'] ?? '') === 'api.request'
				&& ($config['operation'] ?? '') === $parts[2]
				&& ($config['method'] ?? '') === ($parts[2] === 'create' ? 'POST' : 'PATCH')
				&& ($config['route'] ?? '') === $contract['route'] . ($parts[2] === 'update' ? '/:id' : '')
				&& ($config['read_action'] ?? '') === $parts[1] . '.get'
				&& ($config['body_defaults']['context'] ?? '') === $contract['context']
				&& ($config['authentication'] ?? '') === 'joomla-api-token'
				&& ($readConfig['method'] ?? '') === 'GET'
				&& ($readConfig['route'] ?? '') === $contract['route'] . '/:id'
				&& ($readConfig['body_defaults']['context'] ?? '') === $contract['context']
				&& ($readConfig['authentication'] ?? '') === 'joomla-api-token'
				&& empty($readConfig['select_fields']);
		}

		if (($binding['track'] ?? '') !== 'cli' || ($item['track'] ?? '') !== 'cli'
			|| ($binding['handler'] ?? '') !== 'native.core-entity' || ($item['handler'] ?? '') !== 'native.core-entity'
			|| ($config['operation'] ?? '') !== $parts[2] || ($readConfig['operation'] ?? '') !== 'get')
		{
			return false;
		}

		foreach ([$config['entity'] ?? [], $readConfig['entity'] ?? []] as $entity)
		{
			if (($entity['id'] ?? '') !== $parts[1] || ($entity['component'] ?? '') !== 'com_fields'
				|| ($entity['itemModel'] ?? '') !== 'Field' || ($entity['defaults']['context'] ?? '') !== $contract['context']
				|| !in_array('assigned_cat_ids', $entity['readFields'] ?? [], true)
				|| !in_array('assigned_cat_ids', $entity['writeFields'] ?? [], true))
			{
				return false;
			}
		}

		return true;
	}

	/** @param mixed $value Bounded list. @param bool $desired Normalize submitted sentinels. @return ?int[] Category memberships. */
	private static function members(mixed $value, bool $desired): ?array
	{
		if (!is_array($value) || !array_is_list($value) || count($value) > 1000)
		{
			return null;
		}

		$ids = [];
		foreach ($value as $id)
		{
			if (is_float($id) && is_finite($id) && floor($id) === $id && $id >= -1 && $id <= 2147483647)
			{
				$id = (int) $id;
			}
			if ((!is_int($id) && !(is_string($id) && preg_match('/\\A(?:-1|0|[1-9][0-9]{0,9})\\z/D', $id) === 1))
				|| (int) $id < -1 || (int) $id > 2147483647)
			{
				return null;
			}
			$ids[(int) $id] = (int) $id;
		}
		if (isset($ids[-1]))
		{
			return $desired || count($ids) === 1 ? [-1] : null;
		}

		// Zero is not stored; no assignment reads back as [0] (All).
		unset($ids[0]);
		$ids = array_values($ids);
		sort($ids, SORT_NUMERIC);
		return $ids;
	}
}

