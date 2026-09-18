<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Psr\Http\Client\ClientInterface;
use VDM\Component\JoomEngineMcp\Administrator\Http\CurlClient;
use VDM\Component\JoomEngineMcp\Administrator\Http\NetworkException;


$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
$surface = json_decode(file_get_contents($root . '/docs/integrations/jcb-surface.json'), true, 64, JSON_THROW_ON_ERROR);
$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($name);
	}

	$checks++;
};

$check($composer['name'] === 'joomengine/mcp-component', 'Component package identity is server-only.');
$dependencies = ($composer['require'] ?? []) + ($composer['require-dev'] ?? []);
$check(!isset($dependencies['joomengine/mcp-client']), 'The server must not depend on its external client.');

foreach (array_keys($composer['autoload']['psr-4']) as $namespace)
{
	$check(str_starts_with($namespace, 'VDM\\Component\\JoomEngineMcp\\'), 'First-party server autoloading must remain component-owned.');
}

$check(!is_dir($root . '/libraries/src'), 'The extracted external-client library tree must not remain.');
$check(new CurlClient() instanceof ClientInterface, 'Server-owned outbound HTTP transport must remain available.');
$check(is_subclass_of(NetworkException::class, Psr\Http\Client\NetworkExceptionInterface::class), 'Server transport retains its failure contract.');
$check($surface['integrationRequired'] === true && $surface['api']['required'] === true && $surface['cli']['required'] === true,
	'JCB API and CLI remain required integration workstreams.');
$check($surface['runtimeEnabled'] === false, 'The planning inventory is not an executable runtime seed.');
$entities = $surface['factoryEntities'];
$check(count($entities) === $surface['factoryEntityCount'] && count(array_unique($entities)) === count($entities),
	'Factory inventory has unique entities and truthful counts.');
$check(array_diff($surface['specializedFactoryEntities'], $entities) === [], 'Specialized factory entries belong to the canonical inventory.');

echo json_encode(['checks' => $checks, 'repositoryOwnership' => 'passed', 'jcbInventory' => 'planning only', 'liveJoomla' => 'not run'], JSON_THROW_ON_ERROR) . PHP_EOL;
