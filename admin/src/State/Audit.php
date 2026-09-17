<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\State;


use Closure;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Append-only bounded audit events without request bodies, tokens or exception dumps.
 *
 * @since 0.1.0
 */
final class Audit
{
	/** @var StoreInterface Durable audit storage. @since 0.1.0 */
	private StoreInterface $store;

	/** @var PrincipalInterface Authenticated audit actor. @since 0.1.0 */
	private PrincipalInterface $principal;

	/** @var Closure():int Injectable Unix-second clock. @since 0.1.0 */
	private Closure $clock;

	/** @param StoreInterface $store Persistence. @param PrincipalInterface $principal Actor. @param callable $clock Unix-second clock. @since 0.1.0 */
	public function __construct(StoreInterface $store, PrincipalInterface $principal, callable $clock)
	{
		$this->store = $store;
		$this->principal = $principal;
		$this->clock = Closure::fromCallable($clock);
	}

	/**
	 * Persist explicit metadata only; arbitrary input keys never enter the log.
	 *
	 * @param string $event Stable application event.
	 * @param string $outcome Outcome category.
	 * @param string $action Semantic action name.
	 * @param string $plan Plan identifier or empty.
	 * @param string $execution Execution identifier or empty.
	 * @param array<string,mixed> $metadata Safe, allowlisted metadata.
	 * @return void
	 * @since 0.1.0
	 */
	public function record(string $event, string $outcome, string $action = '', string $plan = '', string $execution = '', array $metadata = []): void
	{
		$safe = [];

		foreach (['transport', 'toolset', 'httpStatus', 'phase', 'dryRun', 'fieldsCount', 'requestId', 'grantId', 'reasonCode'] as $key)
		{
			$value = $metadata[$key] ?? null;

			if (is_bool($value) || is_int($value) || (is_string($value) && strlen($value) <= 190 && preg_match('/[\x00-\x1f]/', $value) !== 1))
			{
				$safe[$key] = $value;
			}
		}

		$identity = $this->principal->getId();
		$this->store->insert('audit', [
			'uuid' => Json::uuid(), 'principal_key' => hash('sha256', $identity),
			'actor_id' => str_starts_with($identity, 'joomla:') ? (int) substr($identity, 7) : 0,
			'event' => substr($event, 0, 190), 'action_name' => substr($action, 0, 190),
			'plan_uuid' => $plan, 'execution_uuid' => $execution, 'outcome' => substr($outcome, 0, 190),
			'metadata' => Json::encode((object) $safe, 8192), 'created_at' => ($this->clock)(),
		]);
	}
}
