<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__) . '/admin/autoload.php';
$check = static function (bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};

/** Protocol-aware independent pipe client: no SDK client framing or session rotation. */
final class EnduranceClient
{
	/** @var resource Child PHP process. */
	private $process;
	/** @var array<int,resource> Independently drained stdin, stdout and stderr. */
	private array $pipes = [];
	/** @var string Incomplete response bytes only. */
	private string $buffer = '';
	/** @var string Process diagnostics, never consumed as a protocol response. */
	public string $diagnostics = '';
	/** @var int Fresh session request counter. */
	private int $counter = 0;
	/** @var float Configured per-response deadline in seconds. */
	private float $deadline = 10.0;

	/** Start exactly one persistent PHP process under the reported 128 MiB limit. */
	public function __construct(string $mode = '')
	{
		$this->process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/Support/StdioServer.php', $mode],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);

		if (!is_resource($this->process))
		{
			throw new RuntimeException('The stdio endurance server could not start.');
		}

		stream_set_blocking($this->pipes[1], false);
		stream_set_blocking($this->pipes[2], false);
		$this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [],
			'clientInfo' => ['name' => 'independent-endurance-client', 'version' => '1']]);
		$this->write('{"jsonrpc":"2.0","method":"notifications/initialized"}' . "\n");
	}

	/** @param string $bytes One frame or deliberate frame fragment. @return void */
	public function write(string $bytes): void
	{
		for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written)
		{
			$written = fwrite($this->pipes[0], substr($bytes, $offset, 8192));

			if ($written === false || $written === 0)
			{
				throw new RuntimeException('The stdio endurance input closed. ' . $this->diagnostics);
			}
		}
	}

	/** @param string $method MCP method. @param array $params Parameters. @return array Decoded protocol response. */
	public function rpc(string $method, array $params = []): array
	{
		$id = ++$this->counter;
		$this->write(Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]) . "\n");
		$response = $this->response();

		if (($response['id'] ?? null) !== $id)
		{
			throw new RuntimeException('The persistent stdio response ID did not match its request.');
		}

		return $response;
	}

	/** @return array Complete UTF-8 JSON-RPC response within the configured deadline. */
	public function response(): array
	{
		$end = microtime(true) + $this->deadline;

		while (($newline = strpos($this->buffer, "\n")) === false)
		{
			$remaining = $end - microtime(true);

			if ($remaining <= 0)
			{
				throw new RuntimeException('Stdio response exceeded the configured 10-second deadline. ' . $this->diagnostics);
			}

			$read = [$this->pipes[1], $this->pipes[2]];
			$write = $except = [];
			stream_select($read, $write, $except, 0, (int) min(500000, $remaining * 1000000));

			foreach ($read as $stream)
			{
				$bytes = stream_get_contents($stream);

				if ($stream === $this->pipes[1])
				{
					$this->buffer .= $bytes;
				}
				else
				{
					$this->diagnostics .= $bytes;
				}
			}

			if (feof($this->pipes[1]) && strpos($this->buffer, "\n") === false)
			{
				throw new RuntimeException('The persistent stdio process exited without a complete response. ' . $this->diagnostics);
			}
		}

		$line = substr($this->buffer, 0, $newline);
		$this->buffer = substr($this->buffer, $newline + 1);
		$response = json_decode($line, true, 64, JSON_THROW_ON_ERROR);

		if (!is_array($response) || ($response['jsonrpc'] ?? '') !== '2.0')
		{
			throw new RuntimeException('Non-protocol bytes contaminated the stdio output.');
		}

		return $response;
	}

	/** @return bool Whether an incomplete input frame incorrectly produced stdout. */
	public function prematureResponse(): bool
	{
		$read = [$this->pipes[1]];
		$write = $except = [];

		return stream_select($read, $write, $except, 0, 50000) > 0;
	}

	/** @return int Child exit status; drain both outputs to completion. */
	public function close(): int
	{
		fclose($this->pipes[0]);
		stream_set_blocking($this->pipes[1], true);
		$this->buffer .= stream_get_contents($this->pipes[1]);
		stream_set_blocking($this->pipes[2], true);
		$this->diagnostics .= stream_get_contents($this->pipes[2]);
		fclose($this->pipes[1]);
		fclose($this->pipes[2]);

		if ($this->buffer !== '')
		{
			throw new RuntimeException('Unexpected stdout remained at stdio shutdown.');
		}

		return proc_close($this->process);
	}
}

$idleStart = microtime(true);
$idle = new EnduranceClient('idle');
usleep(500000);
$check(!$idle->prematureResponse(), 'An idle connection generated an unsolicited protocol response.');
$check(isset($idle->rpc('ping')['result']), 'The idle input wait failed to wake for a new request.');
$check($idle->close() === 0, 'The idle session did not close cleanly.');
$idleElapsed = microtime(true) - $idleStart;
$idleMetrics = json_decode(trim($idle->diagnostics), true, 64, JSON_THROW_ON_ERROR);
$maximumIdlePolls = (int) ceil($idleElapsed / 0.05) + 8;
$check($idleMetrics['idleSessionReads'] <= $maximumIdlePolls && $idleMetrics['idleSessionWrites'] <= $maximumIdlePolls,
	'Idle stdio generated excessive SDK session database reads or writes.');

$discovery = [];
foreach (['joomla_capabilities', 'joomla_companion_capabilities', 'joomla://catalog/core'] as $entry)
{
	$client = new EnduranceClient();
	$start = microtime(true);
	$response = $entry === 'joomla://catalog/core'
		? $client->rpc('resources/read', ['uri' => $entry])
		: $client->rpc('tools/call', ['name' => $entry, 'arguments' => (object) []]);
	$elapsed = microtime(true) - $start;
	$check(isset($response['result']) && empty($response['result']['isError']), 'Fresh discovery did not return consumable data: ' . $entry);
	$discovery[$entry] = ['elapsedSeconds' => round($elapsed, 4), 'responseBytes' => strlen(Json::encode($response))];
	$check($client->close() === 0, 'Fresh discovery process did not close cleanly.');
}

$client = new EnduranceClient();
$client->write('{"jsonrpc":"2.0","id":"fragmented",');
$check(!$client->prematureResponse(), 'The server parsed an input fragment before its newline.');
$client->write('"method":"ping"}' . "\n");
$check(($client->response()['id'] ?? '') === 'fragmented', 'Fragmented input failed to reconstruct a complete frame.');
$client->write(str_repeat(' ', 1048577) . "\n");
$check(($client->response()['error']['code'] ?? 0) === -32600, 'An oversized frame did not produce a bounded protocol error.');
$check(isset($client->rpc('ping')['result']), 'Oversized input corrupted the following frame.');
$start = microtime(true);
for ($request = 1; $request <= 600; $request++)
{
	if ($request % 75 === 0)
	{
		$response = $client->rpc('resources/read', ['uri' => 'joomla://catalog/core']);
	}
	elseif ($request % 50 === 0)
	{
		$response = $client->rpc('tools/call', ['name' => $request % 100 === 0 ? 'joomla_capabilities' : 'joomla_companion_capabilities', 'arguments' => (object) []]);
	}
	else
	{
		$response = match ($request % 4)
		{
			0 => $client->rpc('ping'),
			1 => $client->rpc('tools/call', ['name' => 'fixture_wide_read', 'arguments' => (object) []]),
			2 => $client->rpc('tools/call', ['name' => 'joomla_companion_action_read', 'arguments' => ['action' => 'system.info']]),
			3 => $client->rpc('tools/call', ['name' => 'joomla_companion_action_read', 'arguments' => ['action' => 'contacts.contacts.list', 'input' => ['limit' => null, 'offset' => 0]]]),
		};
	}

	$check(isset($response['result']), 'The endurance workload lost a response at request ' . $request);

	if ($request % 4 !== 3 || $request % 50 === 0 || $request % 75 === 0)
	{
		$check(empty($response['result']['isError']), 'A valid bounded endurance request failed at request ' . $request);
	}
}
$elapsed = microtime(true) - $start;
$check($client->close() === 0, 'The unrotated 600-request process did not close cleanly.');
$samples = [];
foreach (explode("\n", trim($client->diagnostics)) as $line)
{
	$sample = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
	if (isset($sample['sample']))
	{
		$samples[] = $sample;
	}
}
$check(count($samples) >= 8, 'The endurance workload did not record sustained memory samples.');
$growth = $samples[count($samples) - 1]['used'] - $samples[1]['used'];
$check($growth < 8388608, 'Persistent validation retained more than 8 MiB after warmup.');

// Verify canonicalization alone does not create self-referential closure cycles.
gc_collect_cycles();
gc_disable();
$before = memory_get_usage();
for ($call = 0; $call < 400; $call++)
{
	Json::canonical(['z' => (object) ['b' => 2, 'a' => []], 'a' => [1, 2]]);
}
$canonicalGrowth = memory_get_usage() - $before;
gc_enable();
$check($canonicalGrowth < 4096, 'Canonicalization retained recursive closures with cyclic GC disabled.');

foreach (['fatal', 'oom'] as $mode)
{
	$process = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'display_errors=1', '-d', 'error_log=/dev/stdout', __DIR__ . '/Support/StdioServer.php', $mode],
		[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$check(proc_close($process) !== 0, 'Forced ' . $mode . ' unexpectedly reported success.');
	$lines = explode("\n", trim($stdout));
	$check(count($lines) === 1 && json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR)['jsonrpc'] === '2.0', 'Forced ' . $mode . ' contaminated stdout.');
	$check(str_contains($stderr, 'Fatal error'), 'Forced ' . $mode . ' did not preserve stderr diagnostics.');
}
echo Json::encode(['discovery' => $discovery, 'catalogue' => '285 installed-core seed actions plus a configurable wide-schema test tool',
	'unrotatedRequests' => 600, 'memoryLimit' => '128M', 'elapsedSeconds' => round($elapsed, 3),
	'memorySamples' => $samples, 'postWarmupGrowthBytes' => $growth, 'canonicalGrowthWithoutGcBytes' => $canonicalGrowth,
	'idleSessionReads' => $idleMetrics['idleSessionReads'], 'idleSessionWrites' => $idleMetrics['idleSessionWrites'], 'idleMeasuredSeconds' => round($idleElapsed, 3),
	'fatalStdout' => 'valid protocol only', 'liveJoomla' => 'not run; native model execution uses a test double']) . PHP_EOL;
