# Field-definition category assignment contract

The six contexts fields.content-articles, fields.content-categories, fields.contact, fields.contact-mail, fields.contact-categories and fields.users share Joomla's field model. Their API and local create/update schemas accept data.assigned_cat_ids: a list of at most 1,000 integer IDs between -1 and 2147483647.

Positive IDs select categories; [0] or [] selects All. Joomla ignores zero when positive IDs are present, and [-1] selects None and overrides other selected IDs. An explicitly approved only_use_in_subform: 1 makes Joomla save None regardless of the category list. The native form may remove category selection in a context without a category service or categories; Joomla validation remains authoritative.

Partial updates preserve an omitted assignment. API PATCH restores this separate relation from the authorized item read; the local model update includes it in its existing writable-data merge. Explicit assignments, including [], take precedence. Only create/update binding schemas change; the original migration contracts and administrator-owned schemas remain intact.

Independent verification compares exact normalized membership sets and canonical numeric-string IDs from the database. It recognizes native All/None semantics and uses approved full data for the subform override. It never derives that intent from an observed-only flag or accepts missing, malformed, additional or different categories as matches. The API's omitted only_use_in_subform remains listed as unobservable; a matched category selection does not claim that the API returned the flag.

Reviewed upstream sources are pinned at Joomla commit 071afb7ad305c02983a653ccfc301b5c8360264b:

- [Field form](https://github.com/joomla/joomla-cms/blob/071afb7ad305c02983a653ccfc301b5c8360264b/administrator/components/com_fields/forms/field.xml): multiple category selection and All/None options.
- [FieldModel](https://github.com/joomla/joomla-cms/blob/071afb7ad305c02983a653ccfc301b5c8360264b/administrator/components/com_fields/src/Model/FieldModel.php): separate relation persistence, sentinel normalization, subform-only override, native read-back and dynamic form removal.
- [ApiController](https://github.com/joomla/joomla-cms/blob/071afb7ad305c02983a653ccfc301b5c8360264b/libraries/src/MVC/Controller/ApiController.php): partial PATCH recovers table columns, which excludes the assignment relation.

Run php tools/generate-catalogue.php and php tests/field-category-assignments.php. The latter exercises all six API contexts through real component planning, approval, execution and verification with a recording HTTP double; it exercises local contexts through native actions configured from the shipped seed and recording native models. It also checks legacy seed upgrades with managed, customized-binding and customized-schema graphs. These are recording contracts, not a claim of installed Joomla execution.

The existing installed tests/integration/field-defaults.php suite also creates owned article fields with a real fixture category through API and CLI transports, verifies the database relation and independent read-back, checks that title-only PATCH preserves the relation, and exercises explicit category, All, None and subform-only selections. The existing installed CI runners invoke this suite; its live result is reported by those jobs.
