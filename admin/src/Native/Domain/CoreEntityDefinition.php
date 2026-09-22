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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Domain;


use InvalidArgumentException;


/**
 * Describe fixed Joomla model, field and permission mappings for an entity.
 *
 * @since  0.1.0
 */
final class CoreEntityDefinition
{
	/**
	 * The stable entity identifier.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $id;

	/**
	 * The human-readable entity label.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $label;

	/**
	 * The fixed Joomla component identifier.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $component;

	/**
	 * The fixed administrator model used for list reads.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $listModel;

	/**
	 * The fixed administrator model used for item writes and reads.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $itemModel;

	/**
	 * The allowlisted native fields returned to callers.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $readFields;

	/**
	 * The allowlisted fields accepted by the native write model.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $writeFields;

	/**
	 * The reviewed defaults for new native records.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $defaults;

	/**
	 * The fixed filters supplied to the administrator model.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $modelState;

	/**
	 * The native model filter for publication state.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $stateFilter;

	/**
	 * Whether the entity supports native state transitions.
	 *
	 * @var   bool
	 *
	 * @since  0.1.0
	 */
	public bool $supportsState;

	/**
	 * Whether writes require the high-risk policy.
	 *
	 * @var   bool
	 *
	 * @since  0.1.0
	 */
	public bool $highRisk;

	/**
	 * The fields excluded from ordinary readable output.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	public array $sensitiveFields;

	/**
	 * The native model primary key.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $primaryKey;

	/**
	 * The native record field holding its state.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	public string $stateField;

	/**
	 * Validate and retain the fixed native action contract.
	 *
	 * @param   string                       $id               The stable entity identifier.
	 * @param   string                       $label            The human-readable entity label.
	 * @param   string                       $component        The fixed Joomla component identifier.
	 * @param   string                       $listModel        The fixed administrator model used for list reads.
	 * @param   string                       $itemModel        The fixed administrator model used for item writes and reads.
	 * @param   list<string>                 $readFields       The allowlisted native fields returned to callers.
	 * @param   list<string>                 $writeFields      The allowlisted fields accepted by the native write model.
	 * @param   array<string, scalar|array>  $defaults         The reviewed defaults for new native records.
	 * @param   array<string, scalar|array>  $modelState       The fixed filters supplied to the administrator model.
	 * @param   string                       $stateFilter      The native model filter for publication state.
	 * @param   bool                         $supportsState    Whether the entity supports native state transitions.
	 * @param   bool                         $highRisk         Whether writes require the high-risk policy.
	 * @param   list<string>                 $sensitiveFields  The fields excluded from ordinary readable output.
	 * @param   string                       $primaryKey       The native model primary key.
	 * @param   string                       $stateField       The native record field holding its state.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		string $id,
		string $label,
		string $component,
		string $listModel,
		string $itemModel,
		array $readFields,
		array $writeFields,
		array $defaults = [],
		array $modelState = [],
		string $stateFilter = 'filter.published',
		bool $supportsState = true,
		bool $highRisk = false,
		array $sensitiveFields = [],
		string $primaryKey = 'id',
		string $stateField = 'published',
	)
	{
		$this->id = $id;
		$this->label = $label;
		$this->component = $component;
		$this->listModel = $listModel;
		$this->itemModel = $itemModel;
		$this->readFields = $readFields;
		$this->writeFields = $writeFields;
		$this->defaults = $defaults;
		$this->modelState = $modelState;
		$this->stateFilter = $stateFilter;
		$this->supportsState = $supportsState;
		$this->highRisk = $highRisk;
		$this->sensitiveFields = $sensitiveFields;
		$this->primaryKey = $primaryKey;
		$this->stateField = $stateField;

		if (!preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/', $id))
		{
			throw new InvalidArgumentException(sprintf('Invalid core entity id "%s".', $id));
		}

		if (!preg_match('/^com_[a-z0-9_]+$/', $component))
		{
			throw new InvalidArgumentException(sprintf('Invalid core component "%s".', $component));
		}

		if ($readFields === [] || $writeFields === [])
		{
			throw new InvalidArgumentException(sprintf('Entity "%s" must declare explicit fields.', $id));
		}

		if (array_diff($sensitiveFields, $writeFields) !== [])
		{
			throw new InvalidArgumentException(sprintf('Entity "%s" has an unknown sensitive field.', $id));
		}

		if (!preg_match('/^[a-z][a-z0-9_]*$/', $primaryKey))
		{
			throw new InvalidArgumentException(sprintf('Entity "%s" has an invalid primary key.', $id));
		}

		if ($supportsState && !in_array($stateField, $readFields, true))
		{
			throw new InvalidArgumentException(sprintf('Entity "%s" has no readable state field "%s".', $id, $stateField));
		}
	}

	/**
	 * Build the stable action identifier for one reviewed entity operation.
	 *
	 * @param   string  $operation  The fixed operation selected by the registered binding.
	 * @return  string
	 *
	 * @since  0.1.0
	 */
	public function actionName(string $operation): string
	{
		return $this->id . '.' . $operation;
	}

	/**
	 * Resolve the Joomla asset name for one entity record.
	 *
	 * @param   int  $id  The stable entity identifier.
	 * @return  string
	 *
	 * @since  0.1.0
	 */
	public function itemAsset(int $id): string
	{
		return $this->component . '.' . strtolower($this->itemModel) . '.' . $id;
	}
}
