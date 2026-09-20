<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
};
$process = new PhpProcess(PHP_BINARY, __DIR__ . '/fixtures/process-child.php', __DIR__);
$previous = [getenv('JCB_GET_ITEMS'), getenv('JOOMENGINE_MCP_TOKEN')];
putenv('JCB_GET_ITEMS=not-inherited');
putenv('JOOMENGINE_MCP_TOKEN=not-inherited');
try
{
	$argument = 'literal ; $(not-a-command) " ' . str_repeat('x', 15000);
	$result = $process->run(['mode' => 'echo', 'argument' => $argument], 5, 20000, static fn (): bool => false);
	$check($result['input']['argument'] === $argument, 'Worker input travels as exact JSON bytes, not shell syntax.');
	$check($result['jcbEnv'] === false && $result['tokenEnv'] === false, 'Worker cannot inherit ambient JCB selectors or client credentials.');
	foreach (['timeout' => 'WORKER_TIMEOUT', 'oversize' => 'WORKER_OUTPUT_LIMIT', 'failure' => 'WORKER_FAILED', 'cancel' => 'WORKER_CANCELLED'] as $mode => $expected)
	{
		$start = microtime(true);
		try
		{
			$process->run(['mode' => $mode], 1, 1024, static fn (): bool => $mode === 'cancel');
		}
		catch (OperationException $error)
		{
			$check($error->getIdentifier() === $expected || ($mode === 'oversize' && $error->getIdentifier() === 'WORKER_FAILED'), 'Expected worker boundary: ' . $expected);
			$check(!str_contains($error->getMessage(), 'private-test-secret'), 'Worker errors never forward private native diagnostics.');
			$check(microtime(true) - $start < 4, 'Stopped workers are bounded and terminated.');
			continue;
		}
		throw new RuntimeException('Expected a failed bounded worker exchange.');
	}
}
finally
{
	foreach (['JCB_GET_ITEMS', 'JOOMENGINE_MCP_TOKEN'] as $index => $name)
	{
		putenv($previous[$index] === false ? $name : $name . '=' . $previous[$index]);
	}
}
echo Json::encode(['checks' => $checks, 'isolatedPhpProcess' => 'passed']) . PHP_EOL;
