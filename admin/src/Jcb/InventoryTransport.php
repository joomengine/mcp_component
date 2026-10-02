<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use stdClass;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Lossless bounded inventory IPC with shared, content-addressed native forms.
 *
 * Contract JSON strings preserve empty object/list shapes across the worker's
 * associative envelope decoder. References never reach persisted bindings.
 *
 * @since 1.0.6
 */
final class InventoryTransport
{
	/** @var string Explicit fixed-worker inventory encoding. @since 1.0.6 */
	public const FORMAT = 'joomengine-inventory/1';
	/** @var int Existing maximum worker response bytes. @since 1.0.6 */
	public const MAX_WIRE_BYTES = 8388608;
	/** @var int Maximum expanded native inventory, before catalogue mutation. @since 1.0.6 */
	public const MAX_EXPANDED_BYTES = 67108864;
	/** @var int Maximum complete native route inventory. @since 1.0.6 */
	public const MAX_ROUTES = 8192;

	/**
	 * Replace repeated complete contracts with exact canonical JSON references.
	 *
	 * @param array $result Actual observed command and API inventories.
	 * @return array Complete bounded worker representation.
	 * @since 1.0.6
	 */
	public static function pack(array $result): array
	{
		self::inventory($result);
		$fingerprint = Json::canonicalHash($result);
		$contracts = [];
		$expanded = 0;

		foreach ($result['api']['routes'] as &$route)
		{
			if (array_key_exists('form_contract_ref', $route))
			{
				self::invalid('An observed route collides with the inventory reference field.');
			}
			if (!array_key_exists('form_contract', $route))
			{
				continue;
			}
			if (!is_array($route['form_contract']))
			{
				self::invalid('The native form contract is invalid.');
			}

			$text = Json::canonical($route['form_contract']);
			$reference = hash('sha256', $text);
			$contracts[$reference] = $text;
			$expanded += strlen($text);
			self::expanded($expanded);
			unset($route['form_contract']);
			$route['form_contract_ref'] = $reference;
		}
		unset($route);

		$manifest = Json::canonical($result);
		self::expanded($expanded + strlen($manifest));
		$packed = ['protocol' => 'joomengine-worker/1', 'inventory_format' => self::FORMAT, 'inventory_fingerprint' => $fingerprint,
			'inventory' => $manifest, 'form_contracts' => (object) $contracts];
		Json::encode($packed, self::MAX_WIRE_BYTES);

		return $packed;
	}

	/**
	 * Restore every exact contract, rejecting incomplete or altered dictionaries.
	 *
	 * Validation completes before the caller can retire or persist any catalogue
	 * row. Limits constrain expanded work, rather than increasing HTTP budgets.
	 *
	 * @param array $result Decoded fixed-worker representation.
	 * @return array Original complete observed inventory.
	 * @since 1.0.6
	 */
	public static function unpack(array $result): array
	{
		if (($result['inventory_format'] ?? null) !== self::FORMAT
			|| !is_string($result['inventory'] ?? null)
			|| !is_string($result['inventory_fingerprint'] ?? null)
			|| preg_match('/\A[0-9a-f]{64}\z/D', $result['inventory_fingerprint']) !== 1
			|| (!is_array($result['form_contracts'] ?? null) && !($result['form_contracts'] ?? null) instanceof stdClass))
		{
			self::invalid('The compact native inventory encoding is invalid.');
		}

		Json::encode($result, self::MAX_WIRE_BYTES);
		$fingerprint = $result['inventory_fingerprint'];
		$dictionary = (array) $result['form_contracts'];
		if (count($dictionary) > self::MAX_ROUTES)
		{
			self::invalid('The native form dictionary exceeds its bounded cardinality.');
		}
		$expanded = strlen($result['inventory']);
		$result = Json::native(Json::decode($result['inventory'], false, self::MAX_WIRE_BYTES));
		if (!is_array($result))
		{
			self::invalid('The native inventory manifest is invalid.');
		}
		self::inventory($result);
		$contracts = [];
		$used = [];

		foreach ($dictionary as $reference => $text)
		{
			if (!is_string($reference) || preg_match('/\A[0-9a-f]{64}\z/D', $reference) !== 1
				|| !is_string($text) || !hash_equals($reference, hash('sha256', $text)))
			{
				self::invalid('The native form dictionary failed its content fingerprint.');
			}

			$contract = Json::native(Json::decode($text, false, self::MAX_WIRE_BYTES));
			if (!is_array($contract) || !is_string($contract['fingerprint'] ?? null))
			{
				self::invalid('The native form dictionary lacks source contract evidence.');
			}
			$source = $contract;
			unset($source['fingerprint']);
			if (!hash_equals($contract['fingerprint'], Json::canonicalHash($source)))
			{
				self::invalid('The restored native form source fingerprint is invalid.');
			}
			$contracts[$reference] = $contract;
		}

		foreach ($result['api']['routes'] as &$route)
		{
			if (array_key_exists('form_contract', $route))
			{
				self::invalid('The compact inventory contains an unreferenced form contract.');
			}
			if (!array_key_exists('form_contract_ref', $route))
			{
				continue;
			}

			$reference = $route['form_contract_ref'];
			if (!is_string($reference) || !isset($contracts[$reference]))
			{
				self::invalid('The native inventory references a missing form contract.');
			}
			$expanded += strlen($dictionary[$reference]);
			self::expanded($expanded);
			$used[$reference] = true;
			$route['form_contract'] = $contracts[$reference];
			unset($route['form_contract_ref']);
		}
		unset($route);

		if (count($used) !== count($dictionary))
		{
			self::invalid('The native form dictionary contains unrelated contracts.');
		}
		if (!hash_equals($fingerprint, Json::canonicalHash($result)))
		{
			self::invalid('The complete native inventory failed its observed fingerprint.');
		}

		return $result;
	}

	/** @param array $result Complete inventory envelope. @return void @since 1.0.6 */
	private static function inventory(array $result): void
	{
		if (!is_array($result['commands'] ?? null) || !is_array($result['api'] ?? null)
			|| !is_array($result['api']['routes'] ?? null) || !array_is_list($result['api']['routes'])
			|| count($result['api']['routes']) > self::MAX_ROUTES)
		{
			self::invalid('A complete bounded native inventory is required.');
		}
		foreach ($result['api']['routes'] as $route)
		{
			if (!is_array($route))
			{
				self::invalid('The native inventory contains an invalid route.');
			}
		}
	}

	/** @param int $bytes Estimated full expanded JSON bytes. @return void @since 1.0.6 */
	private static function expanded(int $bytes): void
	{
		if ($bytes > self::MAX_EXPANDED_BYTES)
		{
			throw new OperationException('JCB_INVENTORY_LIMIT', 'The complete native inventory exceeds its bounded expanded size.');
		}
	}

	/** @param string $message Safe inventory diagnostic. @return never @since 1.0.6 */
	private static function invalid(string $message): never
	{
		throw new OperationException('JCB_INVENTORY_INVALID', $message);
	}
}
