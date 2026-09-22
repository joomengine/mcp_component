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
 * Read a single article through the Joomla content model.
 *
 * @since  0.1.0
 */
final class GetArticleAction implements ActionInterface
{
	/**
	 * The allowlisted article fields returned to callers.
	 *
	 * @since  0.1.0
	 */
	private const FIELDS = [
		'id', 'title', 'alias', 'introtext', 'fulltext', 'state', 'catid',
		'access', 'language', 'created', 'created_by', 'modified', 'modified_by',
		'publish_up', 'publish_down', 'metadesc', 'metakey',
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
			'content.articles.get',
			'Get one article through the Joomla administrator Article model.',
			'read',
			[['action' => 'core.manage', 'asset' => 'com_content']],
			[
				'type' => 'object',
				'required' => ['id'],
				'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2_147_483_647]],
				'additionalProperties' => false,
			],
			[
				'type' => 'object',
				'properties' => [
					'id' => ['type' => ['integer', 'null']],
					'title' => ['type' => ['string', 'null']],
					'alias' => ['type' => ['string', 'null']],
					'introtext' => ['type' => ['string', 'null']],
					'fulltext' => ['type' => ['string', 'null']],
					'state' => ['type' => ['integer', 'string', 'null']],
					'catid' => ['type' => ['integer', 'string', 'null']],
					'access' => ['type' => ['integer', 'string', 'null']],
					'language' => ['type' => ['string', 'null']],
					'created' => ['type' => ['string', 'null']],
					'created_by' => ['type' => ['integer', 'string', 'null']],
					'modified' => ['type' => ['string', 'null']],
					'modified_by' => ['type' => ['integer', 'string', 'null']],
					'publish_up' => ['type' => ['string', 'null']],
					'publish_down' => ['type' => ['string', 'null']],
					'metadesc' => ['type' => ['string', 'null']],
					'metakey' => ['type' => ['string', 'null']],
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
		Input::rejectUnknown($input, ['id']);
		$id = Input::integer($input, 'id', 0, 1, 2_147_483_647);
		$model = $this->models->administrator('com_content', 'Article');

		if (!method_exists($model, 'getItem'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Article model is incompatible.');
		}

		$item = $model->getItem($id);

		if (!is_object($item) || (int) ($item->id ?? 0) !== $id)
		{
			throw new ActionException('NOT_FOUND', sprintf('Article %d was not found.', $id));
		}

		$result = [];

		foreach (self::FIELDS as $field)
		{
			$value = $item->{$field} ?? null;
			$result[$field] = is_int($value) || is_string($value) ? $value : null;
		}

		return $result;
	}
}
