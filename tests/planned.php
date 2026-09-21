<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Contract\DeferredHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PlannedHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Job\Artifacts;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
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

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never
{
	throw new ErrorException($message, 0, $severity, $file, $line);
});

/** Reviewed lifecycle fixture, intentionally incapable of unplanned execution. */
class LifecycleHandler implements PlannedHandlerInterface
{
	/** @var array Captured validated preparation arguments. */
	public array $arguments = [];
	/** @var int Deterministic external configuration revision. */
	public int $revision = 1;
	/** @var int Mutation count. */
	public int $writes = 0;
	/** @var string Verification observation. */
	public string $verification = 'verified';
	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		throw new RuntimeException('Planned handlers cannot fall through to legacy execution.');
	}
	/** @inheritDoc */
	public function prepare(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		$this->arguments[] = $arguments;

		return ['value' => $arguments['value'], 'revision' => $this->revision, 'owner' => $principal->getId(), 'track' => $principal->getTrack()];
	}
	/** @inheritDoc */
	public function apply(array $prepared, array $binding, PrincipalInterface $principal, array $execution): array
	{
		if (($prepared['revision'] ?? null) !== $this->revision)
		{
			throw new OperationException('PREFLIGHT_CHANGED', 'The domain configuration changed after planning.');
		}

		if (($prepared['owner'] ?? '') !== $principal->getId() || ($prepared['track'] ?? '') !== $principal->getTrack()
			|| !isset($execution['uuid'], $execution['fingerprint']))
		{
			throw new RuntimeException('Mutation received no principal-bound execution.');
		}

		$this->writes++;

		return ['observed' => $prepared['value'], 'writes' => $this->writes];
	}
	/** @inheritDoc */
	public function verify(array $prepared, array $result, array $binding, PrincipalInterface $principal): array
	{
		return ['status' => $this->verification, 'observed' => $result['observed']];
	}
}

/** Same reviewed lifecycle dispatched only after a durable worker claim. */
final class DeferredLifecycleHandler extends LifecycleHandler implements DeferredHandlerInterface
{
}

$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void
{
	$checks++;

	if (!$value)
	{
		throw new RuntimeException($message);
	}
};
$reject = static function (callable $operation, string $code) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === $code, 'Expected ' . $code . ', got ' . $error->getIdentifier());
		return;
	}

	throw new RuntimeException('Expected rejection: ' . $code);
};
$directories = [];
$fixture = static function (bool $deferred = false, string $track = 'api') use (&$directories): array
{
	$seed = Json::decode(file_get_contents(dirname(__DIR__) . '/data/catalogue-seed.json'))['entities'];
	$schema = $seed['schema'][0];
	$schema['id'] = 99999;
	$schema['name'] = 'fixture.lifecycle.input';
	$schema['document'] = Json::encode(['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value'], 'additionalProperties' => false]);
	$seed['schema'][] = $schema;
	$actionId = null;

	foreach ($seed['action'] as &$action)
	{
		if ($action['name'] === 'content.articles.create')
		{
			$actionId = $action['id'];
			$action['input_schema_id'] = $schema['id'];
		}
	}
	unset($action);

	foreach ($seed['binding'] as &$binding)
	{
		if ($binding['action_id'] === $actionId)
		{
			$binding['published'] = $binding['track'] === 'api' ? 1 : 0;

			if ($binding['track'] === 'api')
			{
				$binding['track'] = $track;
				$binding['handler'] = 'fixture.lifecycle';
				$binding['input_schema_id'] = $schema['id'];
				$binding['output_schema_id'] = null;
				$binding['configuration'] = '{}';
			}
		}
	}
	unset($binding);
	$store = new MemoryStore($seed);
	$principal = new Principal($track === 'api' ? 'joomla:17' : 'local-fixture', $track, [1]);
	$settings = new Settings(['api_base' => 'https://joomla.example/api/index.php']);
	$schemas = new SchemaValidator();
	$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
		static fn (string $extension): bool => true, static fn (string $entity, string $handler): bool => true);
	$clock = static fn (): int => time();
	$audit = new Audit($store, $principal, $clock);
	$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
	$envelope = new Envelope(str_repeat('planned-fixture-secret-', 3));
	$executions = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
	$handler = $deferred ? new DeferredLifecycleHandler() : new LifecycleHandler();
	$registry = new HandlerRegistry(['fixture.lifecycle' => $handler]);
	$directory = sys_get_temp_dir() . '/mcp-planned-' . bin2hex(random_bytes(8));
	$directories[] = $directory;
	$artifacts = new Artifacts($store, $principal, $directory, [], $clock);
	$dispatch = new stdClass();
	$dispatch->tickets = [];
	$dispatch->launches = 0;
	$launcher = static function (string $id, string $ticket) use ($dispatch): void
	{
		$dispatch->tickets[$id] = $ticket;
		$dispatch->launches++;
	};
	$jobs = new Jobs($store, $principal, $envelope, $artifacts, $clock, $launcher, [$executions, 'finish']);
	$executor = new ActionExecutor($catalogue, $schemas, $principal, $registry, $permissions, $executions, $audit, $settings, new ApiRequestBuilder(), $jobs, static function (): void {});
	$grant = static function (string $duration = 'once') use ($permissions): array
	{
		$request = $permissions->request(['toolsets' => ['content.write'], 'duration' => $duration, 'reason' => 'Lifecycle behavior test']);

		return $permissions->approve($request['requestId'], $request['acknowledgement']);
	};

	return compact('store', 'principal', 'catalogue', 'permissions', 'executions', 'handler', 'jobs', 'executor', 'grant', 'dispatch');
};
$input = ['value' => 'approved-definition'];

try
{
	$f = $fixture();
	$dry = $f['executor']->plan('content.articles.create', $input, Json::uuid(), true);
	$check($dry['dryRun'] && $f['handler']->writes === 0 && $f['store']->find('plan') === [], 'Preparation previews never mutate or create executable confirmations.');
	$reject(static fn () => $f['executor']->plan('content.articles.create', $input, Json::uuid()), 'PERMISSION_REQUIRED');
	$grant = ($f['grant'])();
	$plan = $f['executor']->plan('content.articles.create', $input, Json::uuid());
	$check($f['handler']->writes === 0 && $f['permissions']->list()[0]['remainingUses'] === 1, 'Preparation neither invokes the handler mutation nor consumes its grant.');
	$f['handler']->revision++;
	$reject(static fn () => $f['executor']->apply($plan['confirmationToken']), 'PREFLIGHT_CHANGED');
	$check($f['handler']->writes === 0 && $f['store']->find('execution') === [], 'Changed preparation rejects before the durable execution claim.');
	$f['handler']->revision--;
	$result = $f['executor']->apply($plan['confirmationToken']);
	$check($result['verification']['status'] === 'verified' && $f['handler']->writes === 1 && $f['permissions']->list() === [], 'Confirmed prepared mutation consumes exactly one grant and verifies its actual result.');
	$again = $f['executor']->apply($plan['confirmationToken']);
	$check($again['idempotentReplay'] && $f['handler']->writes === 1, 'Completed planned execution is replayed from durable state.');

	$local = $fixture(false, 'cli');
	$localPlan = $local['executor']->plan('content.articles.create', $input, Json::uuid());
	$local['executor']->apply($localPlan['confirmationToken']);
	$check($local['handler']->writes === 1 && $local['handler']->arguments === [$input, $input], 'The Planned lifecycle never injects legacy dryRun or confirmation flags into CLI schemas.');

	foreach (['partial', 'unverified'] as $verification)
	{
		$incomplete = $fixture();
		($incomplete['grant'])();
		$incomplete['handler']->verification = $verification;
		$uncertainPlan = $incomplete['executor']->plan('content.articles.create', $input, Json::uuid());
		$incomplete['executor']->apply($uncertainPlan['confirmationToken']);
		$check($incomplete['store']->find('execution')[0]['status'] === 'uncertain' && count($incomplete['store']->find('lease')) === 1, 'Incomplete native evidence retains a reconciliation lease: ' . $verification);
	}

	$async = $fixture(true);
	($async['grant'])();
	$asyncPlan = $async['executor']->plan('content.articles.create', $input, Json::uuid());
	$queued = $async['executor']->apply($asyncPlan['confirmationToken'])['job'];
	$check($queued['status'] === 'queued' && $async['handler']->writes === 0 && $async['dispatch']->launches === 1, 'Deferred apply queues a committed execution without inline mutation.');
	$replay = $async['executor']->apply($asyncPlan['confirmationToken'])['job'];
	$check($replay['jobId'] === $queued['jobId'] && $async['handler']->writes === 0 && $async['dispatch']->launches === 1, 'Queued apply replay resolves the same job without launching another worker.');
	$check($async['permissions']->list() === [] && count($async['store']->find('lease')) === 1, 'The queued job retains its claimed lease and consumed one-use grant.');
	$completed = $async['jobs']->run($queued['jobId'], $async['dispatch']->tickets[$queued['jobId']], [$async['executor'], 'runJob']);
	$check($completed['status'] === 'completed' && $async['handler']->writes === 1 && $async['store']->find('lease') === [], 'Delayed execution honors a consumed bound grant exactly once and settles the same claim.');
	$replay = $async['executor']->apply($asyncPlan['confirmationToken']);
	$check($replay['idempotentReplay'] && $async['handler']->writes === 1, 'Completed asynchronous apply returns the durable result.');

	$revoked = $fixture(true);
	$grant = ($revoked['grant'])();
	$revokedPlan = $revoked['executor']->plan('content.articles.create', $input, Json::uuid());
	$queued = $revoked['executor']->apply($revokedPlan['confirmationToken'])['job'];
	$revoked['permissions']->revoke($grant['id']);
	$stopped = $revoked['jobs']->run($queued['jobId'], $revoked['dispatch']->tickets[$queued['jobId']], [$revoked['executor'], 'runJob']);
	$check($revoked['handler']->writes === 0 && $stopped['status'] !== 'completed', 'Revoked grants are rechecked before delayed mutation.');
	$check(($stopped['result']['error']['code'] ?? '') === 'GRANT_UNAVAILABLE', 'Delayed authorization failure retains its precise non-secret error code.');

	$changed = $fixture(true);
	($changed['grant'])();
	$changedPlan = $changed['executor']->plan('content.articles.create', $input, Json::uuid());
	$queued = $changed['executor']->apply($changedPlan['confirmationToken'])['job'];
	$changed['handler']->revision++;
	$stopped = $changed['jobs']->run($queued['jobId'], $changed['dispatch']->tickets[$queued['jobId']], [$changed['executor'], 'runJob']);
	$check($changed['handler']->writes === 0 && $stopped['status'] !== 'completed', 'A queued job cannot execute after the prepared configuration changes.');
	$check(($stopped['result']['error']['code'] ?? '') === 'PREFLIGHT_CHANGED', 'Worker preparation drift is distinguished from a target mutation failure.');

	echo Json::encode(['checks' => $checks, 'plannedLifecycle' => 'passed against transactional persistence and reviewed handler doubles', 'liveJoomla' => 'not run by this contract suite']) . PHP_EOL;
}
finally
{
	foreach ($directories as $directory)
	{
		rmdir($directory);
	}

	restore_error_handler();
}
