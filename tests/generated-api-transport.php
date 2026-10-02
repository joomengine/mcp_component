<?php
/**
 * @package    JoomEngine.Mcp
 * @created    02 October 2026
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
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;


require dirname(__DIR__) . '/admin/autoload.php';
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
$reject = static function (callable $call, string $code = 'INVALID_INPUT') use ($check): void
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

$builder = new ApiRequestBuilder();
$guid = 'ABCDEF01-2345-6789-abcd-EF0123456789';
$guidRoute = ['method' => 'GET', 'route' => '/v1/generated/items/guid/:guid',
	'route_parameters' => [['name' => 'guid', 'kind' => 'guid', 'maximumLength' => 36]]];
$request = $builder->build(['guid' => $guid], $guidRoute);
$check($request['path'] === '/v1/generated/items/guid/' . $guid, 'GUID binding changed the supplied identity or its case.');

foreach (['', 1, null, [], str_repeat('a', 36), 'abcdef01-2345-6789-abcd-ef012345678',
	'abcdef01-2345-6789-abcd-ef01234567890', 'abcdef01-2345-6789-abcd-ef012345678g',
	$guid . '/other', '%' . $guid, '{' . $guid . '}'] as $invalid)
{
	$reject(static fn () => $builder->build(['guid' => $invalid], $guidRoute));
}

$keyRoute = ['method' => 'GET', 'route' => '/v1/generated/items/key/:key',
	'route_parameters' => [['name' => 'key', 'kind' => 'unique-key', 'maximumLength' => 255]]];

foreach (['alpha', 'a key', 'alpha..beta', 'name:value', 'München', 'literal+$value', str_repeat('x', 255)] as $key)
{
	$request = $builder->build(['key' => $key], $keyRoute);
	$encoded = rawurlencode($key);
	$check($request['path'] === '/v1/generated/items/key/' . $encoded
		&& rawurldecode(basename($request['path'])) === $key, 'Unique-key route lost a literal segment or changed its value.');
}

foreach (['', '.', '..', '../other', 'other/child', 'other\\child', '%2fother', '%252fother',
	'key?query', 'key#fragment', "key\nheader", "key\0", "key\x7f", str_repeat('x', 256), 1, [], null] as $invalid)
{
	$reject(static fn () => $builder->build(['key' => $invalid], $keyRoute));
}

// Strict wire schemas retain stdClass objects for nested arguments. Both the
// schema and the transport must agree without admitting filter lists or trees.
$schemas = new SchemaValidator();
$listSchema = Json::encode(['type' => 'object', 'additionalProperties' => false, 'properties' => [
	'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
	'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
	'filter' => ['type' => 'object', 'maxProperties' => 32, 'additionalProperties' => ['type' => ['string', 'integer', 'number', 'boolean']]],
]]);
$listRoute = ['method' => 'GET', 'route' => '/v1/generated/items', 'paginated' => true, 'native_filter' => true];
$filter = ['search' => 'generated item & related', 'published' => 1, 'enabled' => false];

foreach ([false, true] as $strict)
{
	$arguments = $schemas->input(['offset' => 40, 'limit' => 20, 'filter' => $strict ? (object) $filter : $filter], $listSchema, $strict);
	$request = $builder->build($arguments, $listRoute);
	$check($request['query'] === ['page[offset]' => 40, 'page[limit]' => 20,
		'filter[search]' => $filter['search'], 'filter[published]' => 1, 'filter[enabled]' => 0], 'Nested native filters were not transferred intact.');
}
$request = $builder->build(['filter' => new stdClass()], $listRoute);
$check($request['query'] === ['page[offset]' => 0, 'page[limit]' => 20], 'An empty wire filter object was not accepted.');

foreach ([['search'], (object) ['search' => ['nested']], (object) ['search' => new stdClass()],
	(object) ['search' => null], (object) ['bad[name]' => 'x'], (object) ['search' => str_repeat('x', 2049)],
	array_fill_keys(array_map(static fn (int $index): string => 'field' . $index, range(1, 33)), 'x')] as $invalid)
{
	$reject(static fn () => $builder->build(['filter' => $invalid], $listRoute));
}
$reject(static fn () => $schemas->input(['filter' => ['search']], $listSchema, true));
$reject(static fn () => $schemas->input(['filter' => (object) ['search' => (object) ['nested' => 'x']]], $listSchema, true));

// Generated cleanFilter() accepts scalar lists for native multi-select state.
// That capability is explicit; existing Joomla scalar-filter bindings retain
// their original contract. Empty arrays have no lossless HTTP query encoding.
$multiRoute = array_replace($listRoute, ['native_filter_arrays' => true]);
$multiFilter = ['access' => [1, 3], 'published' => [0, 1], 'selected' => [true, false], 'search' => 'native multiselect'];
$request = $builder->build(['filter' => (object) $multiFilter], $multiRoute);
$check($request['query'] === ['page[offset]' => 0, 'page[limit]' => 20, 'filter[access][0]' => 1, 'filter[access][1]' => 3,
	'filter[published][0]' => 0, 'filter[published][1]' => 1, 'filter[selected][0]' => 1, 'filter[selected][1]' => 0,
	'filter[search]' => 'native multiselect'], 'Native filter lists changed their order, scalar values or query structure.');
$request = $builder->build(['filter' => ['access' => range(1, 64)]], $multiRoute);
$check(count($request['query']) === 66 && $request['query']['filter[access][63]'] === 64, 'The declared native filter-list bound changed.');

foreach ([[], range(1, 65), ['named' => 1], [[1]], [new stdClass()], [null],
	[str_repeat('x', 2049)], [INF], [NAN]] as $invalid)
{
	$reject(static fn () => $builder->build(['filter' => ['access' => $invalid]], $multiRoute));
}
$reject(static fn () => $builder->build(['filter' => ['access' => [1, 3]]], $listRoute));
$reject(static fn () => $builder->build(['filter' => ['access' => [1, 3]]], array_replace($multiRoute, ['native_filter_arrays' => 'true'])));

// Exercise the same declarations emitted from reviewed native route rules,
// rather than only constructing the transport configurations by hand.
$routes = [
	['method' => 'GET', 'route' => $guidRoute['route'], 'controller' => 'items.displayItem', 'variables' => ['guid'],
		'rules' => ['guid' => '([0-9a-fA-F-]{36})'], 'defaults' => ['component' => 'com_componentbuilder']],
	['method' => 'GET', 'route' => $keyRoute['route'], 'controller' => 'items.displayItem', 'variables' => ['key'],
		'rules' => ['key' => '([^/]+)'], 'defaults' => ['component' => 'com_componentbuilder']],
	['method' => 'GET', 'route' => $listRoute['route'], 'controller' => 'items.displayList', 'variables' => [],
		'rules' => [], 'defaults' => ['component' => 'com_componentbuilder']],
];
$graph = (new CatalogueBuilder())->build(['commands' => [], 'unsupported' => []], ['routes' => $routes, 'unsupported' => []]);
$documents = array_column($graph['entities']['schema'], 'document', 'id');

foreach ($graph['entities']['binding'] as $registered)
{
	$configuration = Json::decode($registered['configuration']);
	$arguments = match ($configuration['route']) {
		$guidRoute['route'] => ['guid' => $guid],
		$keyRoute['route'] => ['key' => 'alpha..beta value'],
		default => ['filter' => (object) ($filter + ['access' => [1, 3]])],
	};
	$validated = $schemas->input($arguments, $documents[$registered['input_schema_id']], true);
	$request = $builder->build($validated, $configuration);
	$check($request['path'] === match ($configuration['route']) {
		$guidRoute['route'] => '/v1/generated/items/guid/' . $guid,
		$keyRoute['route'] => '/v1/generated/items/key/alpha..beta%20value',
		default => $listRoute['route'],
	}, 'A catalogue-generated identifier or nested filter declaration did not match its transport.');

	if ($configuration['route'] === $listRoute['route'])
	{
		$check(($configuration['native_filter_arrays'] ?? false) === true
			&& $request['query']['filter[access][0]'] === 1 && $request['query']['filter[access][1]'] === 3,
			'A generated catalogue list binding did not preserve native multi-select filters.');
	}

	if ($configuration['route'] === $keyRoute['route'])
	{
		foreach (['.', '..', '%2fother', 'other/child', 'other\\child'] as $invalid)
		{
			$reject(static fn () => $schemas->input(['key' => $invalid], $documents[$registered['input_schema_id']], true));
		}
	}
}

$writeRoute = array_replace($guidRoute, ['method' => 'PATCH', 'operation' => 'update', 'body_policy' => 'required',
	'preserve_fields' => ['guid'], 'body_defaults' => ['published' => 1]]);
$data = (object) ['name' => 'Changed name', 'settings' => (object) ['enabled' => true],
	'subform' => [(object) ['name' => 'child', 'empty' => new stdClass()]], 'code' => '<?php echo "stored source";'];
$request = $builder->build(['guid' => $guid, 'data' => $data], $writeRoute, ['guid' => $guid]);
$check($request['body']['guid'] === $guid && $request['body']['published'] === 1, 'Wire body normalization lost preservation or native defaults.');
$check($request['body']['settings'] instanceof stdClass && $request['body']['subform'][0]->empty instanceof stdClass,
	'Wire body normalization changed nested object/list shapes.');
$check(!property_exists($data, 'guid') && !property_exists($data, 'published'), 'Building a request mutated the caller\'s body object.');
$reject(static fn () => $builder->build(['guid' => $guid, 'data' => new stdClass()], $writeRoute));
$reject(static fn () => $builder->build(['guid' => $guid, 'data' => ['list']], $writeRoute));

/** Recording transport double verifies actual URI/body bytes, not persistence. */
$http = new class implements ClientInterface
{
	/** @var ?RequestInterface Last emitted request. */
	public ?RequestInterface $request = null;

	/** @inheritDoc */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$this->request = $request;
		return new Response(200, ['Content-Type' => 'application/vnd.api+json'], '{"data":[]}');
	}
};
$principal = new Principal('joomla:17', 'api', [1]);
$handler = new ApiHandler($http, $builder, new Settings(['api_base' => 'https://joomla.example/api/index.php']), 'fixture-token', static fn (): string => 'unused');
$binding = ['handler' => 'api.request', 'track' => 'api', 'configuration' => $listRoute];
$handler->execute(['offset' => 40, 'filter' => (object) $filter], $binding, $principal);
parse_str($http->request->getUri()->getQuery(), $query);
$check($query === ['page' => ['offset' => '40', 'limit' => '20'],
	'filter' => ['search' => $filter['search'], 'published' => '1', 'enabled' => '0']], 'HTTP query encoding did not reach Joomla\'s native nested parameter shape.');
$handler->execute(['filter' => (object) $multiFilter], array_replace($binding, ['configuration' => $multiRoute]), $principal);
parse_str($http->request->getUri()->getQuery(), $query);
$check($query === ['page' => ['offset' => '0', 'limit' => '20'], 'filter' => ['access' => ['1', '3'], 'published' => ['0', '1'],
	'selected' => ['1', '0'], 'search' => 'native multiselect']], 'HTTP query encoding did not preserve native scalar-list filter values.');
$handler->request(['guid' => $guid, 'data' => $data], array_replace($binding, ['configuration' => $writeRoute]), $principal, null, ['guid' => $guid]);
$sent = Json::decode((string) $http->request->getBody(), false);
$check($http->request->getUri()->getPath() === '/api/index.php/v1/generated/items/guid/' . $guid
	&& $sent->guid === $guid && $sent->settings instanceof stdClass
	&& is_array($sent->subform) && $sent->subform[0]->empty instanceof stdClass && $sent->code === $data->code,
	'HTTP mutation altered its GUID, nested subforms, or inert source text.');

// Existing Joomla route primitives remain distinct and continue to resolve.
foreach ([['positive-integer', 42, '42'], ['component-name', 'com_content', 'com_content'],
	['language-code', 'en-GB', 'en-GB'], ['override-constant', 'COM_CONTENT_TITLE', 'COM_CONTENT_TITLE'],
	['adapter-id', 'local-images', 'local-images'], ['media-path', 'images/folder/image one.jpg', 'images/folder/image%20one.jpg']] as [$kind, $value, $encoded])
{
	$route = ['method' => 'GET', 'route' => '/v1/core/items/:value', 'route_parameters' => [['name' => 'value', 'kind' => $kind]]];
	$check($builder->build(['value' => $value], $route)['path'] === '/v1/core/items/' . $encoded, 'A Joomla core route primitive regressed: ' . $kind);
}
$reject(static fn () => $builder->build(['value' => '42'], ['method' => 'GET', 'route' => '/v1/core/items/:value',
	'route_parameters' => [['name' => 'value', 'kind' => 'positive-integer']]]));
$reject(static fn () => $builder->build(['value' => 'images/../private'], ['method' => 'GET', 'route' => '/v1/core/items/:value',
	'route_parameters' => [['name' => 'value', 'kind' => 'media-path']]]));

echo Json::encode(['checks' => $checks, 'generatedApiTransport' => 'passed', 'liveJoomla' => 'not run']) . PHP_EOL;
