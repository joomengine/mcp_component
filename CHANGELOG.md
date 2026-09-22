# Changelog

## 0.1.1 — unreleased

### Added

- Installed JCB API-route and command inventories, explicit administrator/CLI synchronization, and customization-preserving database definitions.
- Confirmed package/compiler planning, frozen input and source/state fingerprints, and isolated native execution with retained diagnostics.
- Durable principal-owned jobs, worker tickets and leases, progress, cancellation, queued redispatch, uncertain-outcome recovery and retained hash-verified compiler artifacts.
- Versioned MySQL/PostgreSQL job and artifact schema updates.
- Combined Joomla package with immutable console-plugin source selection, deterministic ZIP metadata, checksums and build provenance.
- `.octojpack`, manual main-only release automation and component/package update feeds generated only after archive publication and download validation.
- Package lifecycle, release validation, job/process and JCB behavioural tests, alongside installed Joomla and JCB workflows described in `docs/IMPLEMENTATION.md`.

### Changed

- Background execution retains the original API user's identity and authority; a PHP worker does not grant local server-owner permissions.
- Stale command definitions, implementation sources or approved JCB state require a new plan.
- Component update discovery uses verified GitHub release feed assets.
- Imported native production PHP follows the JCB code style, preserving source attribution, protocol literals and the original behavioural contracts.

## 0.1.0 — development baseline

- Database catalogue, schema validation, reviewed API/native handlers, grants/plans/executions/audit, SDK protocol adapters and shared console runtime.
- Native administrator MVC/forms, assets, access rules, API routing plugin, portable SQL, dependency-complete component ZIP and installed core tests.
- Source-pinned migration descriptors and preserved original PHP native handlers.
- External client and remote stdio ownership separated into `mcp_client`.

Release availability is established by GitHub releases, not this changelog.
