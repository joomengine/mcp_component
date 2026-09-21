<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;
use VDM\Component\JoomEngineMcp\Administrator\Console\Inspector;


/**
 * Database-selected MCP tool primitives; the wire names and schemas are data.
 *
 * @since 0.1.0
 */
final class ToolDispatcher
{
	/** @var Catalogue Authorized installed definitions. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var ActionExecutor Shared read and confirmed-write engine. @since 0.1.0 */
	private ActionExecutor $actions;
	/** @var Permissions Principal-bound consent state. @since 0.1.0 */
	private Permissions $permissions;
	/** @var SchemaValidator Inert schema evaluator. @since 0.1.0 */
	private SchemaValidator $schemas;
	/** @var PrincipalInterface Actual authority, not a tool argument. @since 0.1.0 */
	private PrincipalInterface $principal;
	/** @var Settings Trusted installation configuration. @since 0.1.0 */
	private Settings $settings;
	/** @var ?Inspector Local console introspection, absent for HTTP. @since 0.1.0 */
	private ?Inspector $console;
	/** @var ?Jobs Durable operations owned by this principal. @since 0.1.1 */
	private ?Jobs $jobs;

	/**
	 * Compose protocol primitives without retaining a Joomla service locator.
	 *
	 * @param Catalogue $catalogue Authorized definitions.
	 * @param ActionExecutor $actions Action engine.
	 * @param Permissions $permissions Consent state.
	 * @param SchemaValidator $schemas Schema validation.
	 * @param PrincipalInterface $principal Current authority.
	 * @param Settings $settings Installation settings.
	 * @param ?Inspector $console Optional local-only console inspector.
	 * @since 0.1.0
	 */
	public function __construct(Catalogue $catalogue, ActionExecutor $actions, Permissions $permissions, SchemaValidator $schemas, PrincipalInterface $principal, Settings $settings, ?Inspector $console = null, ?Jobs $jobs = null)
	{
		$this->catalogue = $catalogue;
		$this->actions = $actions;
		$this->permissions = $permissions;
		$this->schemas = $schemas;
		$this->principal = $principal;
		$this->settings = $settings;
		$this->console = $console;
		$this->jobs = $jobs;
	}

	/** @return string[] Reviewed tool service keys, not a capability catalogue. @since 0.1.0 */
	public static function keys(): array
	{
		return ['site.list', 'catalog.capabilities', 'catalog.search', 'catalog.describe', 'action.read', 'action.plan',
			'action.apply', 'action.safe_configuration', 'permission.request', 'permission.approve', 'permission.list',
			'permission.revoke', 'console.list', 'console.help', 'console.targets', 'console.capabilities', 'console.inventory',
			'job.list', 'job.status', 'job.cancel', 'job.redispatch', 'job.artifacts', 'job.artifact.read'];
	}

	/**
	 * Resolve and authorize the latest tool row before every invocation.
	 *
	 * @param string $name Published MCP tool name.
	 * @param array<string,mixed> $arguments JSON arguments.
	 * @return array<string,mixed> Bounded structured operation result.
	 * @since 0.1.0
	 */
	public function call(string $name, array $arguments): array
	{
		$this->catalogue->refresh();
		$tool = $this->catalogue->get('tool', $name);
		$this->catalogue->requireExecution($tool);
		$input = $this->schemas->input($arguments, $this->catalogue->schema((int) $tool['input_schema_id']));
		$this->requireSite($input['site'] ?? null);
		$config = $tool['configuration'];
		$key = $tool['handler'];

		$result = match ($key)
		{
			'site.list' => ['defaultSite' => $this->settings->get('site_alias'), 'sites' => [$this->site()]],
			'catalog.capabilities' => ['site' => $this->site(), 'catalog' => $this->catalog()],
			'catalog.search' => $this->search($input),
			'catalog.describe' => $this->describe($input['action']),
			'action.read' => isset($config['action']) ? $this->actions->fixedRead($tool, $input)
				: $this->actions->read($input['action'], $input['input'] ?? [], $config['transport'] ?? $input['transport'] ?? 'auto'),
			'action.plan' => $this->plan($tool, $input),
			'action.apply' => $this->actions->apply($input['confirmationToken']),
			'action.safe_configuration' => $this->safeConfiguration(),
			'permission.request' => $this->permissions->request($input),
			'permission.approve' => $this->permissions->approve($input['requestId'], $input['acknowledgement']),
			'permission.list' => ['grants' => $this->permissions->list()],
			'permission.revoke' => $this->permissions->revoke($input['grantId']),
			'console.capabilities' => $this->companion(),
			'console.targets' => $this->targets($input['command'] ?? null),
			'console.inventory' => $this->inspector()->inventory(),
			'console.list' => $this->inspector()->text(),
			'console.help' => $this->inspector()->help($input['command']),
			'job.list' => $this->jobs()->listing($input['limit'] ?? 50, $input['offset'] ?? 0),
			'job.status' => $this->jobs()->status($input['jobId']),
			'job.cancel' => $this->jobs()->cancel($input['jobId']),
			'job.redispatch' => $this->jobs()->redispatch($input['jobId']),
			'job.artifacts' => $this->jobs()->artifacts($input['jobId']),
			'job.artifact.read' => $this->jobs()->readArtifact($input['artifactId'], $input['offset'] ?? 0, $input['length'] ?? 65536),
			default => throw new OperationException('HANDLER_UNAVAILABLE', 'The requested tool primitive is unavailable.'),
		};

		Json::encode($result, $this->settings->get('max_result_bytes'));

		if (!empty($tool['output_schema_id']))
		{
			$this->schemas->output($result, $this->catalogue->schema((int) $tool['output_schema_id']));
		}

		return $result;
	}

	/** @return array<string,mixed> Source-shaped catalogue containing only authorized rows. @since 0.1.0 */
	public function catalog(): array
	{
		$actions = [];

		foreach ($this->catalogue->all('action') as $row)
		{
			$actions[] = $this->descriptor($this->catalogue->action($row['name']));
		}

		$reads = array_values(array_filter($actions, static fn (array $row): bool => in_array($row['risk'], ['read', 'sensitive-read'], true)));
		$writes = array_values(array_filter($actions, static fn (array $row): bool => !in_array($row['risk'], ['read', 'sensitive-read'], true)));
		$base = ['semanticActions' => count($actions), 'semanticReadActions' => count($reads), 'semanticWriteActions' => count($writes),
			'readActions' => $reads, 'writeActions' => $writes];

		return [
			'joomla' => ['version' => $this->settings->get('joomla_version')],
			'api' => $this->principal->getTrack() === 'api' ? $base : ['semanticActions' => 0, 'readActions' => [], 'writeActions' => []],
			'cli' => $this->principal->isLocal() ? ['companion' => $base + ['actions' => $actions], 'targets' => $this->publicTargets()] : ['targets' => []],
			'implementedActions' => array_map(static fn (array $row): array => [
				'tool' => $row['name'], 'title' => $row['title'], 'description' => $row['description'],
			], $this->catalogue->all('tool')),
		];
	}

	/** @param array<string,mixed> $input Search filters. @return array<string,mixed> Authorized matches. @since 0.1.0 */
	public function search(array $input): array
	{
		$found = [];
		$needle = strtolower(trim($input['text'] ?? ''));

		foreach ($this->catalogue->all('action') as $row)
		{
			if ((!($input['includeWrites'] ?? false) && $row['effect'] === 'write')
				|| (!($input['includeSensitive'] ?? false) && $row['risk'] === 'sensitive-read')
				|| (isset($input['domain']) && $input['domain'] !== $row['domain'])
				|| ($needle !== '' && !str_contains(strtolower($row['name'] . ' ' . $row['title'] . ' ' . $row['description'] . ' ' . $row['domain']), $needle)))
			{
				continue;
			}

			$found[] = $this->descriptor($this->catalogue->action($row['name']));
		}

		return ['site' => $this->settings->get('site_alias'), 'count' => count($found), 'actions' => $found,
			'companionCapability' => $this->principal->isLocal() ? 'available' : 'not-configured'];
	}

	/** @param string $name Semantic identifier. @return array<string,mixed> Current descriptor and track availability. @since 0.1.0 */
	public function describe(string $name): array
	{
		$resolved = $this->catalogue->action($name);

		return ['site' => $this->settings->get('site_alias'), 'action' => $this->descriptor($resolved),
			'availability' => ['executable' => true, 'transports' => [$this->principal->getTrack()], 'blockedReason' => null]];
	}

	/** @return array<string,mixed> Legacy companion description from installed, authorized bindings. @since 0.1.0 */
	public function companion(): array
	{
		if (!$this->principal->isLocal())
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'The companion is available only on the local console.');
		}

		$actions = [];

		foreach ($this->catalogue->all('action') as $row)
		{
			$resolved = $this->catalogue->action($row['name']);
			$descriptor = $this->descriptor($resolved);
			$actions[] = $descriptor + ['name' => $row['name'], 'effectiveAcl' => ['allowed' => true, 'requirements' => []]];
		}

		return ['protocol' => 'joomla-mcp/1', 'ok' => true,
			'runtime' => ['joomlaVersion' => $this->settings->get('joomla_version'), 'phpVersion' => PHP_VERSION],
			'actor' => ['id' => null, 'configured' => true, 'authority' => 'local-server'], 'actions' => $actions];
	}

	/** @param ?string $command Optional reviewed command. @return array<string,mixed> Actual installed command coverage. @since 0.1.0 */
	private function targets(?string $command): array
	{
		$inventory = $this->inspector()->inventory();
		$installed = array_column($inventory['commands'], null, 'name');
		$targets = $this->publicTargets();

		if ($command !== null)
		{
			$targets = array_values(array_filter($targets, static fn (array $row): bool => $row['command'] === $command));

			if ($targets === [])
			{
				throw new OperationException('COMMAND_UNAVAILABLE', 'The requested console target is unavailable.');
			}
		}

		foreach ($targets as &$target)
		{
			$target['installed'] = isset($installed[$target['command']]);
			$target['installedContract'] = $installed[$target['command']] ?? null;
		}

		return ['site' => $this->settings->get('site_alias'), 'installedCommandCount' => count($installed), 'count' => count($targets), 'targets' => $targets];
	}

	/** @return array<int,array<string,mixed>> Authorized inert CLI target metadata. @since 0.1.0 */
	private function publicTargets(): array
	{
		if (!$this->principal->isLocal())
		{
			return [];
		}

		return array_map(static fn (array $row): array => $row['definition'] + ['command' => $row['command'], 'status' => $row['status']], $this->catalogue->all('target'));
	}

	/** @param array<string,mixed> $resolved Authorized action and binding. @return array<string,mixed> Descriptive public contract. @since 0.1.0 */
	private function descriptor(array $resolved): array
	{
		$row = $resolved['action'];
		$binding = $resolved['binding'];
		$data = $row['definition'];
		$data['id'] = $row['name'];
		$data['title'] = $row['title'];
		$data['description'] = $row['description'];
		$data['domain'] = $row['domain'];
		$data['toolset'] = $row['toolset'];
		$data['risk'] = $row['risk'];
		$data['inputSchema'] = Json::decode($this->catalogue->schema((int) $binding['input_schema_id']));
		$data['outputSchema'] = empty($binding['output_schema_id']) ? null : Json::decode($this->catalogue->schema((int) $binding['output_schema_id']));
		$data['availableTransports'] = [$binding['track']];

		return $data;
	}

	/** @param array<string,mixed> $tool Fixed or generic planning primitive. @param array<string,mixed> $input Tool arguments. @return array<string,mixed> Bound plan. @since 0.1.0 */
	private function plan(array $tool, array $input): array
	{
		$action = $tool['configuration']['action'] ?? $input['action'];
		$arguments = isset($tool['configuration']['action']) ? $input : ($input['input'] ?? []);
		unset($arguments['site'], $arguments['idempotencyKey'], $arguments['transport']);

		return $this->actions->plan($action, $arguments, $input['idempotencyKey'], $input['dryRun'] ?? false, $input['transport'] ?? 'auto');
	}

	/** @return array<string,mixed> Safe configuration action remains behind its own row ACL. @since 0.1.0 */
	private function safeConfiguration(): array
	{
		$result = $this->actions->read('configuration.application.get');

		return $result['response'];
	}

	/** @return array<string,mixed> Non-secret single-installation description. @since 0.1.0 */
	private function site(): array
	{
		$toolsets = array_values(array_unique(array_column($this->catalogue->all('action'), 'toolset')));
		sort($toolsets, SORT_STRING);

		return ['id' => $this->settings->get('site_alias'), 'name' => $this->settings->get('site_name'),
			'api' => $this->principal->getTrack() === 'api', 'cli' => $this->principal->isLocal(), 'toolsets' => $toolsets];
	}

	/** @param mixed $site Caller-selected alias. @return void Reject implicit site pivots. @since 0.1.0 */
	private function requireSite(mixed $site): void
	{
		if ($site !== null && $site !== $this->settings->get('site_alias'))
		{
			throw new OperationException('SITE_UNAVAILABLE', 'The requested site alias is not served by this installation.');
		}
	}

	/** @return Inspector Actual local console registry. @since 0.1.0 */
	private function inspector(): Inspector
	{
		if (!$this->principal->isLocal() || $this->console === null)
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'This operation requires the local Joomla console.');
		}

		return $this->console;
	}

	/** @return Jobs Current principal's durable operation boundary. @since 0.1.1 */
	private function jobs(): Jobs
	{
		if ($this->jobs === null)
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The installed durable job service is unavailable.');
		}

		return $this->jobs;
	}
}
