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
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiHandler;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\HandlerRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\JoomlaMessageSnapshot;
use VDM\Component\JoomEngineMcp\Administrator\Service\MessageSnapshot;
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
$reject = static function (callable $call, ?string $code = null) use ($check): array
{
	try
	{
		$call();
	}
	catch (OperationException $error)
	{
		if ($code !== null)
		{
			$check($error->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $error->getIdentifier());
		}

		return $error->toArray();
	}

	throw new RuntimeException('Expected rejection ' . ($code ?? 'without private message disclosure'));
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];

/** Real executor/state boundaries around a deliberately side-effecting HTTP double and independent persisted-row loader. */
$fixture = static function (int $state = 0, bool $withSnapshots = true) use ($seed): array
{
	$http = new class implements ClientInterface
	{
		/** @var array<int,array<string,mixed>> Persisted native-shaped test rows. */
		public array $records = [];
		/** @var array<int,array<string,mixed>> Requests without authentication headers. */
		public array $requests = [];
		/** @var int Number of submitted native mutations. */
		public int $writes = 0;
		/** @var int The observed Joomla missing-message response is not a reliable 404. */
		public int $missingStatus = 500;

		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$method = $request->getMethod();
			$path = $request->getUri()->getPath();
			$id = (int) basename($path);
			parse_str($request->getUri()->getQuery(), $query);
			$this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => (string) $request->getBody()];

			if ($method === 'GET')
			{
				if (!isset($this->records[$id]))
				{
					return new Response($this->missingStatus);
				}

				// Model the native GET's observable side effect, not a pure HTTP read.
				$item = $this->records[$id];
				$this->records[$id]['state'] = 1;
			}
			elseif ($method === 'DELETE')
			{
				$this->writes++;
				unset($this->records[$id]);

				return new Response(204);
			}
			else
			{
				$this->writes++;
				$this->records[$id] = array_replace($this->records[$id] ?? [], Json::decode((string) $request->getBody()));
				$item = $this->records[$id];
			}

			unset($item['private_internal']);

			return new Response(200, ['Content-Type' => 'application/vnd.api+json'], Json::encode([
				'data' => ['type' => 'messages', 'id' => (string) $id, 'attributes' => $item],
			]));
		}
	};
	$http->records[87] = ['message_id' => 87, 'user_id_from' => 23, 'user_id_to' => 17, 'folder_id' => 0,
		'date_time' => '2026-10-01 10:00:00', 'state' => $state, 'priority' => 0,
		'subject' => 'Private fixture subject', 'message' => 'Private fixture body', 'private_internal' => 'not-a-snapshot-field'];
	$loader = new stdClass();
	$loader->calls = [];
	$snapshots = new MessageSnapshot(static function (int $id, PrincipalInterface $principal) use ($http, $loader): array
	{
		$loader->calls[] = ['id' => $id, 'principal' => $principal->getId(), 'track' => $principal->getTrack()];

		return $http->records[$id] ?? [];
	});
	$store = new MemoryStore($seed);
	$principal = new Principal('joomla:17', 'api', [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$executions = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]),
		$permissions, $executions, $audit, $settings, $builder, null, null, $withSnapshots ? $snapshots : null);
	$request = $permissions->request(['toolsets' => ['users.admin'], 'duration' => '30-minutes', 'reason' => 'Message planning purity contract']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);

	return compact('http', 'loader', 'snapshots', 'store', 'principal', 'catalogue', 'executor', 'executions');
};

/** Change one reviewed binding in persistence, then refresh as the protocol boundary would. */
$configure = static function (array $f, string $action, array $changes = [], array $remove = []): void
{
	$binding = $f['store']->one('binding', ['name' => $action . '.api']);
	$config = array_replace(Json::decode($binding['configuration']), $changes);

	foreach ($remove as $key)
	{
		unset($config[$key]);
	}

	$f['store']->update('binding', ['configuration' => Json::encode($config)], ['id' => $binding['id']]);
	$f['catalogue']->refresh();
};

// Dry and executable planning preserve every native state, including trashed messages.
foreach ([0, 1, -2] as $state)
{
	foreach (['update', 'delete'] as $operation)
	{
		$f = $fixture($state);
		$original = $f['http']->records;
		$input = ['id' => 87] + ($operation === 'update' ? ['data' => ['subject' => 'Approved replacement']] : []);
		$dry = $f['executor']->plan('messages.messages.' . $operation, $input, Json::uuid(), true);
		$check($dry['dryRun'] && $f['store']->find('plan') === [], 'Dry planning must not create an executable message plan.');
		$plan = $f['executor']->plan('messages.messages.' . $operation, $input, Json::uuid());
		$check($f['http']->records === $original && $f['http']->requests === [] && $f['http']->writes === 0,
			'Planning ' . $operation . ' changed native message state ' . $state . ' or called the public GET.');
		$check($f['loader']->calls === array_fill(0, 2, ['id' => 87, 'principal' => 'joomla:17', 'track' => 'api']),
			'Both plans must load only the requested record under the authenticated principal.');
		$before = $f['executions']->resolve($plan['confirmationToken'])['payload']['before'];
		$expected = $original[87];
		unset($expected['private_internal'], $expected['message_id']);
		$expected['id'] = 87;
		$check(Json::canonical($before['item']) === Json::canonical($expected) && $before['etag'] === null
			&& $before['snapshotContract'] === MessageSnapshot::CONTRACT
			&& $before['readRevision'] === $f['catalogue']->action('messages.messages.get')['revision'],
			'The frozen snapshot must contain only explicit persisted fields, the ID alias, and the reviewed read revision.');
		$public = Json::encode([$dry, $plan, $f['store']->find('audit')]);
		$check(!str_contains($public, 'Private fixture') && !str_contains($public, 'not-a-snapshot-field')
			&& !str_contains($public, 'snapshotContract') && !str_contains($public, 'readRevision'),
			'Message contents and internal snapshot metadata leaked into public previews or audit.');
	}
}

// The regression reaches exactly one DELETE; the unreliable missing-item 500 remains uncertain.
$f = $fixture();
$plan = $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid());
$result = $f['executor']->apply($plan['confirmationToken']);
$check(array_column($f['http']->requests, 'method') === ['DELETE', 'GET'] && $f['http']->writes === 1
	&& count($f['loader']->calls) === 2 && !isset($f['http']->records[87]), 'Delete must recheck its pure snapshot, then mutate and verify only through the API.');
$check($result['mutation']['status'] === 204 && $result['verification']['status'] === 'uncertain'
	&& $result['error']['details']['httpStatus'] === 500 && $f['store']->find('lease') !== [],
	'Native missing-message 500 must retain the accepted DELETE and uncertain lease, without inventing absence proof.');
$execution = $f['store']->one('execution', ['uuid' => $result['executionId']]);
$check($execution['status'] === 'uncertain', 'The persisted execution must retain the uncertain outcome.');
$requests = $f['http']->requests;
$loads = $f['loader']->calls;
$replay = $f['executor']->apply($plan['confirmationToken']);
$check($replay['idempotentReplay'] && $replay['verification'] === $result['verification']
	&& $f['http']->requests === $requests && $f['loader']->calls === $loads && $f['http']->writes === 1,
	'Uncertain replay must return recorded evidence without another snapshot, GET, or DELETE.');

// Ordinary reliable API verification and update writes still use the public handler.
$f = $fixture(-2);
$f['http']->missingStatus = 404;
$plan = $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid());
$result = $f['executor']->apply($plan['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $f['store']->find('lease') === []
	&& array_column($f['http']->requests, 'method') === ['DELETE', 'GET'], 'A genuine native 404 must keep normal delete settlement.');
$f = $fixture();
$plan = $f['executor']->plan('messages.messages.update', ['id' => 87, 'data' => ['subject' => 'Approved replacement']], Json::uuid());
$result = $f['executor']->apply($plan['confirmationToken']);
$check($result['verification']['status'] === 'verified' && $f['http']->writes === 1
	&& array_column($f['http']->requests, 'method') === ['PATCH', 'GET']
	&& $f['http']->records[87]['subject'] === 'Approved replacement', 'Pure planning must not replace native PATCH or independent public GET verification.');

// Genuine intervening changes still invalidate the approved snapshot before mutation.
foreach (['state' => 1, 'message' => 'Changed private fixture body', 'priority' => 2] as $field => $value)
{
	$f = $fixture();
	$plan = $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid());
	$f['http']->records[87][$field] = $value;
	$reject(static fn () => $f['executor']->apply($plan['confirmationToken']), 'PRECONDITION_CHANGED');
	$check($f['http']->requests === [] && $f['store']->find('execution') === [] && $f['store']->find('lease') === [],
		'Changed ' . $field . ' must invalidate before mutation intent or API I/O.');
}

// Unknown IDs and messages addressed to another recipient have identical public failures.
$errors = [];
foreach (['missing', 'other-recipient'] as $case)
{
	$f = $fixture();
	if ($case === 'missing')
	{
		unset($f['http']->records[87]);
	}
	else
	{
		$f['http']->records[87]['user_id_to'] = 99;
	}
	$errors[] = $reject(static fn () => $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid()), 'DEFINITION_UNAVAILABLE');
	$check($f['http']->requests === [] && $f['store']->find('plan') === []
		&& !str_contains(Json::encode($errors), 'Private fixture'), 'Inaccessible messages must not create plans, call GET, or disclose content.');
}
$check($errors[0] === $errors[1], 'Missing and foreign-recipient messages must have indistinguishable public failures.');
$f = $fixture();
$plan = $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid());
$f['http']->records[87]['user_id_to'] = 99;
$error = $reject(static fn () => $f['executor']->apply($plan['confirmationToken']), 'DEFINITION_UNAVAILABLE');
$check($error === $errors[0] && $f['http']->requests === [] && $f['store']->find('execution') === [],
	'Changed recipient must invalidate without revealing the reassigned message or mutating it.');

// Loader output is untrusted until both exact identities and the persisted-field shape agree.
foreach ([['message_id', 88], ['message_id', '087'], ['user_id_to', '017']] as [$field, $value])
{
	$f = $fixture();
	$f['http']->records[87][$field] = $value;
	$error = $reject(static fn () => $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid()), 'DEFINITION_UNAVAILABLE');
	$check($error === $errors[0] && $f['http']->requests === [] && $f['store']->find('plan') === [],
		'A mismatched or broadly coerced native identity must not become an owned snapshot.');
}
$f = $fixture();
unset($f['http']->records[87]['message']);
$reject(static fn () => $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid()), 'SNAPSHOT_UNAVAILABLE');
$check($f['http']->requests === [] && $f['store']->find('plan') === [], 'Incomplete native rows must not silently weaken snapshot comparison.');
$f = $fixture();
$f['http']->records[87]['message_id'] = '87';
$f['http']->records[87]['user_id_to'] = '17';
$snapshot = $f['snapshots']->capture(['id' => 87], $f['principal'], 'reviewed-read-revision');
$check($snapshot['item']['id'] === 87 && $snapshot['item']['user_id_to'] === '17', 'Canonical native database string IDs must retain exact ownership compatibility.');

// The helper cannot be used to create local authority or coerce unvalidated IDs.
$f = $fixture();
foreach ([0, -1, '87', true] as $id)
{
	$reject(static fn () => $f['snapshots']->capture(['id' => $id], $f['principal'], 'revision'), 'DEFINITION_UNAVAILABLE');
}
$reject(static fn () => $f['snapshots']->capture(['id' => 87], new Principal('joomla:17', 'cli'), 'revision'), 'DEFINITION_UNAVAILABLE');
$reject(static fn () => $f['snapshots']->capture(['id' => 87], new Principal('anonymous', 'api'), 'revision'), 'DEFINITION_UNAVAILABLE');
$check($f['loader']->calls === [], 'Invalid or non-API authority must be rejected before native table I/O.');

// Explicit opt-in on both stock bindings is mandatory; configuration stays authoritative.
foreach ([
	'write marker absent' => ['messages.messages.delete', [], ['snapshot_contract']],
	'read marker absent' => ['messages.messages.get', [], ['snapshot_contract']],
	'wrong contract' => ['messages.messages.get', ['snapshot_contract' => 'unreviewed.contract'], []],
	'custom write route' => ['messages.messages.delete', ['route' => '/v1/custom-messages/:id'], []],
	'custom read route' => ['messages.messages.get', ['route' => '/v1/custom-messages/:id'], []],
	'configured read action' => ['messages.messages.delete', ['read_action' => 'users.users.get'], []],
	'query defaults' => ['messages.messages.get', ['query_defaults' => ['filter[recipient]' => 17]], []],
	'query mapping' => ['messages.messages.get', ['query_map' => ['id' => 'filter[id]']], []],
	'field selection' => ['messages.messages.get', ['select_fields' => ['message_id', 'state']], []],
] as $case => [$action, $changes, $remove])
{
	$f = $fixture();
	$configure($f, $action, $changes, $remove);
	$write = $f['catalogue']->action('messages.messages.delete');
	$read = $f['catalogue']->action('messages.messages.get');
	$check(!MessageSnapshot::supports($write, $read, $f['catalogue']->get('provider', 'joomla.core')), $case . ' acquired the native snapshot contract.');
	$f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid(), true);
	$check($f['loader']->calls === [] && array_column($f['http']->requests, 'method') === ['GET'],
		$case . ' must retain the configured generic HTTP path, not bypass it with a table snapshot.');
}
$f = $fixture();
$configure($f, 'messages.messages.get', ['authentication' => 'unregistered-authentication']);
$reject(static fn () => $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid(), true), 'BINDING_INVALID');
$check($f['loader']->calls === [] && $f['http']->requests === [], 'A custom authentication restriction must never be bypassed by the native loader.');

foreach (['action', 'binding', 'provider'] as $entity)
{
	$f = $fixture();
	$name = match ($entity)
	{
		'action' => 'messages.messages.get',
		'binding' => 'messages.messages.delete.api',
		'provider' => 'joomla.core',
	};
	$f['store']->update($entity, ['customized' => 1], ['name' => $name]);
	$f['catalogue']->refresh();
	$f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid(), true);
	$check($f['loader']->calls === [] && array_column($f['http']->requests, 'method') === ['GET'],
		'Customized ' . $entity . ' must not inherit stock snapshot authority.');
}
$f = $fixture();
$f['store']->update('provider', ['name' => 'unreviewed.messages', 'extension' => 'com_unreviewed'], ['name' => 'joomla.core']);
$f['catalogue']->refresh();
$f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid(), true);
$check($f['loader']->calls === [] && array_column($f['http']->requests, 'method') === ['GET'], 'A foreign provider with identical action names must not obtain native table access.');
$f = $fixture(0, false);
$f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid(), true);
$check($f['loader']->calls === [] && array_column($f['http']->requests, 'method') === ['GET'], 'Existing executor compositions without a snapshot service must retain their generic path.');

// Stored ownership hashes detect unflagged edits outside the binding's semantic keys.
foreach (['provider', 'action', 'binding', 'schema'] as $entity)
{
	$f = $fixture();
	$read = $f['catalogue']->action('messages.messages.get');
	$id = match ($entity)
	{
		'provider' => $read['action']['provider_id'],
		'action' => $read['action']['id'],
		'binding' => $read['binding']['id'],
		'schema' => $read['binding']['input_schema_id'],
	};
	$f['store']->update($entity, ['title' => 'Unflagged administrator customization'], ['id' => $id]);
	$f['catalogue']->refresh();
	$f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid(), true);
	$check($f['loader']->calls === [] && array_column($f['http']->requests, 'method') === ['GET'],
		'Unflagged ' . $entity . ' edits must retain the configured HTTP path even when snapshot markers still match.');
}

// Approval cannot silently switch an existing generic precondition to a native table source.
$f = $fixture();
$originalRead = $f['store']->one('binding', ['name' => 'messages.messages.get.api']);
$configure($f, 'messages.messages.get', [], ['snapshot_contract']);
$plan = $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid());
$requests = $f['http']->requests;
$f['store']->update('binding', ['configuration' => $originalRead['configuration']], ['id' => $originalRead['id']]);
$f['catalogue']->refresh();
$reject(static fn () => $f['executor']->apply($plan['confirmationToken']), 'PLAN_STALE');
$check($f['loader']->calls === [] && $f['http']->requests === $requests && $f['store']->find('execution') === [],
	'Enabling native snapshots after generic planning requires a fresh plan before any additional I/O.');

// An explicit catalogue permission is authoritative even when the table contract is stock.
foreach ([false, true] as $afterPlanning)
{
	$f = $fixture();
	$read = $f['store']->one('binding', ['name' => 'messages.messages.get.api']);
	$definition = Json::decode($read['definition']);
	$definition['required_permissions'] = [['action' => 'core.manage', 'asset' => 'com_messages']];
	// Model an explicitly reviewed seed with this permission, rather than an unflagged edit.
	$read['definition'] = Json::encode($definition);
	$read['seed_hash'] = SeedUpdater::hash('binding', $read);
	$f['store']->update('binding', ['definition' => $read['definition'], 'seed_hash' => $read['seed_hash']], ['id' => $read['id']]);
	$f['catalogue']->refresh();
	$plan = $afterPlanning ? $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid()) : null;
	$f['principal']->deny('core.manage', 'com_messages');
	$f['catalogue']->refresh();
	$reject(static fn () => $afterPlanning ? $f['executor']->apply($plan['confirmationToken'])
		: $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid()), 'DEFINITION_UNAVAILABLE');
	$check(count($f['loader']->calls) === ($afterPlanning ? 1 : 0) && $f['http']->requests === []
		&& $f['store']->find('execution') === [], 'Declared native read permissions must be checked before each snapshot without bypass or fallback.');
}

// A frozen native snapshot must not fall back to a side-effecting GET after read drift.
foreach (['revision', 'route', 'marker', 'hidden', 'acl', 'schema'] as $change)
{
	$f = $fixture();
	$plan = $f['executor']->plan('messages.messages.delete', ['id' => 87], Json::uuid());
	$read = $f['catalogue']->action('messages.messages.get');
	$code = 'PLAN_STALE';

	if ($change === 'revision')
	{
		$f['store']->update('binding', ['version' => $read['binding']['version'] + 1], ['id' => $read['binding']['id']]);
	}
	elseif ($change === 'route' || $change === 'marker')
	{
		$configure($f, 'messages.messages.get', $change === 'route' ? ['route' => '/v1/custom-messages/:id'] : [],
			$change === 'marker' ? ['snapshot_contract'] : []);
	}
	elseif ($change === 'hidden')
	{
		$f['store']->update('action', ['published' => 0], ['id' => $read['action']['id']]);
		$code = 'DEFINITION_UNAVAILABLE';
	}
	elseif ($change === 'acl')
	{
		$f['principal']->deny('mcp.execute', $read['binding']['asset_name']);
		$code = 'DEFINITION_UNAVAILABLE';
	}
	else
	{
		$schema = $f['store']->one('schema', ['id' => $read['binding']['input_schema_id']]);
		$document = Json::decode($schema['document']);
		$document['description'] = 'Changed read contract after approval';
		$f['store']->update('schema', ['document' => Json::encode($document)], ['id' => $schema['id']]);
	}

	$f['catalogue']->refresh();
	$reject(static fn () => $f['executor']->apply($plan['confirmationToken']), $code);
	$check(count($f['loader']->calls) === 1 && $f['http']->requests === [] && $f['http']->records[87]['state'] === 0
		&& $f['store']->find('execution') === [] && $f['store']->find('lease') === [],
		'Read ' . $change . ' changed after approval: reject before another table read, public GET, or mutation intent.');
}

// Ordinary public GET retains the native mark-read behavior and never uses the planner's loader.
$f = $fixture();
$result = $f['executor']->read('messages.messages.get', ['id' => 87]);
$check($result['response']['status'] === 200 && $f['http']->records[87]['state'] === 1
	&& array_column($f['http']->requests, 'method') === ['GET'] && $f['loader']->calls === [],
	'The private planning snapshot must not redefine the public messages GET contract.');

// Pure origin/mount proof uses this existing fixture file as the trusted entrypoint inode.
$server = ['SCRIPT_FILENAME' => __FILE__, 'SCRIPT_NAME' => '/api/index.php', 'HTTP_HOST' => 'joomla.example', 'HTTPS' => 'on'];
foreach ([
	'local origin' => ['https://joomla.example/api/index.php', [], true],
	'explicit default port' => ['https://joomla.example:443/api/index.php', [], true],
	'host default port' => ['https://joomla.example/api/index.php', ['HTTP_HOST' => 'joomla.example:443'], true],
	'path info' => ['https://joomla.example/api/index.php', ['SCRIPT_NAME' => '/api/index.php/v1/mcp'], true],
	'local mounted installation' => ['https://joomla.example/site/api/index.php', ['SCRIPT_NAME' => '/site/api/index.php'], true],
	'local HTTP' => ['http://joomla.example/api/index.php', ['HTTPS' => 'off'], true],
	'remote host' => ['https://remote.example/api/index.php', [], false],
	'unproven TLS proxy' => ['https://joomla.example/api/index.php', ['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], false],
	'wrong mount' => ['https://joomla.example/other/api/index.php', [], false],
	'wrong script name' => ['https://joomla.example/api/index.php', ['SCRIPT_NAME' => '/administrator/index.php'], false],
	'wrong script file' => ['https://joomla.example/api/index.php', ['SCRIPT_FILENAME' => __DIR__ . '/write-verification.php'], false],
	'wrong scheme' => ['http://joomla.example/api/index.php', [], false],
	'wrong port' => ['https://joomla.example:8443/api/index.php', [], false],
	'forwarded host is not authority' => ['https://joomla.example/api/index.php', ['HTTP_HOST' => 'internal.example', 'HTTP_X_FORWARDED_HOST' => 'joomla.example'], false],
] as $case => [$base, $changes, $expected])
{
	$check(JoomlaMessageSnapshot::sameInstallation($base, array_replace($server, $changes), __FILE__) === $expected,
		'Native table routing must prove the executing installation: ' . $case . '.');
}

echo Json::encode(['checks' => $checks, 'messageSnapshots' => 'passed with side-effecting API, persisted-row loader, and transactional state doubles',
	'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
restore_error_handler();
