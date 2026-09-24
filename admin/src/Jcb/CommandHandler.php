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
use VDM\Component\JoomEngineMcp\Administrator\Contract\DeferredHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PlanPreviewInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Principal-bound JCB planning and isolated execution of registered commands.
 * The same user remains the authority when an HTTP job runs in a PHP worker.
 *
 * @since 0.1.0
 */
final class CommandHandler implements DeferredHandlerInterface, PlanPreviewInterface
{
	/** @var Closure(array):array Native registry inspection in an isolated context. @since 0.1.0 */
	private Closure $inspect;
	/** @var CommandInput Immutable native selectors and options. @since 0.1.0 */
	private CommandInput $input;
	/** @var Closure():string Definition/configuration fingerprint. @since 0.1.0 */
	private Closure $snapshot;
	/** @var Closure(array,array):array Server-owned isolated executor. @since 0.1.0 */
	private Closure $executor;

	/**
	 * @param callable $inspect Native registry inspector; never supplied by a row.
	 * @param CommandInput $input Native option freezer.
	 * @param callable $snapshot Current JCB definition fingerprint.
	 * @param callable $executor Isolated worker with durable claim context.
	 * @since 0.1.0
	 */
	public function __construct(callable $inspect, CommandInput $input, callable $snapshot, callable $executor)
	{
		$this->inspect = Closure::fromCallable($inspect);
		$this->input = $input;
		$this->snapshot = Closure::fromCallable($snapshot);
		$this->executor = Closure::fromCallable($executor);
	}

	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		throw new OperationException('CONFIRMATION_REQUIRED', 'JCB commands require a prepared, confirmed durable job.');
	}

	/** @inheritDoc */
	public function prepare(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		$this->authorize($binding, $principal);
		$configuration = $binding['configuration'] ?? [];
		$contract = ($this->inspect)($configuration);
		$frozen = $this->input->freeze((array) ($arguments['options'] ?? []), $configuration, $principal->isLocal());
		$this->authorizeInstallation($frozen, $configuration, $principal);

		return ['command' => $configuration['command'], 'configuration' => $configuration,
			'input' => $frozen, 'contractFingerprint' => $contract['fingerprint'],
			'implementation' => $contract['implementation'], 'snapshot' => ($this->snapshot)(),
			'principal' => $principal->getId(), 'local' => $principal->isLocal()];
	}

	/** @inheritDoc */
	public function preview(array $prepared): array
	{
		return (new CommandPreview())->describe($prepared);
	}

	/** @inheritDoc */
	public function apply(array $prepared, array $binding, PrincipalInterface $principal, array $execution): array
	{
		$this->authorize($binding, $principal);

		if (($prepared['principal'] ?? '') !== $principal->getId() || ($prepared['local'] ?? null) !== $principal->isLocal()
			|| ($prepared['command'] ?? '') !== ($binding['configuration']['command'] ?? '')
			|| !hash_equals(Json::canonical($prepared['configuration'] ?? []), Json::canonical($binding['configuration'] ?? [])))
		{
			throw new OperationException('PLAN_CHANGED', 'The prepared JCB command no longer matches this principal or binding.');
		}

		$actual = ($this->inspect)($binding['configuration']);

		foreach (['fingerprint' => 'contractFingerprint', 'implementation' => 'implementation'] as $current => $saved)
		{
			if (!is_string($prepared[$saved] ?? null) || !hash_equals($prepared[$saved], $actual[$current]))
			{
				throw new OperationException('JCB_COMMAND_CHANGED', 'The native JCB implementation changed after planning.');
			}
		}

		if (!hash_equals((string) ($prepared['snapshot'] ?? ''), ($this->snapshot)()))
		{
			throw new OperationException('PLAN_STALE', 'JCB definitions or repository configuration changed after planning.');
		}

		$this->authorizeInstallation($prepared['input'], $binding['configuration'], $principal);

		return ($this->executor)(['protocol' => 'joomengine-worker/1', 'operation' => 'jcb.command',
			'prepared' => $prepared], $execution);
	}

	/** @inheritDoc */
	public function verify(array $prepared, array $result, array $binding, PrincipalInterface $principal): array
	{
		$this->authorize($binding, $principal);
		$verification = $result['verification'] ?? [];

		if (($result['exitCode'] ?? -1) !== 0)
		{
			return ['status' => 'partial', 'reason' => 'The native command reported failure; persisted effects require inspection.'];
		}

		if (!in_array($verification['status'] ?? '', ['verified', 'partial', 'unverified'], true))
		{
			return ['status' => 'unverified', 'reason' => 'The worker did not supply independent effect evidence.'];
		}

		return $verification;
	}

	/** @param array $binding Reviewed binding. @param PrincipalInterface $principal Current authority. @return void @since 0.1.0 */
	private function authorize(array $binding, PrincipalInterface $principal): void
	{
		if (($binding['handler'] ?? '') !== 'jcb.command' || ($binding['track'] ?? '') !== $principal->getTrack()
			|| ($principal->isLocal() && ($principal->getTrack() !== 'cli' || PHP_SAPI !== 'cli'))
			|| (!$principal->isLocal() && ($principal->getTrack() !== 'api'
				|| !$principal->authorise('mcp.access', 'com_joomengine_mcp')
				|| !$principal->authorise('mcp.write', 'com_joomengine_mcp')
				|| !$principal->authorise('core.admin', 'com_componentbuilder'))))
		{
			throw new OperationException('JCB_ACCESS_DENIED', 'JCB execution requires the current native authority and an explicit confirmed grant.');
		}
	}

	/** @param array $input Frozen options. @param array $configuration Binding. @param PrincipalInterface $principal Current authority. @return void @since 0.1.0 */
	private function authorizeInstallation(array $input, array $configuration, PrincipalInterface $principal): void
	{
		if ($principal->isLocal() || ($configuration['command'] ?? '') !== 'componentbuilder:compile:component')
		{
			return;
		}

		if (CommandInput::requestsInstallation($input) && !$principal->authorise('core.admin', 'com_installer'))
		{
			throw new OperationException('JCB_INSTALL_DENIED', 'Installing compiled extensions requires native installer administration permission.');
		}
	}
}
