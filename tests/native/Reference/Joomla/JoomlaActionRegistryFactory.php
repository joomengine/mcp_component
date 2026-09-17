<?php

declare(strict_types=1);

namespace VDM\Component\JoomEngineMcp\Administrator\Native\Joomla;

use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreUpdateStatusAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CleanCacheAction;
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
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionRegistry;

final readonly class JoomlaActionRegistryFactory
{
    public function __construct(private object $application)
    {
    }

    public function create(): ActionRegistry
    {
        $models = new JoomlaModelProvider($this->application);
        $native = new JoomlaNativeOperations($this->application);

        $actions = [
            new SystemInfoAction(),
            new SafeConfigurationAction($this->application),
            new SafeConfigurationAction($this->application, 'configuration.application.get'),
            new ListExtensionsAction($models),
            new ListExtensionsAction($models, 'extensions.installed.list'),
            new FixedModelListAction(
                'cache.groups.list',
                'List bounded Joomla cache-group metadata.',
                'com_cache',
                'Cache',
                ['group', 'count', 'size', 'client_id'],
                $models,
                'getData',
            ),
            new CleanCacheAction($models),
            new PurgeExpiredCacheAction($models),
            new FixedModelListAction(
                'extensions.updates.list',
                'List cached Joomla extension updates without refreshing or installing them.',
                'com_installer',
                'Update',
                ['update_id', 'update_site_id', 'extension_id', 'name', 'description', 'element', 'type', 'folder', 'client_id', 'version', 'infourl'],
                $models,
            ),
            new FixedModelListAction(
                'extensions.discovered.list',
                'List already-discovered Joomla extensions without scanning or installing them.',
                'com_installer',
                'Discover',
                ['extension_id', 'name', 'type', 'element', 'folder', 'client_id'],
                $models,
            ),
            new RefreshExtensionDiscoveryAction($models),
            new RefreshExtensionUpdatesAction($models),
            // Joomla Installer UpdatesitesModel is the same native model used
            // by com_installer; secret-bearing extra_query/location are omitted.
            new FixedModelListAction(
                'extensions.update-sites.list',
                'List bounded Joomla extension update-site state.',
                'com_installer',
                'Updatesites',
                [
                    'update_site_id', 'update_site_name', 'update_site_type', 'enabled',
                    'extension_id', 'name', 'type', 'element', 'folder', 'client_id',
                ],
                $models,
            ),
            // Fixed adapters over native ManageModel::publish() and
            // UpdatesitesModel::publish(); neither accepts caller model names.
            new FixedModelStateAction(
                'extensions.state.set',
                'Enable or disable one installed extension through Joomla ManageModel::publish().',
                'com_installer',
                'Manage',
                'extension_id',
                ['extension_id', 'name', 'type', 'element', 'folder', 'client_id', 'enabled', 'protected'],
                $models,
            ),
            new FixedModelStateAction(
                'extensions.update-sites.state.set',
                'Enable or disable one Joomla update site through UpdatesitesModel::publish().',
                'com_installer',
                'Updatesites',
                'update_site_id',
                ['update_site_id', 'update_site_name', 'update_site_type', 'extension_id', 'name', 'enabled'],
                $models,
            ),
            new FixedModelListAction(
                'scheduler.tasks.list',
                'List bounded Joomla scheduled-task status without running tasks.',
                'com_scheduler',
                'Tasks',
                [
                    'id', 'title', 'type', 'state', 'last_exit_code', 'locked', 'last_execution',
                    'next_execution', 'times_executed', 'times_failed', 'priority', 'ordering', 'note',
                ],
                $models,
            ),
            new SchedulerTaskAction('state', $models, $native),
            new SchedulerTaskAction('run', $models, $native),
            new SiteStateAction($native),
            new SiteStateAction($native, true),
            new SessionGarbageCollectionAction($native),
            new SessionGarbageCollectionAction($native, true),
            new CoreUpdateStatusAction($models),
        ];

        foreach (CoreEntityCatalogue::all() as $entity) {
            foreach (['list', 'get', 'create', 'update', 'delete'] as $operation) {
                $actions[] = new CoreEntityAction($entity, $operation, $models);
            }

            if ($entity->supportsState) {
                $actions[] = new CoreEntityAction($entity, 'state', $models);
            }
        }

        return new ActionRegistry($actions);
    }
}
