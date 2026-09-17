<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

$source = $argv[1] ?? '';
$target = $argv[2] ?? dirname(__DIR__, 2);
$source = realpath($source);
$target = realpath($target);

if ($source === false || $target === false || !is_file($source . '/companion/plugin/src/Domain/ActionRegistry.php'))
{
	fwrite(STDERR, "Usage: php tools/migration/import-native.php PINNED_SOURCE_DIRECTORY [TARGET_DIRECTORY]\n");
	exit(1);
}

$oldPrefix = 'VDM\\Plugin\\Console\\JoomlaMcp\\';
$newPrefix = 'VDM\\Component\\JoomEngineMcp\\Administrator\\Native\\';
spl_autoload_register(static function (string $class) use ($source, $oldPrefix): void
{
	if (str_starts_with($class, $oldPrefix))
	{
		$path = $source . '/companion/plugin/src/' . str_replace('\\', '/', substr($class, strlen($oldPrefix))) . '.php';

		if (is_file($path))
		{
			require $path;
		}
	}
});

/** Export inert constructor configuration, excluding runtime service objects. */
$configuration = static function (object $action): array
{
	$values = [];

	foreach ((new ReflectionObject($action))->getProperties() as $property)
	{
		if ($property->isStatic() || !$property->isInitialized($action))
		{
			continue;
		}

		$value = $property->getValue($action);

		if ($value instanceof VDM\Plugin\Console\JoomlaMcp\Domain\CoreEntityDefinition)
		{
			$values[$property->getName()] = get_object_vars($value);
		}
		elseif (is_scalar($value) || is_array($value) || $value === null)
		{
			$values[$property->getName()] = $value;
		}
	}

	return $values;
};

$registry = (new VDM\Plugin\Console\JoomlaMcp\Joomla\JoomlaActionRegistryFactory(new stdClass()))->create();
$native = [];
$constructors = [];

foreach ($registry->all() as $action)
{
	$reflection = new ReflectionClass($action);
	$key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', preg_replace('/Action$/', '', $reflection->getShortName())));
	$native[] = ['descriptor' => $action->descriptor()->jsonSerialize(), 'handler' => 'native.' . $key, 'configuration' => $configuration($action)];
	$constructors[$reflection->getShortName()] = array_map(static function (ReflectionParameter $parameter): array
	{
		return ['name' => $parameter->getName(), 'type' => (string) $parameter->getType(), 'optional' => $parameter->isOptional()];
	}, $reflection->getConstructor()?->getParameters() ?? []);
}

$destinations = [
	'companion/plugin/src' => 'admin/src/Native',
	'companion/tests' => 'tests/native',
];
$manifest = [];
$references = ['Joomla/CoreEntityCatalogue.php', 'Joomla/JoomlaActionRegistryFactory.php'];

foreach ($destinations as $from => $to)
{
	$root = $source . '/' . $from;
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file)
	{
		if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php')
		{
			continue;
		}

		$relative = substr($file->getPathname(), strlen($root) + 1);

		if ($from === 'companion/plugin/src' && str_starts_with($relative, 'Extension/'))
		{
			continue;
		}

		$destination = $to . '/' . $relative;

		if ($from === 'companion/plugin/src' && in_array($relative, $references, true))
		{
			$destination = 'tests/native/Reference/' . $relative;
		}

		$content = file_get_contents($file->getPathname());

		if ($content === false)
		{
			throw new RuntimeException('Cannot read the pinned PHP source.');
		}

		$sourceHash = hash('sha256', $content);
		$content = str_replace([$oldPrefix, str_replace('\\', '\\\\', $oldPrefix)], [$newPrefix, str_replace('\\', '\\\\', $newPrefix)], $content);

		if ($from === 'companion/tests' && $relative === 'bootstrap.php')
		{
			$content = str_replace("dirname(__DIR__) . '/plugin/src/'", "dirname(__DIR__, 2) . '/admin/src/Native/'", $content);
			$content = str_replace("    if (is_file(\$path)) {", "    if (!is_file(\$path)) {\n        \$path = __DIR__ . '/Reference/' . str_replace('\\\\', '/', \$relative) . '.php';\n    }\n\n    if (is_file(\$path)) {", $content);
		}

		$fullPath = $target . '/' . $destination;

		if (!is_dir(dirname($fullPath)) && !mkdir(dirname($fullPath), 0775, true) && !is_dir(dirname($fullPath)))
		{
			throw new RuntimeException('Cannot create the migrated source directory.');
		}

		if (file_exists($fullPath))
		{
			throw new RuntimeException('Refusing to overwrite an already migrated PHP file: ' . $destination);
		}

		if (file_put_contents($fullPath, $content) === false)
		{
			throw new RuntimeException('Cannot write migrated PHP source.');
		}

		$manifest[] = ['source' => $from . '/' . $relative, 'sourceSha256' => $sourceHash, 'target' => $destination, 'targetSha256' => hash('sha256', $content)];
	}
}

foreach (['data', 'LICENSES', 'docs/migration'] as $directory)
{
	if (!is_dir($target . '/' . $directory))
	{
		mkdir($target . '/' . $directory, 0775, true);
	}
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
file_put_contents($target . '/data/upstream-native.json', json_encode(['source' => '2cff50f4f6b440da3c684f9995a77efad32e1a36', 'actions' => $native, 'constructors' => $constructors], $flags) . "\n");
file_put_contents($target . '/docs/migration/native-source-map.json', json_encode($manifest, $flags) . "\n");
copy($source . '/LICENSE', $target . '/LICENSES/joomla-mcp.txt');
echo json_encode(['nativeActions' => count($native), 'migratedPhpFiles' => count($manifest), 'constructors' => $constructors], $flags) . "\n";
