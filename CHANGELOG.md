# Changelog

## [[[NEXT_VERSION]]]

### Fix

- Read MCP protocol identity and outbound API User-Agent versions from the native component manifest, including after Joomla relocates the administrator files.

- Make the repository source ZIP directly installable, with committed production dependencies, relocation-safe autoloading, catalogue seed and administrator licence.
- Point component update discovery to the maintained native XML feed.

### Addition

- Add a manual version release workflow that freezes both changelogs, creates an immutable tag, updates the Joomla feed and waits for OctoShoom before invoking OctoJPack.
- Document GitHub secrets and the source-installation contract for future agents.

### Change

- Follow the Octoleo quick starts: set up Git once, run OctoShoom, then let OctoJPack read `.octojpack` directly.

- Delegate combined Joomla package assembly exclusively to OctoJPack and a separate package repository.
- Verify tracked source archives in component and console installation tests.

### Remove

- Remove repository-local ZIP/package builders, distribution locks and package update metadata.
- Remove temporary action checkouts, configuration rendering, custom SSH setup, duplicate hash verification and package repository checks.

### Note

- Release the console plugin first; OctoJPack selects the latest tags using its native configuration.
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
