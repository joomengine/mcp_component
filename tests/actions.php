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
	/** @inheritDoc */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
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
echo Json::encode(['checks' => $checks, 'confirmedActionContracts' => 'passed with recording API and transactional memory doubles', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
