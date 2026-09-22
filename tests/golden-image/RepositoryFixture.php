<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Disposable loopback implementation of the Gitea contents protocol.
 * Native JCB HTTP clients perform every package operation against this fixture.
 * No production URL, repository or credential is used.
 */
final class RepositoryFixture
{
	private string $directory;
	private string $url;
	/** @var resource|null */
	private $process = null;

	public function __construct()
	{
		$this->directory = sys_get_temp_dir() . '/mcp-repository-' . bin2hex(random_bytes(12));
		if (!mkdir($this->directory, 0700))
		{
			throw new RuntimeException('Cannot create disposable repository state.');
		}
		file_put_contents($this->directory . '/state.json', json_encode(['files' => [], 'requests' => [], 'rejectWrites' => false], JSON_THROW_ON_ERROR));
		$socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
		if ($socket === false)
		{
			throw new RuntimeException('Cannot reserve a loopback repository port.');
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$this->url = 'http://' . $address;
		$arguments = [PHP_BINARY];
		if (is_string(php_ini_loaded_file()))
		{
			$arguments = array_merge($arguments, ['-c', php_ini_loaded_file()]);
		}
		$arguments = array_merge($arguments, ['-d', 'extension_dir=' . ini_get('extension_dir'), '-S', $address, __FILE__]);
		$this->process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'],
			1 => ['file', $this->directory . '/server.log', 'a'], 2 => ['file', $this->directory . '/server.log', 'a']],
			$pipes, $this->directory, array_merge(getenv(), ['MCP_REPOSITORY_FIXTURE' => $this->directory]));
		if (!is_resource($this->process))
		{
			throw new RuntimeException('Cannot start the loopback repository fixture.');
		}
		$deadline = microtime(true) + 10;
		do
		{
			if (@file_get_contents($this->url . '/health', false, stream_context_create(['http' => ['timeout' => 1]])) === 'ready')
			{
				return;
			}
			usleep(50000);
		}
		while (microtime(true) < $deadline);
		$this->close();
		throw new RuntimeException('The loopback repository fixture did not become ready.');
	}

	public function url(): string
	{
		return $this->url;
	}

	/** Read the actual bytes persisted by the native remote writer. */
	public function read(string $path, string $branch = 'fixture'): string
	{
		$state = $this->state();
		$value = $state['files'][$branch][$path] ?? null;
		if (!is_string($value))
		{
			throw new RuntimeException('Native package output is missing: ' . $path);
		}
		return base64_decode($value, true);
	}

	/** Change remote bytes, as an independent publisher would, before pull/reset. */
	public function write(string $path, string $bytes, string $branch = 'fixture'): void
	{
		$this->update(static function (array &$state) use ($path, $bytes, $branch): void
		{
			$state['files'][$branch][$path] = base64_encode($bytes);
		});
	}

	public function requests(): array
	{
		return $this->state()['requests'];
	}

	/** Digest only persisted repository bytes, independent of request logging. */
	public function fingerprint(): string
	{
		return hash('sha256', json_encode($this->state()['files'], JSON_THROW_ON_ERROR));
	}

	public function rejectWrites(bool $reject): void
	{
		$this->update(static function (array &$state) use ($reject): void
		{
			$state['rejectWrites'] = $reject;
		});
	}

	public function close(): void
	{
		if (is_resource($this->process))
		{
			proc_terminate($this->process);
			proc_close($this->process);
			$this->process = null;
		}
		foreach (glob($this->directory . '/*') ?: [] as $file)
		{
			unlink($file);
		}
		@rmdir($this->directory);
	}

	private function state(): array
	{
		$stream = fopen($this->directory . '/state.json', 'r');
		flock($stream, LOCK_SH);
		$state = json_decode(stream_get_contents($stream), true, 64, JSON_THROW_ON_ERROR);
		flock($stream, LOCK_UN);
		fclose($stream);
		return $state;
	}

	private function update(callable $operation): void
	{
		$stream = fopen($this->directory . '/state.json', 'r+');
		flock($stream, LOCK_EX);
		$state = json_decode(stream_get_contents($stream), true, 64, JSON_THROW_ON_ERROR);
		$operation($state);
		rewind($stream);
		ftruncate($stream, 0);
		fwrite($stream, json_encode($state, JSON_THROW_ON_ERROR));
		fflush($stream);
		flock($stream, LOCK_UN);
		fclose($stream);
	}

	/** Handle only the bounded repository/branch used by disposable acceptance. */
	public static function serve(): void
	{
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		if ($path === '/health')
		{
			echo 'ready';
			return;
		}
		$response = static function (int $status, mixed $body, bool $raw = false): void
		{
			http_response_code($status);
			header('Content-Type: application/json');
			echo $raw ? $body : json_encode($body, JSON_THROW_ON_ERROR);
		};
		if (preg_match('~\A/api/v1/repos/joomla/packages/(raw|contents)/([A-Za-z0-9_./-]+)\z~D', $path, $match) !== 1
			|| str_contains($match[2], '..'))
		{
			$response(404, ['message' => 'Unknown fixture route']);
			return;
		}
		$stream = fopen(getenv('MCP_REPOSITORY_FIXTURE') . '/state.json', 'r+');
		flock($stream, LOCK_EX);
		$state = json_decode(stream_get_contents($stream), true, 64, JSON_THROW_ON_ERROR);
		$method = $_SERVER['REQUEST_METHOD'];
		$body = file_get_contents('php://input', false, null, 0, 4194305);
		$input = $body === '' ? [] : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
		$branch = $method === 'GET' ? ($_GET['ref'] ?? 'fixture') : ($input['branch'] ?? 'fixture');
		$file = $match[2];
		$state['requests'][] = ['method' => $method, 'path' => $file, 'branch' => $branch];
		$encoded = $state['files'][$branch][$file] ?? null;
		$bytes = is_string($encoded) ? base64_decode($encoded, true) : null;
		$metadata = static fn (string $value): array => ['name' => basename($file), 'path' => $file,
			'type' => 'file', 'size' => strlen($value), 'encoding' => 'base64', 'content' => base64_encode($value),
			'sha' => sha1('blob ' . strlen($value) . "\0" . $value)];
		try
		{
			if ($branch !== 'fixture' || strlen($body) > 4194304 || count($state['requests']) > 10000)
			{
				$response(400, ['message' => 'Fixture bound exceeded']);
			}
			elseif ($method === 'GET')
			{
				if ($bytes !== null)
				{
					$response(200, $match[1] === 'raw' ? $bytes : $metadata($bytes), $match[1] === 'raw');
				}
				else
				{
					$response(404, ['message' => 'File not found']);
				}
			}
			elseif (!in_array($method, ['POST', 'PUT'], true) || $match[1] !== 'contents')
			{
				$response(405, ['message' => 'Unsupported fixture method']);
			}
			elseif ($state['rejectWrites'])
			{
				$response(503, ['message' => 'Requested disposable write failure']);
			}
			elseif (($method === 'POST' && $bytes !== null)
				|| ($method === 'PUT' && ($bytes === null || ($input['sha'] ?? '') !== $metadata($bytes)['sha'])))
			{
				$response(409, ['message' => 'Expected blob does not match']);
			}
			elseif (!is_string($input['content'] ?? null) || ($value = base64_decode($input['content'], true)) === false)
			{
				$response(422, ['message' => 'Expected base64 file content']);
			}
			else
			{
				$state['files'][$branch][$file] = base64_encode($value);
				$response($method === 'POST' ? 201 : 200, ['content' => $metadata($value),
					'commit' => ['sha' => hash('sha1', $value . count($state['requests']))]]);
			}
		}
		finally
		{
			rewind($stream);
			ftruncate($stream, 0);
			fwrite($stream, json_encode($state, JSON_THROW_ON_ERROR));
			fflush($stream);
			flock($stream, LOCK_UN);
			fclose($stream);
		}
	}
}

if (PHP_SAPI === 'cli-server')
{
	RepositoryFixture::serve();
}
