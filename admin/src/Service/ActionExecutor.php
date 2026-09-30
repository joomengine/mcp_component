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
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaDocument;
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
	 * Describe site-specific names using the same authorized source as planning.
	 *
	 * @param string $name Current visible semantic action.
	 * @return array<string,mixed> Input schema and optional resolved custom field metadata.
	 * @since 1.0.0
	 */
	public function describe(string $name): array
	{
		$resolved = $this->catalogue->action($name);
		$document = $this->catalogue->schema((int) $resolved['binding']['input_schema_id']);
		$schema = SchemaDocument::decode($document);
		$context = CustomFields::context($resolved);

		if ($context === null)
		{
			return ['inputSchema' => $schema];
		}

		try
		{
			$this->resolve($context['sourceAction'], 'api', 'read');
		}
		catch (OperationException)
		{
			return ['inputSchema' => $schema, 'customFields' => $context + ['status' => 'unavailable', 'fields' => [],
				'reason' => 'Custom field discovery requires access to the matching fields list read action.']];
		}

		$metadata = $this->customFields($context, Json::decode($document));
		$schema['properties']['data']['properties'] = (array) ($schema['properties']['data']['properties'] ?? []);

		return ['inputSchema' => CustomFields::schema($schema, $metadata, true), 'customFields' => $metadata];
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

		$schema = Json::decode($this->catalogue->schema((int) $resolved['binding']['input_schema_id']));
		$context = CustomFields::context($resolved);

		if ($context !== null && CustomFields::needed($input, $schema))
		{
			$resolved['custom_fields'] = $this->customFields($context, $schema);
			$input = CustomFields::normalize($input, $resolved['custom_fields']);
		}

		$input = $this->validate($resolved, $input);
		$before = $handler instanceof PlannedHandlerInterface ? null : $this->snapshot($resolved, $input);
		$delete = $this->articleDeletionPolicy($resolved, $input, $before);
		$preflight = $this->preflight($resolved, $input, $before);
		$menu = MenuItemComponents::intent($resolved, $preflight);

		if ($menu !== null)
		{
			$resolved['menu_component'] = $this->menuPolicy($menu);
			$resolved['binding']['configuration']['body_defaults']['component_id'] = 0;
			$preflight = $this->preflight($resolved, $input, $before);
		}

		$preview = [
			'site' => $this->settings->get('site_alias'), 'action' => $name,
			'transport' => $resolved['binding']['track'], 'method' => $resolved['binding']['configuration']['method'] ?? 'native',
			'summary' => $resolved['action']['description'], 'fields' => array_keys($input['data'] ?? []),
			'idempotencyKey' => $idempotencyKey, 'preconditions' => $before === null ? 'handler-preflight' : 'resource-snapshot',
		];

		if (isset($resolved['custom_fields']))
		{
			$preview['customFields'] = $resolved['custom_fields'];
		}

		if ($menu !== null)
		{
			$preview['menuComponent'] = MenuItemComponents::preview($menu);
		}

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
			'custom_fields' => $resolved['custom_fields'] ?? null,
			'menu_component' => $resolved['menu_component'] ?? null,
			'delete_verification' => $delete,
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

		if (isset($plan['payload']['delete_verification']))
		{
			$resolved['delete_verification'] = $plan['payload']['delete_verification'];
			$this->articleDeletionBindings($resolved['delete_verification']);
		}

		if (isset($plan['payload']['custom_fields']))
		{
			$resolved['custom_fields'] = $plan['payload']['custom_fields'];
		}

		if (isset($plan['payload']['menu_component']))
		{
			$resolved['menu_component'] = $this->menuPolicy($plan['payload']['menu_component']);
			$resolved['binding']['configuration']['body_defaults']['component_id'] = 0;
		}

		$handler = $this->handlers->get($resolved['binding']['handler']);
		$this->requireWorker($handler);
		$input = $this->validate($resolved, $plan['payload']['input']);
		$before = $handler instanceof PlannedHandlerInterface ? null : $this->snapshot($resolved, $input);

		if (!hash_equals(hash('sha256', Json::canonical($plan['payload']['before'])), hash('sha256', Json::canonical($before))))
		{
			throw new OperationException('PRECONDITION_CHANGED', 'The Joomla resource changed after planning. Create a new plan against the current resource.');
		}

		if (!empty($resolved['delete_verification']['available'])
			&& !$this->articleDeletionVisible($resolved['delete_verification']))
		{
			throw new OperationException('PRECONDITION_CHANGED', 'The article is no longer visible in the approved native collection. Create a new plan against the current resource.');
		}

		$preflight = $this->preflight($resolved, $input, $before);

		if (!hash_equals($plan['payload']['preflight_hash'], hash('sha256', Json::canonical($preflight))))
		{
			throw new OperationException('PREFLIGHT_CHANGED', 'The native or API preflight changed after planning.');
		}

		$execution = $this->executions->claim($plan, $resolved);
		$mutation = null;
		$menuRepair = null;

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

			// Retain the accepted primary mutation before any follow-up can fail.
			// Executions persists this evidence and blocks duplicate create replays.
			if (isset($resolved['menu_component']))
			{
				$menuRepair = $this->repairMenuComponent($resolved['menu_component'], $input, $mutation, $preflight, $plan['idempotency_key']);
			}

			$verification = $handler instanceof PlannedHandlerInterface
				? $handler->verify($plan['payload']['prepared'], $mutation, $resolved['binding'], $this->principal)
				: $this->verify($resolved, $input, $mutation);

			if ($menuRepair !== null)
			{
				$stored = $this->verifyMenuComponent($resolved['menu_component'], $menuRepair['id'], $menuRepair['componentId']);
				$verification['menuComponent'] = $stored;

				if ($stored['status'] !== 'verified')
				{
					$verification['status'] = 'uncertain';
					$verification['reason'] = $stored['reason'];
				}
			}
			$result = [
				'site' => $this->settings->get('site_alias'), 'action' => $resolved['action']['name'],
				'idempotencyKey' => $plan['idempotency_key'], 'mutation' => $mutation,
				'verification' => $verification, 'idempotentReplay' => false,
			];

			if ($menuRepair !== null)
			{
				$result['menuComponentRepair'] = $menuRepair;
			}
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

			if (isset($resolved['menu_component']))
			{
				$result['menuComponentRepair'] = $menuRepair ?? ['status' => 'uncertain',
					'id' => MenuItemComponents::identifier($input['id'] ?? ($mutation === null ? null : ($this->item($mutation, 'api')['id'] ?? null))),
					'reason' => 'The initial menu mutation may already be stored. Inspect this execution and existing item before another write.'];
			}
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
		$document = $this->catalogue->schema((int) $resolved['binding']['input_schema_id']);

		if (isset($resolved['custom_fields']))
		{
			$document = Json::encode(CustomFields::schema(Json::decode($document), $resolved['custom_fields']));
			CustomFields::validateValues($input, $resolved['custom_fields']);
		}

		return $this->schemas->input($input, $document);
	}

	/** @param array $context Reviewed API context. @param array $schema Static write schema. @return array Frozen discovery metadata. @since 1.0.0 */
	private function customFields(array $context, array $schema): array
	{
		return CustomFields::discover($context, $schema, fn (string $name, array $input): array => $this->read($name, $input, 'api'),
			$this->settings->get('max_list_limit'), $this->principal->getViewLevels());
	}

	/**
	 * Bind every follow-up action to the same approval and recheck it before I/O.
	 *
	 * @param array $intent New or frozen menu workflow.
	 * @return array Workflow with current authorized definition revisions.
	 * @since 1.0.0
	 */
	private function menuPolicy(array $intent): array
	{
		$roles = ['readAction' => 'read', 'listAction' => 'read'];

		if ($intent['type'] === 'component')
		{
			$roles['updateAction'] = 'write';
		}

		foreach ($roles as $role => $effect)
		{
			$resolved = $this->resolve($intent[$role], 'api', $effect);
			$expected = '/v1/menus/' . ($intent['clientId'] === 0 ? 'site' : 'administrator') . '/items'
				. ($role === 'listAction' ? '' : '/:id');
			$config = $resolved['binding']['configuration'];

			if ($resolved['binding']['handler'] !== 'api.request' || ($config['route'] ?? '') !== $expected
				|| ($config['method'] ?? '') !== ($effect === 'write' ? 'PATCH' : 'GET'))
			{
				throw new OperationException('BINDING_INVALID', 'Menu component persistence requires the reviewed native menu API bindings.');
			}

			if (isset($intent['revisions'][$role]) && !hash_equals($intent['revisions'][$role], $resolved['revision']))
			{
				throw new OperationException('PLAN_STALE', 'A menu repair or verification definition changed after approval.');
			}

			$intent['revisions'][$role] = $resolved['revision'];
		}

		return $intent;
	}

	/**
	 * Persist a native derived component ID after the accepted primary mutation.
	 *
	 * The original response remains in apply's durable catch path if any step here
	 * fails. The follow-up uses only the approved effective body, the same item ID,
	 * a native derived component ID, and a fresh read's optional entity tag.
	 *
	 * @param array $intent Frozen repair intent.
	 * @param array $input Approved original input.
	 * @param array $mutation Accepted primary API response.
	 * @param array $request Frozen effective primary request.
	 * @param string $key Original idempotency key.
	 * @return array Repair evidence including the independently observed item ID.
	 * @since 1.0.0
	 */
	private function repairMenuComponent(array $intent, array $input, array $mutation, array $request, string $key): array
	{
		$item = $this->item($mutation, 'api');
		$id = MenuItemComponents::identifier($input['id'] ?? $item['id'] ?? null);

		if ($id === null)
		{
			throw new OperationException('MENU_COMPONENT_UNRESOLVED', 'The accepted menu mutation did not expose an item ID. Inspect the existing execution before another write.');
		}

		if ($intent['type'] !== 'component')
		{
			return ['id' => $id, 'componentId' => 0, 'performed' => false];
		}

		$read = $this->resolve($intent['readAction'], 'api', 'read');
		$observed = $this->invoke($read, $this->validate($read, ['id' => $id]));
		$current = $this->item($observed, 'api');

		if (MenuItemComponents::identifier($current['id'] ?? null) !== $id)
		{
			throw new OperationException('MENU_COMPONENT_UNRESOLVED', 'The menu identity changed during component repair. Reconcile the accepted mutation.');
		}

		$componentId = MenuItemComponents::derivedId($intent, $current);
		$update = $this->resolve($intent['updateAction'], 'api', 'write');
		$update['binding']['configuration']['body_defaults']['component_id'] = $componentId;
		$arguments = ['id' => $id, 'data' => $request['body']];

		if (isset($observed['headers']['etag']))
		{
			$arguments['etag'] = $observed['headers']['etag'];
		}

		$hash = hash('sha256', $key . ':menu-component-id');
		$repairKey = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-5' . substr($hash, 13, 3)
			. '-' . dechex((hexdec($hash[16]) & 3) | 8) . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
		$response = $this->invoke($update, $arguments, $repairKey, $current);

		return ['id' => $id, 'componentId' => $componentId, 'performed' => true, 'response' => $response];
	}

	/** @param array $intent Approved menu workflow. @param int $id Item ID. @param int $componentId Expected stored ID. @return array Raw collection evidence. @since 1.0.0 */
	private function verifyMenuComponent(array $intent, int $id, int $componentId): array
	{
		return MenuItemComponents::verify($intent, $id, $componentId, function (string $name, array $arguments) use ($intent): array
		{
			$resolved = $this->resolve($name, 'api', 'read');
			// Stock Joomla supports this filter, including administrator's protected
			// "main" menu. Its value is frozen from the approved effective form.
			$resolved['binding']['configuration']['query_defaults']['filter[menutype]'] = $intent['menutype'];
			$result = $this->invoke($resolved, $this->validate($resolved, $arguments));

			return $this->readEnvelope($name, $result);
		}, $this->settings->get('max_list_limit'));
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
			: $this->requests->build(isset($resolved['custom_fields']) ? CustomFields::wire($input, $resolved['custom_fields']) : $input,
				$resolved['binding']['configuration'], $before['item'] ?? []);
	}

	/** @param array<string,mixed> $resolved Resolution. @param array<string,mixed> $input Arguments. @param ?string $key Idempotency key. @param array<string,mixed> $current Existing form fields. @param bool $missing Expected 404. @return array<string,mixed> Handler result. @since 0.1.0 */
	private function invoke(array $resolved, array $input, ?string $key = null, array $current = [], bool $missing = false): array
	{
		$binding = $resolved['binding'];
		$handler = $this->handlers->get($binding['handler']);

		if (isset($resolved['custom_fields']))
		{
			$input = CustomFields::wire($input, $resolved['custom_fields']);
		}

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

	/**
	 * Freeze independent authorized native read definitions and collection visibility.
	 *
	 * The collection fallback is optional: an unavailable collection cannot prevent
	 * ordinary item-404 verification, but can never be used as evidence of absence.
	 * Definitions with extra default query restrictions are insufficient evidence.
	 *
	 * @param array $resolved Approved primary write.
	 * @param array $input Validated deletion target.
	 * @param ?array $before Native item snapshot.
	 * @return ?array Frozen native article verification policy.
	 * @since 1.0.0
	 */
	private function articleDeletionPolicy(array $resolved, array $input, ?array $before): ?array
	{
		$intent = ArticleDeletion::intent($resolved, $input, $before);

		if ($intent === null)
		{
			return null;
		}

		$rule = $this->verification($resolved);

		if (($rule['read_action'] ?? '') !== $intent['readAction'] || ($rule['operation'] ?? '') !== 'delete'
			|| ($rule['primary_key'] ?? 'id') !== 'id' || ($rule['state_field'] ?? 'state') !== 'state')
		{
			throw new OperationException('BINDING_INVALID', 'Permanent article deletion requires the matching native verification contract.');
		}

		$read = $this->resolve($intent['readAction'], 'api', 'read');
		$intent['readRevision'] = $read['revision'];
		$this->articleDeletionBindings($intent);

		try
		{
			$list = $this->resolve($intent['listAction'], 'api', 'read');
			$intent['listRevision'] = $list['revision'];
			$this->articleDeletionBindings($intent);

			if ($intent['state'] !== null)
			{
				$intent['available'] = $this->articleDeletionVisible($intent);
			}
		}
		catch (OperationException $error)
		{
			if ($error->getIdentifier() === 'BINDING_INVALID')
			{
				unset($intent['listRevision']);
			}

			// Preserve the normal missing-item path, never infer absent from denied
			// reads or a collection which could not expose the pre-mutation target.
		}

		return $intent;
	}

	/**
	 * Recheck every bound definition and same-principal ACL before follow-up I/O.
	 *
	 * @param array $intent Frozen native article policy.
	 * @return array Current authorized list resolution, or empty if never available.
	 * @since 1.0.0
	 */
	private function articleDeletionBindings(array $intent): array
	{
		$this->catalogue->refresh();
		$read = $this->resolve($intent['readAction'], 'api', 'read');

		if (!hash_equals($intent['readRevision'], $read['revision']))
		{
			throw new OperationException('PLAN_STALE', 'The article verification definition changed after approval.');
		}

		$config = $read['binding']['configuration'];

		if ($read['binding']['handler'] !== 'api.request' || ($config['method'] ?? '') !== 'GET'
			|| ($config['route'] ?? '') !== '/v1/content/articles/:id' || !empty($config['query_defaults'])
			|| !empty($config['select_fields']) || ($config['authentication'] ?? '') !== 'joomla-api-token')
		{
			throw new OperationException('BINDING_INVALID', 'Article verification requires the reviewed native item API binding.');
		}

		$request = $this->requests->build($this->readArguments($read, ['id' => $intent['id']]), $config);

		if ($request['path'] !== '/v1/content/articles/' . $intent['id'] || $request['query'] !== [] || $request['body'] !== null)
		{
			throw new OperationException('BINDING_INVALID', 'Article verification requires an unfiltered native item request.');
		}

		if (!isset($intent['listRevision']))
		{
			return [];
		}

		$list = $this->resolve($intent['listAction'], 'api', 'read');

		if (!hash_equals($intent['listRevision'], $list['revision']))
		{
			throw new OperationException('PLAN_STALE', 'The article collection definition changed after approval.');
		}

		$config = $list['binding']['configuration'];

		if ($list['binding']['handler'] !== 'api.request' || ($config['method'] ?? '') !== 'GET'
			|| ($config['route'] ?? '') !== '/v1/content/articles' || ($config['paginated'] ?? false) !== true
			|| !empty($config['query_defaults']) || !empty($config['select_fields']) || !empty($config['route_parameters'])
			|| ($config['authentication'] ?? '') !== 'joomla-api-token')
		{
			throw new OperationException('BINDING_INVALID', 'Article absence requires an unrestricted reviewed native collection API binding.');
		}

		return $list;
	}

	/** @param array $intent Bound native policy. @return bool Pre-mutation target visibility. @since 1.0.0 */
	private function articleDeletionVisible(array $intent): bool
	{
		return ArticleDeletion::present($this->articleDeletionCollection($intent, $intent['state']), $intent['id'], $intent['state']);
	}

	/**
	 * Read a single exact native article identity/state without caller query overrides.
	 *
	 * @param array $intent Bound native policy.
	 * @param int $state Reviewed native article state.
	 * @return array Original same-token collection response.
	 * @since 1.0.0
	 */
	private function articleDeletionCollection(array $intent, int $state): array
	{
		$list = $this->articleDeletionBindings($intent);

		if ($list === [] || !in_array($state, ArticleDeletion::STATES, true))
		{
			throw new OperationException('DELETE_VERIFICATION_UNAVAILABLE', 'The approved article collection evidence is unavailable.');
		}

		$list['binding']['configuration']['query_defaults'] = ['filter[search]' => 'id:' . $intent['id'], 'filter[state]' => $state];
		$limit = min(2, $this->settings->get('max_list_limit'));
		$arguments = $this->validate($list, ['offset' => 0, 'limit' => $limit]);
		$request = $this->requests->build($arguments, $list['binding']['configuration']);
		$query = ['page[offset]' => 0, 'page[limit]' => $limit, 'filter[search]' => 'id:' . $intent['id'], 'filter[state]' => $state];

		if ($request['method'] !== 'GET' || $request['path'] !== '/v1/content/articles' || $request['body'] !== null
			|| Json::canonical($request['query']) !== Json::canonical($query))
		{
			throw new OperationException('DELETE_VERIFICATION_UNAVAILABLE', 'The effective article collection request contains an unapproved scope or filter.');
		}

		return $this->invoke($list, $arguments);
	}

	/** @param array<string,mixed> $resolved Write resolution. @param array<string,mixed> $input Arguments. @return array<string,mixed>|null Resource state before mutation. @since 0.1.0 */
	private function snapshot(array $resolved, array $input): ?array
	{
		$client = $resolved['binding']['track'] === 'api'
			? TemplateStyleInheritance::client($resolved['binding']['configuration']) : null;

		if ($client !== null)
		{
			return TemplateStyleInheritance::snapshot($input['data']['template'] ?? null, $client, function (string $name, array $arguments): array
			{
				$read = $this->resolve($name, 'api', 'read');

				return $this->invoke($read, $this->validate($read, $arguments));
			}, min(100, $this->settings->get('max_list_limit')));
		}

		$rule = $this->verification($resolved);

		if (empty($rule['read_action']) || ($rule['operation'] ?? '') === 'create' || !isset($input['id']))
		{
			return null;
		}

		$read = $this->resolve($rule['read_action'], $resolved['binding']['track'], 'read');
		$arguments = $this->readArguments($read, $input);
		$result = $this->invoke($read, $arguments);
		$item = $this->item($result, $read['binding']['track']);

		if ($resolved['binding']['track'] === 'api'
			&& preg_match('/\Amenus\.(site|administrator)-items\.update\z/D', $resolved['action']['name'], $menu) === 1
			&& (MenuItemComponents::identifier($item['id'] ?? null) !== $input['id']
				|| (string) ($item['client_id'] ?? '') !== ($menu[1] === 'site' ? '0' : '1')))
		{
			throw new OperationException('PRECONDITION_CHANGED', 'The menu snapshot identity or client does not match the requested update.');
		}

		return ['item' => $item, 'etag' => $result['headers']['etag'] ?? null];
	}

	/** @param array<string,mixed> $resolved Write. @param array<string,mixed> $input Applied arguments. @param array<string,mixed> $mutation Mutation result. @return array<string,mixed> Honest verification coverage. @since 0.1.0 */
	private function verify(array $resolved, array $input, array $mutation): array
	{
		if (isset($resolved['delete_verification']))
		{
			$this->articleDeletionBindings($resolved['delete_verification']);
		}

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
			if ($operation === 'delete' && !empty($resolved['delete_verification']['available'])
				&& ($mutation['status'] ?? null) === 204 && $error->getIdentifier() === 'JOOMLA_API_ERROR'
				&& ($error->toArray()['details']['httpStatus'] ?? null) === 500)
			{
				return ArticleDeletion::verify($resolved['delete_verification'], function (int $state) use ($resolved): array
				{
					return $this->articleDeletionCollection($resolved['delete_verification'], $state);
				});
			}

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

			if (!isset($resolved['delete_verification']) && ($record[$rule['state_field'] ?? 'state'] ?? null) == -2)
			{
				return ['status' => 'verified', 'postcondition' => 'resource-trashed', 'id' => $id];
			}

			return ['status' => 'uncertain', 'reason' => 'The deleted resource is still visible; permanent deletion was not verified.', 'id' => $id];
		}

		$record = $this->item($observed, $track);

		if ($record === [])
		{
			return ['status' => 'uncertain', 'reason' => 'The written resource could not be read back.', 'id' => $id];
		}

		$desired = $operation === 'state' ? [($rule['state_field'] ?? 'state') => $input['state']] : ($input['data'] ?? []);

		foreach ($resolved['custom_fields']['fields'] ?? [] as $field)
		{
			$name = $field['name'];

			if (!array_key_exists($name, $record) && is_array($record['com_fields'] ?? null) && array_key_exists($name, $record['com_fields']))
			{
				$record[$name] = $record['com_fields'][$name];
			}
		}

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
