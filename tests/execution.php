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
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
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

	throw new RuntimeException('Expected rejection: ' . $code);
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/data/catalogue-seed.json'))['entities'];
$store = new MemoryStore($seed);
$principal = new Principal('joomla:17', 'api', [1]);
$settings = new Settings(['api_base' => 'https://joomla.example/sub/api/index.php']);
$catalogue = new Catalogue($store, new Authorizer(), $principal, new SchemaValidator(), $settings, static fn (string $name): bool => true, static fn (string $entity, string $handler): bool => true);
$builder = new ApiRequestBuilder();
$read = $catalogue->action('content.articles.get')['binding'];
$check($builder->build(['id' => 7], $read['configuration'])['path'] === '/v1/content/articles/7', 'Read route parity.');
foreach (['7', 0, -1, '../users', '7/2', 9007199254740992, null] as $id)
{
	$reject(static fn () => $builder->build(['id' => $id], $read['configuration']), 'INVALID_INPUT');
}
foreach (['https://other.example/v1/users', '//other.example/v1/users', '/v1/../users', '/v1/users?x=1', '/v1/users#x'] as $route)
{
	$reject(static fn () => $builder->build([], array_replace($read['configuration'], ['route' => $route])), 'BINDING_INVALID');
}
$media = $catalogue->action('media.files.get')['binding'];
$path = $builder->build(['path' => 'local-images:/folder/a b.png'], $media['configuration'])['path'];
$check(str_ends_with($path, '/local-images%3A/folder/a%20b.png'), 'Typed media path preserves safe slash boundaries.');
foreach (['../configuration.php', '/etc/passwd', 'images/%2e%2e/file', 'images//file', "images/evil\0file", 'images/../file'] as $path)
{
	$reject(static fn () => $builder->build(['path' => $path], $media['configuration']), 'INVALID_INPUT');
}
$menu = $catalogue->action('menus.site-items.update')['binding'];
$built = $builder->build(['id' => 8, 'data' => ['title' => 'Updated']], $menu['configuration'], ['type' => 'component', 'link' => 'index.php?option=com_content&view=article&id=9', 'menutype' => 'mainmenu', 'parent_id' => 1, 'params' => ['show_title' => 1]]);
$check($built['body']['request']['id'] === '9' && $built['body']['params']['show_title'] === 1, 'Partial menu form completion preserves Joomla form state.');
$module = $catalogue->action('modules.site.update')['binding'];
$built = $builder->build(['id' => 9, 'data' => ['title' => 'Module']], $module['configuration'], ['params' => ['layout' => 'default'], 'assigned' => [-2]]);
$check($built['body']['assignment'] === -1 && $built['body']['params']['layout'] === 'default', 'Module assignment derivation and preservation.');
$createMedia = $catalogue->action('media.files.create')['binding'];
$reject(static fn () => $builder->build(['data' => ['path' => 'local-images:/image.png']], $createMedia['configuration']), 'INVALID_INPUT');

/** Test transport records requests but performs no network calls. */
$http = new class implements ClientInterface
{
	/** @var RequestInterface[] Captured test requests. */
	public array $requests = [];
	/** @var ResponseInterface[] Queued responses. */
	public array $responses = [];
	/** @return ResponseInterface The next deliberate fixture response. */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$this->requests[] = $request;

		return array_shift($this->responses) ?? new Response(200, ['Content-Type' => 'application/vnd.api+json'], '{"data":{"id":7,"attributes":{"title":"Article"}}}');
	}
};
$updateCalls = 0;
$api = new ApiHandler($http, $builder, $settings, 'api-test-token', static function () use (&$updateCalls): string
{
	$updateCalls++;

	return 'update-test-token';
});
$result = $api->execute(['id' => 7], $read, $principal);
$check($result['data']['data']['id'] === 7 && (string) $http->requests[0]->getUri() === 'https://joomla.example/sub/api/index.php/v1/content/articles/7', 'Same-site API URL and response contract.');
$check($http->requests[0]->getHeaderLine('Authorization') === 'Bearer api-test-token', 'Authenticated API token forwarding.');
$http->responses[] = new Response(302, ['Location' => 'https://other.example/steal']);
$reject(static fn () => $api->execute(['id' => 7], $read, $principal), 'REDIRECT_REFUSED');
$check(count($http->requests) === 2, 'Redirect response never causes a second credential-bearing request.');
$http->responses[] = new Response(403, [], 'SECRET MUST NOT BE DISCLOSED');
$reject(static fn () => $api->execute(['id' => 7], $read, $principal), 'JOOMLA_API_ERROR');
$update = $catalogue->action('joomla-update.status')['binding'];
$principal->deny('core.admin', 'com_joomlaupdate');
$reject(static fn () => $api->execute([], $update, $principal), 'DEFINITION_UNAVAILABLE');
$check($updateCalls === 0, 'Update token is not even resolved before root authorization.');
$root = new Principal('joomla:18', 'api', [1]);
$api->execute([], $update, $root);
$last = $http->requests[array_key_last($http->requests)];
$check($last->getHeaderLine('X-JUpdate-Token') === 'update-test-token' && $last->getHeaderLine('Authorization') === '', 'Core update uses its own token with no API token leakage.');
$check(ApiHandler::selectSafeFields(['data' => [['attributes' => ['sitename' => 'Site', 'password' => 'never']], ['attributes' => ['key' => 'debug', 'value' => false]]]], ['sitename', 'debug']) === ['sitename' => 'Site', 'debug' => false], 'Safe configuration projection strips secrets across API shapes.');

$now = 1800000000;
$clock = static function () use (&$now): int
{
	return $now;
};
$audit = new Audit($store, $principal, $clock);
$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
$envelope = new Envelope(str_repeat('test-secret-', 4));
$executions = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
$request = $permissions->request(['toolsets' => ['content.write'], 'duration' => 'once', 'reason' => 'Create one test article']);
$reject(static fn () => $permissions->approve($request['requestId'], 'not the phrase'), 'PERMISSION_REQUEST_UNAVAILABLE');
$otherPrincipal = new Principal('joomla:19', 'api', [1]);
$otherAudit = new Audit($store, $otherPrincipal, $clock);
$otherPermissions = new Permissions($store, $otherPrincipal, $catalogue, $settings, $otherAudit, $clock);
$reject(static fn () => $otherPermissions->approve($request['requestId'], $request['acknowledgement']), 'PERMISSION_REQUEST_UNAVAILABLE');
$grant = $permissions->approve($request['requestId'], $request['acknowledgement']);
$check($grant['remainingUses'] === 1 && $grant['expiresAt'] === null, 'One-operation grant contract.');
$reject(static fn () => $permissions->approve($request['requestId'], $request['acknowledgement']), 'PERMISSION_REQUEST_UNAVAILABLE');
$resolved = $catalogue->action('content.articles.create');
$payload = ['input' => ['data' => ['title' => 'Secret test title', 'catid' => 2]], 'before' => null];
$preview = ['site' => 'default', 'action' => 'content.articles.create', 'method' => 'POST', 'summary' => 'Create article'];
$plan = $executions->plan($resolved, $payload, $preview, Json::uuid(), $grant['id']);
$check(!str_contains($plan['confirmationToken'], 'Secret'), 'Confirmation never contains input.');
$stored = $executions->resolve($plan['confirmationToken']);
$check($stored['payload'] === $payload && !str_contains($stored['input_cipher'], 'Secret'), 'Durable sensitive state is authenticated and encrypted.');
$otherExecutions = new Executions($store, $otherPrincipal, $envelope, $otherPermissions, $settings, $otherAudit, $clock);
$reject(static fn () => $otherExecutions->resolve($plan['confirmationToken']), 'PLAN_UNAVAILABLE');
$execution = $executions->claim($stored, $resolved);
$check($permissions->list() === [], 'One-shot grant consumed by the execution claim.');
$reject(static fn () => $executions->claim($stored, $resolved), 'PLAN_STALE');
$reject(static fn () => $executions->previous($stored), 'EXECUTION_UNCERTAIN');
$result = $executions->finish($execution, ['applied' => true, 'verified' => true], true, $resolved['action']['name']);
$check($result['executionId'] === $execution['uuid'] && $executions->previous($stored)['replayed'] === true, 'Completed execution returns recorded result without a second mutation.');
$check($store->find('lease') === [], 'Verified completion releases only its owned lease.');
$secondRequest = $permissions->request(['toolsets' => ['content.write'], 'duration' => '30-minutes', 'reason' => 'Test revocation and recovery']);
$secondGrant = $permissions->approve($secondRequest['requestId'], $secondRequest['acknowledgement']);
$plan2 = $executions->plan($resolved, $payload, $preview, Json::uuid(), $secondGrant['id']);
$stored2 = $executions->resolve($plan2['confirmationToken']);
$permissions->revoke($secondGrant['id']);
$reject(static fn () => $executions->claim($stored2, $resolved), 'GRANT_UNAVAILABLE');
$check($store->find('lease') === [] && $store->one('plan', ['uuid' => $stored2['uuid']])['status'] === 'pending', 'Revocation failure rolls back lease and plan claim.');
$now += 601;
$reject(static fn () => $executions->claim($stored2, $resolved), 'PLAN_STALE');
$indefinite = ['toolsets' => ['content.write'], 'duration' => 'indefinite', 'reason' => 'Disabled by default'];
$reject(static fn () => $permissions->request($indefinite), 'INVALID_PERMISSION_REQUEST');
$thirdRequest = $permissions->request(['toolsets' => ['content.write'], 'duration' => 'once', 'reason' => 'Test uncertain execution']);
$thirdGrant = $permissions->approve($thirdRequest['requestId'], $thirdRequest['acknowledgement']);
$plan3 = $executions->plan($resolved, $payload, $preview, Json::uuid(), $thirdGrant['id']);
$stored3 = $executions->resolve($plan3['confirmationToken']);
$execution3 = $executions->claim($stored3, $resolved);
$executions->finish($execution3, ['applied' => null, 'verified' => false, 'reconciliationRequired' => true], false, $resolved['action']['name']);
$check(count($store->find('lease')) === 1, 'Uncertain effects retain the write lease for reconciliation.');
$now += 99999;
$check(count($store->find('lease')) === 1, 'Lease expiry never silently authorizes another potentially overlapping mutation.');
$log = Json::encode($store->find('audit', [], 10000));
$check(!str_contains($log, 'Secret test title') && !str_contains($log, 'api-test-token') && !str_contains($log, $request['acknowledgement']), 'Audit contains no raw arguments, credentials or acknowledgement phrases.');
echo Json::encode(['checks' => $checks, 'apiContracts' => 'passed with a recording transport', 'durableStateContracts' => 'passed with transactional memory double', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
restore_error_handler();
