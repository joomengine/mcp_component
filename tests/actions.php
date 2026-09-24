<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiHandler;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\HandlerRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;


require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never
{
	throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void
{
	$checks++;
	if (!$value)
	{
		throw new RuntimeException($message);
	}
};
$reject = static function (callable $call, string $code) use ($check): void
{
	try
	{
		$call();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $error->getIdentifier());
		return;
	}
	throw new RuntimeException('Expected rejection ' . $code);
};

/** Recording Joomla API double; persistence assertions here are not live Joomla evidence. */
$http = new class implements ClientInterface
{
	/** @var array<int,array<string,mixed>> Fake resources. */
	public array $items = [];
	/** @var int Read count. */
	public int $reads = 0;
	/** @var int Write count. */
	public int $writes = 0;
	/** @var bool Simulate failure after persistence. */
	public bool $uncertain = false;
	/** @var bool Simulate Joomla filtering a requested field. */
	public bool $filter = false;
	/** @var ?ResponseInterface Optional native response for error contract checks. */
	public ?ResponseInterface $response = null;
	/** @inheritDoc */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		if ($this->response !== null)
		{
			return $this->response;
		}

		$id = (int) basename($request->getUri()->getPath());
		if ($request->getMethod() === 'GET')
		{
			$this->reads++;
			return isset($this->items[$id]) ? new Response(200, [], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $this->items[$id]]])) : new Response(404);
		}
		$this->writes++;
		if ($request->getMethod() === 'DELETE')
		{
			unset($this->items[$id]);
			return new Response(204);
		}
		$data = Json::decode((string) $request->getBody());
		if ($request->getMethod() === 'POST')
		{
			$id = count($this->items) + 1;
		}
		$this->items[$id] = array_replace($this->items[$id] ?? [], $data);
		if ($this->filter)
		{
			$this->items[$id]['title'] = 'Filtered title';
		}
		if ($this->uncertain)
		{
			return new Response(500, [], 'private database failure');
		}
		return new Response(200, [], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $this->items[$id]]]));
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
$principal = new Principal('joomla:17', 'api', [1]);
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
$schemas = new SchemaValidator();
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings, static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
$now = 1900000000;
$clock = static function () use (&$now): int
{
	return $now;
};
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
$builder = new ApiRequestBuilder();
$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder);
$input = ['data' => ['title' => 'Created through the API double', 'catid' => 2]];
$dry = $executor->plan('content.articles.create', $input, Json::uuid(), true);
$check($dry['dryRun'] && $http->writes === 0 && $store->find('plan') === [], 'Dry-run mutated or persisted an executable plan.');
$reject(static fn () => $executor->plan('content.articles.create', $input, Json::uuid()), 'PERMISSION_REQUIRED');
$check($http->writes === 0, 'Unapproved plan performed a mutation.');
$request = $permissions->request(['toolsets' => ['content.write'], 'duration' => '30-minutes', 'reason' => 'Behavioural contract test']);
$permissions->approve($request['requestId'], $request['acknowledgement']);
$plan = $executor->plan('content.articles.create', $input, Json::uuid());
$check($http->writes === 0, 'Confirmation planning mutated the API.');
$result = $executor->apply($plan['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $http->writes === 1 && $http->reads === 1, 'Creation was not independently read back.');
$repeat = $executor->apply($plan['confirmationToken']);
$check($repeat['idempotentReplay'] && $http->writes === 1 && $http->reads === 1, 'Replay performed I/O or lost the original flag.');
$check($store->find('lease') === [], 'Completed verified write retained a lease.');
$read = $executor->read('content.articles.get', ['id' => 1]);
$check($read['response']['data']['data']['attributes']['title'] === $input['data']['title'], 'Read response changed the source envelope.');
$reject(static fn () => $executor->read('content.articles.create', $input), 'ACTION_EFFECT_MISMATCH');
$reject(static fn () => $executor->plan('content.articles.get', ['id' => 1], Json::uuid()), 'ACTION_EFFECT_MISMATCH');
$update = $executor->plan('content.articles.update', ['id' => 1, 'data' => ['title' => 'Updated title']], Json::uuid());
$http->items[1]['title'] = 'Changed externally';
$reject(static fn () => $executor->apply($update['confirmationToken']), 'PRECONDITION_CHANGED');
$check($http->writes === 1, 'Stale resource plan still wrote.');
$update = $executor->plan('content.articles.update', ['id' => 1, 'data' => ['title' => 'Updated title']], Json::uuid());
$result = $executor->apply($update['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $http->items[1]['title'] === 'Updated title', 'Update or its read-back failed.');
$expired = $executor->plan('content.articles.update', ['id' => 1, 'data' => ['title' => 'Expired']], Json::uuid());
$now += 301;
$reads = $http->reads;
$reject(static fn () => $executor->apply($expired['confirmationToken']), 'PLAN_STALE');
$check($http->reads === $reads, 'Expired plan performed precondition I/O.');
$delete = $executor->plan('content.articles.delete', ['id' => 1], Json::uuid());
$result = $executor->apply($delete['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $result['verification']['postcondition'] === 'resource-absent', 'Deletion was not verified by a 404.');
$plan = $executor->plan('content.articles.create', $input, Json::uuid());
$http->uncertain = true;
$result = $executor->apply($plan['confirmationToken']);
$check($result['verification']['status'] === 'uncertain' && $store->find('lease') !== [], 'Ambiguous persisted mutation was reported as clean completion.');
$writes = $http->writes;
$result = $executor->apply($plan['confirmationToken']);
$check($result['idempotentReplay'] && $http->writes === $writes, 'Uncertain write was reissued.');
$check(!str_contains(Json::encode($result), 'private database failure'), 'Private API diagnostic leaked.');

// Error feedback must survive the same adapter used by generic entity actions.
$errorDetails = static function (ResponseInterface $response) use ($http, $handler, $catalogue, $principal): array
{
	$http->response = $response;
	try
	{
		$handler->execute(['id' => 1], $catalogue->action('content.articles.get')['binding'], $principal);
	}
	catch (OperationException $error)
	{
		if ($error->getIdentifier() !== 'JOOMLA_API_ERROR')
		{
			throw $error;
		}

		return $error->toArray()['details'];
	}
	throw new RuntimeException('An unsuccessful Joomla response was accepted.');
};
$validation = ['errors' => [['title' => 'Field required: Target Admin View<br />Model Header',
	'detail' => 'Select a target admin view before saving.', 'source' => ['pointer' => '/data/attributes/admin_view', 'parameter' => 'admin_view', 'header' => 'Authorization'],
	'meta' => ['trace' => '/var/www/private.php', 'token' => 'server-secret'], 'links' => ['about' => 'https://private.example/error']]]];
$details = $errorDetails(new Response(422, ['Content-Type' => 'application/vnd.api+json; charset=utf-8'], Json::encode($validation)));
$check($details === ['httpStatus' => 422, 'errors' => [['title' => 'Field required: Target Admin View Model Header',
	'detail' => 'Select a target admin view before saving.', 'source' => ['pointer' => '/data/attributes/admin_view', 'parameter' => 'admin_view']]]],
	'Native validation title, detail and field source must survive without private metadata or HTML.');
$details = $errorDetails(new Response(409, ['Content-Type' => 'application/json'], '{"errors":[{"title":"This item is checked out by another user."}]}'));
$check($details['errors'][0]['title'] === 'This item is checked out by another user.', 'Native checkout conflicts lost their corrective diagnostic.');

foreach ([
	new Response(500, ['Content-Type' => 'application/json'], Json::encode($validation)),
	new Response(400, ['Content-Type' => 'text/html'], '<html>private server diagnostic</html>'),
	new Response(400, ['Content-Type' => 'application/json'], '{malformed'),
	new Response(400, ['Content-Type' => 'application/json'], Json::encode(['errors' => [['title' => str_repeat('x', 65537)]]])),
	new Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"title":"Authorization: Bearer test-token"}]}'),
	new Response(400, ['Content-Type' => 'application/json'], '{"errors":[{"title":"File /var/www/private.php failed"},{"title":"SQLSTATE failed"},{"detail":"UPDATE users SET password=value"},{"title":"Rejected test-token"}]}'),
] as $response)
{
	$details = $errorDetails($response);
	$check($details === ['httpStatus' => $response->getStatusCode()], 'Unsafe, malformed, oversized or server error content escaped the safe diagnostic boundary.');
}
$details = $errorDetails(new Response(400, ['Content-Type' => 'application/json'], Json::encode(['errors' => array_fill(0, 50,
	['title' => str_repeat('é', 400), 'source' => ['pointer' => 'https://private.example/', 'parameter' => 'Authorization: secret']])])));
$check(count($details['errors']) <= 8 && strlen(Json::encode($details['errors'])) <= 2048
	&& mb_check_encoding(Json::encode($details), 'UTF-8') && !isset($details['errors'][0]['source']), 'Error summaries must be bounded, UTF-8-safe and use only valid field references.');

echo Json::encode(['checks' => $checks, 'confirmedActionContracts' => 'passed with recording API and transactional memory doubles', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
