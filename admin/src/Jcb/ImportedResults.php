<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Joomla\DI\Container;
use Joomla\DI\ContainerResource;
use ReflectionProperty;
use VDM\Joomla\Componentbuilder\Factory;
use VDM\Joomla\Componentbuilder\Utilities\RepoHelper;
use VDM\Joomla\Interfaces\Data\ItemInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Compares native imports with fresh repository reads without replaying imports.
 *
 * @since 0.1.1
 */
final class ImportedResults
{
	/**
	 * @param array $targets The native transaction's observed local selections.
	 * @param Container $container Already-executed native package services.
	 * @param ItemInterface $item Native decoded, read-only use of local definitions.
	 * @param array $local Existing local items deliberately retained by get/init.
	 * @param array $prepared Frozen original input.
	 * @return array Independent source and dependency read-back evidence.
	 * @since 0.1.1
	 */
	public function inspect(array $targets, Container $container, ItemInterface $item, array $local, array $prepared): array
	{
		$records = [];
		$failed = 0;
		$unverified = 0;
		$dependencies = [];
		$original = $item->getTable();
		$custom = $this->repository($prepared);

		try
		{
			foreach ($targets as $target)
			{
				// get/init intentionally preserve an existing local definition.
				if (($local[$target['value']] ?? null) === $target['entity'])
				{
					continue;
				}

				try
				{
					if (count($records) >= 500)
					{
						throw new OperationException('JCB_VERIFY_LIMIT', 'The import read-back exceeds its bounded verification budget.');
					}
					$service = $this->existing($container, Factory::getArea($target['entity']) . '.Remote.Get');
					$raw = $item->table($target['entity'])->get($target['value'], $target['key']);
					$grep = clone (new ReflectionProperty('VDM\\Joomla\\Abstraction\\Remote\\Get', 'grep'))->getValue($service);
					$tracker = new ReflectionProperty('VDM\\Joomla\\Abstraction\\Grep', 'tracker');
					$tracker->setValue($grep, clone $tracker->getValue($grep));
					$grep->paths = array_map(static function (object $repo): object
					{
						$copy = clone $repo;
						unset($copy->index);
						return $copy;
					}, $grep->paths ?? []);
					$grep->setBranchField('read_branch');
					$repo = $custom === null ? null : clone $custom;

					if ($repo !== null)
					{
						unset($repo->index);
						if (!$grep->validRepo($repo))
						{
							throw new OperationException('JCB_VERIFY_REPOSITORY', 'The approved repository is no longer readable.');
						}
					}

					$remote = $grep->get($target['value'], ['remote'], $repo);
					$expected = is_object($remote) ? $service->mapItem($remote) : null;
					$actual = is_object($raw) ? $service->mapItem($raw) : null;
					$matches = $expected !== null && $actual !== null
						&& hash_equals(Json::canonical($expected), Json::canonical($actual));
					$failed += $matches ? 0 : 1;
					$records[] = $target + ['matches' => $matches,
						'expectedHash' => $expected === null ? null : hash('sha256', Json::canonical($expected)),
						'localHash' => $actual === null ? null : hash('sha256', Json::canonical($actual))];

					foreach ((array) ($remote->{'@dependencies'} ?? []) as $dependency)
					{
						$dependency = (array) $dependency;
						$dependencies[Json::canonical($dependency)] = $dependency;
					}
				}
				catch (\Throwable $error)
				{
					$unverified++;
					$records[] = $target + ['matches' => false, 'reason' => $error instanceof OperationException
						? $error->getIdentifier() : 'JCB_IMPORT_READBACK_UNAVAILABLE'];
				}
			}

			foreach ($dependencies as $dependency)
			{
				if (($dependency['table'] ?? '') === 'file_system')
				{
					continue;
				}

				$entity = $dependency['entity'] ?? '';
				$key = $dependency['key'] ?? '';
				$value = $dependency['value'] ?? '';

				if (!is_string($entity) || !is_string($key) || !is_string($value)
					|| preg_match('/\A[a-z][a-z0-9_]*\z/D', $entity) !== 1
					|| preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) !== 1
					|| Factory::getArea($entity) === null || strlen($value) > 190)
				{
					$unverified++;
					continue;
				}

				$present = $item->table($entity)->get($value, $key) !== null;
				$failed += $present ? 0 : 1;
				$records[] = ['entity' => $entity, 'key' => $key, 'value' => $value,
					'matches' => $present, 'scope' => 'referenced local dependency'];
			}
		}
		finally
		{
			$item->table($original);
		}

		$files = (new FileResults())->inspect(array_values($dependencies), $container, false, $custom, $local);
		$failed += $files['failedCount'];
		$unverified += $files['unverifiedCount'];

		return ['records' => $records, 'files' => $files, 'verifiedCount' => count(array_filter($records, static fn (array $row): bool => $row['matches'])),
			'failedCount' => $failed, 'unverifiedCount' => $unverified,
			'complete' => $records !== [] && $failed === 0 && $unverified === 0];
	}

	/** @param Container $container Native registry. @param string $key Fixed service key. @return object Existing importer. @since 0.1.1 */
	private function existing(Container $container, string $key): object
	{
		$resource = $container->getResource($key);
		$instance = $resource === null ? null : (new ReflectionProperty(ContainerResource::class, 'instance'))->getValue($resource);

		if (!$instance instanceof \VDM\Joomla\Abstraction\Remote\Get)
		{
			throw new OperationException('JCB_VERIFY_UNAVAILABLE', 'The native operation did not instantiate this import handler.');
		}

		return $instance;
	}

	/** @param array $prepared Frozen native input. @return object|null Explicit repository or native configured order. @since 0.1.1 */
	private function repository(array $prepared): ?object
	{
		$value = ((array) $prepared['input']['options'])['repo'] ?? null;

		if ($value === null || $value === '')
		{
			return null;
		}

		$decoded = json_decode($value);

		if (is_object($decoded))
		{
			return $decoded;
		}

		$guid = is_string($decoded) ? $decoded : $value;
		// This native helper reads the configured row and models its target type,
		// access mode and credentials exactly as the original command does.
		$repo = RepoHelper::getRepo($guid);

		if (!is_object($repo))
		{
			throw new OperationException('JCB_VERIFY_REPOSITORY', 'The explicit repository cannot be independently resolved locally.');
		}

		return $repo;
	}
}
