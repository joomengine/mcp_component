<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Http\Boundary;
use VDM\Component\JoomEngineMcp\Administrator\Http\RequestHeaders;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	$checks++;

	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};
$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php', 'allowed_origins' => ['https://client.example']]);
$boundary = new Boundary($settings);
/** Test-local terminal handler records whether rejected transport requests reached the application. */
$next = new class implements RequestHandlerInterface
{
	/** @var int Invocation count. */
	public int $calls = 0;

	/** @inheritDoc */
	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		$this->calls++;

		return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
	}
};
$request = new ServerRequest('POST', 'https://joomla.example/api/index.php/v1/joomengine-mcp', ['Content-Type' => 'application/json'], '{}');
$response = $boundary->process($request, $next);
$check($response->getStatusCode() === 200 && $next->calls === 1, 'Canonical non-browser request did not pass.');
$check($response->getHeaderLine('Cache-Control') === 'no-store', 'Private protocol response can be cached.');
$check($response->getHeaderLine('Access-Control-Allow-Origin') === '', 'Non-browser request received wildcard CORS.');

foreach (['attacker.example', 'joomla.example.attacker.example', 'joomla.example:80', 'joomla.example, attacker.example'] as $host)
{
	$check($boundary->process($request->withHeader('Host', $host), $next)->getStatusCode() === 403, 'Noncanonical Host passed: ' . $host);
}

$check($boundary->process($request->withHeader('Host', 'joomla.example:443'), $next)->getStatusCode() === 200, 'Explicit default TLS port rejected.');

foreach (['null', 'https://client.example.attacker.example', 'https://attacker.example', 'https://client.example/'] as $origin)
{
	$check($boundary->process($request->withHeader('Origin', $origin), $next)->getStatusCode() === 403, 'Unapproved Origin passed.');
}

$response = $boundary->process($request->withHeader('Origin', 'https://client.example'), $next);
$check($response->getHeaderLine('Access-Control-Allow-Origin') === 'https://client.example', 'Approved origin was not reflected exactly.');
$check($response->getHeaderLine('Access-Control-Allow-Credentials') === 'true', 'Configured credentialed browser origin was not supported.');
$check(str_contains($response->getHeaderLine('Vary'), 'Origin'), 'Origin-varying response omitted cache separation.');
$check($boundary->process($request->withHeader('Content-Type', 'text/plain'), $next)->getStatusCode() === 415, 'Wrong content type accepted.');
$check($boundary->process($request->withHeader('Content-Length', '1048577'), $next)->getStatusCode() === 413, 'Oversized declared body accepted.');
$check($boundary->process($request->withHeader('Content-Length', '-1'), $next)->getStatusCode() === 413, 'Malformed length accepted.');
$check($boundary->process(new ServerRequest('POST', 'https://joomla.example/', ['Content-Type' => 'application/json'], str_repeat('x', 1048577)), $next)->getStatusCode() === 413, 'Actual oversized body accepted.');
$preflight = new ServerRequest('OPTIONS', 'https://joomla.example/api/index.php/v1/joomengine-mcp', [
	'Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'POST',
	'Access-Control-Request-Headers' => 'Authorization, Mcp-Method, Mcp-Name, Mcp-Param-Tenant',
]);
$response = $boundary->preflight($preflight);
$check($response->getStatusCode() === 204 && (string) $response->getBody() === '', 'CORS preflight contains protocol data or fails.');
$check(str_contains($response->getHeaderLine('Access-Control-Allow-Headers'), 'mcp-param-tenant'), 'Declared modern mirror header cannot pass preflight.');
$check($boundary->preflight($preflight->withHeader('Access-Control-Request-Headers', 'X-Unreviewed-Authorization'))->getStatusCode() === 403, 'Unreviewed CORS header accepted.');
$check($boundary->preflight($preflight->withHeader('Access-Control-Request-Method', 'TRACE'))->getStatusCode() === 405, 'Unsupported preflight method accepted.');
$check((new Boundary(new Settings([])))->validate($request)->getStatusCode() === 503, 'Unconfigured endpoint fell back to request-supplied Host.');
$headers = RequestHeaders::extract(['HTTP_HOST' => 'joomla.example', 'HTTP_AUTHORIZATION' => 'secret', 'HTTP_X_JOOMLA_TOKEN' => 'secret',
	'HTTP_MCP_METHOD' => 'tools/call', 'HTTP_MCP_NAME' => 'example', 'HTTP_MCP_PARAM_TENANT' => '42',
	'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://client.example', 'HTTP_COOKIE' => 'secret']);
$check($headers['mcp-method'] === 'tools/call' && $headers['mcp-name'] === 'example' && $headers['mcp-param-tenant'] === '42', 'Modern MCP headers were lost.');
$check(!isset($headers['authorization'], $headers['cookie']) && !isset($headers['x-joomla-token']), 'Credentials were copied into the SDK header set.');
$headers = RequestHeaders::extract([], ['Host' => 'joomla.example', 'Mcp-Param-tenant_id' => '42']);
$check($headers['mcp-param-tenant_id'] === '42', 'Original header spelling was not preserved where supplied.');

foreach ([['HTTP_MCP_NAME' => "x\r\nInjected:y"], ['HTTP_MCP_NAME' => []], ['HTTP_MCP_NAME' => str_repeat('a', 8193)]] as $invalid)
{
	try
	{
		RequestHeaders::extract($invalid);
		$check(false, 'Malformed protocol header passed extraction.');
	}
	catch (InvalidArgumentException)
	{
		$check(true, 'Malformed protocol header rejected.');
	}
}

echo Json::encode(['checks' => $checks, 'canonicalBoundary' => 'passed', 'protocolHeaders' => 'passed', 'authentication' => 'Joomla integration suite']) . PHP_EOL;
