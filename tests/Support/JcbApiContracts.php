<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Router\ApiRouter;
use Joomla\Router\Route;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\ApiRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
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
	$router->addRoutes([
		new Route(['PUT'], 'v1/jcb-fixture/a/:id', 'entities.edit', ['id' => '(\d+)'], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/compile', 'compiler.compile', [], $defaults),
		new Route(['POST'], 'v1/jcb-fixture/unreviewed', 'entities.edit', [], $defaults),
		new Route(['POST'], 'v1/jcb-fixture/publish', 'entities.publish', [], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/uuid/:id', 'entities.displayItem', ['id' => '[0-9a-f-]{36}'], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/unsafe-default', 'entities.displayList', [], $defaults + ['task' => 'compile']),
		new Route(['GET'], 'v1/jcb-fixture/collision/:filter', 'entities.displayList', [], $defaults),
		new Route(['GET'], 'v1/jcb-fixture/numeric-default/:id', 'entities.displayItem', ['id' => '(\d+)'], $defaults + ['id' => 99]),
	]);
	$inventory = (new ApiRegistry($router))->inventory();
	$check(count($inventory['routes']) === 12, 'Every reviewed actual CRUD method/path is inventoried.');
	$check(count($inventory['unsupported']) === 6, 'Unreviewed method/task, variable and routing-default contracts remain explicit.');
	$check(!str_contains(Json::encode($inventory), 'false-owner'), 'Ownership comes only from native component defaults.');
	$commands = ['commands' => [], 'unsupported' => []];
	$seed = (new CatalogueBuilder())->build($commands, $inventory);
	$check(count($seed['entities']['action']) === count($inventory['routes']), 'Every supported registration produces exactly one action.');
	$metadata = Json::decode($seed['entities']['provider'][0]['definition']);
	$check($metadata['unsupportedRoutes'] === $inventory['unsupported'], 'Unsupported inventory remains visible in provider diagnostics.');
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
	foreach ([['filter' => ['x][task' => 'compile']], ['filter' => ['search' => ['nested']]], ['filter' => array_fill_keys(range('A', 'Z'), 'x') + array_fill_keys(range('a', 'z'), 'x')],
		['filter' => ['search' => str_repeat('x', 2049)]], ['direction' => 'unsafe'], ['ordering' => 'a.title desc'], ['limit' => 501]] as $invalid)
	{
		$reject(static fn () => $validator->input($invalid, $schemas[$list['input_schema_id']]), 'INVALID_INPUT');
	}
	foreach ([['bad][task' => 'compile'], ['search' => []], ['search' => str_repeat('x', 2049)]] as $invalid)
	{
		$reject(static fn () => $builder->build(['filter' => $invalid], $list['config']), 'INVALID_INPUT');
	}
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
	$before = $inventory['fingerprint'];
	$router->addRoutes([new Route(['GET'], 'v1/jcb-fixture/new-special', 'entities.archive', [], $defaults)]);
	$check((new ApiRegistry($router))->inventory()['fingerprint'] !== $before, 'Unsupported registration changes alter the inventory fingerprint.');
	$router->addRoutes([new Route(['GET'], 'v1/jcb-fixture/a', 'compiler.compile', [], $defaults)]);
	$reject(static fn () => (new ApiRegistry($router))->inventory(), 'JCB_ROUTE_AMBIGUOUS');

	return $checks;
};
