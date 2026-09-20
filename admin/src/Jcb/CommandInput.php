<?php
/**
 * @package    JoomEngine.Mcp
 * @created    20 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Closure;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Freezes native JCB selectors, option bundles, environment fallbacks and file
 * bytes during planning. The worker receives data, never mutable operator file
 * paths. Omitted compiler options retain JCB's global semantics. Remote callers
 * cannot read server files or select a credential-bearing repository object.
 *
 * @since  0.1.0
 */
final class CommandInput
{
	/** @var Closure(string):string|false Explicit environment accessor. @since 0.1.0 */
	private Closure $environment;

	/** @var Closure(string):string Bounded local file reader. @since 0.1.0 */
	private Closure $reader;

	/**
	 * Inject testable environment and file boundaries.
	 *
	 * @param   ?callable  $environment  Native process environment by default.
	 * @param   ?callable  $reader       Native bounded local files by default.
	 * @since   0.1.0
	 */
	public function __construct(?callable $environment = null, ?callable $reader = null)
	{
		$this->environment = Closure::fromCallable($environment ?? 'getenv');
		$this->reader = Closure::fromCallable($reader ?? static function (string $path): string
		{
			if ($path === '' || strlen($path) > 4096 || str_contains($path, '://') || str_contains($path, "\0")
				|| !is_file($path) || !is_readable($path))
			{
				throw new OperationException('JCB_INPUT_FILE', 'A readable local input file is required.');
			}

			$data = file_get_contents($path, false, null, 0, 1048577);

			if (!is_string($data) || strlen($data) > 1048576 || !mb_check_encoding($data, 'UTF-8'))
			{
				throw new OperationException('JCB_INPUT_FILE', 'JCB input files must contain at most 1 MiB of UTF-8 text.');
			}

			return $data;
		});
	}

	/**
	 * Preserve the actual native input precedence while removing live file reads.
	 *
	 * @param   array<string,mixed>  $options        Validated long-name options.
	 * @param   array<string,mixed>  $configuration  Reviewed command binding.
	 * @param   bool                $local          Trusted local, rather than HTTP, request.
	 * @return  array<string,mixed>  Immutable options, environment and selectors.
	 * @since   0.1.0
	 */
	public function freeze(array $options, array $configuration, bool $local): array
	{
		$options = CommandContract::options($options, $configuration['contract'] ?? []);
		$compile = ($configuration['command'] ?? '') === 'componentbuilder:compile:component';
		$names = $compile ? ['JCB_COMPILE_COMPONENT', 'JCB_COMPILE_COMPONENTS', 'JCB_COMPILE_COMPONENTS_FILE',
			'JCB_COMPILER_OPTIONS', 'JCB_COMPILE_INSTALL', 'JCB_BACKUP', 'JCB_REPOSITORY', 'JCB_INSTALL',
			'JCB_ADD_PLACEHOLDERS', 'JCB_DEBUG_LINE_NR', 'JCB_MINIFY', 'JCB_POWERS', 'JCB_JOOMLA_VERSION',
			'JCB_POWERS_REPOSITORY', 'JCB_INDENTATION_VALUE', 'JCB_ADD_BUILD_DATE', 'JCB_BUILD_DATE']
			: ['JCB_GET_ITEMS', 'JCB_GET_ITEMS_FILE', 'JCB_GET_REPO', 'JCB_GET_REPO_FILE', 'JCB_GET_FORCE', 'JCB_GET_RESOLVE'];
		$environment = [];

		// HTTP has explicit input only, never the webserver's ambient JCB environment.
		if ($local)
		{
			foreach ($names as $name)
			{
				$value = ($this->environment)($name);

				if ($value !== false && $value !== '')
				{
					if (!is_string($value) || strlen($value) > 1048576 || str_contains($value, "\0"))
					{
						throw new OperationException('JCB_ENVIRONMENT', 'A JCB environment value exceeds the input boundary.');
					}

					$environment[$name] = $value;
				}
			}
		}

		$prefix = $compile ? 'JCB_COMPILE_COMPONENTS' : 'JCB_GET_ITEMS';
		$listKey = $compile ? 'components' : 'items';
		$inline = $this->value($options[$listKey] ?? null, $environment[$prefix] ?? '');
		$file = $this->value($options[$listKey . '-file'] ?? null, $environment[$prefix . '_FILE'] ?? '');

		if (str_starts_with($inline, '@'))
		{
			$file = substr($inline, 1);
			$inline = '';
		}

		$items = $this->parseList($inline, $compile);

		if ($file !== '')
		{
			$items = array_merge($items, $this->parseList($this->read($file, $local), $compile));
		}

		if ($compile)
		{
			$single = $this->value($options['component'] ?? null, $environment['JCB_COMPILE_COMPONENT'] ?? '');

			if ($single !== '')
			{
				array_unshift($items, $single);
			}

			unset($options['component'], $environment['JCB_COMPILE_COMPONENT']);
		}

		$items = $this->selectors($items, $compile || str_contains((string) $configuration['command'], ':push:'));

		if ($items === [])
		{
			throw new OperationException('INVALID_INPUT', 'Select at least one JCB item before planning this operation.');
		}

		$options[$listKey] = Json::encode($items);
		unset($options[$listKey . '-file'], $environment[$prefix], $environment[$prefix . '_FILE']);

		if ($compile)
		{
			$bundle = $this->value($options['options'] ?? null, $environment['JCB_COMPILER_OPTIONS'] ?? '');

			if (str_starts_with($bundle, '@'))
			{
				$bundle = $this->read(substr($bundle, 1), $local);
			}

			if ($bundle !== '')
			{
				$parsed = Json::decode($bundle, false);

				if (!$parsed instanceof \stdClass)
				{
					throw new OperationException('INVALID_INPUT', 'A compiler options bundle must be a JSON object.');
				}

				$options['options'] = $bundle;
			}

			unset($environment['JCB_COMPILER_OPTIONS']);
		}
		elseif (isset(((array) ($configuration['contract']['options'] ?? []))['repo']))
		{
			$repo = $this->value($options['repo'] ?? null, $environment['JCB_GET_REPO'] ?? '');
			$file = $this->value($options['repo-file'] ?? null, $environment['JCB_GET_REPO_FILE'] ?? '');

			if (str_starts_with($repo, '@'))
			{
				$file = substr($repo, 1);
				$repo = '';
			}

			if ($repo === '' && $file !== '')
			{
				$repo = $this->read($file, $local);
			}

			if ($repo !== '')
			{
				if (!$local && preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', trim($repo)) !== 1)
				{
					throw new OperationException('INVALID_INPUT', 'Remote JCB jobs may reference a configured repository GUID, not a repository URL or credentials.');
				}

				$options['repo'] = $repo;
			}

			unset($options['repo-file'], $environment['JCB_GET_REPO'], $environment['JCB_GET_REPO_FILE']);
		}

		ksort($options, SORT_STRING);
		ksort($environment, SORT_STRING);
		$result = ['options' => (object) $options, 'environment' => (object) $environment, 'selectors' => $items];
		Json::encode($result, 2097152);

		return $result;
	}

	/** @param mixed $value Explicit option. @param string $fallback Ambient fallback. @return string Native empty-value precedence. @since 0.1.0 */
	private function value(mixed $value, string $fallback): string
	{
		return $value === null || $value === '' ? $fallback : (string) $value;
	}

	/** @param string $path Explicit input file. @param bool $local Local-only authority. @return string Frozen UTF-8 bytes. @since 0.1.0 */
	private function read(string $path, bool $local): string
	{
		if (!$local)
		{
			throw new OperationException('LOCAL_FILE_FORBIDDEN', 'Remote JCB input must be inline; it cannot read files on the server.');
		}

		return ($this->reader)($path);
	}

	/** @param string $raw Native selector string. @param bool $compile Compiler-specific object forms. @return array Native list values. @since 0.1.0 */
	private function parseList(string $raw, bool $compile): array
	{
		$raw = trim($raw);

		if ($raw === '')
		{
			return [];
		}

		$decoded = json_decode($raw, true);

		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
		{
			if (array_is_list($decoded))
			{
				return $decoded;
			}

			foreach ($compile ? ['components', 'items'] : ['items'] as $key)
			{
				if (isset($decoded[$key]) && is_array($decoded[$key]))
				{
					return $decoded[$key];
				}
			}

			if ($compile)
			{
				return array_values($decoded);
			}
		}

		$raw = str_replace(["\r\n", "\r"], "\n", $raw);

		return str_contains($raw, ',') ? explode(',', $raw) : explode("\n", $raw);
	}

	/** @param array $items Native selectors. @param bool $guidOnly Compiler/push require GUIDs. @return string[] Valid normalized unique selectors. @since 0.1.0 */
	private function selectors(array $items, bool $guidOnly): array
	{
		if (count($items) > 500)
		{
			throw new OperationException('INVALID_INPUT', 'A single JCB operation is limited to 500 explicit selectors.');
		}

		$result = [];

		foreach ($items as $item)
		{
			if (!is_string($item) && !is_int($item))
			{
				throw new OperationException('INVALID_INPUT', 'JCB selectors must be strings or integer identifiers.');
			}

			$item = trim((string) $item);

			if ($item === '')
			{
				continue;
			}

			if (strlen($item) > 191 || preg_match('/[\x00-\x1f\x7f]/', $item)
				|| ($guidOnly && preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $item) !== 1))
			{
				throw new OperationException('INVALID_INPUT', $guidOnly ? 'This JCB command requires valid GUID selectors.' : 'A JCB selector is invalid.');
			}

			$result[$item] = $item;
		}

		return array_values($result);
	}
}
