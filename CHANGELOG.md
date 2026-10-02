# Changelog

## [[[NEXT_VERSION]]]

### Fix

- Synchronize full-size installed generated API catalogues with bounded shared form-contract transport and incremental inventory fingerprints. Release the previous catalogue snapshot before refreshing, avoid an unused catalogue preload during synchronization, and reject invalid inventories or failed refreshes before reusing definitions. Preserve every native route and validation policy, and exercise the complete supplied API-enabled JCB package through repeated installed synchronization and HTTP discovery.

- Preserve effective administrator input restrictions during catalogue upgrades when only the referenced schema was customized. Keep its tool, action, prompt or binding attached to that policy, including hash-detected edits and disabled schemas; verify allowed and denied requests before and after upgrade.

- Identify Joomla core components through the native core-extension catalogue during API synchronization. Preserve their existing MCP bindings while supporting enabled third-party components independently of uninstall and disable protection flags; exercise registered-route synchronization and nested-filter reads in the installed Joomla and JCB checks.

- Describe generated API inputs from their installed native forms, including nested subforms, GUID relationships and validation metadata. Generate a required record GUID only when the native contract calls for one, freeze it in the approved plan, and verify writes through an independently bound item read. Preserve omitted PATCH fields and report unverifiable native responses truthfully.

- Support observed GUID and alternate unique-key routes across installed generated component APIs, with scoped provider permissions, bounded multiselect filters and exact independent item-read bindings. Preserve existing Joomla route encoders and unselected provider definitions during synchronization.

- Accept bounded nested JSON inputs in the generic API and companion read tools while retaining selected-action validation, existing Joomla MCP behavior and administrator-owned schema policies.
### Addition

- Add package-first Joomla setup, administrator-area, token/ACL, tool, confirmed-write, JCB and recovery guidance, with linked AI and direct-client connection instructions.

### Change

- Lead the README with the actively maintained fourth MCP build, stable package installation, repository roles and user documentation links.

## 1.0.6

### Fix

- Verify empty native Registry fields across content, banner, contact and newsfeed categories and article images, URLs and metadata, while retaining strict comparison for other fields and nonempty values.
- Verify custom list and checkbox selections against approved option values and their native API label maps across articles, content categories, contacts and users. Preserve exact keys and labels, independent read-back, uncertainty for mismatches, execution leases and idempotent replay.

## 1.0.5

### Fix

- Acquire private-message planning preconditions through an explicit, recipient-scoped native table snapshot without marking messages read. Retain catalogue ownership, API identity, local installation and revision checks, strict change detection, native mutation/read-back and truthful GET side-effect metadata.

- Verify category empty params, user group-ID memberships and automatic module ordering using field-specific native contracts; preserve API response JSON object/list types, independent read-back, execution leases and idempotent replay.
- Replace the catalogue All page-size option with explicit bounded sizes through 500; keep rendered selections, saved page sizes and offsets consistent for legacy and out-of-range requests across all eight catalogue views.

### Note

- Document the installed-audit scope: five component fixes are verified; eight native Joomla API limitations are closed as outside this repository's fix scope, while their behavior and truthful verification boundaries remain explicit.

## 1.0.4

### Fix

- Preserve native administrator catalogue pagination when unchanged blank SearchTools filters are resubmitted, reset only genuine filter changes, and align bounded page offsets. Keep Operations pagination independent for each list kind and align its bounded page size.
- Allow component Options to save with blank optional PHP CLI executable and artifact directory fields when Joomla supplies null during form validation. Keep validation of supplied paths and malformed values unchanged.
- Verify permanent HTTP article deletion through an authorized, approval-bound exact-ID collection lookup across all native article states when Joomla returns HTTP 500 for the missing item. Complete verified executions and release their write lease; retain uncertain outcomes when absence cannot be established.

### Language

- Clarify that the PHP CLI executable may remain empty when JCB background jobs are not used, including on shared hosting with restricted process execution.

## 1.0.3

### Fix

- Keep long-lived stdio processes within a 128 MB limit by reusing bounded schema validation, avoiding cyclic canonicalization closures, and selecting only the requested discovery catalogue. Bound fragmented frames, drain oversized frames, handle output backpressure, and retain native idle polling cadence, and route runtime diagnostics to stderr even with inherited stdout logging.
- Return distinct JSON-RPC errors for malformed envelopes, unknown methods and invalid method parameters; preserve request IDs, ignore notifications, and keep subsequent requests usable under the SDK's original session/version rules.
- Validate tool calls using original JSON object/list types at stdio and authenticated HTTP boundaries. Accept omitted or explicit empty-object input, reject arrays/null/scalars, and preserve empty and numeric-key mappings through nested action validation, encrypted plans, deferred jobs and replay. Adapt validated objects to Joomla form arrays only at native save; retain stored Registry JSON types in item read-back without widening the model's visible content.
- Map content-language identifier ordering to Joomla's physical `lang_id` column and approved table aliases, preserving the public `id` alias on fresh single-language sites.
- Preserve JSON Schema object mappings and object-valued defaults in tools/list, action search and descriptions, while retaining legitimate array values and numeric object keys.
- Preserve exact zero-based list offsets across Joomla's native last-page clamping, including empty, partial-final, exact-end and beyond-end pages. Count filtered installer and cache collections before slicing, and include live list acceptance in both installed tracks.
- Deliver discovery responses that exceed the former 1 MiB encrypted-session bound. Retain principal isolation and compare-and-swap persistence, reject oversized queued state with a protocol error, and keep later requests usable.

## 1.0.2

### Fix

- Default omitted custom field creation defaults to an empty string on API and native console transports, matching Joomla's administrator form and avoiding null-default backend DOM deprecations. Preserve explicit defaults and partial-update behavior.

## 1.0.1

### Fix

- Accept published, accessible Joomla custom field values in API article, content-category, contact and user create/update plans, including typed article tools and the optional `com_fields` alias. Keep unknown keys blocked, expose runtime field metadata in describe, and bind the resolved fields to the approved plan without rediscovery at apply.
- Preserve installed template inheritance when creating site or administrator styles; bind verified parent metadata to the confirmed plan and refuse incomplete discovery before saving.

- Persist native menu component IDs through an approved follow-up PATCH and verify the raw stored collection value for create/update, including link changes to another component. Retain durable uncertain outcomes and the created item identity if a later step fails, preventing duplicate creation on replay.
- Supply OctoJPack with the workflow's read-only GitHub token when the optional `GIT_TOKEN` secret is unset, so public tagged sources can be packaged.
- Use the console plugin's independent-version installer fix in the installed Joomla and JCB checks.

## 1.0.0

### Fix

- Read MCP protocol identity and outbound API User-Agent versions from the native component manifest, including after Joomla relocates the administrator files.

- Make the repository source ZIP directly installable, with committed production dependencies, relocation-safe autoloading, catalogue seed and administrator licence.
- Point component update discovery to the maintained native XML feed.

### Addition

- Document the complete first-package workflow order, valid first versions and secret scope for both plugins and the component.
- Add a manual version release workflow that freezes both changelogs, creates an immutable tag, updates and hashes the component feed, then publishes the package with OctoJPack.
- Document GitHub secrets and the source-installation contract for future agents.

### Change

- Follow the Octoleo quick starts: set up Git once, let OctoShoom hash the component download, then let OctoJPack read `.octojpack` directly.

- Separate component and package update feeds: the component feed stays here; the package feed and tag-triggered OctoShoom workflow live in `mcp_package/.github`, preserved by native OctoJPack replacement.
- Select each extension's latest tag explicitly; derive the package version from the component and retain the shared versioned changelog.
- Extract webservices routing into its own `mcp_webservices` repository and include it as a separate OctoJPack extension.
- Verify tracked source archives in component and console installation tests.

### Remove

- Remove repository-local ZIP/package builders, distribution locks and generated package manifests.
- Remove component-installer ownership of the webservices plugin.
- Remove temporary action checkouts, configuration rendering, custom SSH setup, duplicate hash verification and package repository checks.

### Note

- Tag the independent console and webservices plugin releases before releasing the component; OctoJPack selects their latest tags.
- Configure the secrets documented in docs/RELEASE.md before the first release.

## 0.1.1 — development baseline

### Addition

- Installed JCB API-route and command inventories, explicit administrator/CLI synchronization, and customization-preserving database definitions.
- Confirmed package/compiler planning, frozen input and source/state fingerprints, and isolated native execution with retained diagnostics.
- Durable principal-owned jobs, worker tickets and leases, progress, cancellation, queued redispatch, uncertain-outcome recovery and retained hash-verified compiler artifacts.
- Versioned MySQL/PostgreSQL job and artifact schema updates.
- Job/process and JCB behavioural tests, alongside installed Joomla and JCB workflows described in docs/IMPLEMENTATION.md.

### Change

- Background execution retains the original API user's identity and authority; a PHP worker does not grant local server-owner permissions.
- Stale command definitions, implementation sources or approved JCB state require a new plan.
- Imported native production PHP follows the JCB code style, preserving source attribution, protocol literals and original behavioural contracts.

## 0.1.0 — development baseline

### Addition

- Database catalogue, schema validation, reviewed API/native handlers, grants/plans/executions/audit, SDK protocol adapters and shared console runtime.
- Native administrator MVC/forms, assets, access rules, API routing plugin, portable SQL and installed core tests.
- Source-pinned migration descriptors and preserved original PHP native handlers.
- External client and remote stdio ownership separated into mcp_client.

Development baseline entries describe existing source, not previously published releases. Published immutable tags establish release availability.
