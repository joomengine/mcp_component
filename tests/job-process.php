<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Job\ProcessLauncher;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/vendor/autoload.php';
$base = sys_get_temp_dir() . '/mcp-job-process-' . bin2hex(random_bytes(8));
$directory = $base . '/administrator/components/com_joomengine_mcp/cli';
mkdir($directory, 0700, true);
mkdir($base . '/includes', 0700);
copy(dirname(__DIR__) . '/admin/cli/job.php', $directory . '/job.php');
copy(__DIR__ . '/fixtures/job-framework.php', $base . '/includes/framework.php');
file_put_contents($base . '/includes/defines.php', '<?php');
file_put_contents($base . '/configuration.php', '<?php');

try
{
	$launcher = new ProcessLauncher(PHP_BINARY, $directory . '/job.php', $base);
	$launcher->ready();
	$id = Json::uuid();
	$ticket = bin2hex(random_bytes(32));
	$launcher($id, $ticket);
	$deadline = hrtime(true) + 5000000000;

	while (!is_file($base . '/completed.json') && hrtime(true) < $deadline)
	{
		usleep(10000);
		clearstatcache(true, $base . '/completed.json');
	}

	if (!is_file($base . '/completed.json'))
	{
		throw new RuntimeException('The detached worker never reached its reviewed bootstrap hook.');
	}

	$result = Json::decode(file_get_contents($base . '/completed.json'));

	if ($result !== ['jobId' => $id, 'ticketHash' => hash('sha256', $ticket), 'sapi' => 'cli', 'stdin' => true, 'stdout' => true, 'stderr' => true])
	{
		throw new RuntimeException('Detached worker identity, input or process stream isolation changed.');
	}

	echo Json::encode(['checks' => 3, 'detachedProcess' => 'passed with actual PHP fork and fixed-script process dispatch', 'joomlaBootstrap' => 'minimal bootstrap double; installed Joomla tested separately']) . PHP_EOL;
}
finally
{
	$remove = static function (string $path) use (&$remove): void
	{
		if (is_dir($path) && !is_link($path))
		{
			foreach (scandir($path) as $name)
			{
				if ($name !== '.' && $name !== '..')
				{
					$remove($path . '/' . $name);
				}
			}

			rmdir($path);
		}
		else
		{
			unlink($path);
		}
	};
	$remove($base);
}
