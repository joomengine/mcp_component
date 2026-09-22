<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @git        JoomEngine MCP <https://github.com/joomengine/mcp_component>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSES/joomla-mcp.txt
 * @since      0.1.0
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Action;


use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\NativeOperationsInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;


/**
 *  Adapts Joomla SessionGcCommand and SessionMetadataGcCommand.
 *
 * @since  0.1.0
 */
final class SessionGarbageCollectionAction implements ActionInterface
{
	/**
	 * The fixed native console operation adapters.
	 *
	 * @var   NativeOperationsInterface
	 *
	 * @since  0.1.0
	 */
	private NativeOperationsInterface $operations;

	/**
	 * Whether to collect session metadata instead of sessions.
	 *
	 * @var   bool
	 *
	 * @since  0.1.0
	 */
	private bool $metadata;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   NativeOperationsInterface  $operations  The fixed native console operation adapters.
	 * @param   bool                       $metadata    Whether to collect session metadata instead of sessions.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		NativeOperationsInterface $operations,
		bool $metadata = false,
	)
	{
		$this->operations = $operations;
		$this->metadata = $metadata;
	}

	/**
	 * Return the action identity, schemas and required Joomla permissions.
	 *
	 * @return  ActionDescriptor
	 *
	 * @since  0.1.0
	 */
	public function descriptor(): ActionDescriptor
	{
		return new ActionDescriptor(
			$this->metadata ? 'sessions.metadata.gc' : 'sessions.data.gc',
			$this->metadata
				? 'Delete expired Joomla session metadata through session:metadata:gc.'
				: 'Run Joomla session storage garbage collection through session:gc.',
			'high',
			[['action' => 'core.admin', 'asset' => 'com_config']],
			[
				'type' => 'object',
				'properties' => array_filter([
					'application' => $this->metadata ? null : ['type' => 'string', 'enum' => ['site', 'administrator'], 'default' => 'site'],
					'dryRun' => ['type' => 'boolean', 'default' => true],
					'_edgeConfirmed' => ['type' => 'boolean', 'writeOnly' => true],
				]),
				'additionalProperties' => false,
			],
			[
				'type' => 'object',
				'required' => ['applied', 'dryRun', 'preState', 'recovery'],
				'properties' => [
					'applied' => ['type' => 'boolean'],
					'dryRun' => ['type' => 'boolean'],
					'preState' => ['type' => 'object'],
					'postState' => ['type' => 'object'],
					'verification' => ['type' => 'object'],
					'recovery' => ['type' => 'object'],
					'requiresEdgeConfirmation' => ['type' => 'boolean'],
				],
				'additionalProperties' => false,
			],
		);
	}

	/**
	 * Validate the supplied input and perform this action within its native contract.
	 *
	 * @param   array  $input  The input value.
	 * @return  array
	 *
	 * @since  0.1.0
	 */
	public function execute(array $input): array
	{
		$allowed = $this->metadata ? ['dryRun', '_edgeConfirmed'] : ['application', 'dryRun', '_edgeConfirmed'];
		Input::rejectUnknown($input, $allowed);
		$application = $this->metadata
			? null
			: (string) Input::choice($input, 'application', 'site', ['site', 'administrator']);
		$scope = $this->metadata ? 'expired-session-metadata' : 'expired-session-data';
		$plan = [
			'applied' => false,
			'dryRun' => true,
			'preState' => ['scope' => $scope, 'application' => $application, 'eligibleCount' => null],
			'recovery' => ['reversible' => false, 'reason' => 'Only expired session records are eligible.'],
			'requiresEdgeConfirmation' => true,
		];

		if (Input::boolean($input, 'dryRun', true))
		{
			return $plan;
		}

		if (!Input::boolean($input, '_edgeConfirmed', false))
		{
			throw new ActionException('CONFIRMATION_REQUIRED', 'Session garbage collection requires signed MCP edge confirmation.');
		}

		$exitCode = $this->metadata
			? $this->operations->garbageCollectSessionMetadata()
			: $this->operations->garbageCollectSessions((string) $application);

		return [
			'applied' => $exitCode === 0,
			'dryRun' => false,
			'preState' => ['scope' => $scope, 'application' => $application, 'eligibleCount' => null],
			'postState' => ['scope' => $scope, 'application' => $application],
			'verification' => ['nativeExitCode' => $exitCode, 'completed' => $exitCode === 0],
			'recovery' => ['reversible' => false, 'reason' => 'Only expired session records are eligible.'],
		];
	}
}
