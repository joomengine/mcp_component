# Native language translation source: remaining creation requirement

Issue [#46](https://github.com/joomengine/mcp_component/issues/46) is partially resolved. Merged component PR [#47](https://github.com/joomengine/mcp_component/pull/47) repairs updates of existing translations. A new source still cannot pass the installed legacy JCB API form. Keep the issue open until native creation is implemented and an installed create/read/update/delete scenario passes.

## Source inspection, 9 November 2026

The inspected upstream head is `extension-builder/joomla` commit `24c4671dfb03f8e75c64e8b3888f68b39df3f273`:

- [Language translation XML](https://github.com/extension-builder/joomla/blob/24c4671dfb03f8e75c64e8b3888f68b39df3f273/admin/forms/language_translation.xml), blob `8171446020fef39408eb7b95aa4113eb0a3c8016`, declares `source` as `readonly="true"` and `disabled="true"` with the existing `safehtml` filter.
- [Language translation model](https://github.com/extension-builder/joomla/blob/24c4671dfb03f8e75c64e8b3888f68b39df3f273/admin/src/Model/Language_translationModel.php), blob `fb61abcd628f288ffa4b88d964c17f178c7b2af9`, does not enable that field for API creation.
- Upstream [PR #55](https://github.com/extension-builder/joomla/pull/55) identifies source-field definition GUID `c2f5d193-ef76-422b-aae5-421cd0a4b22b` and records the same native disabled-field validation mechanism.

Joomla's native form rejects a present value for a disabled field. Omitting or nulling the source is a valid preservation control for an existing record, but cannot create the requested new source. Bypassing the form or creating an empty record would not fix this contract.

## Proposed native policy

Apply the policy through JCB's authoritative field/admin-view customization definitions and regenerate the component. Generated administrator models and API controllers are outputs, not the maintenance source. No generic compiler rule should make every readonly or disabled field writable. This component change does not edit or publish upstream JCB code.

The following 17-line addition is the reviewed target output inside `Language_translationModel::getForm()`, after record identity resolution and immediately after `$user = Factory::getApplication()->getIdentity();`:

```diff
 		$user = Factory::getApplication()->getIdentity();
 
+		// Source text is supplied once when the API creates a translation.
+		// Existing sources remain immutable and must not be backfilled into
+		// Joomla's validation of a disabled administrator-only form control.
+		if ($app->isClient('api'))
+		{
+			if ($id === 0 && $jinput->getMethod() === 'POST')
+			{
+				$form->setFieldAttribute('source', 'disabled', 'false');
+				$form->setFieldAttribute('source', 'readonly', 'false');
+				$form->setFieldAttribute('source', 'required', 'true');
+			}
+			else
+			{
+				$form->removeField('source');
+			}
+		}
+
 		// Check for existing item.
```

This enables a nonempty source only for a new API POST. Existing API updates omit the immutable source from validation and binding, including Joomla's backfilled value. Administrator and site forms retain their disabled/readonly controls. Native controller ACL, model saving, filtering and all unrelated field permissions remain in place. The existing MCP update adapter remains compatible: its explicit `source: null` is omitted by the adjusted native form and the original source is independently verified.

## Verification and limits

An isolated harness executed the current native `getForm()` method and Joomla 6.2.0 `Form::process()` under PHP 8.3.6. It substituted only application/identity and form-loading boundaries, loaded the exact upstream source-field XML, and prohibited database connections. The original upstream model failed the desired creation case with `JLIB_FORM_VALIDATE_FIELD_INVALID`. The proposed output passed 12 checks:

| Behavior | Result |
| --- | --- |
| New API POST preserves the requested source and translation | Passed |
| Source is writable only for creation | Passed |
| Absent and empty creation sources are rejected | Both passed |
| PATCH with original, changed, null or omitted source excludes source from validated binding while retaining translation changes | All four passed |
| Existing record data wins over a zero request ID | Passed |
| Administrator and site controls remain disabled and readonly | Both passed |
| Existing omitted-PATCH creation-metadata protection remains active | Passed |

This is native form/model evidence, not a live API write or database persistence result. The upstream change has not been applied, regenerated, installed or released by this work. A protected generated-model change also needs the upstream GUI change record and source-reconciliation evidence.

The MCP source-inspection regression separately proves that assignments of `"true"`, `"1"` or an unknown expression do not advertise source creation. Its new assertions fail on the previous `FormContracts` implementation. Updated local suites pass 36 form-contract checks and 150 generated-API verification checks; the latter uses recording transport/state doubles.

After the upstream source change, regenerate and install JCB, refresh Operations or run `joomla:mcp:jcb-sync`, and run authenticated native and MCP create/read/update/delete acceptance with a disposable source. Assert exact source/translation persistence, required-source rejection without a row, immutable source on update, ordinary-user ACL denial/allowance, lease release, idempotent replay, and cleanup. Keep the unmodified legacy-form test to verify that older installations fail during preflight without acquiring an execution or write lease. Only then is the remaining creation part of #46 resolved.
