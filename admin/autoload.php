<?php
/**
 * @package    JoomEngine.Mcp
 * @created    28 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Load the committed runtime before and after Joomla relocates the admin folder.
 *
 * Composer owns third-party dependencies; Joomla owns extension namespaces. The
 * administrator mapping is also needed during installation and standalone tests,
 * before Joomla has refreshed its extension namespace cache.
 *
 * @var \Composer\Autoload\ClassLoader $loader
 */
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->addPsr4('VDM\\Component\\JoomEngineMcp\\Administrator\\', __DIR__ . '/src');

return $loader;
