<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use ReflectionProperty;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Reads actual native package result categories and independent persisted rows.
 * Remote write equivalence is never inferred from a command's success message.
 *
 * @since 0.1.0
 */
final class PackageResults
{
	/**
	 * Observe the reviewed package command after its native execution.
	 *
	 * @param object $command Registry-verified native package command.
	 * @param array $prepared Frozen inputs and original selection.
	 * @param array $definition Inspected actual entity/implementation.
	 * @return array Categorized GUIDs, row hashes and explicit verification scope.
	 * @since 0.1.0
	 */
	public function inspect(object $command, array $prepared, array $definition): array
	{
		$push = str_starts_with($prepared['command'], 'componentbuilder:push:');
		$direction = $push ? 'Set' : 'Get';
		$builder = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Abstraction\\Console\\Package\\' . $direction,
			strtolower($direction)))->getValue($command);
		$categories = ['local' => [], 'not_found' => [], 'added' => []];
		$targets = [];
		$failed = 0;

		if ($builder !== null)
		{
			if (!$push)
			{
				$results = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Package\\Builder\\Get', 'results'))->getValue($builder);

				foreach ($categories as $category => $_)
				{
					foreach ((array) ($results[$category] ?? []) as $guid => $entity)
					{
						$this->target($targets, $entity, 'guid', $guid);
						$categories[$category][$guid] = $entity;
					}
				}
			}

			$tracker = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Package\\Builder\\' . $direction, 'tracker'))->getValue($builder);

			foreach ((array) $tracker->get('save', []) as $entity => $records)
			{
				foreach ((array) $records as $selector => $saved)
				{
					$parts = explode('|', (string) $selector, 2);

					if (count($parts) !== 2)
					{
						continue;
					}

					$this->target($targets, $entity, $parts[0], $parts[1]);
					$failed += $saved === false ? 1 : 0;
				}
			}
		}

		$item = (new ReflectionProperty('VDM\\Joomla\\Componentbuilder\\Abstraction\\Console\\Package', 'item'))->getValue($command);
		$original = $item->getTable();
		$observations = [];
		$missing = 0;

		try
		{
			foreach ($targets as $target)
			{
				$row = $item->table($target['entity'])->get($target['value'], $target['key']);
				$missing += $row === null ? 1 : 0;
				$observations[] = $target + ['persisted' => $row !== null,
					'sha256' => $row === null ? null : hash('sha256', Json::canonical($row))];
			}
		}
		finally
		{
			$item->table($original);
		}

		$covered = $prepared['input']['selectors'] !== [];

		foreach ($prepared['input']['selectors'] as $selector)
		{
			$covered = $covered && ($categories['local'][$selector] ?? '') === $definition['entity'];
		}

		$status = 'unverified';
		$reason = 'Local row hashes are observed; complete remote and dependency equivalence has not been independently established.';

		if ($missing > 0 || $failed > 0 || $categories['not_found'] !== [])
		{
			$status = 'partial';
			$reason = 'Some native package targets were missing or failed; successful partial effects remain recorded.';
		}
		elseif ($covered && $categories['added'] === [] && $observations !== []
			&& preg_match('/\Acomponentbuilder:(get|init):/', $prepared['command']) === 1)
		{
			$status = 'verified';
			$reason = 'Every explicitly selected item was already local and was independently read back through the native data service.';
		}

		return ['categories' => (object) array_map(static fn (array $values): object => (object) $values, $categories),
			'readBack' => $observations, 'verification' => ['status' => $status, 'reason' => $reason,
				'scope' => 'local definitions', 'observedCount' => count($observations), 'missingCount' => $missing,
				'nativeFailureCount' => $failed, 'notFoundCount' => count($categories['not_found'])]];
	}

	/** @param array $targets Bounded trusted native selections. @param mixed $entity Native entity. @param mixed $key Native selector field. @param mixed $value Native selector. @return void @since 0.1.0 */
	private function target(array &$targets, mixed $entity, mixed $key, mixed $value): void
	{
		if (!is_string($entity) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $entity) !== 1
			|| !is_string($key) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) !== 1
			|| !is_string($value) || strlen($value) > 190 || str_contains($value, "\0")
			|| \VDM\Joomla\Componentbuilder\Factory::getArea($entity) === null)
		{
			throw new OperationException('JCB_RESULT_UNVERIFIABLE', 'A native package result has no reviewed local entity identity.');
		}

		$targets[$entity . '.' . $key . '|' . $value] = ['entity' => $entity, 'key' => $key, 'value' => $value];

		if (count($targets) > 2000)
		{
			throw new OperationException('JCB_RESULT_LIMIT', 'The package result exceeds the bounded independent read-back limit.');
		}
	}
}
