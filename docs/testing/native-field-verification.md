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

## Regression coverage

`tests/write-verification.php` covers create/update, empty/nonempty/nested JSON
shapes, unrelated differences, binding boundaries, settled versus uncertain
leases and immutable replay. `tests/custom-fields.php` covers frozen option
metadata, exact choice maps, numeric keys, malformed inputs and independent
plan/apply verification across the four supported value contexts.

Installed `write-verification.php` checks all four category extensions and the
article Registry fields against native storage and API read-back. Installed
`custom-field-verification.php` creates owned grouped list and checkbox fields,
writes and updates values in all four contexts, checks storage and API maps,
tests clear and invalid-choice behavior, and verifies cleanup. The integration
runner includes these suites in the existing PHP/database matrix.

Native API errors remain errors. In particular, empty field/banner parameter
payloads rejected by Joomla, missing-record HTTP 500 responses and other native
limitations are not converted into success by these comparisons.
