<?php
/**
 * @package    JoomEngine.Mcp
 * @created    9 November 2026
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
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;

require dirname(__DIR__) . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void
{
	$checks++;
	if (!$value)
	{
		throw new RuntimeException($message);
	}
};
$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/admin/data/catalogue-seed.json'))['entities'];
$fixture = static function (array $record, ?string $timezone) use ($seed): array
{
	$http = new class implements ClientInterface
	{
		public array $record = [];
		public array $bodies = [];
		public int $writes = 0;
		public function sendRequest(RequestInterface $request): ResponseInterface
		{
			if ($request->getMethod() !== 'GET')
			{
				$this->writes++;
				$this->bodies[] = Json::decode((string) $request->getBody());
			}
			return new Response(200, ['Content-Type' => 'application/vnd.api+json'],
				Json::encode(['data' => ['id' => 87, 'attributes' => $this->record]]));
		}
	};
	$http->record = $record;
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
	$executorForTimezone = static fn (?string $zone): ActionExecutor => new ActionExecutor($catalogue, $schemas, $principal,
		new HandlerRegistry(['api.request' => $handler]), $permissions, $state, $audit, $settings, $builder, userTimezone: $zone);
	$executor = $executorForTimezone($timezone);
	$request = $permissions->request(['toolsets' => ['content.write', 'structure.write'], 'duration' => '30-minutes', 'reason' => 'Calendar contracts']);
	$permissions->approve($request['requestId'], $request['acknowledgement']);
	return compact('http', 'store', 'catalogue', 'executor', 'executorForTimezone', 'state');
};

foreach (['content.articles' => ['created', 'publish_up', 'publish_down'], 'modules.site' => ['publish_up', 'publish_down'],
	'modules.administrator' => ['publish_up', 'publish_down']] as $resource => $fields)
{
	foreach (['create', 'update'] as $operation)
	{
		foreach ($fields as $field)
		{
			foreach ([['2026-11-09 12:30:45', '2026-11-09 11:30:45', 'Europe/Amsterdam'],
				['2026-07-09 12:30:45', '2026-07-09 10:30:45', 'Europe/Amsterdam'],
				['2026-11-09 12:30:45', '2026-11-09 06:45:45', 'Asia/Kathmandu']] as [$desired, $observed, $zone])
			{
				$data = [$field => $desired, 'title' => 'Calendar fixture'];
				if ($resource === 'content.articles' && $operation === 'create')
				{
					$data += ['catid' => 2];
				}
				if (str_starts_with($resource, 'modules.') && $operation === 'create')
				{
					$data += ['module' => 'mod_custom', 'ordering' => 1];
				}
				$f = $fixture(array_replace($data, [$field => $observed]), $zone);
				$input = ['data' => $data] + ($operation === 'update' ? ['id' => 87] : []);
				$plan = $f['executor']->plan($resource . '.' . $operation, $input, Json::uuid());
				$check(($plan['operation']['nativeDates']['timezone'] ?? null) === $zone, 'Plan discloses trusted date timezone.');
				$result = $f['executor']->apply($plan['confirmationToken']);
				$check(($result['verification']['differentFields'] ?? null) === []
					&& in_array($field, $result['verification']['matchedFields'], true), 'Native date instant verifies: ' . $resource . '.' . $operation . ' ' . $field);
				$check($f['http']->bodies[0][$field] === $desired && $f['http']->writes === 1, 'Approved date is sent unchanged once.');
				$check($f['store']->find('lease') === [], 'Verified date releases the write lease.');
				$replay = $f['executor']->apply($plan['confirmationToken']);
				$check($replay['idempotentReplay'] && $f['http']->writes === 1, 'Date plan replay does not write again.');
			}
		}
	}
}

$f = $fixture(['title' => 'Calendar fixture', 'publish_up' => '2026-11-09 11:30:45'], 'Europe/Amsterdam');
$write = $f['catalogue']->action('content.articles.update', 'api');
$read = $f['catalogue']->action('content.articles.get', 'api');
$compare = static fn (mixed $desired, mixed $observed, ?string $zone = 'Europe/Amsterdam'): ?bool =>
	ApiWriteVerification::compare($write, $read, 'publish_up', $desired, $observed, userTimezone: $zone);
$check($compare('2026-11-09 12:30:45', '2026-11-09 12:30:45') === false, 'Same text is not the same instant in a non-UTC user timezone.');
$check($compare('2026-11-09 12:30:45', '2026-11-09 11:30:46') === false, 'A one-second change is a mismatch.');
$check($compare('2026-02-30 12:30:45', '2026-03-02 11:30:45') === false, 'Invalid calendar dates never normalize into success.');
$check($compare('tomorrow', '2026-11-10 00:00:00') === null, 'Relative dates have no inferred absolute instant.');
$check($compare('2026-11-09 +1 day', '2026-11-10 00:00:00') === null, 'Relative suffixes do not acquire absolute date equality.');
foreach (['2026-11-09', '2026-11-09 12:30', '2026-11-09T12:30:45Z', '2026-11-09 12:30:45+02:00'] as $unsupported)
{
	$check($compare($unsupported, '2026-11-09 11:30:00') === null, 'Formats rejected by the native calendar do not acquire normalized equality.');
}
$check($compare('2026-11-09 12:30:45', '2026-11-09 11:30:45', null) === null, 'Unknown timezone cannot justify a shifted value.');
foreach (['route' => '/v1/custom/articles/:id', 'method' => 'POST', 'authentication' => 'none'] as $key => $value)
{
	$custom = $write;
	$custom['binding']['configuration'][$key] = $value;
	$check(ApiWriteVerification::compare($custom, $read, 'publish_up', '2026-11-09 12:30:45', '2026-11-09 11:30:45', userTimezone: 'Europe/Amsterdam') === null,
		'Customized write binding cannot inherit calendar normalization.');
	$customRead = $read;
	$customRead['binding']['configuration'][$key] = $value;
	$check(ApiWriteVerification::compare($write, $customRead, 'publish_up', '2026-11-09 12:30:45', '2026-11-09 11:30:45', userTimezone: 'Europe/Amsterdam') === null,
		'Customized read binding cannot inherit calendar normalization.');
}
$plan = $f['executor']->plan('content.articles.update', ['id' => 87, 'data' => ['publish_up' => '2026-11-09 12:30:45']], Json::uuid());
try
{
	$f['executorForTimezone']('UTC')->apply($plan['confirmationToken']);
	throw new RuntimeException('Changed timezone was not rejected.');
}
catch (OperationException $error)
{
	$check($error->getIdentifier() === 'PRECONDITION_CHANGED' && $f['http']->writes === 0
		&& $f['store']->find('execution') === [] && $f['store']->find('lease') === [], 'Changed timezone fails before claiming or mutating.');
}
foreach ([['2026-11-09 12:30:45', '2026-11-09 12:30:45', 'Europe/Amsterdam'],
	['2026-11-09 12:30:45', '2026-11-09 11:30:45', null]] as [$desired, $observed, $zone])
{
	$f = $fixture(['publish_up' => $observed], $zone);
	$plan = $f['executor']->plan('modules.site.update', ['id' => 87, 'data' => ['publish_up' => $desired]], Json::uuid());
	$result = $f['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === 'uncertain' && $f['store']->find('lease') !== [], 'Unproven calendar change retains uncertainty and lease.');
}
echo Json::encode(['checks' => $checks, 'dates' => 'native UTC read-back, exact bindings, timezone-bound plans and immutable replay']) . PHP_EOL;
