<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Handler;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Resolve typed route and form bindings while keeping origin and credentials server-owned.
 *
 * @since 0.1.0
 */
final class ApiRequestBuilder
{
	/**
	 * Build one constrained relative API request from validated action arguments.
	 *
	 * @param array<string,mixed> $arguments Validated input.
	 * @param array<string,mixed> $configuration Administrator-validated binding.
	 * @param array<string,mixed> $current Optional verified current record for partial updates.
	 * @return array<string,mixed> Method, relative path, query, body and optional entity tag.
	 * @since 0.1.0
	 */
	public function build(array $arguments, array $configuration, array $current = []): array
	{
		$method = $configuration['method'] ?? '';
		$route = $configuration['route'] ?? '';

		if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)
			|| !is_string($route) || strlen($route) > 2048
			|| preg_match('/\A\/v[1-9][0-9]*\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*)(?:\/(?:[A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*))*\/?\z/D', $route) !== 1)
		{
			throw new OperationException('BINDING_INVALID', 'A canonical relative Joomla API binding is required.');
		}

		$parameters = $configuration['route_parameters'] ?? [];
		$seen = [];

		foreach ($parameters as $parameter)
		{
			$name = $parameter['name'] ?? '';

			if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $name) !== 1 || isset($seen[$name]))
			{
				throw new OperationException('BINDING_INVALID', 'Route parameter declarations must be unique identifiers.');
			}

			$seen[$name] = true;
			$value = $this->encodeParameter($parameter, $arguments[$name] ?? null);
			$count = 0;
			$route = preg_replace('/:' . preg_quote($name, '/') . '(?=\/|$)/D', $value, $route, -1, $count);

			if ($count !== 1)
			{
				throw new OperationException('BINDING_INVALID', 'Each declared route parameter must occur exactly once.');
			}
		}

		if (str_contains($route, ':') || str_contains($route, '..'))
		{
			throw new OperationException('INVALID_INPUT', 'The resolved Joomla API route is unsafe or incomplete.');
		}

		$query = [];

		if (!empty($configuration['paginated']))
		{
			$offset = $arguments['offset'] ?? 0;
			$limit = $arguments['limit'] ?? 20;

			if (!is_int($offset) || $offset < 0 || $offset > 100000 || !is_int($limit) || $limit < 1 || $limit > 500)
			{
				throw new OperationException('INVALID_INPUT', 'The Joomla list pagination bounds were exceeded.');
			}

			$query = ['page[offset]' => $offset, 'page[limit]' => $limit];
		}

		foreach ($configuration['query_map'] ?? [] as $argument => $key)
		{
			if (!is_string($key) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_.]*(?:\[[a-zA-Z0-9_]+\])*\z/D', $key) !== 1)
			{
				throw new OperationException('BINDING_INVALID', 'A configured Joomla query key is invalid.');
			}

			if (array_key_exists($argument, $arguments) && $arguments[$argument] !== null)
			{
				$value = $arguments[$argument];

				if (!is_scalar($value) || strlen((string) $value) > 2048)
				{
					throw new OperationException('INVALID_INPUT', 'A bounded scalar query argument is required.');
				}

				$query[$key] = is_bool($value) ? (int) $value : $value;
			}
		}

		if (!empty($configuration['native_filter']) && array_key_exists('filter', $arguments))
		{
			$filter = $arguments['filter'];
			if (!is_array($filter) || ($filter !== [] && array_is_list($filter)) || count($filter) > 32)
			{
				throw new OperationException('INVALID_INPUT', 'A bounded native filter object is required.');
			}

			foreach ($filter as $key => $value)
			{
				if (!is_string($key) || preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $key) !== 1
					|| !is_scalar($value) || strlen((string) $value) > 2048
					|| (is_float($value) && !is_finite($value)))
				{
					throw new OperationException('INVALID_INPUT', 'A native filter needs bounded scalar values and literal field names.');
				}

				$query['filter[' . $key . ']'] = is_bool($value) ? (int) $value : $value;
			}
		}

		$query = array_replace($query, (array) ($configuration['query_defaults'] ?? []));
		$policy = $configuration['body_policy'] ?? 'none';
		$body = null;

		if (!in_array($policy, ['none', 'required', 'optional'], true)
			|| ($policy === 'none' && array_key_exists('data', $arguments))
			|| ($policy === 'required' && !array_key_exists('data', $arguments)))
		{
			throw new OperationException('INVALID_INPUT', 'The mutation body does not match the action contract.');
		}

		if (array_key_exists('data', $arguments))
		{
			$body = $arguments['data'];

			if (!is_array($body) || $body === [] || array_is_list($body))
			{
				throw new OperationException('INVALID_INPUT', 'A non-empty Joomla form object is required.');
			}

			foreach ($configuration['preserve_fields'] ?? [] as $field)
			{
				if (!array_key_exists($field, $body) && array_key_exists($field, $current))
				{
					$body[$field] = $current[$field];
				}
			}

			$this->mutationRule($body, $configuration['mutation_rule'] ?? null);

			foreach ($configuration['derived_fields'] ?? [] as $derived)
			{
				if ($derived === 'menu_request' && ($body['type'] ?? null) === 'component' && is_string($body['link'] ?? null))
				{
					$queryString = parse_url($body['link'], PHP_URL_QUERY);
					$request = [];

					foreach (explode('&', is_string($queryString) ? $queryString : '') as $pair)
					{
						if ($pair !== '')
						{
							[$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
							$request[urldecode($key)] = urldecode($value);
						}
					}

					if ($request !== [])
					{
						$body['request'] = $request;
					}
				}
				elseif ($derived === 'module_assignment' && is_array($body['assigned'] ?? null))
				{
					$assigned = array_map('intval', $body['assigned']);
					$body['assignment'] = in_array(0, $assigned, true) ? 0 : (array_filter($assigned, static fn (int $value): bool => $value < 0) !== [] ? -1 : ($assigned !== [] ? 1 : '-'));
				}
				elseif (!in_array($derived, ['menu_request', 'module_assignment'], true))
				{
					throw new OperationException('BINDING_INVALID', 'An unregistered form derivation was requested.');
				}
			}

			$body = array_replace($body, (array) ($configuration['body_defaults'] ?? []));
			Json::encode((object) $body, 1048576);
		}

		$etag = $arguments['etag'] ?? null;

		if ($etag !== null && (!is_string($etag) || strlen($etag) > 512 || preg_match('/\A(?:W\/)?"[^"\x00-\x20\x7f]*"\z/D', $etag) !== 1))
		{
			throw new OperationException('INVALID_INPUT', 'A valid bounded HTTP entity tag is required.');
		}

		return ['method' => $method, 'path' => $route, 'query' => $query, 'body' => $body, 'etag' => $etag];
	}

	/** @param array<string,mixed> $parameter Typed path declaration. @param mixed $value Validated argument. @return string Safe path bytes. @since 0.1.0 */
	private function encodeParameter(array $parameter, mixed $value): string
	{
		$kind = $parameter['kind'] ?? '';

		if ($kind === 'positive-integer')
		{
			if (!is_int($value) || $value < 1 || $value > 9007199254740991)
			{
				throw new OperationException('INVALID_INPUT', 'A positive safe-integer resource identifier is required.');
			}

			return (string) $value;
		}

		$maximum = $parameter['maximumLength'] ?? ($kind === 'media-path' ? 1024 : 255);

		if (!is_string($value) || $value === '' || !is_int($maximum) || $maximum < 1 || $maximum > 2048 || strlen($value) > $maximum)
		{
			throw new OperationException('INVALID_INPUT', 'A bounded non-empty route argument is required.');
		}

		if ($kind === 'media-path')
		{
			if (preg_match('/[\\\\%?#\x00-\x1f\x7f]/', $value) === 1)
			{
				throw new OperationException('INVALID_INPUT', 'The media path contains unsafe syntax.');
			}

			$parts = explode('/', $value);

			foreach ($parts as $part)
			{
				if ($part === '' || $part === '.' || $part === '..')
				{
					throw new OperationException('INVALID_INPUT', 'The media path contains an unsafe segment.');
				}
			}

			return implode('/', array_map('rawurlencode', $parts));
		}

		$pattern = match ($kind)
		{
			'component-name' => '/\Acom_[A-Za-z0-9_]+\z/D',
			'language-code' => '/\A[a-z]{2,3}-[A-Z]{2}\z/D',
			'override-constant' => '/\A[A-Z][A-Z0-9_]*\z/D',
			'adapter-id' => '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D',
			default => null,
		};

		if ($pattern === null || preg_match($pattern, $value) !== 1)
		{
			throw new OperationException('INVALID_INPUT', 'A route argument does not match its declared type.');
		}

		return rawurlencode($value);
	}

	/** @param array<string,mixed> $body Form fields. @param ?string $rule Reviewed extra validation primitive. @return void @since 0.1.0 */
	private function mutationRule(array $body, ?string $rule): void
	{
		if (!in_array($rule, [null, 'media_create', 'media_update', 'override_create'], true))
		{
			throw new OperationException('BINDING_INVALID', 'An unregistered mutation validator was requested.');
		}

		if (($rule === 'media_create' && isset($body['path']) && str_contains(basename((string) $body['path']), '.'))
			|| ($rule === 'media_update' && array_key_exists('content', $body)))
		{
			$content = $body['content'] ?? null;

			if (!is_string($content) || $content === '' || base64_decode($content, true) === false)
			{
				throw new OperationException('INVALID_INPUT', 'A file upload requires valid non-empty base64 content.');
			}
		}

		if ($rule === 'media_update' && empty($body['path']) && empty($body['content']))
		{
			throw new OperationException('INVALID_INPUT', 'A media update requires a new path or file content.');
		}

		if ($rule === 'override_create' && in_array(strtoupper((string) ($body['key'] ?? $body['constant'] ?? '')), ['YES', 'NO', 'NULL', 'FALSE', 'ON', 'OFF', 'NONE', 'TRUE'], true))
		{
			throw new OperationException('INVALID_INPUT', 'The language constant is reserved by the INI format.');
		}
	}
}
