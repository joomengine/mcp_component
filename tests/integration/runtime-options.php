<?php
/**
 * @package    JoomEngine.Mcp
 * @created    30 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Form\Form;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;

require __DIR__ . '/bootstrap.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$factory = $app->bootComponent('com_config')->getMVCFactory();
$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($name);
	}

	$checks++;
	echo 'PASS ' . $name . "\n";
};
$check($admin->id > 0 && $admin->authorise('core.admin', 'com_joomengine_mcp'), 'Native component options administrator identity');
$extension = new Extension($db);
$check($extension->load(['type' => 'component', 'element' => 'com_joomengine_mcp']), 'Installed component options registry exists');
$extensionId = (int) $extension->extension_id;
$originalParams = $extension->params;
$temporary = tempnam(sys_get_temp_dir(), 'mcp-runtime-options-');
$check(is_string($temporary) && unlink($temporary) && mkdir($temporary, 0700), 'Create private runtime options fixture outside the Joomla site');
$artifacts = $temporary . '/artifacts';
$plainFile = $temporary . '/not-executable';
$link = $temporary . '/artifact-link';
$base = [
	'api_base' => 'https://fixture.example.test/api/index.php', 'site_alias' => 'default', 'allowed_origins' => '',
	'timeout' => 30, 'max_request_bytes' => 1048576, 'max_result_bytes' => 8388608, 'max_list_limit' => 100,
	'plan_ttl' => 300, 'request_ttl' => 600, 'session_ttl' => 3600, 'job_timeout' => 3600, 'allow_indefinite' => 0,
];

/** Create the native model used by Joomla's component options save controller. */
$model = static function () use ($factory, $admin)
{
	$instance = $factory->createModel('Component', 'Administrator', ['ignore_request' => true]);
	$instance->setCurrentUser($admin);
	$instance->setState('component.option', 'com_joomengine_mcp');

	return $instance;
};

/** Load the installed config.xml, including its native rule namespace discovery. */
$form = static function () use ($model, $check): Form
{
	$instance = $model();
	$loaded = $instance->getForm([], false);
	$check($loaded instanceof Form, 'Native com_config loads installed component config.xml');
	$check($loaded->getField('php_cli_binary') !== false && $loaded->getField('artifact_directory') !== false,
		'Installed component options include both optional runtime path fields');

	return $loaded;
};

try
{
	$check(mkdir($artifacts, 0700) && file_put_contents($plainFile, 'Runtime options fixture') !== false && chmod($plainFile, 0600)
		&& symlink($artifacts, $link), 'Create concrete valid and invalid runtime path fixtures');
	$valid = ['php_cli_binary' => PHP_BINARY, 'artifact_directory' => $artifacts];
	$cases = [
		'missing' => [],
		'null' => ['php_cli_binary' => null, 'artifact_directory' => null],
		'empty' => ['php_cli_binary' => '', 'artifact_directory' => ''],
		'explicit' => $valid,
		'PHP binary only' => ['php_cli_binary' => PHP_BINARY],
		'artifact directory only' => ['artifact_directory' => $artifacts],
	];

	foreach ($cases as $name => $paths)
	{
		$input = $paths + $base;
		$loaded = $form();
		$check($loaded->validate($input), 'Native Form::validate accepts ' . $name . ' optional runtime paths');
		$instance = $model();
		$filtered = $instance->validate($instance->getForm([], false), $input);
		$check(is_array($filtered), 'Native com_config filters and validates ' . $name . ' optional runtime paths');
		$check($instance->save(['params' => $filtered, 'id' => $extensionId, 'option' => 'com_joomengine_mcp']),
			'Native com_config saves ' . $name . ' optional runtime paths');
		$readBack = new Extension($db);
		$check($readBack->load($extensionId), 'Read back persisted ' . $name . ' component options');
		$settings = new Settings((new Registry($readBack->params))->toArray());

		foreach (array_keys($valid) as $field)
		{
			$check($settings->get($field) === ($paths[$field] ?? ''), 'Persisted ' . $name . ' ' . $field . ' retains its runtime meaning');
		}
	}

	foreach (array_keys($valid) as $field)
	{
		$invalid = [
			'relative path' => 'relative/path',
			'parent traversal' => $temporary . '/../runtime-options',
			'current directory segment' => $temporary . '/./artifacts',
			'control character' => $temporary . "/\0artifacts",
			'oversized path' => '/' . str_repeat('a', 4096),
			'missing path' => $temporary . '/does-not-exist',
		];

		if ($field === 'php_cli_binary')
		{
			$invalid += ['directory as binary' => $artifacts, 'non-executable file' => $plainFile];
		}
		else
		{
			$invalid += ['file as directory' => $plainFile, 'symbolic link' => $link, 'public Joomla directory' => JPATH_ROOT];
		}

		$loaded = $form();

		foreach ($invalid as $name => $value)
		{
			$check(!$loaded->validate([$field => $value] + $valid + $base), 'Native Form::validate rejects ' . $field . ' ' . $name);
		}

		// Validate before Joomla's string filter: malformed values must never gain empty-value semantics in the rule.
		foreach (['false' => false, 'true' => true, 'zero' => 0, 'integer' => 17, 'float' => 1.5,
			'empty array' => [], 'array' => [$valid[$field]], 'object' => (object) ['path' => $valid[$field]]] as $name => $value)
		{
			$check(!$loaded->validate([$field => $value] + $valid + $base), 'Native Form::validate rejects ' . $field . ' malformed ' . $name);
		}

		// Follow the administrator filter/validation pipeline for paths unchanged by the native string filter.
		foreach (['relative/path', $temporary . '/../runtime-options', $temporary . '/does-not-exist'] as $value)
		{
			$instance = $model();
			$check($instance->validate($instance->getForm([], false), [$field => $value] + $valid + $base) === false,
				'Native com_config refuses invalid ' . $field . ' before persistence');
		}
	}
}
finally
{
	// Restore the exact pre-test database value even when a native form or save assertion fails.
	$restored = new Extension($db);
	$check($restored->load($extensionId), 'Resolve component options for restoration');
	$restored->params = $originalParams;
	$check($restored->store(), 'Restore original component options after native saves');
	$readBack = new Extension($db);
	$check($readBack->load($extensionId) && $readBack->params === $originalParams, 'Original component options restoration read-back');

	foreach ([$link, $plainFile] as $path)
	{
		if (is_link($path) || is_file($path))
		{
			$check(unlink($path), 'Remove runtime options fixture file');
		}
	}

	foreach ([$artifacts, $temporary] as $path)
	{
		if (is_dir($path))
		{
			$check(rmdir($path), 'Remove runtime options fixture directory');
		}
	}
}

echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(), 'live' => true], JSON_THROW_ON_ERROR) . "\n";
