<?php
// Fixed full-scale catalogue fixture, with the same128MiB budget as live HTTP.
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;

ini_set('memory_limit', '128M');
require dirname(__DIR__, 2) . '/admin/autoload.php';
require dirname(__DIR__) . '/Support/MemoryStore.php';
require dirname(__DIR__) . '/Support/Principal.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
};
$reject = static function (callable $operation, string $expected) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $expected, 'Unexpected catalogue failure: ' . $error->getIdentifier());
		return;
	}
	throw new RuntimeException('The catalogue operation should have been rejected.');
};
$fields = [];
$properties = [];
$verification = [];
for ($index = 0; $index < 60; $index++)
{
	$name = 'native_field_' . $index;
	$properties[$name] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Native source-backed field ' . $index];
	$fields[$name] = ['native_type' => 'list', 'label' => 'COM_EXAMPLE_FIELD_' . $index,
		'required' => false, 'readonly' => false, 'disabled' => false, 'default' => 'first', 'filter' => 'STRING',
		'validation' => '', 'multiple' => false, 'choices' => [
			['value' => 'first', 'label' => 'COM_EXAMPLE_FIRST', 'disabled' => false],
			['value' => 'second', 'label' => 'COM_EXAMPLE_SECOND', 'disabled' => false],
			['value' => 'third', 'label' => 'COM_EXAMPLE_THIRD', 'disabled' => false]]];
	$verification[$name] = ['representation' => 'string', 'properties' => [], 'numeric' => false];
}
$form = ['schema' => ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => true],
	'fields' => $fields, 'verification' => ['fields' => $verification, 'request_only' => []], 'generation' => [],
	'native_required_adjustments' => false, 'provenance' => ['model' => 'record', 'form' => 'record',
		'sources' => [['path' => 'administrator/forms/record.xml', 'sha256' => hash('sha256', 'native-fixture')]]]];
$form['fingerprint'] = Json::canonicalHash($form);
$routes = [];
for ($index = 0; $index < 320; $index++)
{
	$routes[] = ['method' => 'GET', 'route' => '/v1/example/records_' . $index . '/:id', 'controller' => 'record.displayItem',
		'defaults' => ['component' => 'com_example'], 'variables' => ['id'], 'rules' => ['id' => '(\\d+)'],
		'required_extensions' => ['webservices/ExampleRoutes'], 'form_contract' => $form];
}
$seed = (new CatalogueBuilder())->build(['commands' => []], ['routes' => $routes, 'components' => ['com_example']]);
$store = new MemoryStore($seed['entities']);
$names = array_column($seed['entities']['action'], 'name');
$configuration = $seed['entities']['binding'][159]['configuration'];
unset($seed, $routes, $form, $properties, $fields, $verification);
$principal = new Principal();
$schemas = new SchemaValidator();
$extensions = true;
$handlers = true;
$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, new Settings(['joomla_version' => '6.1.4']),
	static function (string $extension) use (&$extensions): bool { return $extensions; },
	static function (string $entity, string $handler) use (&$handlers): bool { return $handlers; });
$catalogue->refresh();
$check(count($catalogue->all('action')) === 320, 'All320 complete native-form routes must be available.');
$resolved = $catalogue->action($names[0]);
$check(count($resolved['binding']['configuration']['api_form']['fields']) === 60, 'Native form descriptors must remain complete.');
unset($resolved);
$firstBytes = memory_get_usage(true);
fwrite(STDERR, 'Initial complete320-route snapshot: ' . $firstBytes . " bytes.\n");
$catalogue->refresh();
$check(count($catalogue->all('action')) === 320, 'Repeated refresh must retain every native route under128MiB.');
for ($repeat = 0; $repeat < 3; $repeat++)
{
	$catalogue->refresh();
	$check(count($catalogue->all('action')) === 320, 'Persistent refresh cannot accumulate full native-form snapshots.');
}
$store->update('action', ['title' => 'Administrator changed title', 'name' => 'administrator.changed.action'], ['id' => 1]);
$catalogue->refresh();
$check($catalogue->get('action', 'administrator.changed.action')['title'] === 'Administrator changed title', 'Changed names and metadata must be read from current rows.');
$reject(static fn () => $catalogue->get('action', $names[0]), 'DEFINITION_UNAVAILABLE');
$binding = $catalogue->action('administrator.changed.action')['binding'];
$schemaId = (int) $binding['input_schema_id'];
unset($binding);
$store->update('schema', ['document' => '{"type":"object","properties":{"id":{"type":"integer","maximum":7}},"required":["id"]}', 'customized' => 1], ['id' => $schemaId]);
$catalogue->refresh();
$current = $catalogue->action('administrator.changed.action');
$check($schemas->input(['id' => 7], $catalogue->schema($schemaId)) === ['id' => 7], 'Customized current input schemas must remain effective.');
$reject(static fn () => $schemas->input(['id' => 8], $catalogue->schema($schemaId)), 'INVALID_INPUT');
unset($current);
$extensions = false;
$catalogue->refresh();
$check($catalogue->all('action') === [], 'Installed extension revocation must invalidate cached visibility and bindings.');
$extensions = true;
$handlers = false;
$catalogue->refresh();
$check($catalogue->all('action') === [], 'Registered handler revocation must invalidate cached execution availability.');
$handlers = true;
$catalogue->refresh();
$check(count($catalogue->all('action')) === 320, 'Fresh extension and handler observations must restore all available routes.');
$beforeBinding = $store->one('binding', ['id' => 160]);
$changedConfiguration = Json::decode($configuration);
$changedConfiguration['administrator_note'] = 'Current raw administrator configuration';
$configuration = Json::encode($changedConfiguration);
$store->update('binding', ['configuration' => $configuration], ['id' => 160]);
$catalogue->refresh();
$changed = $catalogue->action($names[159])['binding'];
$check($changed['configuration']['administrator_note'] === 'Current raw administrator configuration'
	&& $changed['version'] === $beforeBinding['version'] && $changed['seed_hash'] === $beforeBinding['seed_hash'],
	'Raw configuration edits are refreshed even when version and installer ownership markers are unchanged.');
unset($beforeBinding, $changedConfiguration, $changed);
$store->update('binding', ['configuration' => '{invalid-json'], ['id' => 160]);
$reject(static fn () => $catalogue->refresh(), 'INVALID_JSON');
$reject(static fn () => $catalogue->get('action', 'administrator.changed.action'), 'INVALID_JSON');
$reject(static fn () => $catalogue->all('action'), 'INVALID_JSON');
$reject(static fn () => $catalogue->action($names[319]), 'INVALID_JSON');
$store->update('binding', ['configuration' => $configuration], ['id' => 160]);
$store->update('action', ['access' => 9], ['id' => 1]);
$reject(static fn () => $catalogue->get('action', 'administrator.changed.action'), 'DEFINITION_UNAVAILABLE');
$check(count($catalogue->all('action')) === 319, 'Recovery must read current rows rather than restoring a previously authorized snapshot.');
$principal->deny('mcp.execute', 'com_joomengine_mcp.provider.1');
$catalogue->refresh();
$check($catalogue->all('action') === [], 'Fresh principal permission revocation must hide all native routes.');
$check(ini_get('memory_limit') === '128M', 'The fixed process must keep the real128MiB budget.');
echo Json::encode(['protocol' => 'joomengine-worker/1', 'checks' => $checks, 'routes' => 320, 'fieldsPerContract' => 60,
	'firstSnapshotBytes' => $firstBytes, 'peakBytes' => memory_get_peak_usage(true), 'memoryLimit' => ini_get('memory_limit')]) . PHP_EOL;
