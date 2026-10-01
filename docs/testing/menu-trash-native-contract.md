# Native menu trash verification

Issue [#25](https://github.com/joomengine/mcp_component/issues/25) remains open.
Trashing can persist successfully while Joomla's API cannot expose that row in
the stored menu collection. This change tests truthful uncertainty and safe
replay; it does **not** claim that menu trash verification is fixed.

## Installed source evidence

The isolated Joomla 6.1.4 installation was inspected on 2026-10-01. Paths below
are relative to the Joomla installation; line numbers refer to that source.

- `api/components/com_menus/src/Controller/ItemsController.php:70-81` copies only
  `filter[menutype]` and the route's client into the list model state. Neither
  `filter[published]` nor `filter[state]` is forwarded.
- `libraries/src/MVC/Controller/ApiController.php:240` constructs the list model
  with `ignore_request => true` and that explicit model state.
- `libraries/src/MVC/Model/BaseModel.php:70-78` marks the state populated when
  `ignore_request` is true. Supplying another request parameter cannot make the
  administrator model's normal request-state population run.
- `administrator/components/com_menus/src/Model/ItemsModel.php:328-336` reads
  `filter.published`, defaults to the empty string, and then restricts the stored
  rows to `a.published IN (0, 1)`. A trashed row has `published = -2`.
- `administrator/components/com_menus/src/Model/ItemModel.php:666-715` rewrites
  `component_id` in item-read results: URL/alias items receive zero and component
  items receive the installed component's derived ID. An item GET therefore
  cannot prove the stored component ID, even for URL items.
- `plugins/webservices/menus/src/Extension/Menus.php` registers the menu and
  menu-item CRUD routes plus menu-item type discovery. It provides no separate
  raw stored-item endpoint that bypasses the collection's publication filter.

Adding an ignored query parameter would be dead contract logic. Falling back to
an item GET would weaken the stored-component guarantee introduced by PR #14.
There is no runtime workaround, state gate, Joomla source modification, or
weakened verification in this change.

## Regression coverage

`php tests/menu-components.php` uses a recording API double matching native
stored/derived IDs and default collection visibility. Both clients and both URL
and component menu items exercise:

- Ordinary title updates still verify and release their leases
- Trash preserves the exact item, client, menu type, link and stored component ID
- Hidden stored trash produces `MENU_VERIFICATION_UNAVAILABLE` and retains the
  uncertain execution, accepted mutation and installation write lease
- Approval replay returns the original result without another API request or
  changes to the durable execution and lease

`tests/integration/menu-components.php` additionally trashes its existing owned
site-component fixture. It independently inspects the stored row and probes the
authenticated native collection using `filter[menutype]`, the intended
`filter[published] = -2`, and bounded pagination. If the collection exposes the
item, MCP must verify it and release its lease; otherwise MCP must report the
native visibility limitation and retain the lease. This is capability evidence,
not a version-number exception or a silent skip. Output reports
`trashContractResolved: false` when the native contract is still unavailable.

The installed test checks replay stability before cleanup. Cleanup deletes only
its owned fixture through the native menu model, independently verifies absence,
and reconciles only its own uncertain execution as inspected partial effects.
Cleanup is not counted as successful mutation verification.

## Criteria for resolving #25

1. Joomla exposes the approved resulting publication state through an
   authenticated raw collection or equivalent native stored-value endpoint.
2. MCP binds that supported state selection to the approved effective request;
   it must not accept caller-controlled identity or broaden client/menu scope.
3. Installed URL and component trash updates for both site and administrator
   menus verify exact stored IDs, links, menu types, clients and component IDs.
4. Verified trash settles its execution and releases its lease; approval replay
   never performs a second update.
5. Ordinary title updates and pagination continue to work, while genuinely
   missing, malformed or inaccessible records remain verification failures.

The recording suite is not evidence that these installed release criteria pass.
