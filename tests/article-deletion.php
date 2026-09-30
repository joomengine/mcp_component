<?php
/**
 * @package    JoomEngine.Mcp
 * @created    30 September 2026
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
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];

/** Real executor and durable state boundaries with an explicitly recording API double. */
$fixture = static function (?callable $configure = null) use ($seed): array
{
	$http = new class implements ClientInterface
	{
		/** @var array<int,array> Simulated native articles. */
		public array $items = [41 => ['id' => 41, 'title' => 'Approved trashed article', 'state' => -2, 'catid' => 2]];
		/** @var array<int,array> Recorded outbound exchanges, without credentials. */
		public array $requests = [];
		/** @var bool Whether an approved DELETE was submitted. */
		public bool $deleted = false;
		/** @var bool Whether DELETE actually removes the article. */
		public bool $remove = true;
		/** @var int Native DELETE response. */
		public int $deleteStatus = 204;
		/** @var int Native missing item response after deletion. */
		public int $missingStatus = 500;
		/** @var bool Force item GET failure even if DELETE left a row. */
		public bool $failItem = false;
		/** @var bool Simulate collection omitting an inaccessible pre-mutation row. */
		public bool $hiddenBefore = false;
		/** @var ?ResponseInterface Override the post-mutation collection evidence. */
		public ?ResponseInterface $collectionAfter = null;
		/** @var ?Closure Simulate a current authorization/definition change after DELETE. */
		public ?Closure $afterDelete = null;
		/** @var ?int Retained row state after DELETE. */
		public ?int $retainedState = null;

		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$path = $request->getUri()->getPath();
			$id = ctype_digit(basename($path)) ? (int) basename($path) : null;
			parse_str($request->getUri()->getQuery(), $query);
			$this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'query' => $query, 'afterDelete' => $this->deleted];

			if ($request->getMethod() === 'GET' && $id === null)
			{
				if ($this->deleted && $this->collectionAfter !== null)
				{
					return $this->collectionAfter;
				}

				$target = (int) substr((string) ($query['filter']['search'] ?? ''), 3);
				$state = (int) ($query['filter']['state'] ?? 0);
				$items = (!$this->deleted && $this->hiddenBefore) || !isset($this->items[$target])
					|| $this->items[$target]['state'] !== $state ? [] : [$this->row($target)];

				return new Response(200, [], Json::encode(['data' => $items, 'meta' => ['total-pages' => count($items)], 'links' => ['self' => 'https://joomla.example/unused']]));
			}

			if ($request->getMethod() === 'GET')
			{
				if ((!isset($this->items[$id]) || $this->failItem) && $this->deleted)
				{
					return new Response($this->missingStatus);
				}

				return isset($this->items[$id]) ? new Response(200, [], Json::encode(['data' => $this->row($id)])) : new Response(404);
			}

			if ($request->getMethod() === 'DELETE')
			{
				$this->deleted = true;

				if ($this->remove)
				{
					unset($this->items[$id]);
				}
				elseif ($this->retainedState !== null)
				{
					$this->items[$id]['state'] = $this->retainedState;
				}

				if ($this->afterDelete !== null)
				{
					($this->afterDelete)();
				}

				return new Response($this->deleteStatus);
			}

			$data = Json::decode((string) $request->getBody());
			$id ??= max([0, ...array_keys($this->items)]) + 1;
			$this->items[$id] = array_replace(['id' => $id, 'state' => 0], $this->items[$id] ?? [], $data);

			return new Response(200, [], Json::encode(['data' => $this->row($id)]));
		}

		/** @param int $id Native fixture identity. @return array JSON:API resource. */
		public function row(int $id): array
		{
			return ['type' => 'articles', 'id' => (string) $id, 'attributes' => $this->items[$id]];
		}
	};
	$store = new MemoryStore($seed);
	$principal = new Principal('joomla:17', 'api', [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings, static fn (string $name): bool => true, static fn (string $entity, string $key): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$executions = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$handler = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal, new HandlerRegistry(['api.request' => $handler]), $permissions, $executions, $audit, $settings, $builder);
	$request = $permissions->request(['toolsets' => ['content.write'], 'duration' => '30-minutes', 'reason' => 'Independent deletion verification contract']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);
	$context = compact('http', 'store', 'principal', 'catalogue', 'executor');

	if ($configure !== null)
	{
		$configure($context);
		$catalogue->refresh();
	}

	return $context;
};
$delete = static function (array $context): array
{
	$plan = $context['executor']->plan('content.articles.delete', ['id' => 41], Json::uuid());

	return [$plan, $context['executor']->apply($plan['confirmationToken'])];
};
$postCollections = static fn (array $context): array => array_values(array_filter($context['http']->requests,
	static fn (array $request): bool => $request['method'] === 'GET' && str_ends_with($request['path'], '/articles') && $request['afterDelete']));
$assertUncertain = static function (array $context, array $plan, array $result, string $name) use ($check, $reject): void
{
	$check($result['verification']['status'] === 'uncertain' && $context['store']->find('lease') !== [], $name . ' released an unverified mutation.');
	$requests = count($context['http']->requests);
	$repeat = $context['executor']->apply($plan['confirmationToken']);
	$check($repeat['idempotentReplay'] && count($context['http']->requests) === $requests, $name . ' replay repeated a native DELETE/read.');
	$next = $context['executor']->plan('content.articles.create', ['data' => ['title' => 'Blocked subsequent write', 'catid' => 2]], Json::uuid());
	$reject(static fn () => $context['executor']->apply($next['confirmationToken']), 'WRITE_BUSY');
};

$context = $fixture();
[$plan, $result] = $delete($context);
$check($result['verification']['status'] === 'verified' && $result['verification']['postcondition'] === 'resource-absent'
	&& $result['verification']['source'] === 'article-collection' && $context['store']->find('lease') === [],
	'Accepted DELETE plus complete exact-ID absence did not settle the execution and release its lease.');
$collections = $postCollections($context);
$check(array_column(array_column($collections, 'query'), 'filter') === [
	['search' => 'id:41', 'state' => '-2'], ['search' => 'id:41', 'state' => '0'],
	['search' => 'id:41', 'state' => '1'], ['search' => 'id:41', 'state' => '2'],
], 'Verification did not cover every numeric native state with the exact approved ID.');
$check(count($collections) === 4 && array_column(array_column($collections, 'query'), 'page') === array_fill(0, 4, ['offset' => '0', 'limit' => '2']),
	'Exact-ID evidence must use four bounded fixed-route reads, without returned links.');
$requests = count($context['http']->requests);
$repeat = $context['executor']->apply($plan['confirmationToken']);
$check($repeat['idempotentReplay'] && count($context['http']->requests) === $requests && $repeat['verification'] === $result['verification'], 'Verified replay changed evidence or performed I/O.');
$next = $context['executor']->plan('content.articles.create', ['data' => ['title' => 'Following approved write', 'catid' => 2]], Json::uuid());
$nextResult = $context['executor']->apply($next['confirmationToken']);
$check($nextResult['verification']['status'] === 'verified' && $context['store']->find('lease') === [], 'Confirmed absence left a WRITE_BUSY lease on the next approved write.');

$context = $fixture(static function (array $context): void { $context['http']->missingStatus = 404; });
[$plan, $result] = $delete($context);
$check($result['verification']['status'] === 'verified' && $postCollections($context) === [], 'Ordinary item-404 verification was changed.');

foreach ([-2, 0, 1, 2] as $state)
{
	$context = $fixture(static function (array $context) use ($state): void
	{
		$context['http']->remove = false;
		$context['http']->failItem = true;
		$context['http']->retainedState = $state;
	});
	[$plan, $result] = $delete($context);
	$assertUncertain($context, $plan, $result, 'Retained native state ' . $state);
}
$context = $fixture(static function (array $context): void { $context['http']->remove = false; });
[$plan, $result] = $delete($context);
$assertUncertain($context, $plan, $result, 'Still-trashed item GET');
$check($postCollections($context) === [], 'A visible trashed item should not invoke absence fallback.');

foreach ([401, 403, 429, 502] as $status)
{
	$context = $fixture(static function (array $context) use ($status): void { $context['http']->missingStatus = $status; });
	[$plan, $result] = $delete($context);
	$assertUncertain($context, $plan, $result, 'Unrelated item error ' . $status);
	$check($postCollections($context) === [], 'Unrelated item error invoked article absence fallback.');
}
$context = $fixture(static function (array $context): void { $context['http']->deleteStatus = 500; });
[$plan, $result] = $delete($context);
$assertUncertain($context, $plan, $result, 'Failed mutation response');
$check($postCollections($context) === [], 'A failed DELETE was accepted based on collection absence.');

$row = ['type' => 'articles', 'id' => '41', 'attributes' => ['id' => 41, 'state' => -2]];
foreach ([
	'collection denied' => new Response(403),
	'collection failed' => new Response(500),
	'missing metadata' => new Response(200, [], Json::encode(['data' => []])),
	'incomplete empty collection' => new Response(200, [], Json::encode(['data' => [], 'meta' => ['total-pages' => 1]])),
	'next page' => new Response(200, [], Json::encode(['data' => [], 'meta' => ['total-pages' => 0], 'links' => ['next' => 'https://other.example/private']])),
	'malformed collection' => new Response(200, [], Json::encode(['data' => $row, 'meta' => ['total-pages' => 1]])),
	'duplicate rows' => new Response(200, [], Json::encode(['data' => [$row, $row], 'meta' => ['total-pages' => 1]])),
	'unexpected identity' => new Response(200, [], Json::encode(['data' => [array_replace($row, ['id' => '42'])], 'meta' => ['total-pages' => 1]])),
	'attribute identity mismatch' => new Response(200, [], Json::encode(['data' => [array_replace($row, ['attributes' => ['id' => 42, 'state' => -2]])], 'meta' => ['total-pages' => 1]])),
	'unexpected state' => new Response(200, [], Json::encode(['data' => [array_replace($row, ['attributes' => ['id' => 41, 'state' => 1]])], 'meta' => ['total-pages' => 1]])),
	'wrong resource type' => new Response(200, [], Json::encode(['data' => [array_replace($row, ['type' => 'contacts'])], 'meta' => ['total-pages' => 1]])),
] as $name => $response)
{
	$context = $fixture(static function (array $context) use ($response): void { $context['http']->collectionAfter = $response; });
	[$plan, $result] = $delete($context);
	$assertUncertain($context, $plan, $result, $name);
}

foreach (['hidden', 'nonstandard-state', 'list-unpublished', 'narrowed-list'] as $mode)
{
	$context = $fixture(static function (array $context) use ($mode): void
	{
		if ($mode === 'hidden') { $context['http']->hiddenBefore = true; }
		if ($mode === 'nonstandard-state') { $context['http']->items[41]['state'] = 7; }
		if ($mode === 'list-unpublished' || $mode === 'narrowed-list')
		{
			$binding = $context['store']->one('binding', ['name' => 'content.articles.list.api']);
			$config = Json::decode($binding['configuration']);
			$config['query_defaults'] = ['filter[category]' => 999];
			$context['store']->update('binding', $mode === 'list-unpublished' ? ['published' => 0] : ['configuration' => Json::encode($config)], ['id' => $binding['id']]);
		}
	});
	[$plan, $result] = $delete($context);
	$assertUncertain($context, $plan, $result, 'Unavailable pre-deletion evidence: ' . $mode);
	$check($postCollections($context) === [], 'Unproven pre-deletion visibility was used to certify absence.');
}

foreach (['content.articles.get.api', 'content.articles.list.api'] as $name)
{
	$context = $fixture();
	$plan = $context['executor']->plan('content.articles.delete', ['id' => 41], Json::uuid());
	$binding = $context['store']->one('binding', ['name' => $name]);
	$context['store']->update('binding', ['version' => (int) $binding['version'] + 1], ['id' => $binding['id']]);
	$context['catalogue']->refresh();
	$reject(static fn () => $context['executor']->apply($plan['confirmationToken']), 'PLAN_STALE');
	$check(!$context['http']->deleted, 'Changed verification definition did not invalidate the plan before DELETE.');
}

foreach (['native_filter', 'query_map'] as $mode)
{
	$context = $fixture(static function (array $context) use ($mode): void
	{
		$binding = $context['store']->one('binding', ['name' => 'content.articles.list.api']);
		$config = Json::decode($binding['configuration']);
		$schema = $context['store']->one('schema', ['id' => $binding['input_schema_id']]);
		$document = Json::decode($schema['document']);

		if ($mode === 'native_filter')
		{
			$config['native_filter'] = true;
			$document['properties']['filter'] = ['type' => 'object', 'default' => ['category' => 999],
				'properties' => ['category' => ['type' => 'integer']], 'additionalProperties' => false];
		}
		else
		{
			$config['query_map'] = ['category' => 'filter[category]'];
			$document['properties']['category'] = ['type' => 'integer', 'default' => 999];
		}

		$context['store']->update('binding', ['configuration' => Json::encode($config)], ['id' => $binding['id']]);
		$context['store']->update('schema', ['document' => Json::encode($document)], ['id' => $schema['id']]);
	});
	[$plan, $result] = $delete($context);
	$assertUncertain($context, $plan, $result, 'Schema-default narrowed query: ' . $mode);
	$check($postCollections($context) === [], 'An effective narrowed collection query was used as evidence of absence.');
}

$context = $fixture(static function (array $context): void
{
	$binding = $context['store']->one('binding', ['name' => 'content.articles.delete.api']);
	$context['store']->update('binding', ['params' => Json::encode(['verification' => ['read_action' => 'content.categories.get', 'operation' => 'delete']])], ['id' => $binding['id']]);
});
$reject(static fn () => $context['executor']->plan('content.articles.delete', ['id' => 41], Json::uuid()), 'BINDING_INVALID');
$check(!$context['http']->deleted, 'Overridden incompatible verification contract submitted a DELETE.');

$context = $fixture(static function (array $context): void
{
	$binding = $context['store']->one('binding', ['name' => 'content.articles.list.api']);
	$context['http']->afterDelete = static function () use ($context, $binding): void
	{
		$context['store']->update('binding', ['published' => 0], ['id' => $binding['id']]);
		$context['catalogue']->refresh();
	};
});
[$plan, $result] = $delete($context);
$assertUncertain($context, $plan, $result, 'Collection access revoked after mutation');
$check($postCollections($context) === [], 'Revoked collection access still performed fallback I/O.');

foreach (['version', 'published'] as $field)
{
	$context = $fixture(static function (array $context) use ($field): void
	{
		$binding = $context['store']->one('binding', ['name' => 'content.articles.get.api']);
		$context['http']->missingStatus = 404;
		$context['http']->afterDelete = static function () use ($context, $binding, $field): void
		{
			$context['store']->update('binding', [$field => $field === 'version' ? (int) $binding['version'] + 1 : 0], ['id' => $binding['id']]);
		};
	});
	[$plan, $result] = $delete($context);
	$assertUncertain($context, $plan, $result, 'Item definition changed after DELETE: ' . $field);
	$reads = array_filter($context['http']->requests, static fn (array $request): bool => $request['method'] === 'GET' && $request['afterDelete']);
	$check($reads === [], 'Changed or revoked item definition issued a cached verification GET and accepted its 404.');
}

echo Json::encode(['checks' => $checks, 'articleDeletionContracts' => 'passed with recording API and transactional memory doubles', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
