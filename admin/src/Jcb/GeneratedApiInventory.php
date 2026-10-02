<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Select installed extension-owned API contracts without shadowing Joomla core.
 * Registration and native form evidence remain authoritative; names never invent routes.
 *
 * @since 1.0.6
 */
final class GeneratedApiInventory
{
	/**
	 * Select enabled components outside Joomla's authoritative core identity list.
	 * Native uninstall protection and locking do not determine API capabilities.
	 *
	 * @param array $extensions Native extension registry observations.
	 * @param string[] $coreComponents Component elements from Joomla ExtensionHelper::getCoreExtensions().
	 * @return string[] Stable component allowlist for the observed route registry.
	 * @since 1.0.6
	 */
	public static function components(array $extensions, array $coreComponents): array
	{
		$components = [];

		foreach ($coreComponents as $component)
		{
			if (!is_string($component) || preg_match('/\Acom_[a-z][a-z0-9_]{0,95}\z/D', $component) !== 1)
			{
				throw new OperationException('JCB_INVENTORY_INVALID', 'The native core component identity list is invalid.');
			}
		}

		foreach ($extensions as $extension)
		{
			$element = $extension['element'] ?? '';

			if (($extension['type'] ?? '') === 'component' && (int) ($extension['enabled'] ?? 0) === 1
				&& !in_array($element, $coreComponents, true) && $element !== 'com_joomengine_mcp'
				&& is_string($element) && preg_match('/\Acom_[a-z][a-z0-9_]{0,95}\z/D', $element) === 1)
			{
				$components[$element] = $element;
			}
		}

		ksort($components, SORT_STRING);

		return array_values($components);
	}

	/**
	 * Attach native form evidence or explicit source-contract diagnostics.
	 *
	 * @param array $inventory Observed registered route inventory.
	 * @param string $administratorRoot Installed administrator component parent directory.
	 * @param string $apiRoot Installed API component parent directory.
	 * @return array Source-fingerprinted contracts, never fabricated executable rows.
	 * @since 1.0.6
	 */
	public static function enrich(array $inventory, string $administratorRoot, string $apiRoot): array
	{
		$routes = [];
		$forms = [];
		$unsupported = $inventory['unsupported'] ?? [];
		$owners = $inventory['unsupported_components'] ?? [];

		foreach ($inventory['routes'] ?? [] as $route)
		{
			try
			{
				$component = $route['defaults']['component'];
				if (!is_string($component) || preg_match('/\Acom_[A-Za-z][A-Za-z0-9_]*\z/D', $component) !== 1)
				{
					throw new OperationException('JCB_FORM_CONTRACT_INVALID', 'The native form component identity is invalid.');
				}
				$forms[$component] ??= new FormContracts($administratorRoot . '/' . $component, $apiRoot . '/' . $component);
				$contract = $forms[$component]->forRoute($route);

				if ($contract !== null)
				{
					$route['form_contract'] = $contract;
				}
				else
				{
					$route['form_contract_status'] = 'Native form metadata unavailable; the endpoint remains the validation authority.';
				}

				$routes[] = $route;
			}
			catch (OperationException $error)
			{
				$key = $route['method'] . ' ' . $route['route'];
				$unsupported[$key] = 'Native form metadata could not be safely synchronized: ' . $error->getMessage();
				$owners[$key] = $route['defaults']['component'];
			}
		}

		ksort($unsupported, SORT_STRING);
		ksort($owners, SORT_STRING);
		$inventory['routes'] = $routes;
		$inventory['unsupported'] = $unsupported;
		$inventory['unsupported_components'] = $owners;
		$inventory['fingerprint'] = Json::canonicalHash(['routes' => $routes,
			'unsupported' => $unsupported, 'components' => $inventory['components'] ?? []]);

		return $inventory;
	}
}
