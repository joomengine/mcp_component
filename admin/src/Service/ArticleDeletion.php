<?php
/**
 * @package    JoomEngine.Mcp
 * @created    30 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Prove permanent native article deletion through complete exact-ID collections.
 *
 * Joomla 6.1's article controller casts the state filter to INT: "*" is not an
 * all-state query. Its administrator model supports exact search "id:<id>".
 * Query each native article state, after proving the same collection exposed
 * the approved target before deletion. A failed item GET alone proves nothing.
 *
 * @since 1.0.0
 */
final class ArticleDeletion
{
	/** @var int[] Native ContentComponent article states. @since 1.0.0 */
	public const STATES = [-2, 0, 1, 2];

	/**
	 * Select only the reviewed native article permanent DELETE contract.
	 *
	 * @param array $resolved Authorized current write definition.
	 * @param array $input Validated approved input.
	 * @param ?array $before Native single-item snapshot.
	 * @return ?array Private verification intent, or null for other operations.
	 * @since 1.0.0
	 */
	public static function intent(array $resolved, array $input, ?array $before): ?array
	{
		if (($resolved['action']['name'] ?? '') !== 'content.articles.delete'
			|| ($resolved['binding']['track'] ?? '') !== 'api')
		{
			return null;
		}

		$config = $resolved['binding']['configuration'];

		if (($resolved['binding']['handler'] ?? '') !== 'api.request' || ($config['method'] ?? '') !== 'DELETE'
			|| ($config['route'] ?? '') !== '/v1/content/articles/:id' || ($config['read_action'] ?? '') !== 'content.articles.get')
		{
			throw new OperationException('BINDING_INVALID', 'Permanent article deletion requires the reviewed native article API binding.');
		}

		$id = MenuItemComponents::identifier($input['id'] ?? null);
		$item = $before['item'] ?? [];

		if ($id === null || MenuItemComponents::identifier($item['id'] ?? null) !== $id)
		{
			throw new OperationException('PRECONDITION_CHANGED', 'The article snapshot identity does not match the approved deletion target.');
		}

		$state = $item['state'] ?? null;
		$state = (is_int($state) || is_string($state)) && in_array((string) $state, ['-2', '0', '1', '2'], true) ? (int) $state : null;

		return ['id' => $id, 'state' => $state, 'available' => false,
			'readAction' => 'content.articles.get', 'listAction' => 'content.articles.list'];
	}

	/**
	 * Require complete native exact-ID evidence; never follow a returned URL.
	 *
	 * The reviewed search can return at most one identity on offset zero. Joomla's
	 * JSON:API view reports zero total pages for an empty collection. Any additional
	 * page, unrelated identity or inconsistent state means evidence is unavailable.
	 *
	 * @param array $response Original authenticated API response.
	 * @param int $id Approved article identity.
	 * @param int $state Exact native state requested.
	 * @return bool Whether the article is present in this complete collection.
	 * @since 1.0.0
	 */
	public static function present(array $response, int $id, int $state): bool
	{
		$document = $response['data'] ?? null;
		$items = is_array($document) ? ($document['data'] ?? null) : null;
		$total = is_array($document) ? ($document['meta']['total-pages'] ?? null) : null;

		if (($response['status'] ?? null) !== 200 || !is_array($items) || !array_is_list($items)
			|| count($items) > 1 || !is_int($total) || $total !== count($items)
			|| !empty($document['links']['next']) || !empty($document['errors']))
		{
			throw new OperationException('DELETE_VERIFICATION_UNAVAILABLE', 'The native exact-ID article collection was incomplete or malformed.');
		}

		if ($items === [])
		{
			return false;
		}

		$row = $items[0];
		$attributes = is_array($row) ? ($row['attributes'] ?? null) : null;
		$observedState = is_array($attributes) ? ($attributes['state'] ?? null) : null;

		if (!is_array($row) || ($row['type'] ?? '') !== 'articles'
			|| MenuItemComponents::identifier($row['id'] ?? null) !== $id || !is_array($attributes)
			|| (array_key_exists('id', $attributes) && MenuItemComponents::identifier($attributes['id']) !== $id)
			|| (!is_int($observedState) && !is_string($observedState)) || (string) $observedState !== (string) $state)
		{
			throw new OperationException('DELETE_VERIFICATION_UNAVAILABLE', 'The native article collection did not confirm its requested identity and state.');
		}

		return true;
	}

	/**
	 * Confirm absence across all native states using four bounded read requests.
	 *
	 * @param array $intent Frozen intent with pre-deletion collection visibility.
	 * @param callable $read Same-principal current authorized collection reader.
	 * @return array Independent permanent deletion evidence.
	 * @since 1.0.0
	 */
	public static function verify(array $intent, callable $read): array
	{
		foreach (self::STATES as $state)
		{
			if (self::present($read($state), $intent['id'], $state))
			{
				return ['status' => 'uncertain', 'id' => $intent['id'], 'source' => 'article-collection',
					'reason' => 'The article remains in the native collection; permanent deletion was not verified.'];
			}
		}

		return ['status' => 'verified', 'postcondition' => 'resource-absent', 'id' => $intent['id'],
			'source' => 'article-collection', 'checkedStates' => self::STATES];
	}
}
