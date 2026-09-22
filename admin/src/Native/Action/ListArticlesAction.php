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
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaModelProvider;


/**
 * List a bounded page of articles through the Joomla content model.
 *
 * @since  0.1.0
 */
final class ListArticlesAction implements ActionInterface
{
	/**
	 * The allowlisted article fields returned to callers.
	 *
	 * @since  0.1.0
	 */
	private const FIELDS = [
		'id', 'title', 'alias', 'state', 'catid', 'access', 'language',
		'created', 'modified', 'publish_up', 'publish_down',
	];

	/**
	 * The provider of native administrator models.
	 *
	 * @var   JoomlaModelProvider
	 *
	 * @since  0.1.0
	 */
	private JoomlaModelProvider $models;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   JoomlaModelProvider  $models  The provider of native administrator models.
	 *
	 * @since  0.1.0
	 */
	public function __construct(JoomlaModelProvider $models)
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
			'content.articles.list',
			'List article metadata through the Joomla administrator Articles model.',
			'read',
			[['action' => 'core.manage', 'asset' => 'com_content']],
			[
				'type' => 'object',
				'properties' => [
					'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1_000_000],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
					'search' => ['type' => 'string', 'maxLength' => 200],
					'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2]],
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
		Input::rejectUnknown($input, ['offset', 'limit', 'search', 'state']);
		$offset = Input::integer($input, 'offset', 0, 0, 1_000_000);
		$limit = Input::integer($input, 'limit', 20, 1, 100);
		$search = Input::text($input, 'search');
		$state = Input::choice($input, 'state', 1, [-2, 0, 1, 2]);
		$model = $this->models->administrator('com_content', 'Articles');

		if (!method_exists($model, 'setState') || !method_exists($model, 'getItems'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Articles model is incompatible.');
		}

		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);
		$model->setState('filter.published', $state);

		if ($search !== '')
		{
			$model->setState('filter.search', $search);
		}

		$rawItems = $model->getItems();

		if (!is_array($rawItems))
		{
			throw new ActionException('MODEL_RESULT_INVALID', 'The Joomla Articles model returned an invalid result.');
		}

		$items = [];

		foreach ($rawItems as $item)
		{
			if (is_object($item))
			{
				$items[] = $this->normalise($item);
			}
		}
		$total = method_exists($model, 'getTotal') ? (int) $model->getTotal() : count($items);

		return [
			'items' => $items,
			'page' => [
				'offset' => $offset,
				'limit' => $limit,
				'count' => count($items),
				'total' => $total,
			],
		];
	}

	/**
	 * Project native output onto the reviewed readable fields.
	 *
	 * @param   object  $item  The item value.
	 *  @return array<string, int|string|null>
	 *
	 * @since  0.1.0
	 */
	private function normalise(object $item): array
	{
		$result = [];

		foreach (self::FIELDS as $field)
		{
			$value = $item->{$field} ?? null;
			$result[$field] = is_int($value) || is_string($value) ? $value : null;
		}

		return $result;
	}
}
