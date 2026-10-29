# Native field representation verification

The October 2026 installed audit reproduced successful native writes that MCP
incorrectly left uncertain because the independent API read-back represented a
field differently. These contracts compare the approved field meaning while
preserving exact JSON comparison elsewhere.

## Empty native Registry fields

The reviewed fields are category `params` under content, banners, contacts and
newsfeeds, and article `images`, `urls` and `metadata`. An approved empty object
may match an empty array or empty object returned by those native APIs. Null,
scalars, nonempty values and nested shape changes are not equivalent. Both write
and independent read must retain their reviewed API action, route, method,
handler and authentication contract.

Article `attribs` is not exposed by the native item response. It remains listed
as unobservable rather than becoming verified through a database fallback.
Category metadata and unrelated configuration fields retain their existing
comparison behavior.

## Custom choice values

Joomla list and checkbox plugins expose selected values as map keys and option
names as labels. For example, selecting `alpha` returns `{"alpha":"Alpha"}`.
The authorized field catalogue supplies the field identity, context, type and
explicit choices. Planning freezes that metadata with the approved values.

The comparator applies only to the reviewed native article, content-category,
contact and user API bindings and their independent item reads. It requires the
exact selected keys and approved labels. Missing, additional, duplicated,
unknown or malformed selections cannot become a successful match. Native
numeric keys may produce a JSON label list; that is accepted only when its exact
keys and labels match the approved choices. Native omission of a selected value
still leaves the execution uncertain.

Missing, inherited or malformed option metadata does not disable a callable
operation. Such fields retain strict comparison. Customized bindings and field
types outside this contract also retain strict comparison. Applying a plan does
not rediscover options or acquire another permission. A changed read-back label
or selection remains visible as a mismatch against the approved metadata.

## Subform JSON values

The reviewed native article, content-category, contact and user API contexts also
support published fields of type `subform`. A normally formatted JSON string
such as `{"row0": {"url": "https://example.org/a"}}` matches Joomla's compact
`{"row0":{"url":"https:\/\/example.org\/a"}}` response. Only the outer JSON
serialization is decoded; a text subfield containing JSON remains ordinary text.
Object member ordering is ignored. Lists retain their exact order and length;
objects remain distinct from lists; strings, booleans and null remain distinct
from numbers. Native numeric `1` and `1.0` compare as the same decoded number.

The approved field snapshot and independent read contract scope this behavior.
No field rediscovery occurs during apply. Malformed JSON, oversized values,
excessive nesting, non-finite numbers and numbers outside the safe integer range
cannot establish a match. Raw numeric tokens must also retain their decimal
value through native JSON decoding and encoding; precision loss and underflow
are refused, while `1.0`/`1` and `1e3`/`1000` remain equivalent. Quoted numeric
text is never parsed as a number. An explicit empty string still matches the same native
empty-string clear operation. A changed value retains its uncertain outcome and
write lease. Successful matches release the lease; replay returns retained
evidence without sending another mutation.

Joomla stores a cleared media subfield as an empty array. When field discovery
finds a published, accessible `media` field referenced by the subform's own
options, an approved empty string for that `field<ID>` row key matches a stored
`[]` or `{}`. This applies to a single row object or to its named rows, never to
deeper values. Text subfields, unknown or hidden subfields, nonempty media
values and an approved `[]` read back as `""` keep their differences.

Installing this fix does not rewrite existing uncertain executions. Inspect and
reconcile those records through the administrator Operations view. A retained
token does not automatically rerun its mutation or clear an unresolved lease.

The TypeScript server keeps its existing raw API read response and adds scoped
`customFieldVerification` evidence for approved subform values. Its configured
live-test runner uses the same scoped semantic comparison. This evidence covers
subform fields only; the TypeScript server does not implement the PHP component's
durable `WRITE_BUSY` lease or administrative reconciliation store.

## Menu item params

Joomla's menu item model returns the request variables of a component link,
such as `option`, `view`, `layout` and `id`, inside `params`, although they are
not stored in that column. For the reviewed site and administrator menu item
API and console create/update bindings, read-back accepts an additional params
key only when the item is of type `component` and the key and value come from
the stored link's query. A request variable with another value, or any other
additional key, remains a difference.

Every requested param must be present. Its value must match exactly, except
that an integer and its canonical decimal string (`0` and `"0"`) are the same
value: the menu form filters such params to integers. `"060"`, `"60.0"`,
`true` and `0.0` keep their differences. Customized bindings and other
resources retain the strict comparison.

## HTTP errors after a save

A client-error HTTP status does not prove that a write had no effects. In Joomla
6.1.4, [ApiController::save](https://github.com/joomla/joomla-cms/blob/6.1.4/libraries/src/MVC/Controller/ApiController.php)
saves the model before checking the new record ID and checking it back in. A
failure at either subsequent step throws `CheckinCheckout`. Its
[JSON:API exception handler](https://github.com/joomla/joomla-cms/blob/6.1.4/libraries/src/Error/JsonApi/CheckinCheckoutExceptionHandler.php)
returns HTTP 400. Failed validation also uses 400, so neither the status nor a
generic JSON:API error body establishes whether persistence occurred.

Unproven HTTP 400, server errors and interrupted exchanges therefore retain
uncertainty and require inspection. The regression deliberately persists a
resource before returning HTTP 400 and checks that its lease remains held and
replay cannot create a duplicate. The reported native field-create SQL error
requires fixing its field payload or upstream save contract; matching localized
SQL/error text is not a safe basis for unlocking writes.

For the concrete missing-description/params field-create case, MCP completes
only omitted values from the [native administrator form](https://github.com/joomla/joomla-cms/blob/6.1.4/administrator/components/com_fields/forms/field.xml):
`description: ""` and params `show_on: ""`, `showlabel: "1"`, `display: "2"`,
`display_readonly: "2"`, `searchindex: "0"`. The nonempty parameter group matters
because Joomla filters an empty group away. These defaults apply only to the six
reviewed field-create contexts, through API and console transports. Explicit
values and PATCH data are preserved. This prevents the reported missing payload
failure; it does not reclassify an ambiguous HTTP error as an effect-free failure.

## Regression coverage

`tests/write-verification.php` covers create/update, empty/nonempty/nested JSON
shapes, unrelated differences, binding boundaries, settled versus uncertain
leases and immutable replay. `tests/custom-fields.php` covers frozen option
metadata, exact choice maps, numeric keys, malformed inputs and independent
plan/apply verification across the four supported value contexts, including
cleared media subfields. `tests/menu-components.php` covers menu params with
link request variables and integer spelling for site and administrator items,
create and update, both transports, binding boundaries and real differences.

Installed `write-verification.php` checks all four category extensions and the
article Registry fields against native storage and API read-back. Installed
`custom-field-verification.php` creates owned grouped list and checkbox fields,
writes and updates values in all four contexts, checks storage and API maps,
tests clear and invalid-choice behavior, and verifies cleanup. The integration
runner includes these suites in the existing PHP/database matrix.

Native API errors remain errors. In particular, empty field/banner parameter
payloads rejected by Joomla, missing-record HTTP 500 responses and other native
limitations are not converted into success by these comparisons.
