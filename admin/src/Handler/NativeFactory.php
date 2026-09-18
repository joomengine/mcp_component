<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Handler;


use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CleanCacheAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreUpdateStatusAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\FixedModelListAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\FixedModelStateAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\ListExtensionsAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\PurgeExpiredCacheAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\RefreshExtensionDiscoveryAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\RefreshExtensionUpdatesAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\SafeConfigurationAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\SchedulerTaskAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\SessionGarbageCollectionAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\SiteStateAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\SystemInfoAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\NativeOperationsInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\CoreEntityDefinition;


/**
 * Compose reviewed native primitives from administrator-validated database data.
 *
 * The registry contains primitive service keys, not an action catalogue. Components,
 * model fields and names arrive only from trusted binding records, never arguments.
 *
 * @since 0.1.0
 */
final class NativeFactory
{
	/** @var ModelProviderInterface Joomla-native administrator model factory. @since 0.1.0 */
	private ModelProviderInterface $models;

	/** @var NativeOperationsInterface Fixed stock-console integrations. @since 0.1.0 */
	private NativeOperationsInterface $operations;

	/** @var object Actual application, also used by the safe configuration primitive. @since 0.1.0 */
	private object $application;

	/** @param ModelProviderInterface $models Native models. @param NativeOperationsInterface $operations Native commands. @param object $application Joomla application. @since 0.1.0 */
	public function __construct(ModelProviderInterface $models, NativeOperationsInterface $operations, object $application)
	{
		$this->models = $models;
		$this->operations = $operations;
		$this->application = $application;
	}

	/** @return string[] Reviewed reusable native handler identifiers. @since 0.1.0 */
	public static function keys(): array
	{
		return [
			'native.core-entity', 'native.clean-cache', 'native.purge-expired-cache', 'native.fixed-model-list',
			'native.safe-configuration', 'native.core-update-status', 'native.refresh-extension-discovery',
			'native.list-extensions', 'native.fixed-model-state', 'native.refresh-extension-updates',
			'native.scheduler-task', 'native.session-garbage-collection', 'native.site-state', 'native.system-info',
		];
	}

	/**
	 * Create exactly one known primitive without reflection or row-supplied classes.
	 *
	 * @param array<string,mixed> $binding Current authorized binding.
	 * @return ActionInterface Native semantic operation.
	 * @since 0.1.0
	 */
	public function create(array $binding): ActionInterface
	{
		$key = $binding['handler'] ?? '';
		$config = $binding['configuration'] ?? [];
		$this->validate($key, $config);

		try
		{
			return match ($key)
			{
				'native.core-entity' => new CoreEntityAction(new CoreEntityDefinition(...$config['entity']), $config['operation'], $this->models),
				'native.clean-cache' => new CleanCacheAction($this->models),
				'native.purge-expired-cache' => new PurgeExpiredCacheAction($this->models),
				'native.fixed-model-list' => new FixedModelListAction($config['name'], $config['description'], $config['component'], $config['modelName'], $config['fields'], $this->models, $config['getter'] ?? 'getItems'),
				'native.safe-configuration' => new SafeConfigurationAction($this->application, $config['actionName'] ?? 'configuration.application.get'),
				'native.core-update-status' => new CoreUpdateStatusAction($this->models),
				'native.refresh-extension-discovery' => new RefreshExtensionDiscoveryAction($this->models),
				'native.list-extensions' => new ListExtensionsAction($this->models, $config['actionName'] ?? 'extensions.installed.list'),
				'native.fixed-model-state' => new FixedModelStateAction($config['name'], $config['description'], $config['component'], $config['modelName'], $config['idField'], $config['readFields'], $this->models),
				'native.refresh-extension-updates' => new RefreshExtensionUpdatesAction($this->models),
				'native.scheduler-task' => new SchedulerTaskAction($config['operation'], $this->models, $this->operations),
				'native.session-garbage-collection' => new SessionGarbageCollectionAction($this->operations, $config['metadata'] ?? false),
				'native.site-state' => new SiteStateAction($this->operations, $config['write'] ?? false),
				'native.system-info' => new SystemInfoAction(),
			};
		}
		catch (Throwable)
		{
			throw new OperationException('BINDING_INVALID', 'The native binding configuration does not satisfy its handler contract.');
		}
	}

	/**
	 * Reject executable configuration and restrict model/field identifiers.
	 *
	 * @param string $key Registered primitive key.
	 * @param array<string,mixed> $config Declarative configuration.
	 * @return void
	 * @since 0.1.0
	 */
	private function validate(string $key, array $config): void
	{
		if (!in_array($key, self::keys(), true))
		{
			throw new OperationException('BINDING_INVALID', 'The native handler is not registered.');
		}

		$allowed = match ($key)
		{
			'native.core-entity' => ['entity', 'operation'],
			'native.fixed-model-list' => ['name', 'description', 'component', 'modelName', 'fields', 'getter'],
			'native.fixed-model-state' => ['name', 'description', 'component', 'modelName', 'idField', 'readFields'],
			'native.safe-configuration', 'native.list-extensions' => ['actionName'],
			'native.scheduler-task' => ['operation'],
			'native.session-garbage-collection' => ['metadata'],
			'native.site-state' => ['write'],
			default => [],
		};

		if (array_diff(array_keys($config), $allowed) !== [])
		{
			throw new OperationException('BINDING_INVALID', 'A native binding contains unsupported configuration.');
		}

		$required = match ($key)
		{
			'native.core-entity' => ['entity', 'operation'],
			'native.fixed-model-list' => ['name', 'description', 'component', 'modelName', 'fields'],
			'native.fixed-model-state' => ['name', 'description', 'component', 'modelName', 'idField', 'readFields'],
			'native.scheduler-task' => ['operation'],
			default => [],
		};

		foreach ($required as $field)
		{
			if (!array_key_exists($field, $config))
			{
				throw new OperationException('BINDING_INVALID', 'A required native binding property is missing.');
			}
		}

		$definition = $config['entity'] ?? $config;

		if (!is_array($definition))
		{
			throw new OperationException('BINDING_INVALID', 'The native entity definition must be an object.');
		}

		if ($key === 'native.core-entity' && array_diff(array_keys($definition), [
			'id', 'label', 'component', 'listModel', 'itemModel', 'readFields', 'writeFields', 'defaults',
			'modelState', 'stateFilter', 'supportsState', 'highRisk', 'sensitiveFields', 'primaryKey', 'stateField',
		]) !== [])
		{
			throw new OperationException('BINDING_INVALID', 'The native entity contains unknown configuration.');
		}

		foreach (['component', 'listModel', 'itemModel', 'modelName', 'idField', 'primaryKey', 'stateField'] as $field)
		{
			if (isset($definition[$field]))
			{
				$pattern = $field === 'component' ? '/\Acom_[a-z][a-z0-9_]*\z/D' : '/\A[A-Za-z][A-Za-z0-9_]*\z/D';

				if (!is_string($definition[$field]) || strlen($definition[$field]) > 128 || preg_match($pattern, $definition[$field]) !== 1)
				{
					throw new OperationException('BINDING_INVALID', 'A native component, model or field identifier is invalid.');
				}
			}
		}

		foreach (['readFields', 'writeFields', 'sensitiveFields', 'fields'] as $field)
		{
			if (!isset($definition[$field]))
			{
				continue;
			}

			if (!is_array($definition[$field]) || !array_is_list($definition[$field]) || count($definition[$field]) > 512)
			{
				throw new OperationException('BINDING_INVALID', 'Native fields must be an explicit bounded list.');
			}

			foreach ($definition[$field] as $name)
			{
				if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $name) !== 1)
				{
					throw new OperationException('BINDING_INVALID', 'A native field identifier is invalid.');
				}
			}
		}

		if (isset($config['getter']) && !in_array($config['getter'], ['getItems', 'getData'], true))
		{
			throw new OperationException('BINDING_INVALID', 'The configured native getter is not an approved read primitive.');
		}
	}
}
