<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Form\Form;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Component\Fields\Administrator\Model\FieldModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/HttpFixture.php';
require __DIR__ . '/StdioFixture.php';
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$app->loadIdentity($admin);
$app->bootComponent('com_joomengine_mcp');
$http = new HttpFixture((string) getenv('MCP_TEST_BASE_URL'), trim(file_get_contents((string) getenv('MCP_TEST_TOKEN_FILE'))));
$cli = null;
$grantId = null;
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}

	$checks++;
	echo 'PASS ' . $message . PHP_EOL;
};
$prefix = 'mcp-default-' . bin2hex(random_bytes(6)) . '-';
$createFieldModel = static function () use ($app, $admin): FieldModel
{
	$input = $app->getInput();
	$previousContext = $input->get('context', '', 'RAW');
	$input->set('context', 'com_content.article');

	try
	{
		$model = $app->bootComponent('com_fields')->getMVCFactory()->createModel('Field', 'Administrator', ['ignore_request' => true]);
		$model->setCurrentUser($admin);
		$model->setState('field.context', 'com_content.article');
		$model->setState('field.component', 'com_content');
		$model->setState('field.section', 'article');

		return $model;
	}
	finally
	{
		$input->set('context', $previousContext);
	}
};
$readRow = static function (string $name) use ($db): ?array
{
	$row = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'label', 'default_value']))->from($db->quoteName('#__fields'))
		->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
		->where($db->quoteName('name') . ' = ' . $db->quote($name)))->loadAssoc();

	return is_array($row) ? $row : null;
};

/** Exercise Joomla's installed field plugin with persisted data, including its default CDATA branch. */
$verifyDom = static function (int $id, string $expected, string $label) use ($app, $db, $check, $createFieldModel): void
{
	$field = $createFieldModel()->getItem($id);
	$check(is_object($field) && $field->default_value === $expected, $label . ' native field model returns the exact string default');
	$field->params = new Registry($field->params);
	$field->fieldparams = new Registry($field->fieldparams);
	$plugin = $app->bootPlugin('text', 'fields');
	$document = new DOMDocument();
	$parent = $document->appendChild(new DOMElement('form'));
	$form = new Form($field->context);
	$form->setDatabase($db);
	set_error_handler(static function (int $severity, string $message, string $file, int $line): never
	{
		throw new ErrorException($message, 0, $severity, $file, $line);
	}, E_DEPRECATED);

	try
	{
		$node = $plugin->onCustomFieldsPrepareDom($field, $parent, $form);
	}
	finally
	{
		restore_error_handler();
	}

	$check($node instanceof DOMElement, $label . ' real text plugin builds the form field without a deprecation');
	$defaults = $node->getElementsByTagName('default');
	$check($expected === '' ? $defaults->length === 0
		: $defaults->length === 1 && $defaults->item(0)->textContent === $expected
			&& $defaults->item(0)->firstChild instanceof DOMCdataSection,
		$label . ' native plugin emits no empty default node and preserves an explicit CDATA default');
};

try
{
	$http->initialize();
	$permission = $http->tool('joomla_permission_request', ['toolsets' => ['structure.write'], 'duration' => '30-minutes',
		'reason' => 'Disposable native field default persistence and DOM verification']);
	$grant = $http->tool('joomla_permission_approve', ['requestId' => $permission['requestId'], 'acknowledgement' => $permission['acknowledgement']]);
	$grantId = $grant['id'] ?? $grant['grantId'] ?? null;
	$check($grantId !== null, 'Approve the API field fixture write scope');
	$cli = new StdioFixture();

	foreach (['api' => $http, 'cli' => $cli] as $track => $client)
	{
		foreach (['omitted' => '', 'explicit-zero' => '0'] as $case => $expected)
		{
			$name = $prefix . $track . '-' . $case;
			// Supply all editable non-null native fields. Omit context/id/audit and
			// category assignment because those are outside the public write schema.
			$data = ['title' => $name, 'name' => $name, 'label' => $name, 'type' => 'text',
				'group_id' => 0, 'state' => 1, 'access' => 1, 'language' => '*', 'required' => 0,
				'only_use_in_subform' => 0, 'description' => '', 'note' => '', 'ordering' => 0,
				'params' => ['show_on' => ''], 'fieldparams' => ['filter' => 'raw']];

			if ($case === 'explicit-zero')
			{
				$data['default_value'] = $expected;
			}

			// Joomla filters supplied leaf values, so empty groups disappear from
			// validated data. Supply native options for both-client visibility and
			// the raw text filter, then keep their filtered parameter shapes.
			$model = $createFieldModel();
			$formInput = $data + ['id' => 0, 'context' => 'com_content.article', 'default_value' => $expected];
			Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_fields/forms');
			$form = $model->getForm($formInput, false);
			$check($form !== false, $track . ' ' . $case . ' loads the installed native field form');
			$filtered = $model->validate($form, $formInput);
			$errors = array_map(static fn (mixed $error): string => $error instanceof Throwable ? $error->getMessage() : (string) $error, $model->getErrors());
			$check(is_array($filtered) && is_array($filtered['params'] ?? null) && is_array($filtered['fieldparams'] ?? null),
				$track . ' ' . $case . ' validates parameter shapes through the installed text field plugin: '
				. Json::encode(['errors' => $errors, 'resultType' => get_debug_type($filtered),
					'paramsType' => get_debug_type($filtered['params'] ?? null), 'fieldparamsType' => get_debug_type($filtered['fieldparams'] ?? null)]));
			$data['params'] = $filtered['params'];
			$data['fieldparams'] = $filtered['fieldparams'];
			$check($case !== 'omitted' || !array_key_exists('default_value', $data), $track . ' omitted-default input stays absent at the MCP boundary');

			$plan = $client->tool('joomla_action_write_plan', ['action' => 'fields.content-articles.create', 'transport' => $track,
				'idempotencyKey' => Json::uuid(), 'input' => ['data' => $data]]);
			$check(is_string($plan['confirmationToken'] ?? null) && $readRow($name) === null, $track . ' ' . $case . ' plan performs no field write');
			$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
			$row = $readRow($name);
			$check($row !== null && (int) $row['id'] > 0 && $row['default_value'] === $expected,
				$track . ' ' . $case . ' create persists the exact expected non-null default');
			$check(in_array($result['verification']['status'] ?? '', ['verified', 'partial'], true),
				$track . ' ' . $case . ' create retains normal independent read-back');
			$verifyDom((int) $row['id'], $expected, $track . ' ' . $case . ' create');
			$check($client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']])['idempotentReplay'] === true,
				$track . ' ' . $case . ' confirmation replay uses the existing outcome');
			$count = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__fields'))
				->where($db->quoteName('name') . ' = ' . $db->quote($name)))->loadResult();
			$check($count === 1, $track . ' ' . $case . ' replay leaves exactly one persisted field');

			$updatedLabel = $name . ' updated';
			$plan = $client->tool('joomla_action_write_plan', ['action' => 'fields.content-articles.update', 'transport' => $track,
				'idempotencyKey' => Json::uuid(), 'input' => ['id' => (int) $row['id'], 'data' => ['label' => $updatedLabel]]]);
			$check($readRow($name)['label'] === $name, $track . ' ' . $case . ' update plan performs no write');
			$result = $client->tool('joomla_write_apply', ['confirmationToken' => $plan['confirmationToken']]);
			$updated = $readRow($name);
			$check($updated !== null && $updated['label'] === $updatedLabel && $updated['default_value'] === $expected
				&& ($result['verification']['status'] ?? '') === 'verified',
				$track . ' ' . $case . ' partial update preserves an omitted existing default');
			$verifyDom((int) $row['id'], $expected, $track . ' ' . $case . ' update');
		}
	}
}
finally
{
	$cli?->disconnect();

	try
	{
		$ids = array_map('intval', $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
			->where($db->quoteName('name') . ' LIKE ' . $db->quote($prefix . '%')))->loadColumn());

		if ($ids !== [])
		{
			$model = $createFieldModel();
			$trashIds = $ids;
			$check($model->publish($trashIds, -2) && $model->delete($ids), 'Remove every field fixture through its native Joomla model');
		}

		$remaining = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__fields'))
			->where($db->quoteName('name') . ' LIKE ' . $db->quote($prefix . '%')))->loadResult();
		$check($remaining === 0, 'No field default fixture remains after cleanup');
	}
	finally
	{
		if ($grantId !== null)
		{
			$http->tool('joomla_permission_revoke', ['grantId' => $grantId]);
		}

		$http->disconnect();
	}
}

echo Json::encode(['checks' => $checks, 'liveJoomlaFieldDefaults' => 'actual API and console persistence, partial updates and installed text-plugin DOM rendering']) . PHP_EOL;
