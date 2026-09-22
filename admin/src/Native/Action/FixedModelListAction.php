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
 * Read an allowlisted projection from one fixed administrator model.
 *
 * @since  0.1.0
 */
final class FixedModelListAction implements ActionInterface
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
	 * The allowlisted output fields.
	 *
	 * @var   array
	 *
	 * @since  0.1.0
	 */
	private array $fields;

	/**
	 * The provider of native administrator models.
	 *
	 * @var   ModelProviderInterface
	 *
	 * @since  0.1.0
	 */
	private ModelProviderInterface $models;

	/**
	 * The reviewed native model read method.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $getter;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   string                  $name         The stable public action identifier.
	 * @param   string                  $description  The human-readable action purpose.
	 * @param   string                  $component    The fixed Joomla component identifier.
	 * @param   string                  $modelName    The fixed administrator model name.
	 * @param   list<string>            $fields       The allowlisted output fields.
	 * @param   ModelProviderInterface  $models       The provider of native administrator models.
	 * @param   string                  $getter       The reviewed native model read method.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		string $name,
		string $description,
		string $component,
		string $modelName,
		array $fields,
		ModelProviderInterface $models,
		string $getter = 'getItems',
	)
	{
		$this->name = $name;
		$this->description = $description;
		$this->component = $component;
		$this->modelName = $modelName;
		$this->fields = $fields;
		$this->models = $models;
		$this->getter = $getter;
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
			'read',
			[['action' => 'core.manage', 'asset' => $this->component]],
			[
				'type' => 'object',
				'properties' => [
					'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1_000_000],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
					'search' => ['type' => 'string', 'maxLength' => 200],
				],
				'additionalProperties' => false,
			],
			[
				'type' => 'object',
				'required' => ['items', 'page'],
				'properties' => [
					'items' => ['type' => 'array', 'items' => ['type' => 'object']],
					'page' => ['type' => 'object'],
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
		Input::rejectUnknown($input, ['offset', 'limit', 'search']);
		$offset = Input::integer($input, 'offset', 0, 0, 1_000_000);
		$limit = Input::integer($input, 'limit', 20, 1, 100);
		$search = Input::text($input, 'search');
		$model = $this->models->administrator($this->component, $this->modelName);

		if (!method_exists($model, 'setState') || !method_exists($model, $this->getter))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', sprintf('The Joomla model for "%s" is incompatible.', $this->name));
		}

		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);
		$model->setState('filter.search', $search);

		try
		{
			$rawItems = $model->{$this->getter}();
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', sprintf('Joomla could not execute "%s".', $this->name));
		}

		if (!is_array($rawItems))
		{
			throw new ActionException('MODEL_RESULT_INVALID', sprintf('The Joomla model for "%s" returned an invalid list.', $this->name));
		}

		$items = [];

		foreach ($rawItems as $item)
		{
			if (!is_object($item) && !is_array($item))
			{
				continue;
			}

			$source = is_object($item) ? get_object_vars($item) : $item;
			$normalised = [];

			foreach ($this->fields as $field)
			{
				$value = $source[$field] ?? null;
				$normalised[$field] = is_scalar($value) || $value === null ? $value : null;
			}

			$items[] = $normalised;
		}

		try
		{
			$total = method_exists($model, 'getTotal') ? (int) $model->getTotal() : count($items);
		}
		catch (Throwable)
		{
			$total = count($items);
		}

		return [
			'items' => $items,
			'page' => ['offset' => $offset, 'limit' => $limit, 'count' => count($items), 'total' => max(0, $total)],
		];
	}
}
