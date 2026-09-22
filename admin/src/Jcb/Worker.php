<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Event\Extension\AfterInstallEvent;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use VDM\Component\JoomEngineMcp\Administrator\Console\WorkerApplication;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * One isolated native invocation with an unchanged, authenticated Joomla user.
 * Only the fixed worker bootstrap constructs this class; it has no HTTP route.
 *
 * @since 0.1.0
 */
final class Worker
{
	/** @var WorkerApplication Native application owning the registered command. @since 0.1.0 */
	private WorkerApplication $application;
	/** @var CommandRegistry Reviewed installed commands. @since 0.1.0 */
	private CommandRegistry $registry;
	/** @var DefinitionSnapshot Native definition/configuration observation. @since 0.1.0 */
	private DefinitionSnapshot $snapshot;
	/** @var DatabaseInterface Independent native installation read-back. @since 0.1.0 */
	private DatabaseInterface $database;

	/** @param WorkerApplication $application Isolated application. @param DatabaseInterface $database Installed Joomla database. @since 0.1.0 */
	public function __construct(WorkerApplication $application, DatabaseInterface $database)
	{
		$this->application = $application;
		$this->registry = new CommandRegistry($application);
		$this->snapshot = new DefinitionSnapshot($database);
		$this->database = $database;
	}

	/** @return array Actual installed command definitions; no command is executed. @since 0.1.0 */
	public function inventory(): array
	{
		return $this->registry->inventory();
	}

	/** @param array $configuration Reviewed database command contract. @return array Verified input/source definition. @since 0.1.0 */
	public function inspect(array $configuration): array
	{
		return $this->registry->inspect($configuration);
	}

	/**
	 * Invoke one already-approved plan after reloading its original identity.
	 *
	 * @param array $prepared Decrypted server-owned plan; never direct request JSON.
	 * @param PrincipalInterface $principal Reloaded principal, not a claimed user ID.
	 * @return array Native exit status, observations and private artifact descriptors.
	 * @since 0.1.0
	 */
	public function execute(array $prepared, PrincipalInterface $principal): array
	{
		if (($prepared['principal'] ?? '') !== $principal->getId() || ($prepared['local'] ?? null) !== $principal->isLocal()
			|| (!$principal->isLocal() && (!$principal->authorise('core.admin', 'com_componentbuilder')
				|| !$principal->authorise('mcp.access', 'com_joomengine_mcp') || !$principal->authorise('mcp.write', 'com_joomengine_mcp'))))
		{
			throw new OperationException('JCB_ACCESS_DENIED', 'The original Joomla identity no longer authorizes this job.');
		}

		if (!$principal->isLocal() && $principal->getId() !== 'joomla:' . (int) $this->application->getIdentity()->id)
		{
			throw new OperationException('JCB_ACCESS_DENIED', 'The worker application must retain the original Joomla user.');
		}

		$config = $prepared['configuration'];
		$actual = $this->registry->inspect($config);

		if (!hash_equals($actual['fingerprint'], (string) $prepared['contractFingerprint'])
			|| !hash_equals($actual['implementation'], (string) $prepared['implementation'])
			|| !hash_equals($this->snapshot->fingerprint(), (string) $prepared['snapshot']))
		{
			throw new OperationException('PLAN_STALE', 'The JCB command or definition graph changed before worker execution.');
		}

		$command = $this->registry->get($prepared['command']);
		$options = CommandContract::options((array) $prepared['input']['options'], $config['contract']);
		if (!$principal->isLocal() && $prepared['command'] === 'componentbuilder:compile:component'
			&& CommandInput::requestsInstallation($prepared['input'])
			&& !$principal->authorise('core.admin', 'com_installer'))
		{
			throw new OperationException('JCB_INSTALL_DENIED', 'Installer administration permission was revoked before execution.');
		}
		$values = [];

		foreach ($options as $key => $value)
		{
			$values['--' . $key] = $value;
		}

		$input = new ArrayInput($values, $command->getDefinition());
		$input->setInteractive(false);
		$output = new CommandOutput();
		$savedEnvironment = [];
		$environment = (array) $prepared['input']['environment'];

		if (!$principal->isLocal() && $environment !== [])
		{
			throw new OperationException('JCB_ENVIRONMENT', 'Remote jobs cannot choose worker environment values.');
		}

		foreach ($environment as $key => $value)
		{
			if (!is_string($key) || preg_match('/\AJCB_[A-Z_]+\z/D', $key) !== 1 || !is_string($value) || str_contains($value, "\0"))
			{
				throw new OperationException('JCB_ENVIRONMENT', 'The frozen native environment is invalid.');
			}
		}

		foreach (array_keys(getenv()) as $key)
		{
			if (str_starts_with($key, 'JCB_'))
			{
				$savedEnvironment[$key] = getenv($key);
				putenv($key);
			}
		}

		foreach ($environment as $key => $value)
		{
			$savedEnvironment[$key] ??= false;
			putenv($key . '=' . $value);
		}

		$compile = $prepared['command'] === 'componentbuilder:compile:component';
		$archives = $compile ? new CompiledArchives((string) $this->application->get('tmp_path')) : null;
		$installations = [];
		$beforeInstall = null;
		$afterInstall = null;
		$messageOffset = count($this->application->getMessageQueue());

		if ($compile && CommandInput::requestsInstallation($prepared['input']))
		{
			$beforeInstall = static function () use ($archives, $command): void
			{
				$paths = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Console\\Compiler', 'outputPaths'))->getValue($command);
				$archives->capture($paths, true);
			};
			$afterInstall = function (AfterInstallEvent $event) use (&$installations): void
			{
				$id = $event->getEid();
				$row = null;

				if (is_int($id) && $id > 0)
				{
					$db = $this->database;
					$query = $db->createQuery()->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'manifest_cache']))
						->from($db->quoteName('#__extensions'))->where($db->quoteName('extension_id') . ' = ' . $id);
					$row = $db->setQuery($query)->loadAssoc();
				}

				$installations[] = ['persisted' => is_array($row), 'extensionId' => is_int($id) ? $id : null,
					'element' => $row['element'] ?? null, 'type' => $row['type'] ?? null,
					'sha256' => $row === null ? null : hash('sha256', Json::canonical($row))];
			};
			$this->application->getDispatcher()->addListener('onExtensionBeforeInstall', $beforeInstall);
			$this->application->getDispatcher()->addListener('onExtensionAfterInstall', $afterInstall);
		}

		try
		{
			$exit = $this->application->invokeNativeCommand($command, $input, $output);
			$artifacts = [];
			$messages = ['success' => [], 'warning' => [], 'error' => []];
			$package = [];

			if ($compile)
			{
				// The reviewed JCB compiler collects paths but this source revision does
				// not emit them. Read that fixed result property, never arbitrary fields.
				$paths = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Console\\Compiler', 'outputPaths'))->getValue($command);

				try
				{
					$artifacts = $archives->capture($paths);
				}
				catch (OperationException $error)
				{
					$messages['error'][] = $error->getIdentifier();
				}
			}
			else
			{
				$bus = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Abstraction\\Console\\Package', 'message'))->getValue($command);

				if ($bus !== null)
				{
					foreach (array_keys($messages) as $category)
					{
						$messages[$category] = array_values((array) $bus->get($category));
					}
				}

				try
				{
					$package = (new PackageResults())->inspect($command, $prepared, $actual);
				}
				catch (\Throwable $error)
				{
					$package = ['verification' => ['status' => 'unverified',
						'code' => $error instanceof OperationException ? $error->getIdentifier() : 'JCB_PACKAGE_READBACK_UNAVAILABLE',
						'reason' => 'Native package completion was recorded, but independent result read-back was unavailable.']];
				}
			}

			foreach (array_slice($this->application->getMessageQueue(), $messageOffset) as $message)
			{
				$category = in_array($message['type'] ?? '', ['error', 'warning'], true) ? $message['type'] : 'success';
				$messages[$category][] = (string) ($message['message'] ?? '');
			}

			$after = $this->snapshot->fingerprint();
			$verification = ($package['verification'] ?? ['status' => 'unverified', 'reason' => 'The command produced no independently verified result.']) + [
				'definitionsChanged' => !hash_equals($prepared['snapshot'], $after),
				'nativeMessages' => array_map('count', $messages)];

			if ($exit !== 0 || $messages['error'] !== [])
			{
				$verification['status'] = 'partial';
				$verification['reason'] = 'Native command errors may follow partial persisted effects.';
			}
			elseif ($compile && $artifacts !== [])
			{
				$verification = ['status' => 'verified', 'artifactCount' => count($artifacts),
					'scope' => 'compiled archives', 'artifactHashes' => array_column($artifacts, 'sha256'),
					'reason' => 'Native compilation completed and each returned ZIP passed archive validation and was hashed.'];

				if (CommandInput::requestsInstallation($prepared['input']))
				{
					$verifiedInstalls = count(array_filter($installations, static fn (array $row): bool => $row['persisted']));
					$verification['installationCount'] = $verifiedInstalls;
					$verification['scope'] = 'compiled archives and installed extension records';

					if ($verifiedInstalls < count($artifacts) || $verifiedInstalls !== count($installations))
					{
						$verification['status'] = 'partial';
						$verification['reason'] = 'Archives were retained, but not every native installation had an independent extension record read-back.';
					}
				}
			}

			if ($messages['warning'] !== [] && $verification['status'] === 'verified' && !($package['remoteReadBack']['complete'] ?? false))
			{
				$verification['status'] = 'partial';
				$verification['reason'] = 'Persisted results were observed, but the native operation also reported warnings requiring review.';
			}

			return ['protocol' => 'joomengine-worker/1', 'exitCode' => $exit,
				'stdout' => $principal->isLocal() ? $output->contents() : '',
				'stderr' => $principal->isLocal() ? $output->getErrorOutput()->contents() : '',
				'messages' => $principal->isLocal() ? $messages : array_map('count', $messages),
				'beforeSnapshot' => $prepared['snapshot'], 'afterSnapshot' => $after,
				'package' => $package, 'installations' => $installations,
				'artifacts' => $artifacts, 'verification' => $verification];
		}
		finally
		{
			if ($beforeInstall !== null)
			{
				$this->application->getDispatcher()->removeListener('onExtensionBeforeInstall', $beforeInstall);
				$this->application->getDispatcher()->removeListener('onExtensionAfterInstall', $afterInstall);
			}

			foreach ($savedEnvironment as $key => $value)
			{
				putenv($value === false ? $key : $key . '=' . $value);
			}
		}
	}
}
