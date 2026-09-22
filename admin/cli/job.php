<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Session\SessionInterface;

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit(1);
}

ini_set('display_errors', 'stderr');
$input = stream_get_contents(STDIN, 8193);

try
{
	$request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);

	if (strlen($input) > 8192 || !is_array($request) || ($request['protocol'] ?? '') !== 'joomengine-worker/1')
	{
		throw new RuntimeException('Invalid worker envelope.');
	}

	$root = dirname(__DIR__, 4);
	$available = function_exists('pcntl_fork') && function_exists('posix_setsid') && function_exists('proc_open')
		&& is_file($root . '/configuration.php') && is_file($root . '/includes/framework.php');

	if (($request['operation'] ?? '') === 'job.probe')
	{
		fwrite(STDOUT, json_encode(['protocol' => 'joomengine-worker/1', 'available' => $available], JSON_THROW_ON_ERROR));
		exit(0);
	}

	if (!in_array($request['operation'] ?? '', ['job.launch', 'job.run'], true) || !$available
		|| !is_string($request['jobId'] ?? null) || preg_match('/\A[0-9a-f-]{36}\z/D', $request['jobId']) !== 1
		|| !is_string($request['ticket'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $request['ticket']) !== 1)
	{
		throw new RuntimeException('The worker launch is unavailable.');
	}

	if ($request['operation'] === 'job.launch')
	{
		$pid = pcntl_fork();

		if ($pid === -1)
		{
			throw new RuntimeException('The background process could not be created.');
		}

		if ($pid > 0)
		{
			fwrite(STDOUT, json_encode(['protocol' => 'joomengine-worker/1', 'started' => true], JSON_THROW_ON_ERROR));
			exit(0);
		}

		if (posix_setsid() === -1)
		{
			exit(1);
		}

		// Disconnect inherited IPC before any Joomla/database initialization.
		// A fresh fixed PHP process receives valid STDIO constants; merely closing
		// STDOUT in this fork would break native Symfony/Joomla console services.
		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
		$nullInput = fopen('/dev/null', 'rb');
		$nullOutput = fopen('/dev/null', 'ab');
		$nullError = fopen('/dev/null', 'ab');
		$argv = [PHP_BINARY];
		$ini = php_ini_loaded_file();

		if (is_string($ini))
		{
			$argv = array_merge($argv, ['-c', $ini]);
		}

		$argv = array_merge($argv, ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'display_errors=stderr', __FILE__]);
		$pipes = [];
		$child = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
			$pipes, $root, null, ['bypass_shell' => true]);

		if (!is_resource($child))
		{
			exit(1);
		}

		$request['operation'] = 'job.run';
		$input = json_encode($request, JSON_THROW_ON_ERROR);
		$offset = 0;

		while ($offset < strlen($input))
		{
			$written = fwrite($pipes[0], substr($input, $offset));

			if ($written === false || $written === 0)
			{
				proc_terminate($child);
				exit(1);
			}

			$offset += $written;
		}

		fclose($pipes[0]);
		exit(proc_close($child) === 0 ? 0 : 1);
	}

	ob_start(static fn (string $buffer): string => '');
	// Preserve the host's native installation permissions. Private MCP storage
	// applies explicit directory/file modes at its own filesystem boundary.
	set_time_limit(0);
	define('_JEXEC', 1);
	define('JPATH_BASE', $root);
	require JPATH_BASE . '/includes/defines.php';
	require JPATH_BASE . '/includes/framework.php';
	$container = Factory::getContainer();
	$container->alias('session', 'session.cli')->alias(Session::class, 'session.cli')
		->alias(\Joomla\Session\Session::class, 'session.cli')->alias(SessionInterface::class, 'session.cli');
	$application = $container->get(ConsoleApplication::class);
	Factory::$application = $application;
	$application->createExtensionNamespaceMap();
	$live = \Joomla\CMS\Uri\Uri::getInstance($application->get('live_site') ?: 'https://joomla.invalid/set/by/console/application');
	$_SERVER['HTTP_HOST'] = $live->toString(['host', 'port']);
	$_SERVER['REQUEST_URI'] = $live->getPath() ?: '/';
	$_SERVER['HTTPS'] = $live->getScheme() === 'https' ? 'on' : 'off';
	$component = $application->bootComponent('com_joomengine_mcp');
	$component->runJobWorker($application, $request['jobId'], $request['ticket']);
	exit(0);
}
catch (Throwable)
{
	// No exception strings or credentials are emitted from the detached worker.
	// A claimed job retains its lease for reconciliation if bootstrap is lost.
	if (is_resource(STDERR))
	{
		fwrite(STDERR, "The approved job worker did not complete its bootstrap.\n");
	}

	exit(1);
}
