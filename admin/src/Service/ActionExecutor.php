<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Closure;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\DeferredHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PlannedHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PlanPreviewInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiHandler;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;


/**
 * Executes database-defined reads and durable, principal-bound confirmed writes.
 *
 * Plans never authorize a different action or changed input. Execution intent and
 * one-shot grants are committed before mutation I/O. Unexpected mutation or
 * verification failures become retained uncertain outcomes, never automatic retries.
 *
 * @since 0.1.0
 */
final class ActionExecutor
{
	/** @var Catalogue Current authorized definitions. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var SchemaValidator Input/output validation. @since 0.1.0 */
	private SchemaValidator $schemas;
	/** @var PrincipalInterface Actual execution authority. @since 0.1.0 */
	private PrincipalInterface $principal;
	/** @var HandlerRegistry Reviewed primitive services. @since 0.1.0 */
	private HandlerRegistry $handlers;
	/** @var Permissions Current explicit write grants. @since 0.1.0 */
	private Permissions $permissions;
	/** @var Executions Durable plans, claims and outcomes. @since 0.1.0 */
	private Executions $executions;
	/** @var Audit Redacted durable events. @since 0.1.0 */
	private Audit $audit;
	/** @var Settings Installation settings. @since 0.1.0 */
	private Settings $settings;
	/** @var ApiRequestBuilder Preflight without network mutation. @since 0.1.0 */
	private ApiRequestBuilder $requests;
	/** @var ?Jobs Durable deferred operation service. @since 0.1.1 */
	private ?Jobs $jobs;
	/** @var ?Closure Checks the fixed worker before consuming a grant. @since 0.1.1 */
	private ?Closure $workerReady;

	/**
	 * Compose authorization, validation, execution and durable state boundaries.
	 *
	 * @param Catalogue $catalogue Current definitions.
	 * @param SchemaValidator $schemas Schema validation.
	 * @param PrincipalInterface $principal Actual authority.
	 * @param HandlerRegistry $handlers Primitive registry.
	 * @param Permissions $permissions Explicit grants.
	 * @param Executions $executions Durable state.
	 * @param Audit $audit Durable audit.
	 * @param Settings $settings Installation configuration.
	 * @param ApiRequestBuilder $requests Safe API request construction.
	 * @since 0.1.0
	 */
	public function __construct(Catalogue $catalogue, SchemaValidator $schemas, PrincipalInterface $principal, HandlerRegistry $handlers, Permissions $permissions, Executions $executions, Audit $audit, Settings $settings, ApiRequestBuilder $requests, ?Jobs $jobs = null, ?callable $workerReady = null)
	{
		$this->catalogue = $catalogue;
		$this->schemas = $schemas;
		$this->principal = $principal;
		$this->handlers = $handlers;
		$this->permissions = $permissions;
		$this->executions = $executions;
		$this->audit = $audit;
		$this->settings = $settings;
		$this->requests = $requests;
		$this->jobs = $jobs;
		$this->workerReady = $workerReady === null ? null : Closure::fromCallable($workerReady);
	}

	/**
	 * Execute a read and retain the original API/companion response envelope.
	 *
	 * @param string $name Semantic action name.
	 * @param array<string,mixed> $input Action arguments.
	 * @param string $transport Actual track or auto.
	 * @return array<string,mixed> Read result.
	 * @since 0.1.0
	 */
	public function read(string $name, array $input = [], string $transport = 'auto'): array
	{
		$resolved = $this->resolve($name, $transport, 'read');
		$input = $this->validate($resolved, $input);
		$result = $this->invoke($resolved, $input);
		$this->audit->record('action.read', 'completed', $name, metadata: ['transport' => $this->principal->getTrack()]);

		return $this->readEnvelope($name, $result);
	}

	/**
	 * Apply an authorized fixed-tool query mapping without exposing binding overrides.
	 *
	 * Only the server's tool dispatcher supplies the already-authorized tool row.
	 * Its own schema is validated here, independently of the generic action schema.
	 *
	 * @param array<string,mixed> $tool Authorized database tool record.
	 * @param array<string,mixed> $input Tool arguments, including optional site alias.
	 * @return array<string,mixed> Original convenience-tool response envelope.
	 * @since 0.1.0
	 */
	public function fixedRead(array $tool, array $input): array
	{
		$this->catalogue->requireExecution($tool);
		$input = $this->schemas->input($input, $this->catalogue->schema((int) $tool['input_schema_id']));
		$config = $tool['configuration'];
		$resolved = $this->resolve((string) $config['action'], 'auto', 'read');
		unset($input['site']);

		if ($resolved['binding']['track'] !== 'api' || empty($config['query_map']))
		{
			return $this->read((string) $config['action'], $input);
		}

		$resolved['binding']['configuration']['query_map'] = $config['query_map'];

		if (isset($input['limit']))
		{
			$input['limit'] = min($input['limit'], $this->settings->get('max_list_limit'));
		}

		$result = $this->invoke($resolved, $input);
		$this->audit->record('action.read', 'completed', $resolved['action']['name'], metadata: ['transport' => 'api']);

		return ['site' => $this->settings->get('site_alias'), 'response' => $result];
	}

	/**
	 * Validate and preflight a write, without executing it or consuming a grant.
	 *
	 * @param string $name Semantic write action.
	 * @param array<string,mixed> $input Desired action arguments.
	 * @param string $idempotencyKey Stable operation UUID.
	 * @param bool $dryRun Whether only a non-executable preview is requested.
	 * @param string $transport Actual track or auto.
	 * @return array<string,mixed> Redacted preview or bound confirmation token.
	 * @since 0.1.0
	 */
	public function plan(string $name, array $input, string $idempotencyKey, bool $dryRun = false, string $transport = 'auto'): array
	{
		$resolved = $this->resolve($name, $transport, 'write');
		$handler = $this->handlers->get($resolved['binding']['handler']);
		$this->requireWorker($handler);
		Json::requireUuid($idempotencyKey);
		unset($input['_edgeConfirmed'], $input['dryRun']);

		if ($resolved['binding']['track'] === 'cli' && !$handler instanceof PlannedHandlerInterface)
		{
			$input['dryRun'] = true;
			$input['_edgeConfirmed'] = false;
		}

		$input = $this->validate($resolved, $input);
		$before = $handler instanceof PlannedHandlerInterface ? null : $this->snapshot($resolved, $input);
		$preflight = $this->preflight($resolved, $input, $before);
		$preview = [
			'site' => $this->settings->get('site_alias'), 'action' => $name,
			'transport' => $resolved['binding']['track'], 'method' => $resolved['binding']['configuration']['method'] ?? 'native',
			'summary' => $resolved['action']['description'], 'fields' => array_keys($input['data'] ?? []),
			'idempotencyKey' => $idempotencyKey, 'preconditions' => $before === null ? 'handler-preflight' : 'resource-snapshot',
		];

		if ($handler instanceof PlanPreviewInterface)
		{
			$preview['details'] = $handler->preview($preflight);
			$preview['definitionRevision'] = $resolved['revision'];
		}

		if ($dryRun)
		{
			return ['dryRun' => true, 'confirmationRequired' => false, 'operation' => $preview];
		}

		$grant = $this->permissions->authorize($resolved['action']['toolset']);

		return $this->executions->plan($resolved, [
			'input' => $input, 'before' => $before,
			'prepared' => $handler instanceof PlannedHandlerInterface ? $preflight : null,
			'preflight_hash' => hash('sha256', Json::canonical($preflight)),
		], $preview, $idempotencyKey, $grant);
	}

	/**
	 * Apply an unchanged authorized plan once and verify available postconditions.
	 *
	 * @param string $token Principal-bound opaque confirmation token.
	 * @return array<string,mixed> Mutation, verification and replay/recovery evidence.
	 * @since 0.1.0
	 */
	public function apply(string $token): array
	{
		$plan = $this->executions->resolve($token);
		$resolved = $this->resolve((string) $plan['preview']['action'], (string) $plan['preview']['transport'], 'write');
		try
		{
			$previous = $this->executions->previous($plan);
		}
		catch (OperationException $error)
		{
			$id = $error->toArray()['details']['executionId'] ?? null;

			if ($error->getIdentifier() !== 'EXECUTION_UNCERTAIN' || $this->jobs === null || !is_string($id)
				|| ($job = $this->jobs->forExecution($id)) === null)
			{
				throw $error;
			}

			return ['site' => $this->settings->get('site_alias'), 'action' => $resolved['action']['name'],
				'executionId' => $id, 'job' => $job, 'idempotentReplay' => true,
				'verification' => ['status' => 'pending', 'reason' => 'Read the existing job for its observed outcome.']];
		}

		if ($previous !== null)
		{
			return $previous;
		}

		$this->executions->assertFresh($plan, $resolved);
		$handler = $this->handlers->get($resolved['binding']['handler']);
		$this->requireWorker($handler);
		$input = $this->validate($resolved, $plan['payload']['input']);
		$before = $handler instanceof PlannedHandlerInterface ? null : $this->snapshot($resolved, $input);

		if (!hash_equals(hash('sha256', Json::canonical($plan['payload']['before'])), hash('sha256', Json::canonical($before))))
		{
			throw new OperationException('PRECONDITION_CHANGED', 'The Joomla resource changed after planning. Create a new plan against the current resource.');
		}

		$preflight = $this->preflight($resolved, $input, $before);

		if (!hash_equals($plan['payload']['preflight_hash'], hash('sha256', Json::canonical($preflight))))
		{
			throw new OperationException('PREFLIGHT_CHANGED', 'The native or API preflight changed after planning.');
		}

		$execution = $this->executions->claim($plan, $resolved);
		$mutation = null;

		try
		{
			if ($handler instanceof DeferredHandlerInterface)
			{
				$job = $this->jobs->enqueue($execution, ['action' => $resolved['action']['name'],
					'resolved' => $resolved, 'prepared' => $plan['payload']['prepared'],
					'input' => $input, 'idempotencyKey' => $plan['idempotency_key']]);

				return ['site' => $this->settings->get('site_alias'), 'action' => $resolved['action']['name'],
					'executionId' => $execution['uuid'], 'job' => $job, 'idempotentReplay' => false,
					'verification' => ['status' => 'pending', 'reason' => 'The approved operation was queued; inspect its job for completion.']];
			}

			if ($resolved['binding']['track'] === 'cli' && !$handler instanceof PlannedHandlerInterface)
			{
				$input['dryRun'] = false;
				$input['_edgeConfirmed'] = true;
			}

			$mutation = $handler instanceof PlannedHandlerInterface
				? $handler->apply($plan['payload']['prepared'], $resolved['binding'], $this->principal, $execution)
				: $this->invoke($resolved, $input, $plan['idempotency_key'], $before['item'] ?? []);
			$verification = $handler instanceof PlannedHandlerInterface
				? $handler->verify($plan['payload']['prepared'], $mutation, $resolved['binding'], $this->principal)
				: $this->verify($resolved, $input, $mutation);
			$result = [
				'site' => $this->settings->get('site_alias'), 'action' => $resolved['action']['name'],
				'idempotencyKey' => $plan['idempotency_key'], 'mutation' => $mutation,
				'verification' => $verification, 'idempotentReplay' => false,
			];
			$settled = $handler instanceof PlannedHandlerInterface
				? in_array($verification['status'] ?? '', ['verified', 'notPerformed', 'cancelled'], true)
				: $verification['status'] !== 'uncertain';
		}
		catch (Throwable $error)
		{
			$result = [
				'site' => $this->settings->get('site_alias'), 'action' => $resolved['action']['name'],
				'idempotencyKey' => $plan['idempotency_key'], 'mutation' => $mutation,
				'verification' => ['status' => 'uncertain', 'reason' => 'Mutation or read-back did not complete. Do not replay; reconcile the execution record.'],
				'error' => $error instanceof OperationException ? $error->toArray() : ['code' => 'EXECUTION_UNCERTAIN', 'message' => 'The Joomla operation did not complete reliably.'],
				'idempotentReplay' => false,
			];
			$settled = false;
		}

		return $this->executions->finish($execution, $result, $settled, $resolved['action']['name']);
	}

	/**
	 * Execute one authenticated worker payload after current ACL and grant checks.
	 *
	 * @param array<string,mixed> $payload Decrypted immutable queued operation.
	 * @param callable $progress Durable bounded progress callback.
	 * @param callable $cancel Polls cancellation and renews the worker lease.
	 * @return array<string,mixed> Observed result; Jobs commits the final execution.
	 * @since 0.1.1
	 */
	public function runJob(array $payload, callable $progress, callable $cancel): array
	{
		$this->catalogue->refresh();
		$resolved = $this->resolve($payload['action'], $payload['resolved']['binding']['track'], 'write');

		if (!hash_equals($resolved['revision'], (string) ($payload['resolved']['revision'] ?? ''))
			|| (int) $resolved['binding']['id'] !== (int) $payload['resolved']['binding']['id'])
		{
			throw new OperationException('PLAN_STALE', 'The queued action definition changed before execution.');
		}

		$this->permissions->assertExecution($payload['execution'], $resolved['action']['toolset']);
		$handler = $this->handlers->get($resolved['binding']['handler']);

		if (!$handler instanceof DeferredHandlerInterface)
		{
			throw new OperationException('HANDLER_UNAVAILABLE', 'The queued deferred handler is unavailable.');
		}

		$progress(5, 'Current permissions and the approved definition were verified.');
		$execution = $payload['execution'] + ['cancel' => $cancel, 'progress' => $progress];
		$mutation = $handler->apply($payload['prepared'], $resolved['binding'], $this->principal, $execution);
		$verification = $handler->verify($payload['prepared'], $mutation, $resolved['binding'], $this->principal);

		return ['site' => $this->settings->get('site_alias'), 'action' => $resolved['action']['name'],
			'idempotencyKey' => $payload['idempotencyKey'], 'mutation' => $mutation,
			'verification' => $verification, 'idempotentReplay' => false];
	}

	/** @param object $handler Reviewed primitive. @return void @since 0.1.1 */
	private function requireWorker(object $handler): void
	{
		if (!$handler instanceof DeferredHandlerInterface)
		{
			return;
		}

		if ($this->jobs === null || $this->workerReady === null)
		{
			throw new OperationException('WORKER_UNAVAILABLE', 'The durable operation worker is unavailable.');
		}

		($this->workerReady)();
	}

	/**
	 * Preserve the original trusted-local companion write contract without HTTP elevation.
	 *
	 * @param string $name Native semantic action.
	 * @param array<string,mixed> $input Original companion arguments.
	 * @return array<string,mixed> Original native action result.
	 * @since 0.1.0
	 */
	public function legacy(string $name, array $input): array
	{
		if (PHP_SAPI !== 'cli' || !$this->principal->isLocal())
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'Legacy companion dispatch is local-only.');
		}

		$resolved = $this->catalogue->action($name, 'cli');

		if ($resolved['action']['effect'] === 'read')
		{
			return $this->read($name, $input, 'cli')['response']['data'];
		}

		$validated = $this->validate($resolved, $input);

		if (($validated['dryRun'] ?? true) === true)
		{
			return $this->invoke($resolved, $validated);
		}

		if (($validated['_edgeConfirmed'] ?? false) !== true)
		{
			throw new OperationException('CONFIRMATION_REQUIRED', 'The native write requires explicit confirmation.');
		}

		$plan = $this->plan($name, $input, Json::uuid(), false, 'cli');
		$result = $this->apply($plan['confirmationToken']);

		if (($result['verification']['status'] ?? '') === 'uncertain')
		{
			throw new OperationException('EXECUTION_UNCERTAIN', 'The native write requires reconciliation.', ['executionId' => $result['executionId']]);
		}

		return $result['mutation'];
	}

	/** @param string $name Action. @param string $transport Track. @param string $effect Required effect. @return array<string,mixed> Authorized current resolution. @since 0.1.0 */
	private function resolve(string $name, string $transport, string $effect): array
	{
		$resolved = $this->catalogue->action($name, $transport);

		if ($resolved['action']['effect'] !== $effect)
		{
			throw new OperationException('ACTION_EFFECT_MISMATCH', 'Use the read or confirmed-write interface appropriate to this action.');
		}

		$this->catalogue->requireExecution($resolved['action']);
		$this->catalogue->requireExecution($resolved['binding'] + ['effect' => $effect]);

		return $resolved;
	}

	/** @param array<string,mixed> $resolved Resolution. @param array<string,mixed> $input Arguments. @return array<string,mixed> Validated arguments. @since 0.1.0 */
	private function validate(array $resolved, array $input): array
	{
		return $this->schemas->input($input, $this->catalogue->schema((int) $resolved['binding']['input_schema_id']));
	}

	/**
	 * Prepare a reviewed handler without granting legacy flags or performing writes.
	 *
	 * @param array<string,mixed> $resolved Authorized action and binding.
	 * @param array<string,mixed> $input Validated operation input.
	 * @param ?array $before Existing resource snapshot for API form merging.
	 * @return array<string,mixed> Deterministic private preparation.
	 * @since 0.1.0
	 */
	private function preflight(array $resolved, array $input, ?array $before): array
	{
		$handler = $this->handlers->get($resolved['binding']['handler']);

		if ($handler instanceof PlannedHandlerInterface)
		{
			return $handler->prepare($input, $resolved['binding'], $this->principal);
		}

		return $resolved['binding']['track'] === 'cli'
			? $this->invoke($resolved, $input)
			: $this->requests->build($input, $resolved['binding']['configuration'], $before['item'] ?? []);
	}

	/** @param array<string,mixed> $resolved Resolution. @param array<string,mixed> $input Arguments. @param ?string $key Idempotency key. @param array<string,mixed> $current Existing form fields. @param bool $missing Expected 404. @return array<string,mixed> Handler result. @since 0.1.0 */
	private function invoke(array $resolved, array $input, ?string $key = null, array $current = [], bool $missing = false): array
	{
		$binding = $resolved['binding'];
		$handler = $this->handlers->get($binding['handler']);
		$result = $handler instanceof ApiHandler
			? $handler->request($input, $binding, $this->principal, $key, $current, $missing)
			: $handler->execute($input, $binding, $this->principal);

		if (!empty($binding['output_schema_id']))
		{
			$this->schemas->output($result, $this->catalogue->schema((int) $binding['output_schema_id']));
		}

		return $result;
	}

	/** @param string $name Action name. @param array<string,mixed> $result Handler result. @return array<string,mixed> Source-compatible envelope. @since 0.1.0 */
	private function readEnvelope(string $name, array $result): array
	{
		$track = $this->principal->getTrack();

		return ['site' => $this->settings->get('site_alias'), 'transport' => $track, 'action' => $name,
			'response' => $track === 'api' ? $result : ['protocol' => 'joomla-mcp/1', 'transport' => 'cli', 'data' => $result]];
	}

	/** @param array<string,mixed> $resolved Resolution. @return array<string,mixed> Declarative read-back mapping. @since 0.1.0 */
	private function verification(array $resolved): array
	{
		$binding = $resolved['binding'];

		return $binding['params']['verification'] ?? [
			'read_action' => $binding['configuration']['read_action'] ?? null,
			'operation' => $binding['configuration']['operation'] ?? '', 'primary_key' => 'id', 'state_field' => 'state',
		];
	}

	/** @param array<string,mixed> $resolved Write resolution. @param array<string,mixed> $input Arguments. @return array<string,mixed>|null Resource state before mutation. @since 0.1.0 */
	private function snapshot(array $resolved, array $input): ?array
	{
		$rule = $this->verification($resolved);

		if (empty($rule['read_action']) || ($rule['operation'] ?? '') === 'create' || !isset($input['id']))
		{
			return null;
		}

		$read = $this->resolve($rule['read_action'], $resolved['binding']['track'], 'read');
		$arguments = $this->readArguments($read, $input);
		$result = $this->invoke($read, $arguments);

		return ['item' => $this->item($result, $read['binding']['track']), 'etag' => $result['headers']['etag'] ?? null];
	}

	/** @param array<string,mixed> $resolved Write. @param array<string,mixed> $input Applied arguments. @param array<string,mixed> $mutation Mutation result. @return array<string,mixed> Honest verification coverage. @since 0.1.0 */
	private function verify(array $resolved, array $input, array $mutation): array
	{
		$rule = $this->verification($resolved);
		$track = $resolved['binding']['track'];
		$operation = $rule['operation'] ?? '';
		$primary = $rule['primary_key'] ?? 'id';
		$item = $this->item($mutation, $track);
		$id = $input['id'] ?? $mutation['id'] ?? $item[$primary] ?? null;

		if (empty($rule['read_action']) || $id === null)
		{
			return ['status' => 'notPerformed', 'reason' => 'The handler acknowledged completion but declares no independent resource read-back for this operation.'];
		}

		$read = $this->resolve($rule['read_action'], $track, 'read');
		$arguments = $this->readArguments($read, array_replace($input, ['id' => is_numeric($id) ? (int) $id : $id]));

		try
		{
			$observed = $this->invoke($read, $arguments, missing: $operation === 'delete');
		}
		catch (OperationException $error)
		{
			if ($operation === 'delete' && in_array($error->getIdentifier(), ['NOT_FOUND', 'ITEM_NOT_FOUND'], true))
			{
				return ['status' => 'verified', 'postcondition' => 'resource-absent', 'id' => $id];
			}

			throw $error;
		}

		if ($operation === 'delete')
		{
			if (($observed['status'] ?? null) === 404)
			{
				return ['status' => 'verified', 'postcondition' => 'resource-absent', 'id' => $id];
			}

			$record = $this->item($observed, $track);

			if (($record[$rule['state_field'] ?? 'state'] ?? null) == -2)
			{
				return ['status' => 'verified', 'postcondition' => 'resource-trashed', 'id' => $id];
			}

			return ['status' => 'uncertain', 'reason' => 'The deleted resource is still visible and was not verified as trashed.', 'id' => $id];
		}

		$record = $this->item($observed, $track);

		if ($record === [])
		{
			return ['status' => 'uncertain', 'reason' => 'The written resource could not be read back.', 'id' => $id];
		}

		$desired = $operation === 'state' ? [($rule['state_field'] ?? 'state') => $input['state']] : ($input['data'] ?? []);
		$matched = [];
		$different = [];
		$unobservable = [];

		foreach ($desired as $field => $value)
		{
			if (!array_key_exists($field, $record))
			{
				$unobservable[] = $field;
			}
			elseif (Json::canonical($record[$field]) === Json::canonical($value)
				|| ((is_int($value) || is_bool($value)) && is_numeric($record[$field]) && (string) (int) $value === (string) $record[$field]))
			{
				$matched[] = $field;
			}
			else
			{
				$different[] = $field;
			}
		}

		return [
			'status' => $different === [] ? ($unobservable === [] ? 'verified' : 'partial') : 'uncertain',
			'postcondition' => 'resource-read-back', 'id' => $id, 'matchedFields' => $matched,
			'unobservableFields' => $unobservable, 'differentFields' => $different,
			'reason' => $different === [] ? 'Read-back confirms the listed observable fields; write-only fields cannot be compared.' : 'Joomla may have filtered or changed requested fields. Reconcile the persisted record before another write.',
		];
	}

	/** @param array<string,mixed> $read Read resolution. @param array<string,mixed> $input Write arguments. @return array<string,mixed> Required read path arguments. @since 0.1.0 */
	private function readArguments(array $read, array $input): array
	{
		$schema = Json::decode($this->catalogue->schema((int) $read['binding']['input_schema_id']));
		$arguments = array_intersect_key($input, $schema['properties'] ?? []);

		return $this->validate($read, $arguments);
	}

	/** @param array<string,mixed> $response Handler result. @param string $track Transport track. @return array<string,mixed> One resource's observable attributes. @since 0.1.0 */
	private function item(array $response, string $track): array
	{
		$value = $track === 'cli' ? ($response['item'] ?? []) : ($response['data']['data'] ?? $response['data'] ?? []);

		if (is_array($value) && isset($value['attributes']) && is_array($value['attributes']))
		{
			$value = $value['attributes'] + (isset($value['id']) ? ['id' => $value['id']] : []);
		}

		return is_array($value) && !array_is_list($value) ? $value : [];
	}
}
