<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/admin/autoload.php';
$process = new PhpProcess(PHP_BINARY, __DIR__ . '/fixtures/catalogue-refresh-child.php', __DIR__);
$result = $process->run([], 15, 16384, static fn (): bool => false);
if (($result['checks'] ?? 0) < 22 || ($result['routes'] ?? null) !== 320 || ($result['fieldsPerContract'] ?? null) !== 60
	|| ($result['memoryLimit'] ?? '') !== '128M' || ($result['peakBytes'] ?? PHP_INT_MAX) >= 134217728)
{
	throw new RuntimeException('Full native catalogue refresh must stay fresh, fail closed and fit the fixed128MiB process.');
}
echo Json::encode(['checks' => $result['checks'] + 1, 'catalogueRefresh' => 'passed', 'routes' => $result['routes'],
	'fieldsPerContract' => $result['fieldsPerContract'], 'firstSnapshotBytes' => $result['firstSnapshotBytes'],
	'peakBytes' => $result['peakBytes'], 'memoryLimit' => $result['memoryLimit']]) . PHP_EOL;
