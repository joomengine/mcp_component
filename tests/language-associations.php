<?php
/**
 * @package    JoomEngine.Mcp
 * @created    12 October 2026
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
use VDM\Component\JoomEngineMcp\Administrator\Service\LanguageAssociations;
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
$check = static function (bool $condition, string $message) use (&$checks): void
{
	$checks++;
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];

/** Actual executor, permission, planning and settlement around independent JSON:API responses. */
$fixture = static function (array $document) use ($seed): array
{
	$http = new class implements ClientInterface
	{
		/** @var array Independent resource response. */
		public array $document = [];
		/** @var array Native requests without credentials. */
		public array $requests = [];
		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			$this->requests[] = ['method' => $request->getMethod(), 'body' => (string) $request->getBody()];
			$document = $request->getMethod() === 'GET' ? $this->document
				: ['data' => ['id' => '87', 'type' => 'articles', 'attributes' => ['id' => 87, 'title' => 'Article']]];
			return new Response(200, ['Content-Type' => 'application/vnd.api+json'], Json::encode($document));
		}
	};
	$http->document = $document;
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
	$request = $permissions->request(['toolsets' => ['content.write'], 'duration' => '30-minutes', 'reason' => 'Native association verification']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);
	return compact('http', 'store', 'catalogue', 'executor', 'state');
};

$document = ['data' => ['id' => '87', 'type' => 'articles', 'attributes' => ['id' => 87, 'title' => 'Article'],
	'relationships' => ['languageAssociations' => ['data' => [['type' => 'articles', 'id' => '1248']]]]],
	'included' => [['type' => 'articles', 'id' => '1248', 'attributes' => ['id' => '1248', 'language' => 'en-GB'],
		'links' => ['self' => 'https://joomla.example/api/index.php/v1/content/articles/1248']]]];
$f = $fixture($document);

$menuConfig = $f['catalogue']->action('menus.site-items.update')['binding']['configuration'];
$builder = new ApiRequestBuilder();
foreach ([null, [], ['en-GB' => '1248']] as $current)
{
	$request = $builder->build(['id' => 87, 'data' => ['title' => 'Menu']], $menuConfig, ['associations' => $current]);
	$check($request['body']['associations'] === $current, 'Disabled, empty and valid native menu maps retain their original request representation.');
}
foreach ([false, '', [1248], ['en-GB' => -1], ['*' => 1248]] as $current)
{
	try
	{
		$builder->build(['id' => 87, 'data' => ['title' => 'Menu']], $menuConfig, ['associations' => $current]);
		throw new RuntimeException('Malformed preserved association data must fail before a write.');
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === 'PREFLIGHT_FAILED', 'Malformed native associations must fail safe preflight.');
	}
}
$request = $builder->build(['id' => 87, 'data' => ['associations' => new stdClass()]], $menuConfig, ['associations' => ['bad' => false]]);
$check($request['body']['associations'] instanceof stdClass, 'An explicit approved clear must replace the existing association data.');

foreach (['menus.site-items', 'content.articles'] as $base)
{
	foreach (['create', 'update'] as $operation)
	{
		$write = $f['catalogue']->action($base . '.' . $operation);
		$read = $f['catalogue']->action($base . '.get');
		foreach ([[['en-GB' => 1248], ['en-GB' => '1248']], [(object) ['en-GB' => 1248.0], ['en-GB' => 1248]],
			[['en-GB' => 1248, 'nl-NL' => 1247], ['nl-NL' => '1247', 'en-GB' => '1248']], [new stdClass(), []], [new stdClass(), new stdClass()]] as [$desired, $observed])
		{
			$check(ApiWriteVerification::compare($write, $read, 'associations', $desired, $observed) === true, 'Equivalent native maps should verify.');
		}
		foreach ([null, false, '', ['en-GB' => true], ['en-GB' => 0], ['en-GB' => -1], ['en-GB' => 1.5], ['en-GB' => '01248'],
			['en-GB' => '1248e0'], ['en-GB' => 2147483648], [1248], ['*' => 1248], ['en-GB' => '1248:slug'],
			['nl-NL' => 1248], ['en-GB' => 1249], ['en-GB' => 1248, 'nl-NL' => 1247]] as $observed)
		{
			$check(ApiWriteVerification::compare($write, $read, 'associations', ['en-GB' => 1248], $observed) === false,
				'Changed, extra or malformed associations cannot verify.');
		}
		foreach (['route' => '/v1/other', 'method' => 'DELETE', 'authentication' => 'other', 'read_action' => 'other.get'] as $key => $value)
		{
			$changed = $write;
			$changed['binding']['configuration'][$key] = $value;
			$check(ApiWriteVerification::compare($changed, $read, 'associations', new stdClass(), []) === null,
				'Customized write routes cannot inherit association equivalence.');
		}
		foreach (['route' => '/v1/other/:id', 'method' => 'POST', 'authentication' => 'other', 'select_fields' => ['associations']] as $key => $value)
		{
			$changed = $read;
			$changed['binding']['configuration'][$key] = $value;
			$check(ApiWriteVerification::compare($write, $changed, 'associations', new stdClass(), []) === null,
				'Customized independent reads cannot inherit association equivalence.');
		}
	}
}

$write = $f['catalogue']->action('content.articles.create');
$read = $f['catalogue']->action('content.articles.get');
$record = $document['data']['attributes'];
$check(LanguageAssociations::observe($write, $read, ['data' => $document], $record)['associations'] === ['en-GB' => 1248],
	'Article relationship linkage must join its exact included resource.');
$empty = $document;
$empty['data']['relationships']['languageAssociations']['data'] = [];
unset($empty['included']);
$missing = $document;
unset($missing['data']['relationships']);
$check(!array_key_exists('associations', LanguageAssociations::observe($write, $read, ['data' => $missing], $record)),
	'Missing relationships are unobservable rather than proof of clearing.');

$malformed = [];
$change = $document;
unset($change['included']);
$malformed[] = $change;
$change = $document;
$change['included'][] = $change['included'][0];
$malformed[] = $change;
$change = $document;
$change['included'][0]['attributes']['language'] = '*';
$malformed[] = $change;
$change = $document;
$change['included'][0]['id'] = '1249';
$malformed[] = $change;
$change = $document;
$change['included'][0]['type'] = 'contacts';
$malformed[] = $change;
$change = $document;
$change['data']['relationships']['languageAssociations']['data'][] = $change['data']['relationships']['languageAssociations']['data'][0];
$malformed[] = $change;
$change = $document;
$change['data']['relationships']['languageAssociations']['data'] = null;
$malformed[] = $change;
$change = $document;
$change['data']['attributes']['associations'] = ['en-GB' => 900];
$malformed[] = $change;
$change = $document;
$change['included'][0]['attributes'] = ['id' => 1248];
$malformed[] = $change;
$change = $document;
$change['included'][0]['attributes']['id'] = 999;
$malformed[] = $change;
$change = $document;
$change['data']['relationships']['languageAssociations']['data'][] = ['type' => 'articles', 'id' => '1249'];
$change['included'][] = ['type' => 'articles', 'id' => '1249', 'attributes' => ['id' => 1249, 'language' => 'en-GB']];
$malformed[] = $change;
$change = $document;
$change['data']['type'] = 'contacts';
$change['data']['relationships']['languageAssociations']['data'][0]['type'] = 'contacts';
$change['included'][0]['type'] = 'contacts';
$malformed[] = $change;

foreach ($malformed as $bad)
{
	$check(LanguageAssociations::observe($write, $read, ['data' => $bad], $bad['data']['attributes'])['associations'] === null,
		'Malformed or contradictory relationship evidence cannot produce an association match.');
}

$run = static function (array $document, mixed $desired, string $status, string $operation = 'create') use ($fixture, $check): void
{
	$f = $fixture($document);
	$input = ['data' => ['title' => 'Article', 'associations' => $desired]] + ($operation === 'update' ? ['id' => 87] : []);
	$plan = $f['executor']->plan('content.articles.' . $operation, $input, Json::uuid());
	$result = $f['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === $status, 'Article association settlement mismatch: ' . Json::encode($result['verification']));
	$check(($f['store']->find('lease') === []) === ($status !== 'uncertain'), 'Uncertain association writes must retain the owned lease.');
	$count = count($f['http']->requests);
	$replay = $f['executor']->apply($plan['confirmationToken']);
	$check($replay['idempotentReplay'] && count($f['http']->requests) === $count, 'Association reconciliation outcomes must not cause mutation replay.');
};
foreach (['create', 'update'] as $operation)
{
	$run($document, ['en-GB' => 1248], 'verified', $operation);
	$run($empty, new stdClass(), 'verified', $operation);
	$run($document, new stdClass(), 'uncertain', $operation);
	$run($missing, ['en-GB' => 1248], 'partial', $operation);
}
foreach ($malformed as $bad)
{
	$run($bad, ['en-GB' => 1248], 'uncertain');
}

$f = $fixture($document);
$plan = $f['executor']->plan('content.articles.update', ['id' => 87, 'data' => ['title' => 'Article', 'associations' => ['en-GB' => 1248]]], Json::uuid());
$f['http']->document['included'][0]['attributes']['language'] = 'nl-NL';
try
{
	$f['executor']->apply($plan['confirmationToken']);
	throw new RuntimeException('A concurrent relationship change must invalidate the approved article snapshot.');
}
catch (OperationException $error)
{
	$check($error->getIdentifier() === 'PRECONDITION_CHANGED', 'Concurrent article associations must invalidate the resource snapshot.');
}
$check(array_filter($f['http']->requests, static fn (array $request): bool => $request['method'] !== 'GET') === [],
	'Concurrent association changes must be detected before any mutation.');

echo Json::encode(['checks' => $checks, 'languageAssociationVerification' => 'passed', 'liveJoomla' => 'not run by this unit suite']) . PHP_EOL;
restore_error_handler();
