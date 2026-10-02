<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\FormContracts;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


require dirname(__DIR__) . '/admin/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never
{
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
	$checks++;
};
$reject = static function (callable $operation, string $identifier) use ($check): void
{
	try
	{
		$operation();
	}
	catch (OperationException $exception)
	{
		$check($exception->getIdentifier() === $identifier, 'Expected diagnostic ' . $identifier);
		return;
	}
	throw new RuntimeException('Expected diagnostic ' . $identifier);
};
$temporary = sys_get_temp_dir() . '/mcp-native-forms-' . bin2hex(random_bytes(8));
$write = static function (string $path, string $content) use ($temporary): void
{
	$path = $temporary . '/' . $path;

	if (!is_dir(dirname($path)))
	{
		mkdir(dirname($path), 0700, true);
	}
	file_put_contents($path, $content);
};
$remove = static function (string $path) use (&$remove): void
{
	if (is_dir($path) && !is_link($path))
	{
		foreach (new DirectoryIterator($path) as $entry)
		{
			if (!$entry->isDot())
			{
				$remove($entry->getPathname());
			}
		}
		rmdir($path);
	}
	elseif (file_exists($path) || is_link($path))
	{
		unlink($path);
	}
};

try
{
	$write('api/src/Controller/RecordsController.php', <<<'PHP'
<?php
class RecordsController extends ApiController
{
	public function getModel($name = '', $prefix = '', $config = [])
	{
		if ($name !== '')
		{
			$name = 'records';
		}
		else
		{
			$name = 'record';
		}
		return parent::getModel($name, $prefix, $config);
	}
}
PHP);
	$write('admin/src/Model/RecordModel.php', <<<'PHP'
<?php
class RecordModel extends AdminModel
{
	public function getForm($data = [], $loadData = true)
	{
		$form = $this->loadForm('com_fixture.record', 'record', ['load_data' => $loadData]);
		$form->setValue('guid', null, GuidHelper::get());
		return $form;
	}
	public function getItem($pk = null)
	{
		$item = parent::getItem($pk);
		$rows = new Registry;
		$rows->loadString($item->rows);
		$item->rows = $rows->toArray();
		return $item;
	}
	public function validate($form, $data, $group = null)
	{
		foreach (explode(',', $data['not_required']) as $requiredField)
		{
			$form->setFieldAttribute($requiredField, 'required', 'false');
			unset($data[$requiredField]);
		}
		return parent::validate($form, $data, $group);
	}
}
PHP);
	$write('admin/src/Field/ReferenceField.php', <<<'PHP'
<?php
class ReferenceField extends ListField
{
	protected function getOptions()
	{
		$options = [];
		foreach ($this->items as $item)
		{
			$options[] = Html::_('select.option', $item->guid, $item->name);
		}
		return $options;
	}
}
PHP);
	$write('admin/src/Rule/GuidRule.php', '<?php class GuidRule extends FormRule {}');
	$write('admin/src/Field/ModalSelectField.php', '<?php class ModalSelectField extends ModalSelectFieldCore {}');
	$form = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<form>
	<fieldset name="details">
		<field name="title" type="text" required="true" filter="STRING" />
		<field name="active" type="radio" filter="INT" default="0"><option value="0">No</option><option value="1">Yes</option></field>
		<field name="reference" type="reference" required="true" />
		<field name="modal_reference" type="ModalSelect" sql_title_table="#__fixture_record" sql_title_column="title" sql_title_key="guid" data-key-name="guid" />
		<field name="guid" type="text" validate="guid" readonly="true" />
		<field name="not_required" type="hidden" default="一_一" />
		<field name="ignored" type="text" filter="unset" />
		<field name="source" type="editor" filter="raw" validate="code" />
		<field name="conditional" type="text" required="true" showon="active:1" />
		<field name="note" type="note" />
		<field name="rows" type="subform" multiple="true" default="">
			<form repeat="true">
				<field name="sequence" type="integer" default="0" />
				<field name="value" type="text" filter="STRING" />
				<field name="details" type="subform" formsource="nested.xml" />
			</form>
		</field>
	</fieldset>
	<fields name="settings"><field name="enabled" type="radio" filter="BOOL" default="0" /></fields>
</form>
XML;
	$write('admin/forms/record.xml', $form);
	$write('admin/forms/nested.xml', '<form><field name="code" type="editor" filter="raw" /><field name="defaulted" type="text" default="native default" /></form>');
	$contracts = new FormContracts($temporary . '/admin', $temporary . '/api');
	$route = ['controller' => 'records.add', 'method' => 'POST'];
	$contract = $contracts->forRoute($route);
	$check($contract !== null && $contract['provenance']['model'] === 'record', 'Literal native model mapping resolves without singular-name guessing.');
	$check($contract['fields']['reference']['relationship']['option_value_properties'] === ['guid'], 'Relationship GUID values come from the installed field source.');
	$check($contract['fields']['modal_reference']['native_type'] === 'ModalSelect'
		&& $contract['fields']['modal_reference']['relationship']['option_value_properties'] === ['guid']
		&& $contract['fields']['modal_reference']['field_source']['path'] === 'administrator/src/Field/ModalSelectField.php',
		'Native modal relationship keys and case-sensitive custom field source are preserved from XML.');
	$check($contract['fields']['guid']['identity']['server_generated'] && $contract['generation'] === [], 'Observed native GUID generation remains native.');
	$check($contract['native_required_adjustments'] && !isset($contract['schema']['required']), 'Native required adjustments prevent over-requiring a create body.');
	$check($contract['fields']['conditional']['showon'] === 'active:1' && $contract['fields']['conditional']['required'], 'Conditional declarations remain visible without invented enforcement.');
	$check($contract['fields']['active']['default'] === '0' && count($contract['fields']['active']['choices']) === 2, 'Literal defaults and choices retain their XML values.');
	$check($contract['fields']['source']['inert_source'] && $contract['schema']['properties']['source']['type'] === ['string', 'null'], 'Source code remains inert, unrestricted text.');
	$check(!isset($contract['fields']['note']), 'Presentation-only notes do not become write fields.');
	$check(in_array('not_required', $contract['verification']['request_only'], true) && in_array('ignored', $contract['verification']['request_only'], true), 'Only observed native controls and unset filters are request-only.');
	$check($contract['verification']['fields']['rows']['representation'] === 'json', 'Native Registry decoding supplies the structured read-back representation.');
	$check($contract['verification']['fields']['rows']['additionalProperties']['properties']['sequence']['representation'] === 'integer', 'Named repeatable rows retain native integer child contracts.');
	$check(isset($contract['schema']['properties']['rows']['anyOf'][0]['items']['properties']['details']['properties']['code']), 'Referenced and inline nested subforms expose their data structure.');
	$check($contract['verification']['fields']['settings']['properties']['enabled']['representation'] === 'boolean', 'Named native groups retain nested field contracts.');
	$check($contracts->forRoute($route)['fingerprint'] === $contract['fingerprint'], 'Contract fingerprints are deterministic.');
	(new SchemaValidator())->document(Json::encode($contract['schema']));
	$check(true, 'Generated data schema is a valid JSON Schema document.');
	$patchContract = $contracts->forRoute(['controller' => 'records.edit', 'method' => 'PATCH']);
	$patchSchema = ['type' => 'object', 'properties' => ['data' => $patchContract['schema']], 'required' => ['data'], 'additionalProperties' => false];
	$patch = (new SchemaValidator())->input(['data' => ['title' => 'Changed title']], Json::encode($patchSchema));
	$check($patch['data'] === ['title' => 'Changed title'], 'Actual validation keeps omitted XML defaults and hidden controls absent from PATCH input.');
	$partialRows = ['data' => (object) ['rows' => [(object) ['value' => 'Retained value',
		'details' => (object) ['code' => '<?php echo "inert source";']]], 'settings' => (object) []]];
	$nestedPatch = (new SchemaValidator())->input($partialRows, Json::encode($patchSchema), true);
	$check(Json::canonical($nestedPatch) === Json::canonical($partialRows), 'Actual validation preserves nested partial rows and empty named groups without inserting XML child defaults.');

	// Comment and string contents can never manufacture native form mappings.
	$write('api/src/Controller/UnboundController.php', <<<'PHP'
<?php
class UnboundController extends ApiController
{
	// public function getModel() { $name = 'record'; }
	public function getModel($name = '', $prefix = '', $config = [])
	{
		$inert = '$name = "record";';
		return parent::getModel($name, $prefix, $config);
	}
}
PHP);
	$check($contracts->forRoute(['controller' => 'unbound.add', 'method' => 'POST']) === null, 'Embedded source and comments never bind a form.');
	$write('api/src/Controller/Site_recordController.php', <<<'PHP'
<?php
class Site_recordController extends ApiController
{
	public function getModel($name = '', $prefix = '', $config = [])
	{
		$name = 'record';
		return parent::getModel($name, 'Site', $config);
	}
}
PHP);
	$check($contracts->forRoute(['controller' => 'site_record.add', 'method' => 'POST']) === null, 'A Site model cannot accidentally inherit the matching administrator form.');
	$check($contracts->forRoute(['controller' => '../records.add', 'method' => 'POST']) === null, 'Controller names cannot select an arbitrary filesystem path.');
	$write('admin/forms/record.xml', '<!DOCTYPE form [<!ENTITY secret SYSTEM "file:///etc/passwd">]><form><field name="title" type="text" default="&secret;" /></form>');
	$reject(static fn () => $contracts->forRoute($route), 'JCB_FORM_CONTRACT_INVALID');
	$write('admin/forms/record.xml', '<form><field name="rows" type="subform" formsource="../../outside.xml" /></form>');
	$reject(static fn () => $contracts->forRoute($route), 'JCB_FORM_CONTRACT_INVALID');
	$write('admin/forms/record.xml', '<form><field name="rows" type="subform" formsource="record.xml" /></form>');
	$reject(static fn () => $contracts->forRoute($route), 'JCB_FORM_CONTRACT_INVALID');
	$write('admin/forms/record.xml', '<form><field name="rows" type="subform" formsource="outside.xml" /></form>');
	$write('outside.xml', '<form><field name="private" type="text" /></form>');
	symlink($temporary . '/outside.xml', $temporary . '/admin/forms/outside.xml');
	$reject(static fn () => $contracts->forRoute($route), 'JCB_FORM_CONTRACT_INVALID');

	// A static form gets create requirements; PATCH remains partial by contract.
	$write('admin/src/Model/RecordModel.php', <<<'PHP'
<?php
class RecordModel extends AdminModel
{
	public function getForm($data = [], $loadData = true)
	{
		return $this->loadForm('com_fixture.record', 'record', []);
	}
}
PHP);
	$write('admin/forms/record.xml', '<form><field name="title" type="text" required="true" /><field name="guid" type="text" validate="guid" required="true" /><field name="relation_guid" type="text" validate="guid" required="true" /><field name="conditional" type="text" required="true" showon="active:1" /></form>');
	$static = $contracts->forRoute($route);
	$check($static['schema']['required'] === ['title', 'guid', 'relation_guid'], 'Only static unconditional requirements enter the create schema.');
	$check($static['generation']['guid'] === ['kind' => 'guid', 'on' => 'create', 'format' => 'uuid-v4'], 'A required client GUID without a native default has an explicit generation contract.');
	$check(!isset($static['generation']['relation_guid']) && !isset($static['fields']['relation_guid']['identity']), 'Required relationship GUIDs must be supplied from existing records and are never generated.');
	$check(!isset($contracts->forRoute(['controller' => 'records.edit', 'method' => 'PATCH'])['schema']['required']), 'Partial update data does not require every create field.');

	// An optional argument inspects every controller of a real compiled package.
	$installedRoot = $argv[1] ?? null;

	if ($installedRoot !== null)
	{
		$installed = new FormContracts($installedRoot . '/admin', $installedRoot . '/api');
		$observed = 0;

		foreach (glob($installedRoot . '/api/src/Controller/*Controller.php') as $path)
		{
			$name = substr(basename($path), 0, -strlen('Controller.php'));
			$native = $installed->forRoute(['controller' => lcfirst($name) . '.add', 'method' => 'POST']);

			if ($native !== null)
			{
				(new SchemaValidator())->document(Json::encode($native['schema']));
				$observed++;
			}
		}

		$check($observed > 0, 'The supplied installed package exposes source-backed native form contracts.');
		echo 'Observed installed form contracts: ' . $observed . PHP_EOL;
	}

	echo 'PASS ' . $checks . ' installed native form contract checks.' . PHP_EOL;
}
finally
{
	$remove($temporary);
}
