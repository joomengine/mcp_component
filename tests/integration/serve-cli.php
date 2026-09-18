<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Plugin\PluginHelper;

require __DIR__ . '/bootstrap.php';

// Boot the actual installed console registry. The component, not a test double,
// supplies the MCP server and its trusted-local authority and native handlers.
foreach (['behaviour', 'system', 'console'] as $group)
{
	PluginHelper::importPlugin($group, null, true, $app->getDispatcher());
}

$app->getDispatcher()->dispatch(ApplicationEvents::BEFORE_EXECUTE, new ApplicationEvent(ApplicationEvents::BEFORE_EXECUTE, $app));
exit($app->bootComponent('com_joomengine_mcp')->getConsoleRuntime($app)->serveStdio());
