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
 * State adapter for a factory-selected Joomla list model.
 *
 * Instances are registered only for ManageModel::publish() and
 * UpdatesitesModel::publish(); there is no caller-selected model or method.
 *
 * @since  0.1.0
 */
final class FixedModelStateAction implements ActionInterface
{
	/**
	 * The stable public action identifier.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $name;

	/**
	 * The human-readable action purpose.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $description;

	/**
	 * The fixed Joomla component identifier.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $component;

	/**
	 * The fixed administrator model name.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $modelName;

	/**
	 * The native record identifier field.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $idField;

	/**
	 * The allowlisted native fields returned to callers.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	private array $readFields;

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
	 * @param   string                  $name         The stable public action identifier.
	 * @param   string                  $description  The human-readable action purpose.
	 * @param   string                  $component    The fixed Joomla component identifier.
	 * @param   string                  $modelName    The fixed administrator model name.
	 * @param   string                  $idField      The native record identifier field.
	 * @param   list<string>            $readFields   The allowlisted native fields returned to callers.
	 * @param   ModelProviderInterface  $models       The provider of native administrator models.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		string $name,
		string $description,
		string $component,
		string $modelName,
		string $idField,
		array $readFields,
		ModelProviderInterface $models,
	)
	{
		$this->name = $name;
		$this->description = $description;
		$this->component = $component;
		$this->modelName = $modelName;
		$this->idField = $idField;
		$this->readFields = $readFields;
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
			$this->name,
			$this->description,
			'high',
			[
				['action' => 'core.manage', 'asset' => $this->component],
				['action' => 'core.edit.state', 'asset' => $this->component],
			],
			[
				'type' => 'object',
				'required' => ['id', 'enabled'],
				'properties' => [
					'id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2_147_483_647],
					'enabled' => ['type' => 'boolean'],
					'dryRun' => ['type' => 'boolean', 'default' => true],
					'_edgeConfirmed' => ['type' => 'boolean', 'writeOnly' => true],
				],
				'additionalProperties' => false,
			],
			[
				'type' => 'object',
				'required' => ['applied', 'dryRun', 'id', 'requestedState', 'preState', 'recovery'],
				'properties' => [
					'applied' => ['type' => 'boolean'],
					'dryRun' => ['type' => 'boolean'],
					'id' => ['type' => 'integer'],
					'requestedState' => ['type' => 'object'],
					'preState' => ['type' => 'object'],
					'postState' => ['type' => ['object', 'null']],
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
		Input::rejectUnknown($input, ['id', 'enabled', 'dryRun', '_edgeConfirmed']);
		$id = Input::integer($input, 'id', 0, 1, 2_147_483_647);
		$enabled = Input::boolean($input, 'enabled', false);
		$before = $this->snapshot($id);

		if ($before === null)
		{
			throw new ActionException('NOT_FOUND', sprintf('Joomla record %d was not found for "%s".', $id, $this->name));
		}

		$plan = [
			'applied' => false,
			'dryRun' => true,
			'id' => $id,
			'requestedState' => ['enabled' => $enabled],
			'preState' => $before,
			'recovery' => [
				'action' => $this->name,
				'input' => ['id' => $id, 'enabled' => (bool) ($before['enabled'] ?? false)],
			],
			'requiresEdgeConfirmation' => true,
		];

		if (Input::boolean($input, 'dryRun', true))
		{
			return $plan;
		}

		if (!Input::boolean($input, '_edgeConfirmed', false))
		{
			throw new ActionException('CONFIRMATION_REQUIRED', sprintf('Action "%s" requires signed MCP edge confirmation.', $this->name));
		}

		$model = $this->models->administrator($this->component, $this->modelName);

		if (!method_exists($model, 'publish'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', sprintf('The Joomla model for "%s" cannot change state.', $this->name));
		}

		$ids = [$id];

		try
		{
			$completed = $model->publish($ids, $enabled ? 1 : 0) === true;
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', sprintf('Joomla could not execute "%s".', $this->name));
		}

		if (!$completed)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', sprintf('Joomla rejected "%s".', $this->name));
		}

		$after = $this->snapshot($id);
		$verified = $after !== null && (bool) ($after['enabled'] ?? !$enabled) === $enabled;

		if (!$verified)
		{
			throw new ActionException(
				'POSTCONDITION_FAILED',
				sprintf('Joomla did not verify the requested state for "%s".', $this->name),
			);
		}

		return [
			'applied' => true,
			'dryRun' => false,
			'id' => $id,
			'requestedState' => ['enabled' => $enabled],
			'preState' => $before,
			'postState' => $after,
			'verification' => ['readBackCompleted' => $after !== null, 'matchesRequestedState' => $verified],
			'recovery' => [
				'action' => $this->name,
				'input' => ['id' => $id, 'enabled' => (bool) ($before['enabled'] ?? false)],
			],
		];
	}

	/**
	 * Read the selected native record before or after its mutation.
	 *
	 * @param   int  $id  The stable entity identifier.
	 *  @return array<string, int|string|bool|null>|null
	 *
	 * @since  0.1.0
	 */
	private function snapshot(int $id): ?array
	{
		$model = $this->models->administrator($this->component, $this->modelName);

		if (!method_exists($model, 'setState') || !method_exists($model, 'getItems'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', sprintf('The Joomla model for "%s" cannot be verified.', $this->name));
		}

		$model->setState('list.start', 0);
		$model->setState('list.limit', 2);
		$model->setState('filter.search', 'id:' . $id);

		try
		{
			$items = $model->getItems();
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', sprintf('Joomla could not verify "%s".', $this->name));
		}

		if (!is_array($items))
		{
			throw new ActionException('MODEL_RESULT_INVALID', sprintf('Joomla returned invalid verification data for "%s".', $this->name));
		}

		foreach ($items as $item)
		{
			if (!is_object($item) && !is_array($item))
			{
				continue;
			}

			$source = is_object($item) ? get_object_vars($item) : $item;

			if ((int) ($source[$this->idField] ?? 0) !== $id)
			{
				continue;
			}

			$snapshot = [];

			foreach ($this->readFields as $field)
			{
				$value = $source[$field] ?? null;
				$snapshot[$field] = is_scalar($value) || $value === null ? $value : null;
			}

			$snapshot['enabled'] = (bool) ($source['enabled'] ?? false);

			return $snapshot;
		}

		return null;
	}
}
