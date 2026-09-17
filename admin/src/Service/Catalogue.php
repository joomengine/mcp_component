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
use RuntimeException;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\StoreInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;


/**
 * Database-only catalogue with identical disclosure and direct-call boundaries.
 *
 * A snapshot lasts one protocol operation. The protocol adapters refresh before
 * discovery and invocation, including on long-lived stdio connections. No source
 * JSON, migration reference or compiled-in fallback can supply runtime records.
 *
 * @since 0.1.0
 */
final class Catalogue
{
	/** @var StoreInterface Portable configuration persistence. @since 0.1.0 */
	private StoreInterface $store;

	/** @var Authorizer Joomla row and operation authorization. @since 0.1.0 */
	private Authorizer $authorizer;

	/** @var PrincipalInterface Authenticated request authority. @since 0.1.0 */
	private PrincipalInterface $principal;

	/** @var SchemaValidator Inert schema validation. @since 0.1.0 */
	private SchemaValidator $schemas;

	/** @var Settings Installation/platform settings. @since 0.1.0 */
	private Settings $settings;

	/** @var Closure(string):bool Actual installed-extension availability. @since 0.1.0 */
	private Closure $extensionEnabled;

	/** @var Closure(string,string):bool Explicit registered-handler availability by entity. @since 0.1.0 */
	private Closure $handlerAvailable;

	/** @var array<string,array<int,array<string,mixed>>> Current operation's database snapshot. @since 0.1.0 */
	private array $records = [];

	/**
	 * Inject policy, persistence and registered execution boundaries.
	 *
	 * @param StoreInterface $store Configuration persistence.
	 * @param Authorizer $authorizer Row authorizer.
	 * @param PrincipalInterface $principal Current authority.
	 * @param SchemaValidator $schemas Schema validator.
	 * @param Settings $settings Installation settings.
	 * @param callable $extensionEnabled Installed extension lookup.
	 * @param callable $handlerAvailable Reviewed handler lookup.
	 * @since 0.1.0
	 */
	public function __construct(
		StoreInterface $store,
		Authorizer $authorizer,
		PrincipalInterface $principal,
		SchemaValidator $schemas,
		Settings $settings,
		callable $extensionEnabled,
		callable $handlerAvailable
	)
	{
		$this->store = $store;
		$this->authorizer = $authorizer;
		$this->principal = $principal;
		$this->schemas = $schemas;
		$this->settings = $settings;
		$this->extensionEnabled = Closure::fromCallable($extensionEnabled);
		$this->handlerAvailable = Closure::fromCallable($handlerAvailable);
	}

	/**
	 * Refresh bounded configuration tables, never loading action-state secrets.
	 *
	 * @return void
	 * @since 0.1.0
	 */
	public function refresh(): void
	{
		$records = [];

		foreach (Structure::definitions() as $entity => $columns)
		{
			$records[$entity] = [];

			for ($offset = 0; ; $offset += 1000)
			{
				$page = $this->store->find($entity, [], 1000, $offset);

				foreach ($page as $row)
				{
					if ((int) ($row['id'] ?? 0) < 1)
					{
						throw new OperationException('CATALOGUE_INVALID', 'The installed catalogue contains an invalid record.');
					}

					foreach (['configuration', 'definition', 'params'] as $field)
					{
						if (isset($row[$field]))
						{
							$value = Json::decode((string) $row[$field]);

							if (!is_array($value))
							{
								throw new OperationException('CATALOGUE_INVALID', 'A catalogue configuration must be a JSON object.');
							}

							$row[$field] = $value;
						}
					}

					$row['entity'] = $entity;
					$row['asset_name'] = 'com_joomengine_mcp.' . $entity . '.' . (int) $row['id'];
					$records[$entity][(int) $row['id']] = $row;
				}

				if (count($page) < 1000)
				{
					break;
				}

				if ($offset >= 19000)
				{
					throw new OperationException('CATALOGUE_LIMIT', 'The installed catalogue exceeds the supported per-entity bound.');
				}
			}
		}

		$this->records = $records;
	}

	/**
	 * Return only disclosed and executable definitions for this principal.
	 *
	 * @param string $entity Reviewed configuration entity.
	 * @return array<int,array<string,mixed>> Authorized definitions.
	 * @since 0.1.0
	 */
	public function all(string $entity): array
	{
		$this->ensureLoaded();

		if (!isset($this->records[$entity]))
		{
			throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested MCP definition is unavailable.');
		}

		$result = [];

		foreach ($this->records[$entity] as $row)
		{
			if (!$this->visible($row))
			{
				continue;
			}

			if ($entity === 'action' && $this->bindings((int) $row['id']) === [])
			{
				continue;
			}

			if ($entity === 'tool')
			{
				$fixedAction = $row['configuration']['action'] ?? null;

				if (is_string($fixedAction) && !$this->hasAction($fixedAction))
				{
					continue;
				}
			}

			$result[] = $this->joinProvider($row);
		}

		usort($result, static function (array $left, array $right): int
		{
			return strcmp((string) $left['name'], (string) $right['name']);
		});

		return $result;
	}

	/**
	 * Resolve a visible record without distinguishing hidden from unknown names.
	 *
	 * @param string $entity Definition type.
	 * @param string|int $identifier Stable name or numeric ID.
	 * @return array<string,mixed> Authorized definition.
	 * @since 0.1.0
	 */
	public function get(string $entity, string|int $identifier): array
	{
		foreach ($this->all($entity) as $row)
		{
			if ((is_int($identifier) && (int) $row['id'] === $identifier) || (is_string($identifier) && $row['name'] === $identifier))
			{
				return $row;
			}
		}

		throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested MCP definition is unavailable.');
	}

	/**
	 * Resolve one action and an unambiguous binding in the actual authority track.
	 *
	 * @param string $name Semantic action name.
	 * @param string $transport Requested track or auto; never creates authority.
	 * @return array{action:array<string,mixed>,binding:array<string,mixed>,revision:string}
	 * @since 0.1.0
	 */
	public function action(string $name, string $transport = 'auto'): array
	{
		if (!in_array($transport, ['auto', $this->principal->getTrack()], true))
		{
			throw new OperationException('TRANSPORT_UNAVAILABLE', 'The requested transport is unavailable to this connection.');
		}

		$action = $this->get('action', $name);
		$bindings = $this->bindings((int) $action['id']);
		usort($bindings, static function (array $left, array $right): int
		{
			return [(int) $left['ordering'], (int) $left['id']] <=> [(int) $right['ordering'], (int) $right['id']];
		});
		$binding = $bindings[0];

		if (isset($bindings[1]) && (int) $bindings[1]['ordering'] === (int) $binding['ordering'])
		{
			throw new OperationException('BINDING_AMBIGUOUS', 'This action has competing bindings with equal priority.');
		}

		$documents = [$this->schema((int) $binding['input_schema_id'])];

		if (!empty($binding['output_schema_id']))
		{
			$documents[] = $this->schema((int) $binding['output_schema_id']);
		}

		$provider = $this->records['provider'][(int) $action['provider_id']];
		$revision = hash('sha256', Json::canonical([$action, $binding, $documents, $provider]));

		return ['action' => $action, 'binding' => $binding, 'revision' => $revision];
	}

	/** @param int $id Authorized schema ID. @return string Inert schema document. @since 0.1.0 */
	public function schema(int $id): string
	{
		$this->ensureLoaded();
		$row = $this->records['schema'][$id] ?? null;

		if ($row === null || !$this->visible($row))
		{
			throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested MCP definition is unavailable.');
		}

		$this->schemas->document($row['document']);

		return $row['document'];
	}

	/** @param array<string,mixed> $row Current authorized row. @return void @since 0.1.0 */
	public function requireExecution(array $row): void
	{
		try
		{
			$this->authorizer->requireExecution($this->joinProvider($row), $this->principal);
		}
		catch (RuntimeException)
		{
			throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested MCP definition is unavailable.');
		}
	}

	/** @param int $actionId Parent action ID. @return array<int,array<string,mixed>> Visible bindings for this track. @since 0.1.0 */
	private function bindings(int $actionId): array
	{
		$result = [];

		foreach ($this->records['binding'] as $row)
		{
			if ((int) $row['action_id'] === $actionId && $row['track'] === $this->principal->getTrack() && $this->visible($row))
			{
				$result[] = $this->joinProvider($row);
			}
		}

		return $result;
	}

	/** @param string $name Action name. @return bool Whether this connection can discover the action. @since 0.1.0 */
	private function hasAction(string $name): bool
	{
		foreach ($this->records['action'] as $row)
		{
			if ($row['name'] === $name && $this->visible($row) && $this->bindings((int) $row['id']) !== [])
			{
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $row Database row. @return bool Whether policy, dependencies and handler permit disclosure. @since 0.1.0 */
	private function visible(array $row): bool
	{
		$provider = $row['entity'] === 'provider' ? $row : ($this->records['provider'][(int) ($row['provider_id'] ?? 0)] ?? null);

		if ($provider === null || !$this->authorizer->canView($this->joinProvider($row), $this->principal))
		{
			return false;
		}

		$metadata = $provider['definition'];
		$version = $this->settings->get('joomla_version');

		if (version_compare($version, $metadata['minimumJoomla'] ?? '6.1.0', '<')
			|| version_compare($version, $metadata['maximumJoomlaExclusive'] ?? '7.0.0', '>=')
			|| !(($this->extensionEnabled)((string) $provider['extension'])))
		{
			return false;
		}

		foreach ($row['definition']['required_extensions'] ?? [] as $extension)
		{
			if (!is_string($extension) || !(($this->extensionEnabled)($extension)))
			{
				return false;
			}
		}

		if (isset($row['handler']) && !(($this->handlerAvailable)($row['entity'], $row['handler'])))
		{
			return false;
		}

		if (isset($row['configuration']['tracks']) && !in_array($this->principal->getTrack(), $row['configuration']['tracks'], true))
		{
			return false;
		}

		foreach (['input_schema_id', 'output_schema_id'] as $key)
		{
			if (!empty($row[$key]))
			{
				$schema = $this->records['schema'][(int) $row[$key]] ?? null;

				if ($schema === null || !$this->visible($schema))
				{
					return false;
				}
			}
		}

		return true;
	}

	/** @param array<string,mixed> $row Database row. @return array<string,mixed> Row with provider policy. @since 0.1.0 */
	private function joinProvider(array $row): array
	{
		$provider = $row['entity'] === 'provider' ? $row : ($this->records['provider'][(int) ($row['provider_id'] ?? 0)] ?? []);

		return $row + [
			'provider_published' => $provider['published'] ?? 0,
			'provider_access' => $provider['access'] ?? 0,
			'provider_asset_name' => $provider['asset_name'] ?? '',
		];
	}

	/** @return void Load the initial operation snapshot on first use. @since 0.1.0 */
	private function ensureLoaded(): void
	{
		if ($this->records === [])
		{
			$this->refresh();
		}
	}
}
