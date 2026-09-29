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
 * Bind native menu component identity repair and verify its actual stored value.
 *
 * Joomla's item model derives this ID on reads, but does not persist the derived
 * value on save. Only a menu collection read proves the stored component ID.
 *
 * @since 1.0.0
 */
final class MenuItemComponents
{
	/**
	 * Describe a bounded server-owned workflow without allowing caller-supplied IDs.
	 *
	 * @param array $resolved Authorized action and binding.
	 * @param array $request Effective native form request after preservation/derivation.
	 * @return ?array Private deterministic workflow intent.
	 * @since 1.0.0
	 */
	public static function intent(array $resolved, array $request): ?array
	{
		if (($resolved['binding']['track'] ?? '') !== 'api' || ($resolved['binding']['handler'] ?? '') !== 'api.request'
			|| preg_match('/\A(menus\.(site|administrator)-items)\.(create|update)\z/D', $resolved['action']['name'] ?? '', $parts) !== 1)
		{
			return null;
		}

		$expectedRoute = '/v1/menus/' . $parts[2] . '/items' . ($parts[3] === 'update' ? '/:id' : '');

		if (($resolved['binding']['configuration']['route'] ?? '') !== $expectedRoute
			|| ($resolved['binding']['configuration']['method'] ?? '') !== ($parts[3] === 'create' ? 'POST' : 'PATCH'))
		{
			throw new OperationException('BINDING_INVALID', 'Menu component persistence requires the reviewed primary menu API route and method.');
		}

		$body = $request['body'] ?? [];
		$type = $body['type'] ?? null;
		$menutype = $body['menutype'] ?? null;

		if ((string) ($body['client_id'] ?? '') !== ($parts[2] === 'site' ? '0' : '1'))
		{
			throw new OperationException('BINDING_INVALID', 'The menu API form client does not match its approved route.');
		}

		if (!is_string($type) || !in_array($type, ['component', 'url', 'alias', 'separator', 'heading', 'container'], true))
		{
			throw new OperationException('INVALID_INPUT', 'A supported effective Joomla menu item type is required.');
		}

		if (!is_string($menutype) || $menutype === '' || strlen($menutype) > 255 || preg_match('/[\x00-\x1f\x7f]/', $menutype) === 1)
		{
			throw new OperationException('INVALID_INPUT', 'A bounded effective menu type is required for stored menu verification.');
		}

		return ['type' => $type, 'option' => $type === 'component' ? self::option($body['link'] ?? null) : null,
			'clientId' => $parts[2] === 'site' ? 0 : 1, 'menutype' => $menutype, 'link' => $body['link'] ?? '', 'readAction' => $parts[1] . '.get',
			'updateAction' => $parts[1] . '.update', 'listAction' => $parts[1] . '.list'];
	}

	/** @param array $intent Frozen approved workflow. @return array Non-secret approval disclosure. @since 1.0.0 */
	public static function preview(array $intent): array
	{
		return ['type' => $intent['type'], 'component' => $intent['option'], 'initialComponentId' => 0,
			'followUp' => $intent['type'] === 'component' ? 'Read the effective item and PATCH its native derived component ID once.' : 'No component-ID correction is needed.',
			'verification' => 'Confirm the stored component ID through the menu collection; a single-item GET is insufficient.'];
	}

	/**
	 * Obtain the native derived component identity only from the just-written item.
	 *
	 * @param array $intent Frozen approved target type and component.
	 * @param array $item Independent authenticated item read.
	 * @return int Native positive component ID.
	 * @since 1.0.0
	 */
	public static function derivedId(array $intent, array $item): int
	{
		$id = self::identifier($item['component_id'] ?? null);

		if (($item['type'] ?? null) !== 'component' || self::option($item['link'] ?? null) !== $intent['option']
			|| ($item['menutype'] ?? null) !== $intent['menutype'] || ($item['link'] ?? null) !== $intent['link']
			|| (string) ($item['client_id'] ?? '') !== (string) $intent['clientId'] || $id === null)
		{
			throw new OperationException('MENU_COMPONENT_UNRESOLVED', 'The written menu item did not expose a positive native component ID for its approved target. The initial mutation may already be stored.');
		}

		return $id;
	}

	/**
	 * Verify a raw collection row, following only bounded offsets on fixed routes.
	 *
	 * @param array $intent Frozen menu workflow.
	 * @param int $id Written menu item ID.
	 * @param int $componentId Expected derived ID, or zero for noncomponent items.
	 * @param callable $read Authorized menu collection reader.
	 * @param int $limit Configured page size.
	 * @return array Stored-state verification evidence.
	 * @since 1.0.0
	 */
	public static function verify(array $intent, int $id, int $componentId, callable $read, int $limit): array
	{
		$limit = max(1, min(100, $limit));
		$offset = 0;
		$seen = [];

		for ($page = 0; $page < 100; $page++)
		{
			$response = $read($intent['listAction'], ['offset' => $offset, 'limit' => $limit]);
			$document = $response['response']['data'] ?? null;
			$items = is_array($document) ? ($document['data'] ?? null) : null;

			if (!is_array($items) || !array_is_list($items) || count($items) > $limit)
			{
				throw new OperationException('MENU_VERIFICATION_UNAVAILABLE', 'The stored menu collection could not be verified after mutation.');
			}

			foreach ($items as $row)
			{
				$rowId = is_array($row) ? self::identifier($row['id'] ?? null) : null;
				$item = is_array($row) ? ($row['attributes'] ?? null) : null;

				if ($rowId === null || !is_array($item) || isset($seen[$rowId]))
				{
					throw new OperationException('MENU_VERIFICATION_UNAVAILABLE', 'The menu collection contains malformed or repeated identities.');
				}

				$seen[$rowId] = true;

				if ($rowId !== $id)
				{
					continue;
				}

				$stored = $item['component_id'] ?? null;
				$matched = (is_int($stored) || is_string($stored)) && (string) $stored === (string) $componentId
					&& ($item['type'] ?? null) === $intent['type']
					&& ($item['link'] ?? null) === $intent['link']
					&& ($item['menutype'] ?? null) === $intent['menutype']
					&& (string) ($item['client_id'] ?? '') === (string) $intent['clientId'];

				if ($intent['type'] === 'component')
				{
					$matched = $matched && self::option($item['link'] ?? null) === $intent['option'];
				}

				return ['status' => $matched ? 'verified' : 'uncertain', 'source' => 'menu-collection', 'id' => $id,
					'expectedComponentId' => $componentId, 'storedComponentId' => $stored,
					'reason' => $matched ? 'The menu collection confirms the persisted component identity.' : 'The stored menu component identity does not match the approved target. Reconcile the existing item before another write.'];
			}

			$offset += count($items);
			$total = $document['meta']['total-pages'] ?? null;
			$next = !empty($document['links']['next']);

			if ($total !== null && (!is_int($total) || $total < 0 || $total > 100))
			{
				throw new OperationException('MENU_VERIFICATION_UNAVAILABLE', 'The menu collection returned an invalid page count.');
			}

			if ($items === [] || (!$next && (count($items) < $limit || ($total !== null && $page + 1 >= $total))))
			{
				break;
			}
		}

		throw new OperationException('MENU_VERIFICATION_UNAVAILABLE', 'The written menu item was not found within the bounded stored collection.');
	}

	/** @param mixed $link Native component menu link. @return string One unambiguous component option. @since 1.0.0 */
	private static function option(mixed $link): string
	{
		if (!is_string($link) || strlen($link) > 8192 || ($uri = parse_url($link)) === false
			|| isset($uri['scheme']) || isset($uri['host']) || ($uri['path'] ?? '') !== 'index.php'
			|| isset($uri['fragment']) || !isset($uri['query']) || str_contains($uri['query'], ';'))
		{
			throw new OperationException('INVALID_INPUT', 'A component menu item requires an internal link with one component option.');
		}

		$options = [];

		foreach (explode('&', $uri['query']) as $pair)
		{
			[$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
			$name = urldecode($name);

			if (str_starts_with($name, 'option[') || str_contains($name, "\0")
				|| ($name !== ltrim($name) && preg_match('/\Aoption(?:\[|\z)/D', ltrim($name)) === 1))
			{
				throw new OperationException('INVALID_INPUT', 'Component options must be scalar query keys without array or null-byte syntax.');
			}

			if ($name === 'option')
			{
				$options[] = urldecode($value);
			}
		}

		if (count($options) !== 1 || preg_match('/\Acom_[A-Za-z0-9_]+\z/D', $options[0]) !== 1)
		{
			throw new OperationException('INVALID_INPUT', 'A component menu link must select one literal Joomla component option.');
		}

		return $options[0];
	}

	/** @param mixed $value Native JSON identity. @return ?int Positive safe integer or null. @since 1.0.0 */
	public static function identifier(mixed $value): ?int
	{
		if ((!is_int($value) && !is_string($value)) || preg_match('/\A[1-9][0-9]*\z/D', (string) $value) !== 1
			|| strlen((string) $value) > 16 || (int) $value > 9007199254740991)
		{
			return null;
		}

		return (int) $value;
	}
}
