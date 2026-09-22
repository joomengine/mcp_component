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


use JsonException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;


/**
 * List installed extensions with bounded, non-secret manifest metadata.
 *
 * @since  0.1.0
 */
final class ListExtensionsAction implements ActionInterface
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
	 * The stable identifier exposed for this action.
	 *
	 * @var   string
	 *
	 * @since  0.1.0
	 */
	private string $actionName;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   ModelProviderInterface  $models      The provider of native administrator models.
	 * @param   string                  $actionName  The stable identifier exposed for this action.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		ModelProviderInterface $models,
		string $actionName = 'extensions.list',
	)
	{
		$this->models = $models;
		$this->actionName = $actionName;
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
			$this->actionName,
			'List installed extension metadata through the Joomla Installer Manage model.',
			'read',
			[['action' => 'core.manage', 'asset' => 'com_installer']],
			[
				'type' => 'object',
				'properties' => [
					'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1_000_000],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
					'search' => ['type' => 'string', 'maxLength' => 200],
					'type' => ['type' => 'string', 'enum' => ['', 'component', 'module', 'plugin', 'template', 'library', 'file', 'package', 'language']],
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
		Input::rejectUnknown($input, ['offset', 'limit', 'search', 'type']);
		$offset = Input::integer($input, 'offset', 0, 0, 1_000_000);
		$limit = Input::integer($input, 'limit', 20, 1, 100);
		$search = Input::text($input, 'search');
		$type = (string) Input::choice(
			$input,
			'type',
			'',
			['', 'component', 'module', 'plugin', 'template', 'library', 'file', 'package', 'language'],
		);
		$model = $this->models->administrator('com_installer', 'Manage');

		if (!method_exists($model, 'setState') || !method_exists($model, 'getItems'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Installer Manage model is incompatible.');
		}

		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);
		$model->setState('filter.search', $search);
		$model->setState('filter.type', $type);
		$rawItems = $model->getItems();

		if (!is_array($rawItems))
		{
			throw new ActionException('MODEL_RESULT_INVALID', 'The Joomla Installer Manage model returned an invalid result.');
		}

		$items = [];

		foreach ($rawItems as $item)
		{
			if (!is_object($item))
			{
				continue;
			}

			$manifest = $this->manifest((string) ($item->manifest_cache ?? ''));
			$items[] = [
				'extensionId' => (int) ($item->extension_id ?? 0),
				'name' => (string) ($item->name ?? ''),
				'type' => (string) ($item->type ?? ''),
				'element' => (string) ($item->element ?? ''),
				'folder' => (string) ($item->folder ?? ''),
				'clientId' => (int) ($item->client_id ?? 0),
				'enabled' => (bool) ($item->enabled ?? false),
				'protected' => (bool) ($item->protected ?? false),
				'version' => isset($manifest['version']) && is_scalar($manifest['version'])
					? (string) $manifest['version']
					: null,
			];
		}

		$total = method_exists($model, 'getTotal') ? (int) $model->getTotal() : count($items);

		return [
			'items' => $items,
			'page' => ['offset' => $offset, 'limit' => $limit, 'count' => count($items), 'total' => $total],
		];
	}

	/**
	 * Decode only non-secret fields from extension manifest metadata.
	 *
	 * @param   string  $json  The json value.
	 *  @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	private function manifest(string $json): array
	{
		if ($json === '')
		{
			return [];
		}

		try
		{
			$value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

			return is_array($value) ? $value : [];
		}
		catch (JsonException)
		{
			return [];
		}
	}
}
