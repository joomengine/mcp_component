# Implementation status — 24 September 2026

## Repository and source baseline

Continue the existing `feature/jcb-mcp-runtime` branch and PR #1. Original MCP source: `2cff50f4f6b440da3c684f9995a77efad32e1a36`. JCB source: `extension-builder/joomla@5ee658dd07eb749dca43ed4722f6cca7eb8208cf`. Component version is 0.1.1; the exact console source and independent version are in `distribution.lock.json`.

## Implemented server

Database catalogue/authorization, schema validation, reviewed API/native handlers, durable permission/plan/execution/audit services, PHP SDK tools/resources/prompts/sessions, authenticated HTTP routing and local console composition are present. Native administrator forms, assets, access/configuration, operational inspection, grant revocation and execution reconciliation are implemented. The installer owns its webservices plugin and preserves operator settings and customized catalogue rows on update.

All 36 imported production native PHP files follow the JCB style, with explicit dependency properties and class/member contract documentation. The source map retains original hashes and records the target transformations. The five original `sourceOnlyGates` remain deliberate upstream diagnostics; they were rejected by the original implementation and are not missing migrated functionality. See [migration provenance](migration/README.md).

JCB synchronization inspects actual installed route and console registries. `CatalogueBuilder` persists provider/schema/action/binding/target records; `CatalogueSynchronizer` applies them through the customization-aware updater and native assets. Synchronization is an explicit administrator action or `joomla:mcp:jcb-sync`; discovery performs no writes. Missing JCB hides dependent functionality; missing API registration yields no invented routes. Unsupported contracts remain visible in inventory diagnostics without being advertised as successful executable operations.

Synchronization records the native registration-plugin dependencies. Disabling or uninstalling a registering plugin immediately hides its persisted operations. Permission schemas support authorized installed provider scopes such as `jcb.execute`, while exact scope checks, publication/ACL changes, revocation and one-use consumption remain enforced. Customized administrator schemas are preserved on upgrade.

JCB commands require approved plans. `CommandInput` freezes supplied/native environment inputs; command/source fingerprints and a JCB definition/configuration snapshot protect against stale execution. Registered native implementations execute in isolated PHP children. Package operations remain writes, including `get`; a missing native handler is reported rather than counted as successful coverage.

Approval previews show the selected definitions, frozen option layers, repository identity, effects and revision fingerprints from that same immutable preparation. Credentials and server paths remain private. The graph revision guard covers the entire installed JCB definition/configuration graph; remote dependency traversal remains native execution work, not a falsely enumerated preview. API validation errors retain bounded native field/checkout diagnostics, and both local and remote stdio enforce byte limits before ignoring blank frames.

`job` and `artifact` state and 0.1.1 MySQL/PostgreSQL migrations support owned asynchronous work. Jobs retain original authority, encrypted frozen payload, one-use worker ticket, execution claim, progress and lease. Cancellation distinguishes never-started work from potentially partial effects; only unstarted queued jobs can be redispatched. Expired/ambiguous work remains uncertain until inspected. Compiler outputs are validated and retained under opaque IDs with size, hash, chunk-integrity and expiry checks.

The administrator Operations screen includes jobs/artifact metadata and cancellation. The `joomla_job_*` tools expose owned listing, status, cancellation, queued redispatch and bounded artifact reads. An API worker reloads the requesting Joomla user and rechecks authority; it does not become the trusted console owner.

Component, console and combined package ZIPs contain their required files and dependencies. `.octojpack`, immutable plugin pins, checksums, provenance and deterministic archives are implemented. Publication is manual from `main`, requires successful exact-commit CI and publishes verified update feeds only after released downloads match the built archives. See [RELEASE.md](RELEASE.md).

## Verified runtime evidence

| Evidence | Verified result |
| --- | --- |
| Source and behavioural contracts | Component `75d9685`, [run 35983426985](https://github.com/joomengine/mcp_component/actions/runs/35983426985), passed PHP 8.3/8.4. Includes 7,218 catalogue checks, 58 protocol checks, 47 JCB contracts, 36 plan-preview checks, 31 preserved native cases and deterministic seed regeneration. All 280 PHP source files passed syntax checks. |
| Distribution | 69 package checks and 14 positive/negative release-metadata checks passed against actual dependency-inclusive component/console/combined archives. Release metadata tests use explicitly synthetic publication URLs; no release was published. |
| Installed Joomla core | Component `75d9685`, [run 35983426742](https://github.com/joomengine/mcp_component/actions/runs/35983426742), passed all four PHP 8.3/8.4 × MySQL 8.4/PostgreSQL 16 combinations on Joomla 6.1.3, including the standalone console plugin and HTTPS client, administrator/HTTP/ACL/native CRUD and standalone/combined install, upgrade and uninstall. |
| Installed JCB | Component `75d9685`, [push run 35983422644](https://github.com/joomengine/mcp_component/actions/runs/35983422644) and [PR run 35983427068](https://github.com/joomengine/mcp_component/actions/runs/35983427068), both passed completely. Actual platform: Joomla 6.1.3, PHP 8.4.25, MariaDB 11.4.13 inside image tag `octoleo/joomengine:6.1.6-php8.4-apache`. The image tag is not treated as the observed Joomla version. |
| Native and remote JCB execution | 299 CLI and 288 authenticated HTTPS-client checks; 30 package-roundtrip checks on each track. All 111 installed commands mapped as executable; zero native JCB API routes were registered. Each compiler track generated/downloaded three verified ZIPs; CLI also installed and independently read back all three extensions. |
| Workers and lifecycle | 17 actual database/process claim-race, cancellation and lost-worker/reconciliation checks; 18 installed console checks; seven upgrade checks, 5,430 upgraded-seed checks and 23 uninstall checks in the golden-image evidence. Partial/uncertain effects were preserved and reconciled. |
| Independent Docker client | Client `1ebb989`, [run 35983558847](https://github.com/joomengine/mcp_client/actions/runs/35983558847), passed PHP 8.3/8.4 contracts and nine actual Compose/TLS checks on each image. [Installed run 35983558934](https://github.com/joomengine/mcp_client/actions/runs/35983558934) passed both PHP versions, with 14 live SDK/executable assertions each. |

The inspected [golden evidence artifact](https://github.com/joomengine/mcp_component/actions/runs/35983422644/artifacts/10801311856) records console source `3526cae818803a02971374c044a2e2184f1c2c61`, client source `72d02491fe80dadc581d3d9d67b1dfbc917d095d` and the installed JCB compiler hash. The later client `1ebb989` fixes Compose command selection when a private-CA entrypoint overrides the image entrypoint; it leaves the tested PHP protocol runtime unchanged. Current workflow pins include that correction and the finalized companion documentation. The [PR completion checklist](https://github.com/joomengine/mcp_component/pull/1#issuecomment-5732685349) records the final-head rerun and readiness status; earlier results never substitute for a failing later run.

## Acceptance boundaries and review handoff

1. The pinned JCB distribution exposes zero native API routes. Native router tests verify adapter mapping, filters, permissions and plugin provenance; they are not live JCB entity API CRUD. A separately installed API distribution must be synchronized from its actual routes, and unsupported specialized contracts remain explicit diagnostics.
2. All five package families have real native and HTTPS-client roundtrip evidence. The pinned upstream file/folder reset index lacks destination metadata; missing/divergent effects are reported as incomplete, never as verified success. Exact source evidence remains in [JCB integration](integrations/JCB.md).
3. The live compiler scenario targets Joomla 6 and tests three output archives, local installation and remote downloads. Native option contracts cover supported targets; the test does not claim every possible target/option combination or JCB on PostgreSQL. The four-platform database matrix covers Joomla core and companion interoperability.
4. Runtime implementation and installed acceptance are complete for the pinned, exposed capabilities. Human testing/review, merge, deliberate release publication and Packagist registration are separate handoff actions. No merge, release or production deployment has been performed.

## Client ownership

The standalone PHP library, remote stdio executable, Docker Compose packaging, private per-site token configuration and Composer delivery belong to `joomengine/mcp_client`. The server retains its outbound API adapter in `admin/src/Http`, without a client-package dependency. Shared wire behaviour is recorded in [CLIENT-HANDOFF.md](CLIENT-HANDOFF.md).
