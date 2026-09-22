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
 *  Adapts Joomla CacheModel::purge(), also used by core cache:clean expired.
 *
 * @since  0.1.0
 */
final class PurgeExpiredCacheAction implements ActionInterface
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
			'cache.expired.purge',
			'Garbage-collect expired entries through Joomla CacheModel::purge().',
			'write',
			[
				['action' => 'core.manage', 'asset' => 'com_cache'],
				['action' => 'core.delete', 'asset' => 'com_cache'],
			],
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
				'required' => ['applied', 'dryRun', 'scope', 'verification', 'recovery'],
				'properties' => [
					'applied' => ['type' => 'boolean'],
					'dryRun' => ['type' => 'boolean'],
					'scope' => ['type' => 'string', 'const' => 'expired-only'],
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

		if (Input::boolean($input, 'dryRun', true))
		{
			return [
				'applied' => false,
				'dryRun' => true,
				'scope' => 'expired-only',
				'verification' => ['method' => 'Joomla CacheModel::purge return value', 'completed' => false],
				'recovery' => ['reversible' => false, 'reason' => 'Expired cache entries are disposable derived data.'],
				'requiresEdgeConfirmation' => true,
			];
		}

		$this->confirm($input);
		$model = $this->models->administrator('com_cache', 'Cache');

		if (!method_exists($model, 'purge'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla Cache model cannot purge expired entries.');
		}

		try
		{
			$completed = $model->purge() === true;
		}
		catch (Throwable)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', 'Joomla could not purge expired cache entries.');
		}

		if (!$completed)
		{
			throw new ActionException('MODEL_OPERATION_FAILED', 'Joomla reported that expired cache purging failed.');
		}

		return [
			'applied' => true,
			'dryRun' => false,
			'scope' => 'expired-only',
			'verification' => ['method' => 'Joomla CacheModel::purge return value', 'completed' => true],
			'recovery' => ['reversible' => false, 'reason' => 'Expired cache entries are disposable derived data.'],
		];
	}

	/**
	 * Require explicit confirmation before invoking the native write.
	 *
	 * @param   array<string, mixed>  $input  The input value.
	 * @return  void
	 *
	 * @since  0.1.0
	 */
	private function confirm(array $input): void
	{
		if (!Input::boolean($input, '_edgeConfirmed', false))
		{
			throw new ActionException('CONFIRMATION_REQUIRED', 'Expired-cache purging requires signed MCP edge confirmation.');
		}
	}
}
