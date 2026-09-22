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


use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;


/**
 * Adapts the native update:extensions:check sequence: UpdateModel::purge(),
 * UpdateModel::findUpdates(), then a fresh Joomla model read-back.
 *
 * @since  0.1.0
 */
final class RefreshExtensionUpdatesAction implements ActionInterface
{
	/**
	 * The provider of native administrator models.
	 *
	 * @var   ModelProviderInterface
	 *
	 * @since  0.1.0
	 */
	private ModelProviderInterface $models;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   ModelProviderInterface  $models  The provider of native administrator models.
	 *
	 * @since  0.1.0
	 */
	public function __construct(ModelProviderInterface $models)
	{
		$this->models = $models;
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
			'extensions.updates.refresh',
			'Refresh stable extension-update metadata through Joomla UpdateModel.',
			'write',
			[['action' => 'core.manage', 'asset' => 'com_installer']],
			[
				'type' => 'object',
				'properties' => [
					'dryRun' => ['type' => 'boolean', 'default' => true],
					'_edgeConfirmed' => ['type' => 'boolean', 'writeOnly' => true],
				],
				'additionalProperties' => false,
			],
			[
				'type' => 'object',
				'required' => ['applied', 'dryRun', 'channel', 'preState', 'recovery'],
				'properties' => [
					'applied' => ['type' => 'boolean'],
					'dryRun' => ['type' => 'boolean'],
					'channel' => ['type' => 'string', 'const' => 'stable'],
					'networkAccess' => ['type' => 'boolean'],
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
		Input::rejectUnknown($input, ['dryRun', '_edgeConfirmed']);
		$before = $this->count();
		$plan = [
			'applied' => false,
			'dryRun' => true,
			'channel' => 'stable',
			'preState' => ['availableUpdateCount' => $before],
			'networkAccess' => true,
			'recovery' => ['automaticRollback' => false, 'retry' => 'extensions.updates.refresh'],
			'requiresEdgeConfirmation' => true,
		];

		if (Input::boolean($input, 'dryRun', true))
		{
			return $plan;
		}

		if (!Input::boolean($input, '_edgeConfirmed', false))
		{
			throw new ActionException('CONFIRMATION_REQUIRED', 'Extension update refresh requires signed MCP edge confirmation.');
		}

		$model = $this->models->administrator('com_installer', 'Update');

		if (!method_exists($model, 'purge') || !method_exists($model, 'findUpdates'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Installer Update model is incompatible.');
		}

		try
		{
			if ($model->purge() !== true || $model->findUpdates() !== true)
			{
				throw new ActionException('MODEL_OPERATION_FAILED', 'Joomla did not complete the extension update refresh.');
			}
		}
		catch (ActionException $exception)
		{
			throw $exception;
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', 'Joomla could not refresh extension updates.');
		}

		$after = $this->count();

		return [
			'applied' => true,
			'dryRun' => false,
			'channel' => 'stable',
			'preState' => ['availableUpdateCount' => $before],
			'postState' => ['availableUpdateCount' => $after],
			'verification' => ['readBackCompleted' => true],
			'recovery' => ['automaticRollback' => false, 'retry' => 'extensions.updates.refresh'],
		];
	}

	/**
	 * Read the current number of records from the fixed installer model.
	 *
	 * @return  int
	 *
	 * @since  0.1.0
	 */
	private function count(): int
	{
		$model = $this->models->administrator('com_installer', 'Update');

		if (!method_exists($model, 'getTotal'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Installer Update model cannot report status.');
		}

		try
		{
			return max(0, (int) $model->getTotal());
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', 'Joomla could not count extension updates.');
		}
	}
}
