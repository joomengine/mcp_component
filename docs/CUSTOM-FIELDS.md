# Custom field values through the API

This implements PHP parity for [joomla-mcp issue #38](https://github.com/joomengine/joomla-mcp/issues/38). It applies to `content.articles`, `content.categories`, `contacts.contacts` and `users.users` create/update actions on the authenticated API track. Typed article plan tools use the same implementation. The trusted native console track retains its existing fixed schemas.

The caller needs access to the entity write action and to the corresponding `fields.*.list` read action (`structure.read` for articles/categories/contacts, `users.read` for users). Existing writes containing only reviewed core fields do not perform field discovery. Describe remains available without field-discovery access and reports why custom field metadata is unavailable.

Call `joomla_action_describe` for the intended create/update action. Its `action.customFields` lists published, accessible names, types and context, and its input schema includes those names. Discovery rejects incomplete or unstable pagination and is bounded to 100 pages and 1,000 rows. The existing argument, schema and response byte limits also apply.

Supply fields by name alongside normal form keys:

```json
{
  "action": "content.articles.update",
  "dryRun": true,
  "idempotencyKey": "c495ad89-0e52-4c84-8c54-fd7d86e2bdaf",
  "input": {
    "id": 9,
    "data": {
      "teamleden": "{\"row0\":{\"name\":\"Test member\"}}"
    }
  }
}
```

`teamleden` and its subform contents must match the site's real field definition. Alternatively use `"data":{"com_fields":{"teamleden":"..."}}`; each field may appear in only one place. Unknown names, unpublished or inaccessible fields, core/reserved aliases and null custom values are rejected. Clear values with the empty string or array supported by that Joomla field. Joomla validates field values, category assignment, enabled plugins and field-value editing permissions.

Unicode field slugs and numeric-leading names such as `2026-team` are supported. Purely numeric names such as `0` or `123` are excluded consistently in both implementations because PHP's associative JSON decoding can confuse these object keys with array indexes. Rename those fields to a slug containing a nonnumeric character before using them through MCP.

The public input is normalized to one flat map. Articles, contacts and users receive top-level field names through Joomla's API controller preprocessing. Content categories receive nested `com_fields`, as required by their native API form path. No arbitrary form group or transport override is accepted.

An executable plan still requires the normal explicit grant. The stored encrypted payload binds the exact normalized values and resolved field metadata to the principal, action, definition revision, idempotency key and confirmation token. Apply never refreshes the accepted field names or widens the payload. Native validation remains active if fields change before apply; reconcile any uncertain result before attempting another write.

## Live test request

On a disposable Joomla 6.1 site, please test the branch's component source ZIP with the independently installed routing plugin:

1. Create a published article text field and a subform field in `com_content.article`; note their actual names and configured subfields.
2. Describe `content.articles.update` and verify the expected names/types. Confirm another context, an unpublished field, and a field in an unpublished/inaccessible group do not appear.
3. Dry-run both direct field keys and `com_fields` using real article IDs and the site's actual subform value format. Confirm an invented field name and duplicate direct/alias name are rejected before a write.
4. Approve the appropriate grant, create/apply a fresh plan, then inspect the Joomla administrator form and rendered site. Test both generic and typed article create/update tools, empty-string/array clearing and a multi-value field.
5. Where used, repeat for categories, contacts and users. Category wire requests are intentionally nested. Confirm Joomla's field-value ACL and category-restricted field rules still apply.
6. Report Joomla/component versions, field plugin/type, redacted input and verification outcome on issue #38. Do not include tokens, passwords or personal content. The unit API doubles establish contract behavior; they do not establish the reporter's live field-plugin compatibility.
