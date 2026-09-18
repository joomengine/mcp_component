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
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use RuntimeException;
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

		return new ConsoleRuntime($application, $this->database, $this->compose($application, $principal, '', $inspector), $inspector);
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
	private function compose(CMSApplicationInterface $application, PrincipalInterface $principal, string $credential, ?Inspector $console = null): RequestRuntime
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
		else
		{
			throw new RuntimeException('The MCP execution context does not match its application.', 403);
		}

		foreach ($this->providers as $provider)
		{
			foreach ($provider->handlers($application, $principal) as $key => $handler)
			{
				if (isset($handlers[$key]) || str_starts_with($key, 'native.') || $key === 'api.request')
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
		$actions = new ActionExecutor($catalogue, $schemas, $principal, $registry, $permissions, $executions, $audit, $settings, $requestBuilder);
		$tools = new ToolDispatcher($catalogue, $actions, $permissions, $schemas, $principal, $settings, $console);
		$definitions = new DatabaseRegistry($catalogue, $tools, $actions, $schemas);
		$sessions = new SessionStore($store, $envelope, $principal, $settings->get('session_ttl'), $clock);

		return new RequestRuntime(new ServerFactory($definitions, $sessions, $settings), $tools, $actions, $catalogue, $settings);
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
