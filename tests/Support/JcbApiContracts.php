<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Router\ApiRouter;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use Joomla\Event\EventInterface;
use Joomla\Router\Route;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\ApiRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\RegistrationObserver;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

/** Exercise real native Joomla registrations without installing a fabricated JCB endpoint. */
return static function (ApiRouter $router): int
{
	$checks = 0;
	$check = static function (bool $condition, string $message) use (&$checks): void
	{
		if (!$condition)
		{
			throw new RuntimeException($message);
		}
		$checks++;
	};
	$reject = static function (callable $callback, string $code) use ($check): void
	{
		try
		{
			$callback();
		}
		catch (OperationException $exception)
		{
			$check($exception->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $exception->getIdentifier());
			return;
		}
		throw new RuntimeException('Expected rejection ' . $code);
	};
	$defaults = ['component' => 'com_componentbuilder', 'extension' => 'com_fixture_a'];
	$router->createCRUDRoutes('v1/jcb-fixture/a', 'entities', $defaults);
	$router->createCRUDRoutes('v1/jcb-fixture/b', 'entities', ['component' => 'com_componentbuilder', 'extension' => 'com_fixture_b']);
	$router->createCRUDRoutes('v1/componentbuilder-false-owner', 'entities', ['component' => 'com_content']);
	$router->createCRUDRoutes('v1/compiled-fixture/items', 'items', ['component' => 'com_compiled_fixture']);
	$router->addRoutes([
		new Route(['PUT'], 'v1/jcb-fixture/a/:id', 'entities.edit', ['id' => '(\d+)'], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/compile', 'compiler.compile', [], $defaults),
		new Route(['POST'], 'v1/jcb-fixture/unreviewed', 'entities.edit', [], $defaults),
		new Route(['POST'], 'v1/jcb-fixture/publish', 'entities.publish', [], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/uuid/:id', 'entities.displayItem', ['id' => '[0-9a-f-]{36}'], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/unsafe-default', 'entities.displayList', [], $defaults + ['task' => 'compile']),
		new Route(['GET'], 'v1/jcb-fixture/collision/:filter', 'entities.displayList', [], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/numeric-default/:id', 'entities.displayItem', ['id' => '(\d+)'], $defaults + ['id' => 99]),
		new Route(['GET'], 'v1/jcb-fixture/a/guid/:guid', 'entities.displayItem', ['guid' => '([0-9a-fA-F-]{36})'], $defaults),
		new Route(['PATCH'], 'v1/jcb-fixture/a/guid/:guid', 'entities.edit', ['guid' => '([0-9a-fA-F-]{36})'], $defaults),
		new Route(['DELETE'], 'v1/jcb-fixture/a/guid/:guid', 'entities.delete', ['guid' => '([0-9a-fA-F-]{36})'], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/a/slug/:slug', 'entities.displayItem', ['slug' => '([^/]+)'], $defaults),
		new Route(['PATCH'], 'v1/jcb-fixture/a/slug/:slug', 'entities.edit', ['slug' => '([^/]+)'], $defaults),
		new Route(['DELETE'], 'v1/jcb-fixture/a/slug/:slug', 'entities.delete', ['slug' => '([^/]+)'], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/b/guid/:guid', 'entities.displayItem', ['guid' => '([0-9a-fA-F-]{36})'], ['component' => 'com_componentbuilder', 'extension' => 'com_fixture_b']),
		new Route(['PATCH'], 'v1/jcb-fixture/b/guid/:guid', 'entities.edit', ['guid' => '([0-9a-fA-F-]{36})'], ['component' => 'com_componentbuilder', 'extension' => 'com_fixture_b']),
		new Route(['DELETE'], 'v1/jcb-fixture/b/guid/:guid', 'entities.delete', ['guid' => '([0-9a-fA-F-]{36})'], ['component' => 'com_componentbuilder', 'extension' => 'com_fixture_b']),
		new Route(['DELETE'], 'v1/jcb-fixture/invalid-guid-delete/:id', 'entities.deleteByGuid', ['id' => '(\d+)'], $defaults),
	]);
	$inventory = (new ApiRegistry($router))->inventory();
	$check(count($inventory['routes']) === 21, 'Every reviewed actual CRUD method/path, GUID and unique-key alias is inventoried.');
	$check(count($inventory['unsupported']) === 7, 'Unreviewed method/task, variable and routing-default contracts remain explicit.');
	$check(!str_contains(Json::encode($inventory), 'false-owner'), 'Ownership comes only from native component defaults.');
	$check(!str_contains(Json::encode($inventory), 'compiled-fixture'), 'Other installed components require an explicit reviewed component scope.');
	$inventory = (new ApiRegistry($router, null, ['com_componentbuilder', 'com_compiled_fixture']))->inventory();
	$check(count($inventory['routes']) === 26 && $inventory['components'] === ['com_compiled_fixture', 'com_componentbuilder'],
		'A reviewed generated component uses its observed registrations without adding Joomla core routes.');
	$commands = ['commands' => [], 'unsupported' => []];
	$seed = (new CatalogueBuilder())->build($commands, $inventory);
	$check(count($seed['entities']['action']) === count($inventory['routes']), 'Every supported registration produces exactly one action.');
	$metadata = Json::decode($seed['entities']['provider'][0]['definition']);
	$check($metadata['unsupportedRoutes'] === $inventory['unsupported'], 'Unsupported inventory remains visible in provider diagnostics.');
	$check(count($seed['entities']['provider']) === 2 && $seed['entities']['provider'][1]['extension'] === 'com_compiled_fixture',
		'Generated components retain separate provider ownership and extension lifecycle dependencies.');
	$bindings = [];
	$schemas = [];
	foreach ($seed['entities']['schema'] as $schema)
	{
		$schemas[$schema['id']] = $schema['document'];
	}
	foreach ($seed['entities']['binding'] as $binding)
	{
		$config = Json::decode($binding['configuration']);
		$bindings[$config['method'] . ' ' . $config['route']] = $binding + ['config' => $config];
	}
	$validator = new SchemaValidator();
	$builder = new ApiRequestBuilder();
	$list = $bindings['GET /v1/jcb-fixture/a'];
	$arguments = $validator->input(['offset' => 4, 'limit' => 7, 'filter' => ['search' => 'literal & nested=value', 'state' => 0, 'active' => true],
		'ordering' => 'a.title', 'direction' => 'desc'], $schemas[$list['input_schema_id']]);
	$request = $builder->build($arguments, $list['config']);
	$check($request['query'] === ['page[offset]' => 4, 'page[limit]' => 7, 'list[ordering]' => 'a.title', 'list[direction]' => 'desc',
		'filter[search]' => 'literal & nested=value', 'filter[state]' => 0, 'filter[active]' => 1, 'extension' => 'com_fixture_a'],
		'Pagination, native filter and list ordering retain distinct bounded query semantics.');
	$check($request['body'] === null && $request['method'] === 'GET', 'Read routes never acquire a mutation body.');
	$check(!isset($request['query']['component'], $request['query']['public']), 'Router metadata remains native metadata, not client-selectable input.');
	foreach ([['filter' => ['x][task' => 'compile']], ['filter' => ['search' => [['nested']]]], ['filter' => ['state' => []]],
		['filter' => ['state' => array_fill(0, 65, 1)]], ['filter' => array_fill_keys(range('A', 'Z'), 'x') + array_fill_keys(range('a', 'z'), 'x')],
		['filter' => ['search' => str_repeat('x', 2049)]], ['direction' => 'unsafe'], ['ordering' => 'a.title desc'], ['limit' => 501]] as $invalid)
	{
		$reject(static fn () => $validator->input($invalid, $schemas[$list['input_schema_id']]), 'INVALID_INPUT');
	}
	foreach ([['bad][task' => 'compile'], ['search' => [['nested']]], ['search' => []], ['search' => str_repeat('x', 2049)]] as $invalid)
	{
		$reject(static fn () => $builder->build(['filter' => $invalid], $list['config']), 'INVALID_INPUT');
	}
	$multiselect = $validator->input(['filter' => ['state' => [0, 1], 'category' => ['first', 'second']]], $schemas[$list['input_schema_id']]);
	$request = $builder->build($multiselect, $list['config']);
	$check($request['query']['filter[state][0]'] === 0 && $request['query']['filter[state][1]'] === 1
		&& $request['query']['filter[category][0]'] === 'first' && $request['query']['filter[category][1]'] === 'second',
		'Generated multiselect filters preserve bounded native scalar values and selection order.');
	foreach (['a', 'b'] as $scope)
	{
		$create = $bindings['POST /v1/jcb-fixture/' . $scope];
		$item = $bindings['GET /v1/jcb-fixture/' . $scope . '/:id'];
		$check($create['config']['read_action'] . '.api' === $item['name'], 'Read-back selects the exact native collection and fixed defaults.');
		$request = $builder->build(['data' => ['title' => 'test', 'extension' => 'caller-override']], $create['config']);
		$check($request['body']['extension'] === 'com_fixture_' . $scope && $request['query']['extension'] === 'com_fixture_' . $scope,
			'Fixed native route defaults cannot be changed in a form or query.');
	}
	$update = $bindings['PATCH /v1/jcb-fixture/a/:id'];
	$input = $validator->input(['id' => 4, 'data' => ['php_code' => '<?php echo "inert source";']], $schemas[$update['input_schema_id']]);
	$request = $builder->build($input, $update['config']);
	$check($request['path'] === '/v1/jcb-fixture/a/4' && $request['body']['php_code'] === '<?php echo "inert source";', 'Native source-code data remains inert form input.');
	$reject(static fn () => $validator->input(['id' => '4', 'data' => ['title' => 'x']], $schemas[$update['input_schema_id']]), 'INVALID_INPUT');
	$fixedId = $bindings['GET /v1/jcb-fixture/numeric-default/:id'];
	$request = $builder->build(['id' => 4], $fixedId['config']);
	$check($request['path'] === '/v1/jcb-fixture/numeric-default/4' && !isset($request['query']['id']), 'Matched route variables override native fallback defaults.');
	$guid = '62C69E4D-9B19-478A-A88A-6F00316FF1DB';
	foreach (['PATCH', 'DELETE'] as $method)
	{
		foreach (['a', 'b'] as $scope)
		{
			$alias = $bindings[$method . ' /v1/jcb-fixture/' . $scope . '/guid/:guid'];
			$read = $bindings['GET /v1/jcb-fixture/' . $scope . '/guid/:guid'];
			$arguments = ['guid' => $guid] + ($method === 'PATCH' ? ['data' => ['title' => 'updated by GUID']] : []);
			$validated = $validator->input($arguments, $schemas[$alias['input_schema_id']]);
			$request = $builder->build($validated, $alias['config']);
			$verification = Json::decode($alias['params'])['verification'];
			$check($request['path'] === '/v1/jcb-fixture/' . $scope . '/guid/' . $guid
				&& $alias['config']['read_action'] . '.api' === $read['name']
				&& $verification['primary_key'] === 'guid' && $verification['input_key'] === 'guid'
				&& $verification['read_input_key'] === 'guid' && $verification['identity_type'] === 'guid',
				'GUID mutations encode the actual alias and bind verification to its exact scoped GUID read.');
			$reject(static fn () => $validator->input(array_replace($arguments, ['guid' => str_repeat('a', 36)]), $schemas[$alias['input_schema_id']]), 'INVALID_INPUT');
		}
	}
	$slug = $bindings['PATCH /v1/jcb-fixture/a/slug/:slug'];
	$request = $builder->build($validator->input(['slug' => '0012 Namibian λ..key', 'data' => ['title' => 'updated by unique key']],
		$schemas[$slug['input_schema_id']]), $slug['config']);
	$check($request['path'] === '/v1/jcb-fixture/a/slug/' . rawurlencode('0012 Namibian λ..key')
		&& Json::decode($slug['params'])['verification']['identity_type'] === 'string',
		'Unique-key aliases preserve leading zeros and safely encode one native path segment.');
	foreach (['.', '..', 'bad/path', 'bad\\path', 'bad%2Fpath', 'bad?query', "bad\nkey"] as $unsafe)
	{
		$reject(static fn () => $validator->input(['slug' => $unsafe, 'data' => ['title' => 'x']], $schemas[$slug['input_schema_id']]), 'INVALID_INPUT');
	}
	$compiled = $bindings['PATCH /v1/compiled-fixture/items/:id'];
	$definition = Json::decode($compiled['definition']);
	$check($definition['required_permissions'] === [['action' => 'core.manage', 'asset' => 'com_compiled_fixture']]
		&& str_starts_with($compiled['name'], 'jcb.api.compiled_fixture.items.edit.patch.')
		&& $compiled['provider_id'] === $seed['entities']['provider'][1]['id'],
		'Generated API actions bind their component permission asset and cannot inherit JCB authority.');
	$before = $inventory['fingerprint'];
	$router->addRoutes([new Route(['GET'], 'v1/jcb-fixture/new-special', 'entities.archive', [], $defaults)]);
	$check((new ApiRegistry($router))->inventory()['fingerprint'] !== $before, 'Unsupported registration changes alter the inventory fingerprint.');
	$router->addRoutes([new Route(['GET'], 'v1/jcb-fixture/a', 'compiler.compile', [], $defaults)]);
	$reject(static fn () => (new ApiRegistry($router))->inventory(), 'JCB_ROUTE_AMBIGUOUS');

	$dispatcher = new Dispatcher();
	$plugin = new class(['name' => 'NativeBuilder', 'type' => 'webservices']) extends CMSPlugin
	{
		/** @var array Native registered objects retained by this fixture. */
		public array $objects = [];
		/** @param EventInterface $event Native registration event. @return void */
		public function register(EventInterface $event): void
		{
			$this->objects[] = new Route(['GET'], 'v1/jcb-fixture/owned', 'entities.displayList', [], ['component' => 'com_componentbuilder']);
		}
		/** @return Closure Legacy Joomla listeners remain bound to the original plugin. */
		public function legacy(): Closure
		{
			return function (EventInterface $event): void { $this->register($event); };
		}
	};
	$native = [$plugin, 'register'];
	$legacy = $plugin->legacy();
	$dispatcher->addListener('native.registration', $native, 10);
	$dispatcher->addListener('native.registration', $legacy, 0);
	$owners = (new RegistrationObserver())->dispatch($dispatcher, new Event('native.registration'), static fn (): array => $plugin->objects);
	$check(count($owners) === 2 && array_unique(array_merge(...array_values($owners))) === ['webservices/NativeBuilder'],
		'Native and legacy listeners retain the exact owning plugin element, including case.');
	$check($dispatcher->getListeners('native.registration') === [$native, $legacy]
		&& $dispatcher->getListenerPriority('native.registration', $native) === 10,
		'Provenance observation restores original listener identity, ordering and priority.');
	$router->addRoutes([$plugin->objects[0]]);
	$owned = (new ApiRegistry($router, [spl_object_id($plugin->objects[0]) => ['webservices/NativeBuilder']]))->inventory();
	$check(count($owned['routes']) === 1 && $owned['routes'][0]['required_extensions'] === ['webservices/NativeBuilder']
		&& count($owned['unsupported']) > 0, 'Only registrations with observed provenance become installed plugin-dependent API bindings.');

	return $checks;
};
