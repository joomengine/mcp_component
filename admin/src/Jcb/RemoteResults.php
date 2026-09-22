<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\DI\Container;
use Joomla\DI\ContainerResource;
use ReflectionMethod;
use ReflectionProperty;
use VDM\Joomla\Componentbuilder\Factory;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Fresh reads of the exact repositories/branches targeted by native push.
 * Native factories are not instantiated during verification, and a read-only
 * clone of the existing dependency resolver cannot silently populate GUIDs.
 *
 * @since 0.1.0
 */
final class RemoteResults
{
	/**
	 * @param array $targets Actual native tracker selections, including dependencies.
	 * @param Container $container The already-executed builder's capability registry.
	 * @return array Hash evidence and explicit failures without URLs or credentials.
	 * @since 0.1.0
	 */
	public function inspect(array $targets, Container $container): array
	{
		$results = [];
		$unverified = 0;
		$failed = 0;
		$reads = 0;
		$dependencies = [];

		foreach ($targets as $target)
		{
			try
			{
				$service = $this->existing($container, Factory::getArea($target['entity']) . '.Remote.Set');
				$items = new ReadOnlyItems((new ReflectionProperty('VDM\\Joomla\\Abstraction\\Remote\\Set', 'items'))->getValue($service));
				$rows = $items->table($target['entity'])->get([$target['value']], $target['key']);
				$raw = $rows === null ? null : reset($rows);

				if (!is_object($raw))
				{
					throw new OperationException('JCB_VERIFY_LOCAL_MISSING', 'The pushed native row cannot be independently re-read.');
				}

				$expected = $service->mapItem(clone $raw);
				$resolverProperty = new ReflectionProperty('VDM\\Joomla\\Abstraction\\Remote\\Set', 'resolver');
				$resolver = $resolverProperty->isInitialized($service) ? $resolverProperty->getValue($service) : null;

				if ($resolver !== null)
				{
					if (get_class($resolver) !== 'VDM\\Joomla\\Componentbuilder\\Package\\Dependency\\Resolver')
					{
						throw new OperationException('JCB_VERIFY_UNSUPPORTED', 'This native dependency resolver has no read-only verification adapter.');
					}

					$resolver = clone $resolver;
					$trackerProperty = new ReflectionProperty($resolver, 'tracker');
					$trackerProperty->setValue($resolver, clone $trackerProperty->getValue($resolver));
					$itemProperty = new ReflectionProperty($resolver, 'items');
					$itemProperty->setValue($resolver, new ReadOnlyItems($itemProperty->getValue($resolver)));

					foreach ($resolver->extract($expected) ?? [] as $key => $value)
					{
						$expected->{$key} = $value;
					}
				}

				foreach ((array) ($expected->{'@dependencies'} ?? []) as $dependency)
				{
					$dependency = (array) $dependency;
					$dependencies[Json::canonical($dependency)] = $dependency;
				}

				$targeted = 0;

				foreach ($service->repos as $repo)
				{
					if (empty($repo->write_branch) || $repo->write_branch === 'default'
						|| !(new ReflectionMethod($service, 'targetRepo'))->invoke($service, $raw, $repo))
					{
						continue;
					}

					if (++$reads > 500)
					{
						throw new OperationException('JCB_VERIFY_LIMIT', 'The remote read-back exceeds its bounded verification budget.');
					}

					$targeted++;
					$proof = $this->read($service, $expected, $repo);
					$results[] = $target + $proof;
					$failed += $proof['matches'] ? 0 : 1;
				}

				if ($targeted === 0)
				{
					$failed++;
					$results[] = $target + ['matches' => false, 'reason' => 'No native approved write repository was selected.'];
				}
			}
			catch (\Throwable $error)
			{
				$unverified++;
				$results[] = $target + ['matches' => false, 'reason' => $error instanceof OperationException
					? $error->getIdentifier() : 'JCB_REMOTE_READBACK_UNAVAILABLE'];
			}
		}

		foreach ($dependencies as $dependency)
		{
			if (($dependency['table'] ?? '') === 'file_system')
			{
				continue;
			}

			try
			{
				$service = $this->existing($container, Factory::getArea($dependency['entity']) . '.Remote.Set');
				$items = new ReadOnlyItems((new ReflectionProperty('VDM\\Joomla\\Abstraction\\Remote\\Set', 'items'))->getValue($service));
				$rows = $items->table($dependency['entity'])->get([$dependency['value']], $dependency['key']) ?? [];
				$guid = $service->getGuidField();
				$covered = $rows !== [];

				foreach ($rows as $row)
				{
					$covered = $covered && count(array_filter($targets, static fn (array $target): bool =>
						$target['entity'] === $dependency['entity'] && $target['key'] === $guid
						&& $target['value'] === ($row->{$guid} ?? null))) > 0;
				}

				$unverified += $covered ? 0 : 1;
			}
			catch (\Throwable $error)
			{
				$unverified++;
			}
		}

		$files = (new FileResults())->inspect(array_values($dependencies), $container, true);
		$failed += $files['failedCount'];
		$unverified += $files['unverifiedCount'];

		return ['records' => $results, 'files' => $files, 'verifiedCount' => count(array_filter($results, static fn (array $row): bool => $row['matches'])),
			'failedCount' => $failed, 'unverifiedCount' => $unverified,
			'complete' => $results !== [] && $failed === 0 && $unverified === 0];
	}

	/** @param Container $container Native registry. @param string $key Fixed factory service name. @return object Already-created native service. @since 0.1.0 */
	private function existing(Container $container, string $key): object
	{
		$resource = $container->getResource($key);
		$instance = $resource === null ? null : (new ReflectionProperty(ContainerResource::class, 'instance'))->getValue($resource);

		if (!$instance instanceof \VDM\Joomla\Abstraction\Remote\Set)
		{
			throw new OperationException('JCB_VERIFY_UNAVAILABLE', 'The native operation did not instantiate this remote handler.');
		}

		return $instance;
	}

	/** @param object $service Existing native writer. @param object $expected Native mapped local source. @param object $repo Actual configured repository. @return array Safe fresh read-back proof. @since 0.1.0 */
	private function read(object $service, object $expected, object $repo): array
	{
		$git = (new ReflectionProperty('VDM\\Joomla\\Abstraction\\Remote\\Set', 'git'))->getValue($service);
		$grep = (new ReflectionProperty('VDM\\Joomla\\Abstraction\\Remote\\Set', 'grep'))->getValue($service);
		$path = (new ReflectionMethod($service, 'index_map_IndexSettingsPath'))->invoke($service, $expected);
		$git->setTarget($repo->target ?? 'gitea');
		$grep->loadApi($git, $repo->base ?? null, $repo->token ?? null);

		try
		{
			// Contents::get performs a new API read; no stale Grep index is reused.
			$remote = $git->get($repo->organisation, $repo->repository, $path, $repo->write_branch);
			$index = $git->get($repo->organisation, $repo->repository, $service->getIndexPath(), $repo->write_branch);
			$guidField = $service->getGuidField();
			$entry = is_object($index) ? ($index->{$expected->{$guidField}} ?? null) : null;
			$expectedIndex = $service->getIndexItem($expected);
			$indexMatches = is_object($entry) && is_array($expectedIndex)
				&& ($entry->settings ?? null) === ($expectedIndex['settings'] ?? null)
				&& ($entry->path ?? null) === ($expectedIndex['path'] ?? null);
			$compared = clone $expected;
			$codeMatches = true;

			if (get_class($service) === 'VDM\\Joomla\\Componentbuilder\\Power\\Remote\\Set')
			{
				$codePath = (new ReflectionMethod($service, 'index_map_PowerPath'))->invoke($service, $expected);
				$code = $git->get($repo->organisation, $repo->repository, $codePath, $repo->write_branch);
				$codeMatches = is_string($code) && hash_equals((string) ($expected->main_class_code ?? ''), $code);
				unset($compared->main_class_code, $compared->extends_name, $compared->implement_names);
			}

			$matches = is_object($remote) && $codeMatches && $indexMatches && self::equivalent($compared, $remote);
			return ['repository' => (string) ($repo->guid ?? ''), 'branchHash' => hash('sha256', $repo->write_branch),
				'matches' => $matches, 'indexMatches' => $indexMatches,
				'expectedHash' => hash('sha256', Json::canonical($compared)),
				'remoteHash' => is_object($remote) ? hash('sha256', Json::canonical($remote)) : null];
		}
		finally
		{
			$git->reset_();
		}
	}

	/** @param object $expected Native mapped source. @param object $actual Fresh repository JSON. @return bool Native fields and dependency sets match. @since 0.1.0 */
	private static function equivalent(object $expected, object $actual): bool
	{
		$left = (array) $expected;
		$right = (array) $actual;

		foreach (['@dependencies'] as $key)
		{
			foreach ([&$left, &$right] as &$row)
			{
				$dependencies = array_map(static fn (mixed $value): string => Json::canonical($value), (array) ($row[$key] ?? []));
				sort($dependencies, SORT_STRING);
				$row[$key] = $dependencies;
			}
			unset($row);
		}

		return hash_equals(Json::canonical($left), Json::canonical($right));
	}
}
