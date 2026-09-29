# Changelog

## [[[NEXT_VERSION]]]

### Fix

- Keep long-lived stdio processes within a 128 MB limit by reusing bounded schema validation, avoiding cyclic canonicalization closures, and selecting only the requested discovery catalogue. Bound fragmented frames, drain oversized frames, handle output backpressure, and retain native idle polling cadence, and route runtime diagnostics to stderr even with inherited stdout logging.
- Return distinct JSON-RPC errors for malformed envelopes, unknown methods and invalid method parameters; preserve request IDs, ignore notifications, and keep subsequent requests usable under the SDK's original session/version rules.
- Validate tool calls using original JSON object/list types at stdio and authenticated HTTP boundaries. Accept omitted or explicit empty-object input, reject arrays/null/scalars, and preserve empty and numeric-key mappings through nested action validation, encrypted plans, deferred jobs and replay. Adapt validated objects to Joomla form arrays only at native save.
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
