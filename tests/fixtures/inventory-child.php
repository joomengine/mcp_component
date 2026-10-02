<?php
// Fixed inventory subprocess; input selects only a bounded fixture route count.
use VDM\Component\JoomEngineMcp\Administrator\Jcb\InventoryTransport;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require dirname(__DIR__, 2) . '/admin/autoload.php';
$input = Json::decode(stream_get_contents(STDIN));
$count = $input['routes'] ?? 80;
if (!is_int($count) || $count < 1 || $count > InventoryTransport::MAX_ROUTES)
{
	exit(1);
}
$schema = ['type' => 'object', 'properties' => (object) [
	'title' => ['type' => 'string', 'minLength' => 1, 'description' => str_repeat('Native field evidence. ', 6000)],
	'custom' => (object) [],
], 'required' => ['title'], 'additionalProperties' => true];
$form = ['schema' => $schema, 'fields' => ['title' => ['native_type' => 'text', 'required' => true]],
	'verification' => ['fields' => ['title' => [], 'rows' => ['representation' => 'json',
		'items' => ['representation' => 'object', 'properties' => (object) ['count' => ['numeric' => true]]]]], 'request_only' => []],
	'generation' => [], 'native_required_adjustments' => false,
	'provenance' => ['model' => 'record', 'form' => 'record', 'sources' => [
		['path' => 'administrator/forms/record.xml', 'sha256' => hash('sha256', 'native-source')]]],
	'empty_object' => (object) [], 'empty_list' => [], 'numeric_object' => (object) ['0' => 1.0, '1' => 2]];
$forms = [];
foreach (['POST', 'PATCH'] as $method)
{
	$contract = $form;
	if ($method !== 'POST')
	{
		unset($contract['schema']['required']);
	}
	$contract['fingerprint'] = hash('sha256', Json::canonical($contract));
	$forms[$method] = $contract;
}
$routes = [];
for ($index = 0; $index < $count; $index++)
{
	$method = $index % 2 === 0 ? 'POST' : 'PATCH';
	$routes[] = ['method' => $method, 'route' => '/v1/example/records_' . $index . ($method === 'PATCH' ? '/:id' : ''),
		'controller' => 'record.' . ($method === 'POST' ? 'add' : 'edit'),
		'defaults' => ['component' => 'com_example', 'public' => false, 'rate' => 1.0],
		'variables' => $method === 'POST' ? [] : ['id'],
		'rules' => $method === 'POST' ? [] : ['id' => '(\\d+)'],
		'required_extensions' => ['webservices/ExampleRoutes'], 'form_contract' => $forms[$method]];
}
$api = ['routes' => $routes, 'components' => ['com_example'], 'unsupported' => ['PUT /v1/example/custom' => 'Reviewed adapter required.'],
	'unsupported_components' => ['PUT /v1/example/custom' => 'com_example'],
	'native_observation' => ['empty' => (object) [], 'numeric' => (object) ['0' => 1.0]]];
$api['fingerprint'] = Json::canonicalHash(['routes' => $routes, 'unsupported' => $api['unsupported'], 'components' => $api['components']]);
$result = ['protocol' => 'joomengine-worker/1', 'commands' => ['commands' => [], 'unsupported' => []], 'api' => $api];
if (($input['inventory_format'] ?? null) === InventoryTransport::FORMAT)
{
	$result = InventoryTransport::pack($result);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
