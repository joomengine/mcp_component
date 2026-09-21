<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Router\ApiRouter;

require __DIR__ . '/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
$test = require dirname(__DIR__) . '/Support/JcbApiContracts.php';
$checks = $test(new ApiRouter($app));
echo json_encode(['checks' => $checks, 'nativeApiRouter' => true, 'joomla' => JVERSION], JSON_THROW_ON_ERROR) . PHP_EOL;
