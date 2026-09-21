<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Closure;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;


/**
 * Explicit privileged catalogue refresh retaining customization and native ACL.
 * Never invoked as a side effect of ordinary tool discovery or execution.
 *
 * @since 0.1.0
 */
final class CatalogueSynchronizer
{
	/** @var StoreInterface Installed definitions. @since 0.1.0 */
	private StoreInterface $store;
	/** @var Closure():void Joomla native asset synchronization. @since 0.1.0 */
	private Closure $assets;

	/** @param StoreInterface $store Installed database. @param callable $assets Native asset synchronizer. @since 0.1.0 */
	public function __construct(StoreInterface $store, callable $assets)
	{
		$this->store = $store;
		$this->assets = Closure::fromCallable($assets);
	}

	/**
	 * @param array $commands Actual native command inventory.
	 * @param array $api Actual native API route inventory.
	 * @param PrincipalInterface $principal Verified server owner or administrator.
	 * @return array Definition changes and actual observed inventory counts.
	 * @since 0.1.0
	 */
	public function synchronize(array $commands, array $api, PrincipalInterface $principal): array
	{
		if (!$principal->isLocal() && (!$principal->authorise('core.admin', 'com_joomengine_mcp')
			|| !$principal->authorise('core.admin', 'com_componentbuilder')))
		{
			throw new OperationException('JCB_CATALOGUE_DENIED', 'Catalogue synchronization requires component and JCB administration permission.');
		}

		$seed = (new CatalogueBuilder())->build($commands, $api);
		$changes = (new SeedUpdater($this->store))->apply($seed);
		($this->assets)();

		return $changes + ['revision' => $seed['source'], 'commands' => count($commands['commands'] ?? []),
			'apiRoutes' => count($api['routes'] ?? []), 'apiAvailable' => ($api['routes'] ?? []) !== [],
			'unsupportedCommands' => (object) ($commands['unsupported'] ?? []),
			'unsupportedRoutes' => (object) ($api['unsupported'] ?? [])];
	}
}
