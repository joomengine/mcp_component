<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Version;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Installer\Assets;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CatalogueSynchronizer;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandHandler;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandInput;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\DefinitionSnapshot;
use VDM\Component\JoomEngineMcp\Administrator\Job\Artifacts;
use VDM\Component\JoomEngineMcp\Administrator\Job\Jobs;
use VDM\Component\JoomEngineMcp\Administrator\Job\ProcessLauncher;
use VDM\Component\JoomEngineMcp\Administrator\Process\PhpProcess;
use VDM\Component\JoomEngineMcp\Administrator\Security\ConsoleIdentity;
use VDM\Component\JoomEngineMcp\Administrator\Console\Inspector;
use VDM\Component\JoomEngineMcp\Administrator\Console\Runtime as ConsoleRuntime;
use VDM\Component\JoomEngineMcp\Administrator\Contract\ConsoleRuntimeInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiHandler;
use VDM\Component\JoomEngineMcp\Administrator\Handler\ApiRequestBuilder;
use VDM\Component\JoomEngineMcp\Administrator\Handler\NativeFactory;
use VDM\Component\JoomEngineMcp\Administrator\Handler\NativeHandler;
use VDM\Component\JoomEngineMcp\Administrator\Http\CurlClient;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaModelProvider;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\JoomlaNativeOperations;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\DatabaseRegistry;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ServerFactory;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\SessionStore;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;
use VDM\Component\JoomEngineMcp\Administrator\Security\ApiCredential;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\JoomlaPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Security\LocalPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\State\Audit;
use VDM\Component\JoomEngineMcp\Administrator\State\Executions;
use VDM\Component\JoomEngineMcp\Administrator\State\Permissions;


/**
 * Joomla-injected composition root with distinct API and local-console entrances.
 *
 * Only reviewed PHP providers can add primitive implementations. Configured rows
 * select service keys; they cannot instantiate providers or change authority.
 *
 * @since 0.1.0
 */
final class RuntimeFactory
{
	/** @var DatabaseInterface Joomla database. @since 0.1.0 */
	private DatabaseInterface $database;
	/** @var Registry Joomla component configuration. @since 0.1.0 */
	private Registry $configuration;
	/** @var ApiCredential Native token verification. @since 0.1.0 */
	private ApiCredential $credentials;
	/** @var HandlerProviderInterface[] Injected extension providers. @since 0.1.0 */
	private array $providers;

	/** @param DatabaseInterface $database Joomla database. @param Registry $configuration Component parameters. @param ApiCredential $credentials Native token integration. @param HandlerProviderInterface[] $providers Reviewed service providers. @since 0.1.0 */
	public function __construct(DatabaseInterface $database, Registry $configuration, ApiCredential $credentials, array $providers = [])
	{
		foreach ($providers as $provider)
		{
			if (!$provider instanceof HandlerProviderInterface)
			{
				throw new RuntimeException('An MCP handler provider does not implement the required contract.');
			}
		}

		$this->database = $database;
		$this->configuration = $configuration;
		$this->credentials = $credentials;
		$this->providers = $providers;
	}

	/** @param ApiApplication $application Authenticated Joomla API application. @return RequestRuntime Restricted HTTP runtime. @since 0.1.0 */
	public function api(ApiApplication $application): RequestRuntime
	{
		$token = $this->credentials->resolve($application);
		$principal = new JoomlaPrincipal($application->getIdentity());

		if (!$principal->authorise('mcp.access', 'com_joomengine_mcp'))
		{
			throw new RuntimeException('This Joomla user is not authorized to access MCP.', 403);
		}

		return $this->compose($application, $principal, $token);
	}

	/** @param ConsoleApplication $application Genuine local console application. @return ConsoleRuntimeInterface Scoped server-owner runtime. @since 0.1.0 */
	public function console(ConsoleApplication $application): ConsoleRuntimeInterface
	{
		$principal = new LocalPrincipal($application);
		$inspector = new Inspector($application);

		return new ConsoleRuntime($application, $this->database, $this->compose($application, $principal, '', $inspector), $inspector,
			fn (): array => $this->synchronizeJcb($application, $principal));
	}

	/** @param CMSApplicationInterface $application Actual Joomla application. @return Settings Non-secret canonical endpoint and bounds. @since 0.1.0 */
	public function settings(CMSApplicationInterface $application): Settings
	{
		return new Settings(['joomla_version' => (new Version())->getShortVersion(),
			'site_name' => $this->configuration->get('site_name', $application->get('sitename', 'Joomla'))] + $this->configuration->toArray());
	}

	/**
	 * Assemble substitutable services without exposing a container to handlers.
	 *
	 * @param CMSApplicationInterface $application Actual application.
	 * @param PrincipalInterface $principal Authority created by the typed entrances.
	 * @param string $credential Current token, empty only for genuine local console.
	 * @param ?Inspector $console Optional local registry.
	 * @return RequestRuntime Composed request services.
	 * @since 0.1.0
	 */
	private function compose(CMSApplicationInterface $application, PrincipalInterface $principal, string $credential, ?Inspector $console = null, bool $worker = false): RequestRuntime
	{
		$settings = $this->settings($application);
		$store = new JoomlaStore($this->database);
		$schemas = new SchemaValidator();
		$envelope = new Envelope((string) $application->get('secret'));
		$clock = static fn (): int => time();
		$available = new ExtensionAvailability($this->database);
		$requestBuilder = new ApiRequestBuilder();
		$handlers = [];

		if ($application instanceof ConsoleApplication && $principal->isLocal())
		{
			$native = new NativeHandler(new NativeFactory(new JoomlaModelProvider($application), new JoomlaNativeOperations($application), $application));

			foreach (NativeFactory::keys() as $key)
			{
				$handlers[$key] = $native;
			}
		}
		elseif ($application instanceof ApiApplication && !$principal->isLocal())
		{
			$handlers['api.request'] = new ApiHandler(
				new CurlClient($settings->get('timeout'), $settings->get('max_result_bytes'), $settings->get('allow_loopback_http')),
				$requestBuilder, $settings, $credential, fn (): string => $this->updateToken()
			);
		}
		elseif (!$worker || !$application instanceof ConsoleApplication || $principal->isLocal())
		{
			throw new RuntimeException('The MCP execution context does not match its application.', 403);
		}

		$launcher = null;
		$process = null;

		try
		{
			$launcher = new ProcessLauncher($this->phpBinary(), JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/cli/job.php', JPATH_ROOT);

			if ($available->enabled('com_componentbuilder'))
			{
				$process = $this->jcbProcess();
			}
		}
		catch (OperationException)
		{
			// Core API/native actions remain usable without the optional PHP worker.
		}

		if ($process !== null)
		{
			$invoke = function (array $payload, int $timeout, callable $cancel) use ($process, $principal, $settings): array
			{
				return $this->workerResult($process->run($payload + ['authority' => ['id' => $principal->getId(),
					'track' => $principal->getTrack()]], $timeout, $settings->get('max_result_bytes'), $cancel));
			};
			$handlers['jcb.command'] = new CommandHandler(
				fn (array $configuration): array => $invoke(['protocol' => 'joomengine-worker/1', 'operation' => 'jcb.inspect',
					'configuration' => $configuration], 60, static fn (): bool => false),
				new CommandInput(), [new DefinitionSnapshot($this->database), 'fingerprint'],
				fn (array $payload, array $execution): array => $invoke($payload,
					$settings->get('job_timeout'),
					$execution['cancel'] ?? static fn (): bool => false)
			);
		}

		foreach ($this->providers as $provider)
		{
			foreach ($provider->handlers($application, $principal) as $key => $handler)
			{
				if (isset($handlers[$key]) || str_starts_with($key, 'native.') || in_array($key, ['api.request', 'jcb.command'], true))
				{
					throw new RuntimeException('An extension cannot replace a reserved MCP handler.');
				}

				$handlers[$key] = $handler;
			}
		}

		$registry = new HandlerRegistry($handlers);
		$catalogue = new Catalogue($store, new Authorizer(), $principal, $schemas, $settings,
			[$available, 'enabled'], static function (string $entity, string $key) use ($registry): bool
			{
				return match ($entity)
				{
					'binding' => $registry->has($key),
					'tool' => in_array($key, ToolDispatcher::keys(), true),
					'resource' => in_array($key, ['catalog.core', 'resource.text', 'action.read'], true),
					default => false,
				};
			});
		$audit = new Audit($store, $principal, $clock);
		$permissions = new Permissions($store, $principal, $catalogue, $settings, $audit, $clock);
		$executions = new Executions($store, $principal, $envelope, $permissions, $settings, $audit, $clock);
		$artifacts = new Artifacts($store, $principal, $this->artifactDirectory($application, $principal),
			[(string) $application->get('tmp_path', JPATH_ROOT . '/tmp')], $clock);
		$jobs = new Jobs($store, $principal, $envelope, $artifacts, $clock, $launcher, [$executions, 'finish'],
			static function (string $name, string $track) use ($catalogue): void
			{
				$catalogue->refresh();
				$resolved = $catalogue->action($name, $track);
				$catalogue->requireExecution($resolved['action']);
				$catalogue->requireExecution($resolved['binding'] + ['effect' => $resolved['action']['effect']]);
			});
		$actions = new ActionExecutor($catalogue, $schemas, $principal, $registry, $permissions, $executions, $audit, $settings, $requestBuilder,
			$jobs, $launcher === null ? null : [$launcher, 'ready']);
		$tools = new ToolDispatcher($catalogue, $actions, $permissions, $schemas, $principal, $settings, $console, $jobs);
		$definitions = new DatabaseRegistry($catalogue, $tools, $actions, $schemas);
		$sessions = new SessionStore($store, $envelope, $principal, $settings->get('session_ttl'), $clock);

		return new RequestRuntime(new ServerFactory($definitions, $sessions, $settings), $tools, $actions, $catalogue, $settings, $jobs);
	}

	/**
	 * Restore only the authenticated queued owner before a fixed local worker runs.
	 * HTTP-origin jobs never acquire the console server-owner identity.
	 *
	 * @param ConsoleApplication $application Installed isolated bootstrap.
	 * @param string $id Job UUID.
	 * @param string $ticket One-time dispatch capability.
	 * @return array Observed durable outcome.
	 * @since 0.1.1
	 */
	public function work(ConsoleApplication $application, string $id, string $ticket): array
	{
		$owner = Jobs::authenticateWorker(new JoomlaStore($this->database), new Envelope((string) $application->get('secret')), $id, $ticket);

		if ($owner['track'] === 'cli')
		{
			$principal = new LocalPrincipal($application);

			if ($principal->getId() !== $owner['principal_id'])
			{
				throw new OperationException('JOB_UNAVAILABLE', 'The worker does not belong to the approved local process owner.');
			}

			$application->loadIdentity(new ConsoleIdentity($application, $this->database));
		}
		elseif (preg_match('/\Ajoomla:([1-9][0-9]*)\z/D', $owner['principal_id'], $matches) === 1)
		{
			$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $matches[1]);
			$principal = new JoomlaPrincipal($user);
			$application->loadIdentity($user);

			if (!$principal->authorise('mcp.access', 'com_joomengine_mcp'))
			{
				throw new OperationException('JOB_UNAVAILABLE', 'The original Joomla user no longer authorizes this job.');
			}
		}
		else
		{
			throw new OperationException('JOB_UNAVAILABLE', 'The approved worker identity is invalid.');
		}

		$runtime = $this->compose($application, $principal, '', null, true);

		return $runtime->jobs()->run($id, $ticket, [$runtime->actions(), 'runJob']);
	}

	/**
	 * Explicitly import the actual installed command and API contracts.
	 *
	 * @param CMSApplicationInterface $application Native application.
	 * @param PrincipalInterface $principal Verified privileged caller.
	 * @return array Observed inventory and changes.
	 * @since 0.1.1
	 */
	public function synchronizeJcb(CMSApplicationInterface $application, PrincipalInterface $principal): array
	{
		if ((!$principal->isLocal() && (!$principal->authorise('core.admin', 'com_joomengine_mcp')
			|| !$principal->authorise('core.admin', 'com_componentbuilder')))
			|| ($principal->isLocal() && (!$application instanceof ConsoleApplication || PHP_SAPI !== 'cli')))
		{
			throw new OperationException('JCB_CATALOGUE_DENIED', 'Catalogue synchronization requires native component administration permission.');
		}

		$result = $this->workerResult($this->jcbProcess()->run(['protocol' => 'joomengine-worker/1', 'operation' => 'jcb.inventory',
			'authority' => ['id' => $principal->getId(), 'track' => $principal->getTrack()]], 60, 8388608, static fn (): bool => false));
		$store = new JoomlaStore($this->database);

		return (new CatalogueSynchronizer($store, [new Assets($this->database, $store), 'synchronize']))
			->synchronize($result['commands'], $result['api'], $principal);
	}

	/** @return string Trusted administrator-selected CLI path. @since 0.1.1 */
	private function phpBinary(): string
	{
		$configured = (new Settings($this->configuration->toArray()))->get('php_cli_binary');

		return $configured !== '' ? $configured : (PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR . '/php');
	}

	/** @return PhpProcess Fixed installed JCB bootstrap. @since 0.1.1 */
	private function jcbProcess(): PhpProcess
	{
		return new PhpProcess($this->phpBinary(), JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/cli/jcb.php', JPATH_ROOT);
	}

	/** @param array $result Worker response. @return array Successful typed payload. @since 0.1.1 */
	private function workerResult(array $result): array
	{
		if (isset($result['error']))
		{
			throw new OperationException((string) ($result['error']['code'] ?? 'JCB_WORKER_FAILED'),
				(string) ($result['error']['message'] ?? 'The installed worker could not complete this request.'),
				(array) ($result['error']['details'] ?? []));
		}

		return $result;
	}

	/** @param CMSApplicationInterface $application Native configuration. @param PrincipalInterface $principal Owner. @return string Private storage outside the served Joomla tree. @since 0.1.1 */
	private function artifactDirectory(CMSApplicationInterface $application, PrincipalInterface $principal): string
	{
		$configured = $this->settings($application)->get('artifact_directory');
		if ($configured !== '' && !is_dir($configured) && !mkdir($configured, 0700, true))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The private artifact storage parent could not be created.');
		}

		$base = realpath($configured !== '' ? $configured : sys_get_temp_dir());
		$root = realpath(JPATH_ROOT);

		if ($base === false || $root === false || !is_dir($base) || $base === $root
			|| str_starts_with($base, $root . DIRECTORY_SEPARATOR))
		{
			throw new OperationException('ARTIFACT_STORAGE', 'The artifact storage parent must exist outside the Joomla web root.');
		}

		$owner = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'local';

		return $base . DIRECTORY_SEPARATOR . 'joomengine-mcp-' . hash('sha256',
			$root . "\0" . $application->get('secret') . "\0" . $principal->getId() . "\0" . $owner);
	}

	/**
	 * Compose administrator operations without requiring an API-token identity.
	 *
	 * @param CMSApplicationInterface $application Native Joomla application.
	 * @return \VDM\Component\JoomEngineMcp\Administrator\Administration\Operations
	 * @since 0.1.0
	 */
	public function administration(CMSApplicationInterface $application): \VDM\Component\JoomEngineMcp\Administrator\Administration\Operations
	{
		return new \VDM\Component\JoomEngineMcp\Administrator\Administration\Operations(
			new JoomlaStore($this->database), new Envelope((string) $application->get('secret'))
		);
	}
	/** @return string Existing native Joomla Update token; never generate or return it to a client. @since 0.1.0 */
	private function updateToken(): string
	{
		$db = $this->database;
		$query = $db->createQuery()->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_joomlaupdate'));

		return (string) (new Registry((string) $db->setQuery($query)->loadResult()))->get('update_token', '');
	}
}
