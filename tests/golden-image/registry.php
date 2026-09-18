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
use VDM\Component\JoomEngineMcp\Administrator\Console\Inspector;

require dirname(__DIR__) . '/integration/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');

// Joomla's own list command has no --format option. Use the same registration
// lifecycle as ConsoleApplication, then inspect actual native InputDefinitions.
foreach (['behaviour', 'system', 'console'] as $group)
{
	PluginHelper::importPlugin($group, null, true, $app->getDispatcher());
}

$app->getDispatcher()->dispatch(ApplicationEvents::BEFORE_EXECUTE, new ApplicationEvent(ApplicationEvents::BEFORE_EXECUTE, $app));
$inventory = (new Inspector($app))->inventory();
$jcb = array_values(array_filter($inventory['commands'], static fn (array $command): bool => str_starts_with($command['name'], 'componentbuilder:')));

if (count($jcb) < 2)
{
	throw new RuntimeException('The installed JCB console plugin did not register its native commands.');
}

$inventory['commands'] = $jcb;
$inventory['commandCount'] = count($jcb);
echo json_encode($inventory, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
