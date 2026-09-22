<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\DI\Container;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\PackageResults;
use VDM\Joomla\Interfaces\Data\LoadInterface;

$joomla = getenv('JOOMLA_SRC') ?: (getenv('JOOMLA_ROOT') ?: '');
$jcb = getenv('JCB_SRC') ?: $joomla;
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $joomla . '/administrator/components/com_joomengine_mcp/vendor/autoload.php';

if (!is_file($joomla . '/libraries/vendor/autoload.php') || !is_dir($jcb . '/libraries/vendor_jcb/VDM.Joomla'))
{
	throw new RuntimeException('JCB_SRC and JOOMLA_SRC must select actual pinned JCB and Joomla source installations.');
}

require $joomla . '/libraries/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($jcb): void
{
	$prefix = 'VDM\\Joomla\\';

	if (str_starts_with($class, $prefix))
	{
		$file = $jcb . '/libraries/vendor_jcb/VDM.Joomla/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if (is_file($file))
		{
			require $file;
		}
	}
});

/** Substitutes only the database read boundary beneath the actual native Item. */
final class PackageProofLoad implements LoadInterface
{
	/** @var string Active native load table. */
	private string $table = 'original_table';

	/** @var array Rows keyed by table and exact selector. */
	public array $rows = [];

	/** @var array Observed database read selections. */
	public array $reads = [];

	/** Select a local table without loading or writing anything. */
	public function table(?string $table): self
	{
		$this->table = (string) $table;
		return $this;
	}

	/** Return an independently persisted row for the exact native selector. */
	public function item(array $keys): ?object
	{
		$this->reads[] = [$this->table, $keys];
		return $this->rows[$this->table . ':' . json_encode($keys, JSON_THROW_ON_ERROR)] ?? null;
	}

	/** Scalar reads are outside this proof's exercised native contract. */
	public function value(array $keys, string $field)
	{
		throw new RuntimeException('Unexpected scalar read.');
	}

	/** Scalar-list reads are outside this proof's exercised native contract. */
	public function values(array $keys, string $field): ?array
	{
		throw new RuntimeException('Unexpected scalar-list read.');
	}

	/** Multi-row reads are outside this proof's exercised native contract. */
	public function items(array $keys): ?array
	{
		throw new RuntimeException('Unexpected multi-row read.');
	}

	/** Return the active load table. */
	public function getTable(): string
	{
		return $this->table;
	}
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}

	$checks++;
};
$set = static function (object $object, string $property, mixed $value): void
{
	(new ReflectionProperty($object, $property))->setValue($object, $value);
};
$make = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
$guid = '1c20aec5-bf1a-44e7-9deb-d1c920ca591d';
$load = new PackageProofLoad();
$item = $make(VDM\Joomla\Data\Item::class);
$set($item, 'load', $load);
$item->table('original_table');
$container = new Container();
$container->set('Data.Item', $item, true);
$container->get('Data.Item');
$tracker = new VDM\Joomla\Componentbuilder\Package\Dependency\Tracker();
$builder = new VDM\Joomla\Componentbuilder\Package\Builder\Get($tracker, $container);
$command = $make(VDM\Joomla\Componentbuilder\Console\Package\Init::class);
$set($command, 'get', $builder);
// A real native facade with no selected table reproduces the original failure;
// read-back must never call its auto-importing get() or uninitialized getTable().
$facade = $make(VDM\Joomla\Data\Power\Item::class);
$set($command, 'item', $facade);
$verifier = new PackageResults();

foreach (['joomla_component' => ['Component', 'guid'], 'component_updates' => ['ComponentUpdates', 'joomla_component']] as $entity => [$area, $key])
{
	$config = $make('VDM\\Joomla\\Componentbuilder\\Package\\' . $area . '\\Remote\\Config');
	$reader = $make(VDM\Joomla\Componentbuilder\Remote\Get::class);
	$set($reader, 'config', $config);
	$container->set($area . '.Remote.Get', $reader, true);
	$container->get($area . '.Remote.Get');
	$set($builder, 'results', ['local' => [$guid => $entity], 'added' => [], 'not_found' => []]);
	$prepared = ['command' => 'componentbuilder:init:' . $entity, 'input' => ['selectors' => [$guid], 'options' => []]];
	$definition = ['entity' => $entity];
	$load->rows[$entity . ':' . json_encode([$key => $guid], JSON_THROW_ON_ERROR)] = (object) [$key => $guid, 'name' => 'persisted native row'];
	$result = $verifier->inspect($command, $prepared, $definition);
	$check($result['verification']['status'] === 'verified', $entity . ': retained local definition is independently verified.');
	$check($result['readBack'][0]['key'] === $key && $result['readBack'][0]['persisted'], $entity . ': actual native selector is preserved.');
	$check($load->reads[array_key_last($load->reads)] === [$entity, [$key => $guid]], $entity . ': local read uses exact table and selector.');
	$check($item->getTable() === 'original_table', $entity . ': shared native item table is restored.');
	$check(!(new ReflectionProperty($facade, 'entity'))->isInitialized($facade), $entity . ': auto-import facade remains untouched.');
	$load->rows = [];
	$result = $verifier->inspect($command, $prepared, $definition);
	$check($result['verification']['status'] === 'partial' && $result['verification']['missingCount'] === 1,
		$entity . ': absent local definition is partial and is never imported by verification.');
	$check($item->getTable() === 'original_table', $entity . ': shared native item table is restored after a missing row.');
}

$empty = new Container();
$set($builder, 'container', $empty);
$empty->set('ComponentUpdates.Remote.Get', $reader, true);
$empty->get('ComponentUpdates.Remote.Get');

try
{
	$verifier->inspect($command, $prepared, $definition);
	throw new RuntimeException('Read-back must fail closed when the native operation has no initialized plain reader.');
}
catch (OperationException $error)
{
	$check($error->getIdentifier() === 'JCB_RESULT_UNVERIFIABLE', 'Missing plain reader is rejected without constructing a mutating provider.');
}

$empty->set('Data.Item', $make(VDM\Joomla\Data\Item::class), true);
$empty->get('Data.Item');
$set($builder, 'results', ['local' => [], 'added' => [], 'not_found' => []]);
$result = $verifier->inspect($command, $prepared, $definition);
$check($result['verification']['status'] === 'unverified' && $result['readBack'] === [],
	'No native targets remain unverified without touching an unused plain reader.');

echo 'JCB native package read-back: ' . $checks . " checks passed.\n";
