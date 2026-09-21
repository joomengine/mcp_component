<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Job;


use Closure;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Owned immutable artifact copies with bounded, verified reads and retention.
 * Paths are selected by service composition and never accepted from MCP input.
 *
 * @since 0.1.0
 */
final class Artifacts
{
	/** @var StoreInterface Durable metadata. @since 0.1.0 */
	private StoreInterface $store;
	/** @var string Principal ownership digest. @since 0.1.0 */
	private string $principalKey;
	/** @var string Private server-owned artifact directory. @since 0.1.0 */
	private string $directory;
	/** @var string[] Reviewed native output roots. @since 0.1.0 */
	private array $roots;
	/** @var Closure Injectable clock. @since 0.1.0 */
	private Closure $clock;
	/** @var int Maximum single file bytes. @since 0.1.0 */
	private int $maximum;
	/** @var int Maximum retained bytes per owner. @since 0.1.0 */
	private int $quota;
	/** @var int Artifact retention seconds. @since 0.1.0 */
	private int $retention;

	/** @param StoreInterface $store Storage. @param PrincipalInterface $principal Real authority. @param string $directory Private output directory. @param array $roots Reviewed native roots. @param callable $clock Clock. @param int $maximum Single-file cap. @param int $quota Owner cap. @param int $retention Retention seconds. @since 0.1.0 */
	public function __construct(StoreInterface $store, PrincipalInterface $principal, string $directory, array $roots, callable $clock, int $maximum = 134217728, int $quota = 536870912, int $retention = 604800)
	{
		if ($maximum < 1 || $quota < $maximum || $retention < 60 || $retention > 2592000 || is_link($directory)
			|| (!is_dir($directory) && !mkdir($directory, 0700, true)))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'Private artifact storage is unavailable.');
		}

		$real = realpath($directory);

		if ($real === false || !is_writable($real) || !chmod($real, 0700))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'Private artifact storage cannot be secured.');
		}

		$this->store = $store;
		$this->principalKey = hash('sha256', $principal->getId());
		$this->directory = $real;
		$this->roots = [];

		foreach ($roots as $root)
		{
			$resolved = is_string($root) ? realpath($root) : false;

			if ($resolved !== false && is_dir($resolved))
			{
				$this->roots[] = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			}
		}

		$this->clock = Closure::fromCallable($clock);
		$this->maximum = $maximum;
		$this->quota = $quota;
		$this->retention = $retention;
	}

	/** @param string $job Owned job UUID. @param array $descriptor Private native file metadata. @return array Safe artifact metadata. @since 0.1.0 */
	public function capture(string $job, array $descriptor): array
	{
		Json::requireUuid($job);
		$owner = $this->store->one('job', ['uuid' => $job, 'principal_key' => $this->principalKey]);
		$path = $descriptor['path'] ?? null;
		$real = is_string($path) && !is_link($path) ? realpath($path) : false;
		$allowed = false;

		foreach ($this->roots as $root)
		{
			$allowed = $allowed || ($real !== false && str_starts_with($real, $root));
		}

		if ($owner === null || $real === false || !$allowed || !is_file($real) || is_link($real))
		{
			throw new OperationException('ARTIFACT_INVALID', 'The native result does not name a permitted generated file.');
		}

		$size = filesize($real);

		if ($size === false || $size < 1 || $size > $this->maximum || (isset($descriptor['size']) && (int) $descriptor['size'] !== $size))
		{
			throw new OperationException('ARTIFACT_LIMIT', 'The generated file is missing, changed or exceeds its size limit.');
		}

		$this->purgeExpired();
		$rows = $this->store->find('artifact', ['principal_key' => $this->principalKey], 10000);
		$total = array_sum(array_column($rows, 'size'));
		$forJob = array_filter($rows, static fn (array $row): bool => $row['job_uuid'] === $job);

		if ($total + $size > $this->quota || count($rows) >= 10000 || count($forJob) >= 32)
		{
			throw new OperationException('ARTIFACT_LIMIT', 'The retained artifact quota has been reached.');
		}

		$id = Json::uuid();
		$target = $this->path($id);
		$input = fopen($real, 'rb');
		$output = fopen($target, 'x+b');

		if ($input === false || $output === false)
		{
			if (is_resource($input))
			{
				fclose($input);
			}

			if (is_resource($output))
			{
				fclose($output);
				unlink($target);
			}

			throw new OperationException('ARTIFACT_STORAGE', 'The generated file could not be retained.');
		}

		try
		{
			chmod($target, 0600);
			$before = fstat($input);
			$copied = stream_copy_to_stream($input, $output, $this->maximum + 1);
			$after = fstat($input);
			fflush($output);
			$hash = hash_file('sha256', $target);

			if ($copied !== $size || $before === false || $after === false || $before['ino'] !== $after['ino']
				|| $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']
				|| $hash === false || (isset($descriptor['sha256']) && !hash_equals((string) $descriptor['sha256'], $hash)))
			{
				throw new OperationException('ARTIFACT_CHANGED', 'The native output changed while its immutable copy was being verified.');
			}

			$name = basename((string) ($descriptor['name'] ?? $real));
			$name = preg_replace('/[^a-zA-Z0-9_. -]/', '_', $name);
			rewind($output);
			$chunks = [];

			while (!feof($output))
			{
				$chunk = fread($output, 262144);

				if ($chunk === false)
				{
					throw new OperationException('ARTIFACT_STORAGE', 'The retained artifact could not be verified.');
				}

				if ($chunk !== '')
				{
					$chunks[] = hash('sha256', $chunk);
				}
			}

			$now = ($this->clock)();
			$record = ['uuid' => $id, 'principal_key' => $this->principalKey, 'job_uuid' => $job,
				'name' => substr($name === '' ? 'artifact.bin' : $name, 0, 190),
				'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($target) ?: 'application/octet-stream',
				'size' => $size, 'sha256' => $hash, 'chunk_hashes' => Json::encode($chunks),
				'created_at' => $now, 'expires_at' => $now + $this->retention];
			$this->store->insert('artifact', $record);
			$this->removeStaged($real, $descriptor);

			return $this->metadata($record);
		}
		catch (Throwable $error)
		{
			unlink($target);
			throw $error;
		}
		finally
		{
			fclose($input);
			fclose($output);
		}
	}

	/** @param string $job Job UUID. @return array Owned retained metadata. @since 0.1.0 */
	public function listing(string $job): array
	{
		Json::requireUuid($job);

		return array_map([$this, 'metadata'], $this->store->find('artifact', ['principal_key' => $this->principalKey,
			'job_uuid' => $job, 'expires_at' => ['gt', ($this->clock)()]], 32));
	}

	/** @param string $id Artifact UUID. @return string Owned parent job UUID. @since 0.1.0 */
	public function job(string $id): string
	{
		return $this->record($id)['job_uuid'];
	}

	/** @param string $id Artifact UUID. @param int $offset Byte offset. @param int $length Bounded chunk size. @return array Verified base64 bytes and metadata. @since 0.1.0 */
	public function read(string $id, int $offset = 0, int $length = 65536): array
	{
		$row = $this->record($id);

		if ($offset < 0 || $offset > (int) $row['size'] || $length < 1 || $length > 262144)
		{
			throw new OperationException('INVALID_INPUT', 'Artifact byte ranges must be within the retained file and at most 262144 bytes.');
		}

		$path = $this->path($id);

		if (is_link($path) || !is_file($path))
		{
			throw new OperationException('ARTIFACT_UNAVAILABLE', 'The retained artifact is unavailable.');
		}

		$stream = fopen($path, 'rb');

		if ($stream === false)
		{
			throw new OperationException('ARTIFACT_UNAVAILABLE', 'The retained artifact is unavailable.');
		}

		try
		{
			$stat = fstat($stream);

			if ($stat === false || $stat['size'] !== (int) $row['size'])
			{
				throw new OperationException('ARTIFACT_CHANGED', 'The retained artifact no longer matches its verified metadata.');
			}

			// Hash the at-most-two intersecting blocks, not the whole archive on
			// every range. This keeps a complete download linear in archive size.
			$hashes = Json::decode($row['chunk_hashes']);
			$first = intdiv($offset, 262144);
			$end = min((int) $row['size'], $offset + $length);
			$last = $end > $offset ? intdiv($end - 1, 262144) : $first - 1;
			$verified = '';

			for ($block = $first; $block <= $last; $block++)
			{
				fseek($stream, $block * 262144);
				$chunk = fread($stream, 262144);

				if ($chunk === false || !isset($hashes[$block]) || !hash_equals($hashes[$block], hash('sha256', $chunk)))
				{
					throw new OperationException('ARTIFACT_CHANGED', 'The retained artifact no longer matches its verified metadata.');
				}

				$verified .= $chunk;
			}

			$data = substr($verified, $offset - $first * 262144, $end - $offset);

			return $this->metadata($row) + ['encoding' => 'base64', 'offset' => $offset, 'length' => strlen($data),
				'eof' => $offset + strlen($data) >= (int) $row['size'], 'data' => base64_encode($data)];
		}
		finally
		{
			fclose($stream);
		}
	}

	/** @return int Expired owned files removed. @since 0.1.0 */
	public function purgeExpired(): int
	{
		$count = 0;

		foreach ($this->store->find('artifact', ['principal_key' => $this->principalKey, 'expires_at' => ['lte', ($this->clock)()]], 10000) as $row)
		{
			$path = $this->path($row['uuid']);

			if (is_file($path) || is_link($path))
			{
				unlink($path);
			}

			$count += $this->store->remove('artifact', ['uuid' => $row['uuid'], 'principal_key' => $this->principalKey]);
		}

		return $count;
	}

	/** @param string $id Artifact UUID. @return array Current owned record. @since 0.1.0 */
	private function record(string $id): array
	{
		$row = $this->store->one('artifact', ['uuid' => Json::requireUuid($id), 'principal_key' => $this->principalKey, 'expires_at' => ['gt', ($this->clock)()]]);

		if ($row === null)
		{
			throw new OperationException('ARTIFACT_UNAVAILABLE', 'The retained artifact is unavailable.');
		}

		return $row;
	}

	/** @param string $id Server-owned UUID. @return string Private path. @since 0.1.0 */
	private function path(string $id): string
	{
		return $this->directory . DIRECTORY_SEPARATOR . Json::requireUuid($id) . '.bin';
	}

	/** @param string $path Verified reviewed source path. @param array $descriptor Native metadata. @return void Remove only component-created installer preservation copies. @since 0.1.0 */
	private function removeStaged(string $path, array $descriptor): void
	{
		$directory = dirname($path);

		if (($descriptor['staged'] ?? false) !== true
			|| preg_match('/\Ajoomengine-mcp-jcb-[a-f0-9]{32}\z/D', basename($directory)) !== 1
			|| preg_match('/\A[a-f0-9]{32}\.zip\z/D', basename($path)) !== 1)
		{
			return;
		}

		try
		{
			if (!is_link($path) && is_file($path) && unlink($path) && scandir($directory) === ['.', '..'])
			{
				rmdir($directory);
			}
		}
		catch (Throwable)
		{
			// Retained output is already verified and committed. A filesystem
			// cleanup failure must not remove or invalidate that durable artifact.
		}
	}

	/** @param array $row Durable record. @return array Public metadata without paths or ownership keys. @since 0.1.0 */
	private function metadata(array $row): array
	{
		return ['artifactId' => $row['uuid'], 'jobId' => $row['job_uuid'], 'name' => $row['name'],
			'mimeType' => $row['mime_type'], 'size' => (int) $row['size'], 'sha256' => $row['sha256'],
			'createdAt' => gmdate('c', (int) $row['created_at']), 'expiresAt' => gmdate('c', (int) $row['expires_at'])];
	}
}
