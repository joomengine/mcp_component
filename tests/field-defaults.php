<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
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


require dirname(__DIR__) . '/admin/autoload.php';
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
	/** @var array Most recent mutation body. */
	public array $body = [];
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
		$data = Json::decode((string) $request->getBody());
		$this->body = $data;
		if ($request->getMethod() === 'POST')
		{
			$id = count($this->items) + 1;
		}
		$this->items[$id] = array_replace($this->items[$id] ?? [], $data);
		return new Response(200, [], Json::encode(['data' => ['id' => (string) $id, 'attributes' => $this->items[$id]]]));
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
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
$input = ['data' => ['title' => 'New field', 'type' => 'text']];
$reject(static fn () => $executor->plan('fields.content-articles.create', $input, Json::uuid()), 'PERMISSION_REQUIRED');
$check($http->writes === 0, 'Field normalization bypassed the explicit write grant.');
$permission = $permissions->request(['toolsets' => ['structure.write', 'users.admin'], 'duration' => '30-minutes', 'reason' => 'Custom field default regression']);
$permissions->approve($permission['requestId'], $permission['acknowledgement']);
$actions = array_values(array_filter($seed['action'], static fn (array $action): bool => str_starts_with($action['name'], 'fields.') && str_ends_with($action['name'], '.create')));
$check(count($actions) === 6, 'All six reviewed field-create contexts must be covered.');

foreach ($actions as $action)
{
	$name = $action['name'];
	$config = $catalogue->action($name)['binding']['configuration'];
	$built = $builder->build($input, $config);
	$check($built['body']['default_value'] === '' && !array_key_exists('default_value', $input['data']), $name . ' failed to default the effective request without mutating input.');
	$writes = $http->writes;
	$dry = $executor->plan($name, $input, Json::uuid(), true);
	$check($dry['dryRun'] && $http->writes === $writes, $name . ' dry-run persisted a field.');
	$plan = $executor->plan($name, $input, Json::uuid());
	$check($http->writes === $writes, $name . ' planning performed a write.');
	$result = $executor->apply($plan['confirmationToken']);
	$id = count($http->items);
	$check($http->body['default_value'] === '' && $http->items[$id]['default_value'] === '', $name . ' saved a missing or null default.');
	$check($result['verification']['status'] === 'verified' && in_array('title', $result['verification']['matchedFields'], true), $name . ' lost its independent field read-back.');
	$check($executor->apply($plan['confirmationToken'])['idempotentReplay'] && $http->writes === $writes + 1, $name . ' replay persisted a duplicate field.');

	foreach (['', '0', 'Chosen default', '["one","two"]', 'one,two', null, 0, false, ['one', 'two']] as $value)
	{
		$plan = $executor->plan($name, ['data' => $input['data'] + ['default_value' => $value]], Json::uuid());
		$executor->apply($plan['confirmationToken']);
		$check(array_key_exists('default_value', $http->body) && $http->body['default_value'] === $value, $name . ' replaced an explicit default.');
	}

	$update = substr($name, 0, -6) . 'update';
	foreach (['Existing default', null] as $existing)
	{
		$http->items[$id]['default_value'] = $existing;
		$plan = $executor->plan($update, ['id' => $id, 'data' => ['title' => 'Renamed field']], Json::uuid());
		$executor->apply($plan['confirmationToken']);
		$check(!array_key_exists('default_value', $http->body) && $http->items[$id]['default_value'] === $existing, $update . ' changed an omitted default.');
	}
	$plan = $executor->plan($update, ['id' => $id, 'data' => ['default_value' => '']], Json::uuid());
	$executor->apply($plan['confirmationToken']);
	$check($http->items[$id]['default_value'] === '', $update . ' did not retain explicit clearing.');
	$reject(static fn () => $executor->plan($name, ['data' => []], Json::uuid()), 'INVALID_INPUT');
	$reject(static fn () => $executor->plan($name, ['data' => $input['data'] + ['unreviewed' => 'value']], Json::uuid()), 'INVALID_INPUT');
}

foreach (['field-groups.content-articles.create', 'content.articles.create'] as $name)
{
	$config = $catalogue->action($name)['binding']['configuration'];
	$check(!array_key_exists('default_value', $builder->build(['data' => ['title' => 'Unrelated entity']], $config)['body']), $name . ' acquired a custom-field default.');
}

echo Json::encode(['checks' => $checks, 'fieldDefaultContracts' => 'passed with recording API and transactional memory doubles', 'liveJoomla' => 'installed field-defaults suite']) . PHP_EOL;
