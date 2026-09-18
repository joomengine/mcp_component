<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';
$builder = new ApiRequestBuilder();
$checks = 0;

foreach (['/v1/content/articles', '/v1/content/articles/', '/v1/media/files/:path/'] as $route)
{
	$configuration = ['method' => 'GET', 'route' => $route];
	$arguments = [];
	$expected = $route;

	if (str_contains($route, ':path'))
	{
		$configuration['route_parameters'] = [['name' => 'path', 'kind' => 'media-path']];
		$arguments['path'] = 'local-images:/folder';
		$expected = '/v1/media/files/local-images%3A/folder/';
	}

	if ($builder->build($arguments, $configuration)['path'] !== $expected)
	{
		throw new RuntimeException('A canonical native route must retain its significant trailing slash.');
	}

	$checks++;
}

foreach (['/v1/content//articles', '/v1/content/articles//', '/v1/media/../files', '/v1/media/%2e%2e/files',
	'//other.test/v1/media', 'https://other.test/v1/media', '/v1/media?host=other', '/v1/media#ignored'] as $route)
{
	try
	{
		$builder->build([], ['method' => 'GET', 'route' => $route]);
	}
	catch (OperationException $error)
	{
		if ($error->getIdentifier() !== 'BINDING_INVALID')
		{
			throw $error;
		}

		$checks++;
		continue;
	}

	throw new RuntimeException('An unsafe route was accepted.');
}

echo json_encode(['checks' => $checks, 'canonicalRoutes' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
