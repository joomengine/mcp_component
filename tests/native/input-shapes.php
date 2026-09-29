<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\CoreEntityCatalogue;

test('validated JSON objects become native arrays only at Joomla form save', static function (): void
{
	$cases = [
		['modules.site', ['title' => 'Object-shaped module', 'module' => 'mod_custom', 'params' => (object) []]],
		['fields.content-articles', ['title' => 'Object-shaped field', 'name' => 'object-shaped-field', 'type' => 'text',
			'params' => (object) [], 'fieldparams' => (object) ['options' => [(object) ['value' => '1']], 'nested' => (object) []]]],
		['content.articles', ['title' => 'Object-shaped article', 'metadata' => (object) [],
			'attribs' => (object) ['nested' => (object) [], 'list' => []]]],
		['modules.site', ['title' => 'Numeric object parameters', 'module' => 'mod_custom', 'params' => (object) ['0' => 'x', '1' => (object) []]]],
		['content.articles', ['title' => 'Numeric object metadata', 'metadata' => (object) ['0' => 'x', '1' => (object) []]]],
	];

	foreach ($cases as [$identifier, $data])
	{
		$entity = array_values(array_filter(CoreEntityCatalogue::all(), static fn ($candidate): bool => $candidate->id === $identifier))[0];
		$model = new class
		{
			/** @var array Native data actually received by save. */
			public array $saved = [];
			/** @var int Actual model save count. */
			public int $calls = 0;

			/** @param string $key Fixed model state. @param mixed $value State value. @return void */
			public function setState(string $key, mixed $value): void
			{
			}

			/** @param array $data Validated native form. @return bool Fixture accepts Joomla Table array contracts. */
			public function save(array $data): bool
			{
				$this->calls++;

				foreach (['params', 'fieldparams', 'metadata', 'attribs'] as $name)
				{
					if (array_key_exists($name, $data))
					{
						expect(is_array($data[$name]), 'Joomla Table binding requires native arrays for ' . $name);
					}
				}

				$this->saved = $data;

				return true;
			}

			/** @param string $key Native model identity state. @param int $default Unused fallback. @return int Persisted fixture identifier. */
			public function getState(string $key, int $default = 0): int
			{
				return 91;
			}

			/** @param int $id Native saved identifier. @return object Independent model read-back. */
			public function getItem(int $id): object
			{
				return (object) (['id' => $id] + $this->saved);
			}
		};
		$provider = new class($model) implements ModelProviderInterface
		{
			/** @var object Fixed fixture administrator model. */
			private object $model;

			/** @param object $model Native administrator model fixture. */
			public function __construct(object $model)
			{
				$this->model = $model;
			}

			/** @inheritDoc */
			public function administrator(string $component, string $modelName): object
			{
				return $this->model;
			}
		};
		$before = json_encode($data, JSON_THROW_ON_ERROR);
		$action = new CoreEntityAction($entity, 'create', $provider);
		$preview = $action->execute(['data' => (object) $data]);
		expect($preview['dryRun'] && $model->calls === 0, 'Object normalization never executes during preview.');
		$result = $action->execute(['data' => (object) $data, 'dryRun' => false, '_edgeConfirmed' => true]);
		expect($result['applied'] && $model->calls === 1, 'The confirmed object-shaped form is saved exactly once.');
		expect(json_encode($data, JSON_THROW_ON_ERROR) === $before, 'Native form adaptation does not mutate the approved JSON objects.');

		if (isset($model->saved['fieldparams']))
		{
			expect($model->saved['fieldparams']['options'] === [['value' => '1']]
				&& $model->saved['fieldparams']['nested'] === [], 'Nested field parameters and lists use the native array binding contract.');
		}

		foreach (['params', 'metadata'] as $name)
		{
			if (isset($data[$name]) && property_exists($data[$name], '0'))
			{
				expect($model->saved[$name] === ['x', []], 'Explicit numeric-key object parameters use Joomla native bind arrays.');
			}
		}
	}
});
