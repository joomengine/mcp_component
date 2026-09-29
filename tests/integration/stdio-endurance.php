<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

require __DIR__ . '/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
require __DIR__ . '/StdioFixture.php';
$db = $container->get(DatabaseInterface::class);
$app->loadIdentity($container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin'));
$client = new StdioFixture(timeout: 10, memoryLimitMiB: 128);
$checks = 0;
$completed = 0;
$samples = [];
$discovery = [];
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}

	$checks++;
};

// The SDK exposes no PID API. Inspect its test-owned proc resource only;
// production MCP discovery receives no additional process metadata surface.
$transport = (new ReflectionProperty(Client::class, 'transport'))->getValue($client->sdk());
$process = (new ReflectionProperty(StdioTransport::class, 'process'))->getValue($transport);
$pid = proc_get_status($process)['pid'];
$serverPid = $pid;
$residentMemory = static function (int $processId): ?int
{
	$path = '/proc/' . $processId . '/status';

	if (!is_file($path))
	{
		return null;
	}

	return preg_match('/^VmRSS:\s+([0-9]+)\s+kB$/m', file_get_contents($path), $match) === 1 ? (int) $match[1] * 1024 : null;
};
$childPids = static function (int $parentId): array
{
	$path = '/proc/' . $parentId . '/task/' . $parentId . '/children';

	if (is_file($path))
	{
		$children = trim(file_get_contents($path));

		return $children === '' ? [] : array_map('intval', preg_split('/\s+/', $children));
	}

	// Some Linux proc mounts omit task children. Read only parent PID fields
	// from process metadata to find descendants of our own test process.
	$children = [];

	foreach (glob('/proc/[0-9]*/stat') ?: [] as $statPath)
	{
		$stat = @file_get_contents($statPath);

		if (!is_string($stat) || ($end = strrpos($stat, ')')) === false)
		{
			continue;
		}

		$fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));

		if ((int) ($fields[1] ?? 0) === $parentId)
		{
			$children[] = (int) basename(dirname($statPath));
		}
	}

	return $children;
};

// proc_open(string) may keep a shell above PHP. Follow its child chain to
// sample the actual server process rather than reporting the shell's RSS.
for ($depth = 0; $depth < 4; $depth++)
{
	$children = $childPids($serverPid);

	if (count($children) !== 1)
	{
		break;
	}

	$serverPid = $children[0];
}
$check(!is_file('/proc/' . $serverPid . '/comm') || str_starts_with(trim(file_get_contents('/proc/' . $serverPid . '/comm')), 'php'),
	'Process memory samples must identify the actual PHP server, not its shell wrapper.');

try
{
	foreach (['joomla_capabilities', 'joomla_companion_capabilities'] as $name)
	{
		$start = microtime(true);
		$result = $client->tool($name);
		$discovery[$name] = ['elapsedSeconds' => round(microtime(true) - $start, 4),
			'resultBytes' => strlen(json_encode($result, JSON_THROW_ON_ERROR))];
		$check($result !== [], 'Installed capability discovery returned no data: ' . $name);
	}
	$start = microtime(true);
	$resource = $client->sdk()->readResource('joomla://catalog/core');
	$discovery['joomla://catalog/core'] = ['elapsedSeconds' => round(microtime(true) - $start, 4),
		'responseBytes' => strlen(json_encode($resource, JSON_THROW_ON_ERROR))];
	$check(count($resource->contents) === 1 && is_array(json_decode($resource->contents[0]->text, true, 64, JSON_THROW_ON_ERROR)),
		'Installed core catalogue resource is not consumable JSON.');
	unset($result, $resource);
	$start = microtime(true);

	for ($request = 1; $request <= 600; $request++)
	{
		if ($request % 75 === 0)
		{
			$resource = $client->sdk()->readResource('joomla://catalog/core');
			$check(count($resource->contents) === 1, 'Sustained core catalogue resource lost its content.');
			unset($resource);
		}
		elseif ($request % 50 === 0)
		{
			$result = $client->tool($request % 100 === 0 ? 'joomla_capabilities' : 'joomla_companion_capabilities');
			$check($result !== [], 'Sustained installed capability discovery returned no data.');
			unset($result);
		}
		else
		{
			switch ($request % 5)
			{
				case 0:
					$client->sdk()->ping();
					break;
				case 1:
					$system = $client->tool('joomla_action_read', ['action' => 'system.info']);
					$check(($system['response']['data']['joomlaVersion'] ?? '') === JVERSION,
						'Installed generic system.info returned the wrong native Joomla version.');
					break;
				case 2:
					$system = $client->tool('joomla_companion_action_read', ['action' => 'system.info', 'input' => (object) []]);
					$check(($system['response']['data']['phpVersion'] ?? '') === PHP_VERSION,
						'Installed companion system.info returned the wrong native PHP version.');
					break;
				case 3:
					$list = $client->tool('joomla_companion_action_read', ['action' => 'languages.content.list', 'input' => ['limit' => 2, 'offset' => 0]]);
					$items = $list['response']['data']['items'] ?? null;
					$check(is_array($items) && count($items) <= 2, 'Installed native list exceeded its bounded page or failed.');
					break;
				case 4:
					$rejection = $client->sdk()->callTool('joomla_companion_action_read',
						['action' => 'contacts.contacts.list', 'input' => ['limit' => null, 'offset' => 0]]);
					$check($rejection->isError === true, 'An invalid native list input was unexpectedly accepted.');
					break;
			}
		}

		$completed++;

		if ($request % 100 === 0)
		{
			$status = proc_get_status($process);
			$check($status['running'] && $status['pid'] === $pid && $client->sdk()->isConnected(),
				'The installed endurance session terminated or rotated its process.');
			$check(is_dir('/proc/' . $serverPid) || !is_dir('/proc'), 'The actual installed server process changed during endurance.');
			$samples[] = ['completedRequests' => $completed, 'serverPid' => $serverPid, 'residentBytes' => $residentMemory($serverPid)];
			echo 'PASS Installed MCP stdio completed ' . $completed . ' requests in the original process' . "\n";
		}
	}
	$elapsed = microtime(true) - $start;
	$client->sdk()->ping();
	$check($completed === 600 && $client->sdk()->isConnected(), 'Installed endurance did not complete all 600 requests in one session.');
}
finally
{
	$client->disconnect();
}

echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'php' => PHP_VERSION, 'database' => $db->getServerType(),
	'actualMcpStdio' => true, 'unrotatedRequests' => $completed, 'configuredChildMemoryLimit' => '128M',
	'configuredRequestDeadlineSeconds' => 10, 'elapsedSeconds' => round($elapsed, 3),
	'initialDiscovery' => $discovery, 'processSamples' => $samples,
	'memoryMeasurement' => 'OS RSS, separate from PHP allocated memory reported by tests/stdio-endurance.php',
	'writesAndCrashRecovery' => 'not exercised by this read-only endurance workload'], JSON_THROW_ON_ERROR) . "\n";
