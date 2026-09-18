<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;

require_once dirname(__DIR__) . '/admin/src/Database/Structure.php';

// This development generator emits real MVC adapters and native XML forms.
// Installation/runtime never run this generator or infer PHP from database rows.
$root = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true);
$header = <<<'HEADER'
<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
HEADER;
$written = [];
$emit = static function (string $path, string $content) use ($root, $checkOnly, &$written): void
{
	$content = rtrim($content) . "\n";
	$file = $root . '/' . $path;

	if ($checkOnly)
	{
		if (!is_file($file) || file_get_contents($file) !== $content)
		{
			throw new RuntimeException('Generated administrator source differs: ' . $path);
		}
	}
	else
	{
		if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true))
		{
			throw new RuntimeException('Cannot create source directory: ' . dirname($path));
		}

		if (file_put_contents($file, $content) === false)
		{
			throw new RuntimeException('Cannot write source: ' . $path);
		}
	}

	$written[] = $path;
};
$language = [
	'COM_JOOMENGINE_MCP' => 'JoomEngine MCP',
	'COM_JOOMENGINE_MCP_XML_DESCRIPTION' => 'Database-driven PHP MCP endpoint and administration for Joomla.',
	'COM_JOOMENGINE_MCP_CONFIGURATION' => 'JoomEngine MCP: Options',
	'COM_JOOMENGINE_MCP_EDIT_CONFLICT' => 'This definition changed or is checked out by another user. Reload it before saving.',
	'COM_JOOMENGINE_MCP_INVALID_STATE' => 'The requested publication state is invalid.',
	'COM_JOOMENGINE_MCP_DEFINITION' => 'Definition',
	'COM_JOOMENGINE_MCP_DETAILS' => 'Operation details',
	'COM_JOOMENGINE_MCP_RELATIONS' => 'Relationships',
	'COM_JOOMENGINE_MCP_JSON_HELP' => 'Enter a JSON object. Definitions are data; only registered PHP handlers can execute.',
	'COM_JOOMENGINE_MCP_IDENTIFIER_HELP' => 'Stable unique identifier. Renaming a shipped definition changes its public protocol identity.',
	'COM_JOOMENGINE_MCP_CUSTOMIZED' => 'Customized',
	'COM_JOOMENGINE_MCP_SHIPPED' => 'Shipped definition',
	'COM_JOOMENGINE_MCP_CHOOSE_REFERENCE' => '- Select a definition -',
	'COM_JOOMENGINE_MCP_OPTIONS_RUNTIME' => 'Endpoint and limits',
	'COM_JOOMENGINE_MCP_OPTIONS_ACCESS' => 'Permissions',
	'COM_JOOMENGINE_MCP_API_BASE' => 'Canonical Joomla API URL',
	'COM_JOOMENGINE_MCP_API_BASE_DESC' => 'The trusted HTTPS address of this installation ending in /api/index.php. Tokens are only forwarded to this configured origin.',
	'COM_JOOMENGINE_MCP_SITE_ALIAS' => 'Site alias',
	'COM_JOOMENGINE_MCP_ALLOWED_ORIGINS' => 'Allowed browser origins',
	'COM_JOOMENGINE_MCP_ALLOWED_ORIGINS_DESC' => 'One explicit origin per line. No wildcards, paths or credentials. Non-browser clients may omit Origin.',
	'COM_JOOMENGINE_MCP_ALLOW_INDEFINITE' => 'Allow indefinite grants',
	'COM_JOOMENGINE_MCP_TIMEOUT' => 'HTTP timeout (seconds)',
	'COM_JOOMENGINE_MCP_MAX_REQUEST_BYTES' => 'Request size limit (bytes)',
	'COM_JOOMENGINE_MCP_MAX_RESULT_BYTES' => 'Response size limit (bytes)',
	'COM_JOOMENGINE_MCP_MAX_LIST_LIMIT' => 'Discovery page limit',
	'COM_JOOMENGINE_MCP_PLAN_TTL' => 'Confirmation lifetime (seconds)',
	'COM_JOOMENGINE_MCP_REQUEST_TTL' => 'Permission request lifetime (seconds)',
	'COM_JOOMENGINE_MCP_SESSION_TTL' => 'Protocol session lifetime (seconds)',
	'COM_JOOMENGINE_MCP_AUDIT' => 'Audit log',
	'COM_JOOMENGINE_MCP_EXECUTIONS' => 'Executions',
	'COM_JOOMENGINE_MCP_GRANTS' => 'Permission grants',
	'COM_JOOMENGINE_MCP_OPERATIONS' => 'Operations',
	'COM_JOOMENGINE_MCP_REVOKE' => 'Revoke grant',
	'COM_JOOMENGINE_MCP_RECONCILE' => 'Reconcile execution',
	'COM_JOOMENGINE_MCP_RECONCILE_WARNING' => 'Inspect the actual Joomla or external changes first. Reconciliation records your assessment and releases the retained write lock; it never rolls back or replays an operation.',
	'COM_JOOMENGINE_MCP_RECONCILE_NOTE' => 'Inspection evidence and outcome',
	'COM_JOOMENGINE_MCP_RECONCILE_ACK' => 'I inspected the persisted effects and authorize releasing this execution lock.',
	'COM_JOOMENGINE_MCP_RECONCILED' => 'The execution assessment was recorded without replaying the operation.',
	'COM_JOOMENGINE_MCP_REVOKED' => 'The grant was revoked.',
	'COM_JOOMENGINE_MCP_MCP_ACCESS' => 'Access the MCP endpoint',
	'COM_JOOMENGINE_MCP_MCP_APPROVE' => 'Approve requested MCP write grants',
	'COM_JOOMENGINE_MCP_MCP_EXECUTE' => 'Discover and execute definitions',
	'COM_JOOMENGINE_MCP_MCP_WRITE' => 'Request confirmed mutations',
	'COM_JOOMENGINE_MCP_MCP_AUDIT' => 'Inspect execution and audit metadata',
	'COM_JOOMENGINE_MCP_MCP_GRANTS' => 'Revoke grants',
	'COM_JOOMENGINE_MCP_MCP_RECONCILE' => 'Reconcile uncertain executions',
];
$commonFields = [
	'id' => 'ID', 'name' => 'Identifier', 'title' => 'Title', 'published' => 'Status', 'access' => 'Viewing access level',
	'ordering' => 'Ordering', 'version' => 'Revision', 'params' => 'Parameters', 'extension' => 'Required extension',
	'description' => 'Description', 'definition' => 'Capability metadata', 'document' => 'JSON Schema',
	'provider_id' => 'Provider', 'input_schema_id' => 'Input schema', 'output_schema_id' => 'Output schema',
	'domain' => 'Domain', 'toolset' => 'Permission scope', 'effect' => 'Effect', 'risk' => 'Risk',
	'action_id' => 'Action', 'track' => 'Execution track', 'handler' => 'Registered handler',
	'configuration' => 'Handler configuration', 'uri' => 'Resource URI', 'mime_type' => 'Media type',
	'is_template' => 'URI template', 'command' => 'Registered command', 'status' => 'Availability status',
];
foreach ($commonFields as $field => $label)
{
	$language['COM_JOOMENGINE_MCP_FIELD_' . strtoupper($field)] = $label;
}
$xml = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
foreach (Structure::definitions() as $entity => $fields)
{
	$singular = ucfirst($entity);
	$plural = $entity === 'schema' ? 'schemas' : $entity . 's';
	$pluralClass = ucfirst($plural);
	$language['COM_JOOMENGINE_MCP_' . strtoupper($plural)] = $pluralClass;
	$language['COM_JOOMENGINE_MCP_' . strtoupper($entity) . '_EDIT'] = 'Edit ' . $entity;
	$language['COM_JOOMENGINE_MCP_' . strtoupper($entity) . '_NEW'] = 'New ' . $entity;
	foreach (['N_ITEMS_PUBLISHED' => 'published', 'N_ITEMS_UNPUBLISHED' => 'unpublished', 'N_ITEMS_ARCHIVED' => 'archived', 'N_ITEMS_TRASHED' => 'trashed', 'N_ITEMS_DELETED' => 'deleted', 'N_ITEMS_CHECKED_IN' => 'checked in'] as $key => $verb)
	{
		$language['COM_JOOMENGINE_MCP_' . strtoupper($entity) . '_' . $key] = '%d definitions ' . $verb . '.';
		$language['COM_JOOMENGINE_MCP_' . strtoupper($entity) . '_' . $key . '_1'] = 'Definition ' . $verb . '.';
	}
	$specs = [
		["Table/{$singular}Table.php", 'Table', $singular . 'Table', 'DefinitionTable', ''],
		["Model/{$singular}Model.php", 'Model', $singular . 'Model', 'DefinitionModel', "\n\t/** @var string Native Joomla message prefix. @since 0.1.0 */\n\tprotected \$text_prefix = 'COM_JOOMENGINE_MCP_" . strtoupper($entity) . "';"],
		["Model/{$pluralClass}Model.php", 'Model', $pluralClass . 'Model', 'DefinitionsModel', ''],
		["Controller/{$singular}Controller.php", 'Controller', $singular . 'Controller', 'DefinitionController', "\n\t/** @var string Native list view. @since 0.1.0 */\n\tprotected \$view_list = '$plural';"],
		["Controller/{$pluralClass}Controller.php", 'Controller', $pluralClass . 'Controller', 'DefinitionsController', ''],
		["View/$singular/HtmlView.php", "View\\$singular", 'HtmlView', '\\VDM\\Component\\JoomEngineMcp\\Administrator\\View\\DefinitionHtmlView', ''],
		["View/$pluralClass/HtmlView.php", "View\\$pluralClass", 'HtmlView', '\\VDM\\Component\\JoomEngineMcp\\Administrator\\View\\DefinitionsHtmlView', ''],
	];
	foreach ($specs as [$path, $namespace, $class, $base, $extra])
	{
		$emit('admin/src/' . $path, $header . "\nnamespace VDM\\Component\\JoomEngineMcp\\Administrator\\$namespace;\n\n\n/**\n * Native $entity adapter bound to the fixed catalogue schema.\n *\n * @since  0.1.0\n */\nfinal class $class extends $base\n{\n\t/** @var string Fixed definition identity. @since 0.1.0 */\n\tprotected const ENTITY = '$entity';\n$extra\n}\n");
	}
	$emit("admin/tmpl/$entity/edit.php", $header . "\n\\defined('_JEXEC') or die;\n\nrequire JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/layouts/definition-edit.php';\n");
	$emit("admin/tmpl/$plural/default.php", $header . "\n\\defined('_JEXEC') or die;\n\nrequire JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/layouts/definitions-list.php';\n");
	$form = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<form addfieldprefix="VDM\Component\JoomEngineMcp\Administrator\Field" addruleprefix="VDM\Component\JoomEngineMcp\Administrator\Rule">' . "\n";
	$form .= "  <fieldset name=\"definition\" label=\"COM_JOOMENGINE_MCP_DEFINITION\">\n";
	$form .= '    <field name="id" type="hidden" filter="uint" default="0" />' . "\n";
	$form .= '    <field name="asset_id" type="hidden" filter="unset" />' . "\n";
	$form .= '    <field name="version" type="hidden" filter="uint" default="1" />' . "\n";
	$form .= '    <field name="name" type="text" label="COM_JOOMENGINE_MCP_FIELD_NAME" description="COM_JOOMENGINE_MCP_IDENTIFIER_HELP" required="true" maxlength="191" filter="string" />' . "\n";
	$form .= '    <field name="title" type="text" label="COM_JOOMENGINE_MCP_FIELD_TITLE" required="true" maxlength="191" filter="string" />' . "\n";
	$form .= '    <field name="published" type="list" label="JSTATUS" default="0" filter="integer"><option value="1">JPUBLISHED</option><option value="0">JUNPUBLISHED</option><option value="2">JARCHIVED</option><option value="-2">JTRASHED</option></field>' . "\n";
	$form .= '    <field name="access" type="accesslevel" label="JFIELD_ACCESS_LABEL" default="1" filter="uint" />' . "\n";
	$form .= '    <field name="ordering" type="number" label="JFIELD_ORDERING_LABEL" default="0" filter="integer" />' . "\n";
	$form .= "  </fieldset>\n  <fieldset name=\"details\" label=\"COM_JOOMENGINE_MCP_DETAILS\">\n";
	foreach ($fields as $name => $type)
	{
		$label = 'COM_JOOMENGINE_MCP_FIELD_' . strtoupper($name);
		$attributes = "name=\"$name\" label=\"$label\"";
		if (str_starts_with($type, 'ref:') || str_starts_with($type, 'optional:'))
		{
			$target = explode(':', $type, 2)[1];
			$required = str_starts_with($type, 'ref:') ? 'true' : 'false';
			$form .= "    <field $attributes type=\"definitionreference\" entity=\"$target\" filter=\"uint\" required=\"$required\" />\n";
		}
		elseif ($type === 'json')
		{
			$form .= "    <field $attributes type=\"textarea\" rows=\"12\" cols=\"80\" class=\"font-monospace\" filter=\"raw\" validate=\"jsonobject\" default=\"{}\" description=\"COM_JOOMENGINE_MCP_JSON_HELP\" />\n";
		}
		elseif (in_array($name, ['effect', 'track', 'is_template', 'risk'], true))
		{
			$options = match ($name) { 'effect' => ['read', 'write'], 'track' => ['api', 'cli'], 'risk' => ['read', 'write', 'high'], default => ['0', '1'] };
			$form .= "    <field $attributes type=\"list\" filter=\"string\">";
			foreach ($options as $option)
			{
				$form .= '<option value="' . $xml($option) . '">' . $xml($option) . '</option>';
			}
			$form .= "</field>\n";
		}
		elseif ($type === 'text')
		{
			$form .= "    <field $attributes type=\"textarea\" rows=\"5\" filter=\"string\" />\n";
		}
		else
		{
			$max = $type === 'uri' ? '2048' : '191';
			$form .= "    <field $attributes type=\"text\" maxlength=\"$max\" filter=\"string\" />\n";
		}
	}
	$form .= '    <field name="params" type="textarea" label="COM_JOOMENGINE_MCP_FIELD_PARAMS" rows="8" class="font-monospace" filter="raw" validate="jsonobject" default="{}" />' . "\n";
	$form .= "  </fieldset>\n  <fieldset name=\"permissions\" label=\"JCONFIG_PERMISSIONS_LABEL\">\n";
	$form .= "    <field name=\"rules\" type=\"rules\" label=\"JCONFIG_PERMISSIONS_LABEL\" filter=\"rules\" validate=\"rules\" component=\"com_joomengine_mcp\" section=\"$entity\" />\n";
	$form .= "  </fieldset>\n</form>\n";
	$emit("admin/forms/$entity.xml", $form);
	$filter = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<form addfieldprefix="VDM\Component\JoomEngineMcp\Administrator\Field"><fields name="filter">' . "\n";
	$filter .= '  <field name="search" type="text" label="JSEARCH_FILTER" hint="JSEARCH_FILTER" filter="string" />' . "\n";
	$filter .= '  <field name="published" type="status" label="JOPTION_SELECT_PUBLISHED" onchange="this.form.submit();"><option value="">JOPTION_SELECT_PUBLISHED</option></field>' . "\n";
	$filter .= '  <field name="access" type="accesslevel" label="JFIELD_ACCESS_LABEL" onchange="this.form.submit();"><option value="">JOPTION_SELECT_ACCESS</option></field>' . "\n";
	if ($entity !== 'provider')
	{
		$filter .= '  <field name="provider_id" type="definitionreference" entity="provider" label="COM_JOOMENGINE_MCP_FIELD_PROVIDER_ID" onchange="this.form.submit();" />' . "\n";
	}
	$filter .= '</fields><fields name="list"><field name="fullordering" type="list" default="a.name ASC" onchange="this.form.submit();" label="JGLOBAL_SORT_BY">';
	foreach (['name' => 'COM_JOOMENGINE_MCP_FIELD_NAME', 'title' => 'JGLOBAL_TITLE', 'id' => 'JGRID_HEADING_ID', 'ordering' => 'JFIELD_ORDERING_LABEL', 'published' => 'JSTATUS'] as $field => $label)
	{
		$filter .= "<option value=\"a.$field ASC\">$label</option><option value=\"a.$field DESC\">$label (descending)</option>";
	}
	$filter .= '</field><field name="limit" type="limitbox" label="JGLOBAL_LIST_LIMIT" default="20" onchange="this.form.submit();" /></fields></form>';
	$emit("admin/forms/filter_$plural.xml", $filter);
}
$accessActions = ['core.create' => 'JACTION_CREATE', 'core.edit' => 'JACTION_EDIT', 'core.edit.state' => 'JACTION_EDITSTATE', 'core.delete' => 'JACTION_DELETE', 'mcp.execute' => 'COM_JOOMENGINE_MCP_MCP_EXECUTE', 'mcp.write' => 'COM_JOOMENGINE_MCP_MCP_WRITE'];
$access = '<?xml version="1.0" encoding="utf-8"?><access component="com_joomengine_mcp">' . "\n  <section name=\"component\">\n";
foreach (['core.admin' => 'JACTION_ADMIN', 'core.options' => 'JACTION_OPTIONS', 'core.manage' => 'JACTION_MANAGE', 'mcp.access' => 'COM_JOOMENGINE_MCP_MCP_ACCESS', 'mcp.approve' => 'COM_JOOMENGINE_MCP_MCP_APPROVE', 'mcp.audit' => 'COM_JOOMENGINE_MCP_MCP_AUDIT', 'mcp.grants' => 'COM_JOOMENGINE_MCP_MCP_GRANTS', 'mcp.reconcile' => 'COM_JOOMENGINE_MCP_MCP_RECONCILE'] + $accessActions as $action => $label)
{
	$access .= "    <action name=\"$action\" title=\"$label\" />\n";
}
$access .= "  </section>\n";
foreach (array_keys(Structure::definitions()) as $entity)
{
	$access .= "  <section name=\"$entity\">\n";
	foreach ($accessActions as $action => $label)
	{
		$access .= "    <action name=\"$action\" title=\"$label\" />\n";
	}
	$access .= "  </section>\n";
}
$emit('admin/access.xml', $access . '</access>');
ksort($language);
$ini = "; JoomEngine MCP — GPL-3.0-or-later\n";
foreach ($language as $key => $text)
{
	$ini .= $key . '="' . str_replace('"', '\\"', $text) . '"' . "\n";
}
$emit('admin/language/en-GB/com_joomengine_mcp.ini', $ini);
$emit('admin/language/en-GB/com_joomengine_mcp.sys.ini', $ini);
echo count($written) . ' generated Joomla administrator files ' . ($checkOnly ? 'verified' : 'written') . ".\n";
