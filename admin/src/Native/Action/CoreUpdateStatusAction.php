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
 * Read Joomla core update availability without applying an update.
 *
 * @since  0.1.0
 */
final class CoreUpdateStatusAction implements ActionInterface
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
			'core.update.status',
			'Return cached Joomla core update status without downloading or installing an update.',
			'read',
			[['action' => 'core.manage', 'asset' => 'com_joomlaupdate']],
			['type' => 'object', 'properties' => [], 'additionalProperties' => false],
			[
				'type' => 'object',
				'required' => ['installed', 'latest', 'hasUpdate'],
				'properties' => [
					'installed' => ['type' => 'string'],
					'latest' => ['type' => ['string', 'null']],
					'hasUpdate' => ['type' => 'boolean'],
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
		Input::rejectUnknown($input, []);
		$model = $this->models->administrator('com_joomlaupdate', 'Update');

		if (!method_exists($model, 'getUpdateInformation'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Update model is incompatible.');
		}

		try
		{
			$information = $model->getUpdateInformation();
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', 'Joomla could not read core update status.');
		}

		if (!is_array($information))
		{
			throw new ActionException('MODEL_RESULT_INVALID', 'The Joomla Update model returned invalid status.');
		}

		return [
			'installed' => is_string($information['installed'] ?? null)
				? $information['installed']
				: (defined('JVERSION') ? (string) JVERSION : 'unknown'),
			'latest' => is_string($information['latest'] ?? null) ? $information['latest'] : null,
			'hasUpdate' => ($information['hasUpdate'] ?? false) === true,
		];
	}
}
