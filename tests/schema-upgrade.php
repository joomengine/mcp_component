<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiHandler;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
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


$root = dirname(__DIR__);
require_once $root . '/admin/autoload.php';
require_once __DIR__ . '/Support/MemoryStore.php';
require_once __DIR__ . '/Support/Principal.php';
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
$reject = static function (callable $operation, string $identifier) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $identifier, 'Expected ' . $identifier . ', got ' . $error->getIdentifier());
		return;
	}

	throw new RuntimeException('An upgraded schema policy accepted an input that it previously denied.');
};
$seed = Json::decode(file_get_contents($root . '/admin/data/catalogue-seed.json'), maximum: 16777216);
$source = json_decode(file_get_contents($root . '/data/upstream-contracts.json'), false, 128, JSON_THROW_ON_ERROR);
$originalTools = array_column($source->tools, null, 'name');
$legacy = $seed;
$replacementIds = [];

// Reconstruct the actual previous read-tool relationships from the immutable
// imported contracts, without depending on another checkout or test-generated
// definitions. The original content-addressed schema rows remain in the seed.
foreach ($legacy['entities']['tool'] as &$tool)
{
	if (!in_array($tool['name'], ['joomla_action_read', 'joomla_companion_action_read'], true))
	{
		continue;
	}

	$replacementIds[(int) $tool['input_schema_id']] = true;
	$original = Json::canonical($originalTools[$tool['name']]->inputSchema);

	foreach ($legacy['entities']['schema'] as $schema)
	{
		if (Json::canonical(json_decode($schema['document'], false, 128, JSON_THROW_ON_ERROR)) === $original)
		{
			$tool['input_schema_id'] = $schema['id'];
			$tool['seed_revision'] = $legacy['source'];
			break;
		}
	}

	$check((int) $tool['input_schema_id'] !== array_key_last($replacementIds), 'The actual old read schema was not found.');
}
unset($tool);
$legacy['entities']['schema'] = array_values(array_filter($legacy['entities']['schema'],
	static fn (array $schema): bool => !isset($replacementIds[(int) $schema['id']])));

/** Compose real dispatch, schema validation and read transport over test-owned storage. */
$fixture = static function (MemoryStore $store, string $track = 'api'): array
{
	$http = new class implements ClientInterface
	{
		/** @var array Captured read requests. */
		public array $requests = [];
		/** @inheritDoc */
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			if ($request->getMethod() !== 'GET')
			{
				throw new RuntimeException('Schema upgrade verification must never mutate Joomla.');
			}

			$this->requests[] = $request;
			$id = (int) basename($request->getUri()->getPath());
			return new Response(200, [], Json::encode(['data' => ['id' => (string) $id,
				'attributes' => ['id' => $id, 'title' => 'Read through the approved schema']]]));
		}
	};
	$native = new class implements HandlerInterface
	{
		/** @var int Captured local read operations. */
		public int $reads = 0;
		/** @inheritDoc */
		public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
		{
			if (($binding['configuration']['operation'] ?? '') !== 'get')
			{
				throw new RuntimeException('The upgrade fixture accepts only native reads.');
			}

			$this->reads++;
			return ['entity' => 'content.articles', 'item' => ['id' => $arguments['id']]];
		}
	};
	$principal = new Principal('joomla:17', $track, [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
	$clock = static fn (): int => 1900000000;
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$state = new Executions($store, $principal, new Envelope(str_repeat('s', 32)), $permissions, $settings, $audit, $clock);
	$builder = new ApiRequestBuilder();
	$api = new ApiHandler($http, $builder, $settings, 'test-token', static fn (): string => 'unused');
	$executor = new ActionExecutor($catalogue, $schemas, $principal,
		new HandlerRegistry(['api.request' => $api, 'native.core-entity' => $native]), $permissions, $state, $audit, $settings, $builder);
	$dispatcher = new ToolDispatcher($catalogue, $executor, $permissions, $schemas, $principal, $settings);
	return compact('http', 'native', 'catalogue', 'dispatcher');
};
$io = static fn (array $fixture): int => count($fixture['http']->requests) + $fixture['native']->reads;
$allowed = ['action' => 'content.articles.get', 'input' => ['id' => 7]];
$denied = ['action' => 'content.articles.get', 'input' => ['id' => 8]];

foreach (['joomla_action_read' => 'api', 'joomla_companion_action_read' => 'cli'] as $name => $track)
{
	foreach ([false, true] as $flagged)
	{
		$store = new MemoryStore();
		$updater = new SeedUpdater($store);
		$updater->apply($legacy);
		$tool = $store->one('tool', ['name' => $name]);
		$schema = $store->one('schema', ['id' => $tool['input_schema_id']]);
		$document = json_decode($schema['document'], false, 128, JSON_THROW_ON_ERROR);
		$document->properties->action->enum = ['content.articles.get'];
		$document->properties->input->properties = (object) ['id' => (object) ['type' => 'integer', 'maximum' => 7]];
		$store->update('schema', ['document' => Json::encode($document), 'customized' => (int) $flagged], ['id' => $schema['id']]);
		$check(SeedUpdater::hash('tool', $tool) === $tool['seed_hash'] && (int) $tool['customized'] === 0,
			'The reproduction must customize only the shared schema, never its tool.');
		$f = $fixture($store, $track);
		foreach (['before', 'after', 'repeat'] as $phase)
		{
			if ($phase !== 'before')
			{
				$counts = $updater->apply($seed);
				$check($phase !== 'repeat' || $counts['updated'] === 0, 'An owned schema relationship is not idempotent on repeated upgrade.');
			}

			$before = $io($f);
			$read = $f['dispatcher']->call($name, $allowed);
			$check($read['transport'] === $track && $io($f) === $before + 1, 'An accepted core read stopped working ' . $phase . ' upgrade.');
			$reject(static fn () => $f['dispatcher']->call($name, $denied), 'INVALID_INPUT');
			$check($io($f) === $before + 1, 'A denied schema input reached read transport ' . $phase . ' upgrade.');
		}

		$check($store->one('tool', ['name' => $name])['input_schema_id'] === $tool['input_schema_id'],
			'Preserving a schema row alone is insufficient: the effective tool relationship changed.');
	}

	// Disabled and missing schemas are both effective execution denials.
	foreach (['disabled', 'missing'] as $unavailable)
	{
		$store = new MemoryStore();
		$updater = new SeedUpdater($store);
		$updater->apply($legacy);
		$tool = $store->one('tool', ['name' => $name]);
		if ($unavailable === 'disabled')
		{
			$store->update('schema', ['published' => 0], ['id' => $tool['input_schema_id']]);
		}
		else
		{
			$store->remove('schema', ['id' => $tool['input_schema_id']]);
		}
		$f = $fixture($store, $track);
		$reject(static fn () => $f['dispatcher']->call($name, $allowed), 'DEFINITION_UNAVAILABLE');
		$updater->apply($seed);
		$reject(static fn () => $f['dispatcher']->call($name, $allowed), 'DEFINITION_UNAVAILABLE');
		$check($io($f) === 0, 'Upgrading bypassed an unavailable administrator input schema.');
		$check($updater->apply($seed)['updated'] === 0, 'Unavailable schema preservation is not idempotent.');
	}

	// Removal from the incoming graph must not retire an owned active schema
	// before dependent tools are checked later in the same upgrade transaction.
	$store = new MemoryStore();
	$updater = new SeedUpdater($store);
	$updater->apply($legacy);
	$tool = $store->one('tool', ['name' => $name]);
	$schema = $store->one('schema', ['id' => $tool['input_schema_id']]);
	$document = json_decode($schema['document'], false, 128, JSON_THROW_ON_ERROR);
	$document->properties->input->properties = (object) ['id' => (object) ['type' => 'integer', 'maximum' => 7]];
	$store->update('schema', ['document' => Json::encode($document)], ['id' => $schema['id']]);
	$withoutOriginal = $seed;
	$withoutOriginal['entities']['schema'] = array_values(array_filter($withoutOriginal['entities']['schema'],
		static fn (array $row): bool => $row['name'] !== $schema['name']));
	$f = $fixture($store, $track);
	$reject(static fn () => $f['dispatcher']->call($name, $denied), 'INVALID_INPUT');
	$updater->apply($withoutOriginal);
	$reject(static fn () => $f['dispatcher']->call($name, $denied), 'INVALID_INPUT');
	$check($f['dispatcher']->call($name, $allowed)['transport'] === $track && $io($f) === 1,
		'An omitted owned schema lost its accepted read or retired its policy.');
	$check($updater->apply($withoutOriginal)['updated'] === 0, 'An omitted owned schema relationship is not idempotent.');

	// Untouched installations still receive the new default nested-read envelope.
	$store = new MemoryStore();
	$updater = new SeedUpdater($store);
	$updater->apply($legacy);
	$old = $store->one('tool', ['name' => $name]);
	$updater->apply($seed);
	$new = $store->one('tool', ['name' => $name]);
	$check($new['input_schema_id'] !== $old['input_schema_id'], 'A pristine tool did not adopt its new shipped read contract.');
	$f = $fixture($store, $track);
	$check($f['dispatcher']->call($name, $allowed)['transport'] === $track && $io($f) === 1,
		'An untouched installation lost an accepted core read after upgrading.');
}

// A replacement execution-track schema must likewise retain its previous
// schema-only input restrictions and independent output validation policy.
foreach (['input_schema_id' => 'INVALID_INPUT', 'output_schema_id' => 'OUTPUT_CONTRACT_FAILED'] as $field => $error)
{
	foreach ([false, true] as $flagged)
	{
		$store = new MemoryStore();
		$updater = new SeedUpdater($store);
		$updater->apply($seed);
		$binding = $store->one('binding', ['name' => 'content.articles.get.api']);
		$schema = $store->one('schema', ['id' => $binding[$field]]);
		$document = json_decode($schema['document'], false, 128, JSON_THROW_ON_ERROR);
		$restricted = clone $document;
		if ($field === 'input_schema_id')
		{
			$restricted->properties = (object) ['id' => (object) ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]];
		}
		else
		{
			$restricted->properties = (object) ['data' => (object) ['type' => 'object',
				'properties' => (object) ['data' => (object) ['type' => 'object',
					'properties' => (object) ['id' => (object) ['const' => '7']]]]]];
		}
		$store->update('schema', ['document' => Json::encode($restricted), 'customized' => (int) $flagged], ['id' => $schema['id']]);
		$next = $seed;
		$replacement = $schema;
		$replacement['id'] = max(array_column($next['entities']['schema'], 'id')) + 1;
		$document->description = 'Replacement shipped binding schema for upgrade contract testing.';
		$replacement['name'] = 'schema.' . hash('sha256', Json::canonical($document));
		$replacement['document'] = Json::encode($document);
		$replacement['seed_revision'] = $next['runtimeSource'];
		$next['entities']['schema'][] = $replacement;
		foreach ($next['entities']['binding'] as &$row)
		{
			if ($row['name'] === $binding['name'])
			{
				$row[$field] = $replacement['id'];
			}
		}
		unset($row);
		$f = $fixture($store);
		foreach (['before', 'after', 'repeat'] as $phase)
		{
			if ($phase !== 'before')
			{
				$counts = $updater->apply($next);
				$check($phase !== 'repeat' || $counts['updated'] === 0, 'An owned binding schema reference is not idempotent.');
			}
			$before = $io($f);
			$check($f['dispatcher']->call('joomla_action_read', $allowed)['response']['status'] === 200,
				'An accepted input/output policy read stopped working ' . $phase . ' binding upgrade.');
			$reject(static fn () => $f['dispatcher']->call('joomla_action_read', ['action' => 'content.articles.get', 'input' => ['id' => 8]]), $error);
			$check($io($f) === $before + ($field === 'input_schema_id' ? 1 : 2),
				'Binding policy validation occurred at the wrong transport boundary.');
		}
		$check($store->one('binding', ['name' => $binding['name']])[$field] === $binding[$field],
			'The effective customized binding schema reference was replaced.');
	}
}

echo 'Schema-only upgrade policy contract checks: ' . $checks . PHP_EOL;
