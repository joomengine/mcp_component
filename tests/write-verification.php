<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
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
use VDM\Component\JoomEngineMcp\Administrator\Service\ApiWriteVerification;
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
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];

/** Complete action lifecycle around deliberately independent mutation/read responses. */
$fixture = static function (array $record, ?array $mutation = null) use ($seed): array
{
	$http = new class implements ClientInterface
	{
		/** @var array Native independently read resource. */
		public array $record = [];
		/** @var array Native accepted mutation resource. */
		public array $mutation = [];
		/** @var array Captured method, path and inert body, without headers or credentials. */
		public array $requests = [];
		/** @var int Mutations performed by the recording double. */
		public int $writes = 0;
		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$method = $request->getMethod();
			$this->requests[] = ['method' => $method, 'path' => $request->getUri()->getPath(), 'body' => (string) $request->getBody()];
			$item = $this->record;

			if ($method !== 'GET')
			{
				$this->writes++;
				$item = $this->mutation;
			}

			$id = $item['id'];
			unset($item['id']);

			return new Response(200, ['Content-Type' => 'application/vnd.api+json'], Json::encode(['data' => ['id' => $id, 'attributes' => $item]]));
		}
	};
	$http->record = $record + ['id' => 87];
	$http->mutation = ($mutation ?? $record) + ['id' => 87];
	$store = new MemoryStore($seed);
	$principal = new Principal('joomla:17', 'api', [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder);
	$request = $permissions->request(['toolsets' => ['content.write', 'structure.write', 'users.admin'], 'duration' => '30-minutes', 'reason' => 'Native field verification contracts']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);

	return compact('http', 'store', 'catalogue', 'executor', 'state');
};

/** Every scenario checks settlement, owned lease retention/release, and no replay I/O. */
$run = static function (string $action, array $data, array $record, string $status, ?array $mutation = null) use ($fixture, $check): array
{
	$f = $fixture($record, $mutation);
	$input = ['data' => $data] + (str_ends_with($action, '.update') ? ['id' => 87] : []);
	$dry = $f['executor']->plan($action, $input, Json::uuid(), true);
	$check($f['http']->writes === 0 && $f['store']->find('plan') === [], 'Dry planning must not mutate or create an executable plan.');
	$plan = $f['executor']->plan($action, $input, Json::uuid());
	$check($f['http']->writes === 0, 'Approved plan creation must not mutate.');
	$result = $f['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === $status, $action . ' expected ' . $status . ': ' . Json::encode($result['verification']));
	$check($f['http']->writes === 1, 'A plan must perform precisely one native mutation.');
	$check(($f['store']->find('lease') === []) === ($status !== 'uncertain'), 'Only settled native postconditions release the write lease.');
	$execution = $f['store']->one('execution', ['uuid' => $result['executionId']]);
	$check($execution['status'] === ($status === 'uncertain' ? 'uncertain' : 'completed'), 'Execution status must agree with field verification.');
	$count = count($f['http']->requests);
	$replay = $f['executor']->apply($plan['confirmationToken']);
	$check($replay['idempotentReplay'] && count($f['http']->requests) === $count && $f['http']->writes === 1, 'Replay of any recorded outcome must perform no second I/O.');

	return $f + compact('dry', 'plan', 'result');
};

// The four category APIs share the native empty params Registry contract.
foreach (['content.categories', 'banners.categories', 'contacts.categories', 'newsfeeds.categories'] as $resource)
{
	foreach (['create', 'update'] as $operation)
	{
		foreach ([[], new stdClass()] as $empty)
		{
			$f = $run($resource . '.' . $operation, ['title' => 'Category', 'params' => new stdClass()], ['title' => 'Category', 'params' => $empty], 'verified');
			$check(in_array('params', $f['result']['verification']['matchedFields'], true), 'An empty native category Registry must be verified as empty.');
			$stored = $f['state']->resolve($f['plan']['confirmationToken']);
			$check($stored['payload']['input']['data']['params'] instanceof stdClass, 'Verification must not rewrite the approved empty object into a list.');
		}
		foreach ([null, false, '', ['unexpected' => true], (object) ['0' => 'value']] as $observed)
		{
			$f = $run($resource . '.' . $operation, ['title' => 'Category', 'params' => new stdClass()], ['title' => 'Category', 'params' => $observed], 'uncertain');
			$check($f['result']['verification']['differentFields'] === ['params'], 'Only an actually empty Registry may satisfy an empty object request.');
		}
		$run($resource . '.' . $operation, ['title' => 'Category', 'params' => new stdClass()], ['title' => 'Changed category', 'params' => []], 'uncertain');
	}
}
$run('content.categories.create', ['title' => 'Category'], ['title' => 'Category', 'params' => []], 'verified');
$f = $run('content.categories.create', ['title' => 'Category', 'metadata' => new stdClass()], ['title' => 'Category'], 'partial');
$check($f['result']['verification']['unobservableFields'] === ['metadata'], 'An omitted native metadata field stays explicitly unobservable.');
foreach ([
	[['params' => ['enabled' => 1]], ['params' => ['enabled' => 0]]],
	[['params' => ['enabled' => 1]], ['params' => []]],
	[['params' => ['nested' => new stdClass()]], ['params' => ['nested' => []]]],
	[['params' => (object) ['0' => 'first']], ['params' => ['first']]],
	[['params' => ['first']], ['params' => (object) ['0' => 'first']]],
	[['params' => new stdClass()], ['params' => ['unexpected' => true]]],
	[['metadata' => new stdClass()], ['metadata' => []]],
] as [$desired, $observed])
{
	$f = $run('content.categories.create', ['title' => 'Category'] + $desired, ['title' => 'Category'] + $observed, 'uncertain');
	$check($f['result']['verification']['differentFields'] === array_keys($desired), 'Native normalization must preserve nonempty, nested and unrelated field differences.');
}
$run('content.categories.create', ['title' => 'Category', 'params' => new stdClass()], ['title' => 'Filtered title', 'params' => []], 'uncertain');
$run('content.categories.create', ['title' => 'Category', 'params' => ['first', 'second']], ['title' => 'Category', 'params' => ['second', 'first']], 'uncertain');

// Article bags have the same empty representation; unexposed attribs stay unobservable.
foreach (['create', 'update'] as $operation)
{
	$data = ['title' => 'Article', 'images' => new stdClass(), 'urls' => new stdClass(), 'metadata' => new stdClass()];
	$record = ['title' => 'Article', 'images' => [], 'urls' => [], 'metadata' => []];
	$f = $run('content.articles.' . $operation, $data, $record, 'verified');
	$check($f['result']['verification']['matchedFields'] === array_keys($data), 'Each independently visible empty article Registry is matched.');
	$f = $run('content.articles.' . $operation, $data + ['attribs' => new stdClass()], $record, 'partial');
	$check($f['result']['verification']['unobservableFields'] === ['attribs'], 'Unexposed article attributes must not become verified.');
	foreach (['images', 'urls', 'metadata'] as $field)
	{
		foreach ([null, false, '', ['unexpected' => true], (object) ['0' => 'value']] as $observed)
		{
			$f = $run('content.articles.' . $operation, $data, array_replace($record, [$field => $observed]), 'uncertain');
			$check($f['result']['verification']['differentFields'] === [$field], 'Nonempty and malformed article Registry read-backs remain different.');
		}
		foreach ([
			[['enabled' => 1], ['enabled' => 0]],
			[['nested' => new stdClass()], ['nested' => []]],
			[(object) ['0' => 'first'], ['first']],
			[['first', 'second'], ['second', 'first']],
		] as [$desired, $observed])
		{
			$run('content.articles.' . $operation, array_replace($data, [$field => $desired]), array_replace($record, [$field => $observed]), 'uncertain');
		}
	}
	$run('content.articles.' . $operation, $data, array_replace($record, ['title' => 'Changed article']), 'uncertain');
}

// Memberships are an exact set of positive IDs, not arbitrary array/object equivalence.
foreach ([
	[[2], (object) ['2' => 2]],
	[[2.0], (object) ['2' => 2]],
	[[2, 3], [2.0, 3.0]],
	[['2'], (object) ['2' => '2']],
	[[3, 2], (object) ['2' => '2', '3' => 3]],
	[[2, 3], [3, '2']],
	[[2, 2], (object) ['2' => 2]],
	[(object) ['2' => 2, '3' => '3'], ['3', 2]],
] as [$desired, $observed])
{
	foreach (['create', 'update'] as $operation)
	{
		$f = $run('users.users.' . $operation, ['name' => 'Blocked fixture', 'groups' => $desired, 'block' => 1],
			['name' => 'Blocked fixture', 'groups' => $observed, 'block' => '1'], 'verified');
		$check(in_array('groups', $f['result']['verification']['matchedFields'], true), 'The supported representations must verify the same exact memberships.');
	}
}
foreach ([[2], [2, 3, 8], (object) ['2' => 3], [true, 3], [2.5, 3], ['02', 3], ['2e0', 3], [0, 3], [-2, 3], [9007199254740992, 3],
	(object) ['0' => 2, '1' => 3], ['Registered', 3], null] as $observed)
{
	$f = $run('users.users.create', ['name' => 'Blocked fixture', 'groups' => [2, 3]], ['name' => 'Blocked fixture', 'groups' => $observed], 'uncertain');
	$check($f['result']['verification']['differentFields'] === ['groups'], 'Missing, additional, malformed or unauthorized memberships remain visible.');
}
$secret = 'unit-only-never-a-real-credential';
$f = $run('users.users.create', ['name' => 'Blocked fixture', 'groups' => [2], 'password' => $secret, 'password2' => $secret, 'requireReset' => 0],
	['name' => 'Blocked fixture', 'groups' => (object) ['2' => 2]], 'partial');
$check($f['result']['verification']['unobservableFields'] === ['password', 'password2', 'requireReset'], 'Native write-only and unexposed fields remain explicitly unobservable.');
$check(!str_contains(Json::encode($f['result']), $secret) && !str_contains(Json::encode($f['store']->find('audit')), $secret)
	&& !str_contains(Json::encode($f['plan']), $secret), 'Credentials must never appear in previews, diagnostics or audit records.');

// Automatic ordering is disclosed, independently verified, and never labelled a literal zero match.
foreach (['site', 'administrator'] as $client)
{
	foreach (['' => 1, 'populated-position' => 12] as $position => $assigned)
	{
		foreach ([[], ['ordering' => 0], ['ordering' => 0.0]] as $ordering)
		{
			$data = ['title' => 'Module', 'module' => 'mod_custom', 'position' => $position] + $ordering;
			$f = $run('modules.' . $client . '.create', $data, ['title' => 'Module', 'module' => 'mod_custom', 'position' => $position, 'ordering' => (string) $assigned], 'verified');
			$check($f['dry']['operation']['nativeOrdering']['mode'] === 'automatic' && $f['plan']['operation']['nativeOrdering']['mode'] === 'automatic', 'Dry and executable previews disclose native automatic order.');
			$check($f['result']['verification']['nativeOrdering']['value'] === $assigned
				&& !in_array('ordering', $f['result']['verification']['matchedFields'], true), 'The generated actual order is separate from matched requested fields.');
		}
	}
	foreach ([-3, 7, -3.0, 7.0] as $explicit)
	{
		$f = $run('modules.' . $client . '.create', ['title' => 'Module', 'ordering' => $explicit], ['title' => 'Module', 'ordering' => (string) $explicit], 'verified');
		$check(!isset($f['plan']['operation']['nativeOrdering']) && in_array('ordering', $f['result']['verification']['matchedFields'], true), 'Explicit signed nonzero ordering keeps literal comparison.');
		$run('modules.' . $client . '.create', ['title' => 'Module', 'ordering' => $explicit], ['title' => 'Module', 'ordering' => $explicit + 1], 'uncertain');
	}
}
foreach ([
	[['id' => 87, 'ordering' => 2], ['id' => 87, 'ordering' => 1]],
	[['id' => 88, 'ordering' => 1], ['id' => 87, 'ordering' => 1]],
	[['id' => 87], ['id' => 87, 'ordering' => 1]],
	[['id' => 87, 'ordering' => 1], ['id' => 87]],
	[['id' => 87, 'ordering' => 1.5], ['id' => 87, 'ordering' => 1.5]],
	[['id' => 87, 'ordering' => true], ['id' => 87, 'ordering' => true]],
] as [$record, $mutation])
{
	$f = $run('modules.site.create', ['title' => 'Module', 'ordering' => 0], $record + ['title' => 'Module'], 'uncertain', $mutation + ['title' => 'Module']);
	$check($f['result']['verification']['nativeOrdering']['status'] === 'uncertain', 'Missing, changed or malformed generated order and mismatched identities must not settle.');
}
$run('modules.site.update', ['title' => 'Module', 'ordering' => 0], ['title' => 'Module', 'ordering' => 1], 'uncertain');
$run('modules.site.create', ['title' => 'Module', 'ordering' => 4, 'params' => new stdClass()], ['title' => 'Module', 'ordering' => 4, 'params' => []], 'uncertain');

// Native exceptions require both reviewed write and independent read bindings.
$f = $fixture(['title' => 'Fixture']);
$category = $f['catalogue']->action('content.categories.create');
$categoryRead = $f['catalogue']->action('content.categories.get');
$user = $f['catalogue']->action('users.users.create');
$userRead = $f['catalogue']->action('users.users.get');
foreach ([['binding', 'track', 'cli'], ['binding', 'handler', 'fixture.handler'], ['configuration', 'route', '/v1/other/categories'],
	['configuration', 'method', 'PATCH'], ['configuration', 'operation', 'update'], ['configuration', 'read_action', 'other.get'],
	['configuration', 'authentication', 'other']] as [$level, $key, $value])
{
	$changed = $category;
	if ($level === 'binding')
	{
		$changed['binding'][$key] = $value;
	}
	else
	{
		$changed['binding']['configuration'][$key] = $value;
	}
	$check(ApiWriteVerification::compare($changed, $categoryRead, 'params', new stdClass(), []) === null, 'Customized write bindings must not gain the category exception.');
}
$changed = $categoryRead;
$changed['binding']['configuration']['route'] = '/v1/other/categories/:id';
$check(ApiWriteVerification::compare($category, $changed, 'params', new stdClass(), []) === null, 'Customized read bindings must not gain the category exception.');
$check(ApiWriteVerification::compare($category, $categoryRead, 'metadata', new stdClass(), []) === null, 'Empty metadata is not the category params contract.');
foreach (['banners.categories' => 'params', 'contacts.categories' => 'params', 'newsfeeds.categories' => 'params',
	'content.articles' => 'images'] as $resource => $field)
{
	$write = $f['catalogue']->action($resource . '.create');
	$read = $f['catalogue']->action($resource . '.get');
	foreach ([['track', 'cli'], ['handler', 'fixture.handler']] as [$key, $value])
	{
		$changed = $write;
		$changed['binding'][$key] = $value;
		$check(ApiWriteVerification::compare($changed, $read, $field, new stdClass(), []) === null, 'Empty Registry semantics require the native API handler.');
	}
	foreach (['route' => '/v1/other/:id', 'method' => 'POST', 'authentication' => 'other', 'select_fields' => [$field]] as $key => $value)
	{
		$changed = $read;
		$changed['binding']['configuration'][$key] = $value;
		$check(ApiWriteVerification::compare($write, $changed, $field, new stdClass(), []) === null, 'A modified read contract cannot gain empty Registry semantics.');
	}
	$check(ApiWriteVerification::compare($write, $read, 'attribs', new stdClass(), []) === null, 'The reviewed contract cannot verify unrelated or unexposed Registry fields.');
}
$check(ApiWriteVerification::compare($user, $userRead, 'params', [2], (object) ['2' => 2]) === null, 'Group membership semantics cannot apply to user params.');
$module = $f['catalogue']->action('modules.site.create');
$module['binding']['configuration']['body_defaults']['ordering'] = 7;
$check(ApiWriteVerification::automaticOrdering($module, ['data' => ['title' => 'Module']]) === null, 'A customized fixed ordering default must not be described as native automatic assignment.');
foreach (['site', 'administrator'] as $client)
{
	foreach (['0', false, null, [], 1.5] as $invalid)
	{
		$reject(static fn () => $f['executor']->plan('modules.' . $client . '.create', ['data' => ['title' => 'Module', 'ordering' => $invalid]], Json::uuid()), 'INVALID_INPUT');
	}
}
$check($f['http']->writes === 0 && $f['store']->find('plan') === [], 'Unsupported automatic-order inputs must be rejected before mutation or executable planning.');

echo Json::encode(['checks' => $checks, 'nativeFieldVerification' => 'passed with recording API and transactional state doubles', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
restore_error_handler();
