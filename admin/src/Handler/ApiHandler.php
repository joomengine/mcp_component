<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Handler;


use Closure;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;


/**
 * Same-installation Joomla API adapter preserving the authenticated user's token and ACL.
 *
 * @since 0.1.0
 */
final class ApiHandler implements HandlerInterface
{
	/** @var ClientInterface Bounded, non-redirecting transport. @since 0.1.0 */
	private ClientInterface $http;
	/** @var ApiRequestBuilder Typed declarative request builder. @since 0.1.0 */
	private ApiRequestBuilder $builder;
	/** @var Settings Trusted installation configuration. @since 0.1.0 */
	private Settings $settings;
	/** @var string Authenticated request token; never exposed to the catalogue. @since 0.1.0 */
	private string $token;
	/** @var Closure():string Server-owned Joomla Update credential resolver. @since 0.1.0 */
	private Closure $updateToken;

	/** @param ClientInterface $http Transport. @param ApiRequestBuilder $builder Request builder. @param Settings $settings Installation settings. @param string $token Current authenticated API token. @param callable $updateToken Existing Joomla Update token resolver. @since 0.1.0 */
	public function __construct(ClientInterface $http, ApiRequestBuilder $builder, Settings $settings, string $token, callable $updateToken)
	{
		$this->http = $http;
		$this->builder = $builder;
		$this->settings = $settings;
		$this->token = $token;
		$this->updateToken = Closure::fromCallable($updateToken);
	}

	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		return $this->request($arguments, $binding, $principal);
	}

	/**
	 * Execute one request; verification can explicitly inspect a missing-resource response.
	 *
	 * @param array<string,mixed> $arguments Current validated input.
	 * @param array<string,mixed> $binding Current authorized database binding.
	 * @param PrincipalInterface $principal Actual API authority, never local CLI.
	 * @param ?string $idempotencyKey Optional application-bound mutation key.
	 * @param array<string,mixed> $current Preserved form values from a verified read.
	 * @param bool $allowMissing Whether a 404 is an expected verification result.
	 * @return array{status:int,headers:array<string,string>,data:mixed} Original API response contract.
	 * @since 0.1.0
	 */
	public function request(array $arguments, array $binding, PrincipalInterface $principal, ?string $idempotencyKey = null, array $current = [], bool $allowMissing = false): array
	{
		if ($principal->isLocal() || $principal->getTrack() !== 'api' || ($binding['track'] ?? '') !== 'api'
			|| ($binding['handler'] ?? '') !== 'api.request' || $this->settings->get('api_base') === '')
		{
			throw new OperationException('TRANSPORT_UNAVAILABLE', 'The authenticated Joomla API transport is unavailable.');
		}

		$config = $binding['configuration'] ?? [];

		if (!empty($config['source_gate']))
		{
			throw new OperationException('ACTION_UNAVAILABLE', 'This upstream operation is catalogued but not executable.');
		}

		$resolved = $this->builder->build($arguments, $config, $current);
		$headers = ['Accept' => 'application/vnd.api+json, application/json', 'User-Agent' => 'JoomEngine-MCP-for-Joomla/0.1.0'];
		$credential = $this->token;
		$authentication = $config['authentication'] ?? 'joomla-api-token';

		if ($authentication === 'joomla-update-token')
		{
			// The public update-token endpoint is not a Joomla identity ACL check.
			if (!$principal->authorise('core.admin', 'com_joomlaupdate'))
			{
				throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested MCP definition is unavailable.');
			}

			$credential = ($this->updateToken)();
			$headers['X-JUpdate-Token'] = $credential;
		}
		elseif ($authentication === 'joomla-api-token')
		{
			$headers['Authorization'] = 'Bearer ' . $credential;
		}
		else
		{
			throw new OperationException('BINDING_INVALID', 'The requested API authentication mode is not registered.');
		}

		if ($credential === '' || strlen($credential) > 8192 || preg_match('/[\x00-\x20\x7f]/', $credential) === 1)
		{
			throw new OperationException('AUTHENTICATION_UNAVAILABLE', 'The required Joomla API credential is unavailable.');
		}

		$body = $resolved['body'] === null ? '' : Json::encode((object) $resolved['body'], 1048576);

		if ($body !== '')
		{
			$headers['Content-Type'] = 'application/json';
		}

		if ($resolved['etag'] !== null)
		{
			$headers['If-Match'] = $resolved['etag'];
		}

		if ($idempotencyKey !== null)
		{
			$headers['Idempotency-Key'] = Json::requireUuid($idempotencyKey);
		}

		$url = $this->settings->get('api_base') . $resolved['path'];

		if ($resolved['query'] !== [])
		{
			$url .= '?' . http_build_query($resolved['query'], '', '&', PHP_QUERY_RFC3986);
		}

		try
		{
			$response = $this->http->sendRequest(new Request($resolved['method'], $url, $headers, $body));
		}
		catch (ClientExceptionInterface)
		{
			throw new OperationException('EXCHANGE_UNCERTAIN', 'The Joomla API exchange did not complete. A submitted mutation must be reconciled before retrying.');
		}

		$status = $response->getStatusCode();

		if ($status >= 300 && $status < 400)
		{
			throw new OperationException('REDIRECT_REFUSED', 'The Joomla API returned a redirect; credentials were not forwarded.');
		}

		if (($status < 200 || $status >= 300) && !($allowMissing && $status === 404))
		{
			$details = ['httpStatus' => $status];

			if ($status >= 400 && $status < 500
				&& preg_match('/\Aapplication\/(?:vnd\.api\+json|json)(?:\s*;|\z)/i', $response->getHeaderLine('content-type')) === 1)
			{
				$errors = $this->validationErrors($response->getBody()->read(65537), $credential);

				if ($errors !== [])
				{
					$details['errors'] = $errors;
				}
			}

			throw new OperationException('JOOMLA_API_ERROR', 'Joomla rejected the API operation.', $details);
		}

		$stream = $response->getBody();
		$text = '';
		$maximum = $this->settings->get('max_result_bytes');

		while (!$stream->eof())
		{
			$chunk = $stream->read(min(8192, $maximum + 1 - strlen($text)));
			$text .= $chunk;

			if (strlen($text) > $maximum || ($chunk === '' && !$stream->eof()))
			{
				throw new OperationException('RESULT_TOO_LARGE', 'The API response exceeded its bounded result contract.');
			}
		}

		$data = $text === '' ? null : Json::decode($text, true, $maximum);

		if (isset($config['select_fields']))
		{
			$data = self::selectSafeFields($data, $config['select_fields']);
		}

		$selected = [];

		foreach (['content-type', 'etag', 'last-modified', 'location', 'x-ratelimit-limit', 'x-ratelimit-remaining'] as $name)
		{
			$value = $response->getHeaderLine($name);

			if ($value !== '' && strlen($value) <= 2048)
			{
				$selected[$name] = $value;
			}
		}

		return ['status' => $status, 'headers' => $selected, 'data' => $data];
	}

	/**
	 * Preserve bounded native validation feedback without exposing raw API errors.
	 *
	 * Only JSON:API public error fields are eligible. Debug metadata, HTML pages,
	 * server failures, credentials, filesystem diagnostics and SQL are withheld.
	 * Joomla's translated form errors may contain simple HTML line separators.
	 *
	 * @param string $body Bounded client-error body.
	 * @param string $credential Credential used for this exchange.
	 * @return array<int,array<string,mixed>> Safe errors suitable for input correction.
	 * @since 0.1.1
	 */
	private function validationErrors(string $body, string $credential): array
	{
		if (strlen($body) > 65536)
		{
			return [];
		}

		$data = json_decode($body, true, 16);

		if (!is_array($data) || !is_array($data['errors'] ?? null) || !array_is_list($data['errors']))
		{
			return [];
		}

		$errors = [];
		$bytes = 0;

		foreach (array_slice($data['errors'], 0, 8) as $error)
		{
			if (!is_array($error))
			{
				continue;
			}

			$safe = [];

			foreach (['title', 'detail'] as $field)
			{
				$value = $error[$field] ?? null;

				if (!is_string($value) || strlen($value) > 4096 || !mb_check_encoding($value, 'UTF-8'))
				{
					continue;
				}

				$value = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>/i', ' ', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$value = trim(preg_replace('/\s+/u', ' ', $value));

				if ($value === '' || str_contains($value, $credential)
					|| preg_match('~[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\b(?:authorization|bearer|token|password|passwd|secret|credential|SQLSTATE|PDOException|stack trace|thrown in)\b|\b(?:SELECT\s.+\sFROM|INSERT\s+INTO|UPDATE\s.+\sSET|DELETE\s+FROM)\b|(?:https?|file)://|(?:^|\s|[\x22\x27(])(?:/[A-Za-z0-9_.-]+/|[A-Za-z]:[\\\\/])~i', $value) === 1)
				{
					continue;
				}

				$safe[$field] = mb_strcut($value, 0, 512, 'UTF-8');
			}

			if ($safe === [])
			{
				continue;
			}

			foreach (['pointer' => '~\A/(?:data/)?(?:attributes/)?[A-Za-z0-9_\~/-]{1,190}\z~D',
				'parameter' => '~\A[A-Za-z][A-Za-z0-9_.\[\]-]{0,127}\z~D'] as $field => $pattern)
			{
				$value = is_array($error['source'] ?? null) ? ($error['source'][$field] ?? null) : null;

				if (is_string($value) && preg_match($pattern, $value) === 1)
				{
					$safe['source'][$field] = $value;
				}
			}

			$bytes += strlen(Json::encode($safe));

			if ($bytes > 2048)
			{
				break;
			}

			$errors[] = $safe;
		}

		return $errors;
	}

	/**
	 * Select scalar safe configuration values from Joomla's supported response shapes.
	 *
	 * @param mixed $data Joomla response body.
	 * @param string[] $fields Administrator-owned explicit safe field list.
	 * @return array<string,mixed> Only explicitly selected scalars.
	 * @since 0.1.0
	 */
	public static function selectSafeFields(mixed $data, array $fields): array
	{
		$source = is_array($data) ? ($data['data'] ?? $data) : [];
		$nodes = is_array($source) && array_is_list($source) ? $source : [$source];
		$result = [];

		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$values = $node['attributes'] ?? $node;

			if (is_array($values) && isset($values['key']) && is_string($values['key']) && array_key_exists('value', $values))
			{
				$values = [$values['key'] => $values['value']];
			}

			foreach (is_array($values) ? $values : [] as $name => $value)
			{
				if (in_array($name, $fields, true) && ($value === null || is_scalar($value)))
				{
					$result[$name] = $value;
				}
			}
		}

		return $result;
	}
}
