<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use InvalidArgumentException;


/**
 * Validated operator configuration, independent of tool arguments and HTTP Host.
 *
 * @since 0.1.0
 */
final class Settings
{
	/** @var array<string,mixed> Validated, non-secret component settings. @since 0.1.0 */
	private array $values;

	/**
	 * Validate the installation's canonical API origin and runtime bounds.
	 *
	 * @param array<string,mixed> $values Joomla component parameters plus platform metadata.
	 * @since 0.1.0
	 */
	public function __construct(array $values)
	{
		$defaults = [
			'api_base' => '', 'site_alias' => 'default', 'site_name' => 'Joomla',
			'joomla_version' => '6.1.0', 'timeout' => 30, 'max_result_bytes' => 8388608,
			'max_request_bytes' => 1048576, 'max_list_limit' => 100,
			'plan_ttl' => 300, 'request_ttl' => 600, 'session_ttl' => 3600,
			'allow_indefinite' => false, 'allow_loopback_http' => false, 'allowed_origins' => [],
			'php_cli_binary' => '', 'job_timeout' => 3600, 'artifact_directory' => '',
		];
		$this->values = array_intersect_key($values, $defaults) + $defaults;

		foreach (['timeout' => [1, 120], 'max_result_bytes' => [1024, 16777216], 'max_request_bytes' => [1024, 4194304],
			'max_list_limit' => [1, 500], 'plan_ttl' => [30, 3600], 'request_ttl' => [30, 3600], 'session_ttl' => [60, 86400], 'job_timeout' => [1, 3600]] as $key => [$min, $max])
		{
			$value = filter_var($this->values[$key], FILTER_VALIDATE_INT);

			if ($value === false || $value < $min || $value > $max)
			{
				throw new InvalidArgumentException('An MCP configuration bound is invalid: ' . $key);
			}

			$this->values[$key] = $value;
		}

		foreach (['allow_indefinite', 'allow_loopback_http'] as $key)
		{
			$this->values[$key] = filter_var($this->values[$key], FILTER_VALIDATE_BOOL);
		}

		if (!is_string($this->values['site_alias']) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}\z/D', $this->values['site_alias']) !== 1)
		{
			throw new InvalidArgumentException('The configured site alias is invalid.');
		}

		$base = rtrim((string) $this->values['api_base'], '/');

		if ($base !== '')
		{
			$parts = parse_url($base);
			$loopback = $parts !== false && in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]', 'localhost'], true);

			if ($parts === false || !isset($parts['host']) || isset($parts['user'], $parts['pass'])
				|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
				|| preg_match('/[\x00-\x20\\\\]/', $base) === 1
				|| (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $loopback && $this->values['allow_loopback_http']))
				|| !str_ends_with($parts['path'] ?? '', '/api/index.php'))
			{
				throw new InvalidArgumentException('Configure a canonical HTTPS Joomla API base ending in /api/index.php.');
			}
		}

		$this->values['api_base'] = $base;
		$origins = $this->values['allowed_origins'];

		if (is_string($origins))
		{
			$origins = preg_split('/[\r\n,]+/', $origins, -1, PREG_SPLIT_NO_EMPTY);
		}

		if (!is_array($origins) || count($origins) > 32)
		{
			throw new InvalidArgumentException('Allowed origins must be a bounded explicit list.');
		}

		$origins = array_map(static function (mixed $origin): string
		{
			if (!is_string($origin))
			{
				throw new InvalidArgumentException('Invalid MCP allowed origin.');
			}

			$origin = rtrim(trim($origin), '/');
			$parts = parse_url($origin);

			if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])
				|| isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
				|| preg_match('/[\x00-\x20*\\\\]/', $origin) === 1)
			{
				throw new InvalidArgumentException('Allowed origins must be explicit origins without paths or wildcards.');
			}

			return $origin;
		}, $origins);

		if ($base !== '')
		{
			$origins[] = self::origin($base);
		}

		$this->values['allowed_origins'] = array_values(array_unique($origins));

		foreach (['php_cli_binary', 'artifact_directory'] as $key)
		{
			$path = $this->values[$key];

			if (!is_string($path) || ($path !== '' && (!str_starts_with($path, '/') || strlen($path) > 4096
				|| preg_match('/[\x00-\x1f\x7f]|(?:^|\/)\.\.?(?:\/|$)/', $path))))
			{
				throw new InvalidArgumentException('The worker configuration requires an absolute server-owned path: ' . $key);
			}

			if ($path === '')
			{
				continue;
			}

			if ($key === 'php_cli_binary' && (!is_file($path) || !is_executable($path)))
			{
				throw new InvalidArgumentException('The configured PHP CLI binary must be an executable regular file.');
			}

			if ($key === 'artifact_directory')
			{
				if (is_link($path) || !is_dir($path) || !is_writable($path))
				{
					throw new InvalidArgumentException('Artifact storage must be an existing writable directory, not a file or symbolic link.');
				}

				$ancestor = $path;
				$suffix = '';

				while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor)
				{
					$suffix = '/' . basename($ancestor) . $suffix;
					$ancestor = dirname($ancestor);
				}

				$canonical = rtrim((string) realpath($ancestor), '/') . $suffix;
				$site = defined('JPATH_ROOT') ? realpath(JPATH_ROOT) : false;

				if ($site !== false && ($canonical === $site || str_starts_with($canonical, rtrim($site, '/') . '/')))
				{
					throw new InvalidArgumentException('Artifact storage must be outside the public Joomla installation.');
				}
			}
		}
	}

	/** @param string $name Reviewed setting name. @return mixed Validated setting. @since 0.1.0 */
	public function get(string $name): mixed
	{
		if (!array_key_exists($name, $this->values))
		{
			throw new InvalidArgumentException('Unknown MCP configuration setting.');
		}

		return $this->values[$name];
	}

	/** @param string $url Validated absolute URL. @return string URL origin including explicit port. @since 0.1.0 */
	public static function origin(string $url): string
	{
		$parts = parse_url($url);

		if ($parts === false || !isset($parts['scheme'], $parts['host']))
		{
			throw new InvalidArgumentException('An absolute URL is required.');
		}

		return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}
}
