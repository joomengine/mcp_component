# Changelog

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
