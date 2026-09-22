# Implementation status — 22 September 2026

## Repository and source baseline

Continue the existing `feature/jcb-mcp-runtime` branch and PR #1. Original MCP source: `2cff50f4f6b440da3c684f9995a77efad32e1a36`. JCB source: `extension-builder/joomla@5ee658dd07eb749dca43ed4722f6cca7eb8208cf`. Component version is 0.1.1; the exact console source and independent version are in `distribution.lock.json`.

## Implemented server

Database catalogue/authorization, schema validation, reviewed API/native handlers, durable permission/plan/execution/audit services, PHP SDK tools/resources/prompts/sessions, authenticated HTTP routing and local console composition are present. Native administrator forms, assets, access/configuration, operational inspection, grant revocation and execution reconciliation are implemented. The installer owns its webservices plugin and preserves operator settings and customized catalogue rows on update.

All 36 imported production native PHP files follow the JCB style, with explicit dependency properties and class/member contract documentation. The source map retains original hashes and records the target transformations. The five original `sourceOnlyGates` remain deliberate upstream diagnostics; they were rejected by the original implementation and are not missing migrated functionality. See [migration provenance](migration/README.md).

JCB synchronization inspects actual installed route and console registries. `CatalogueBuilder` persists provider/schema/action/binding/target records; `CatalogueSynchronizer` applies them through the customization-aware updater and native assets. Synchronization is an explicit administrator action or `joomla:mcp:jcb-sync`; discovery performs no writes. Missing JCB hides dependent functionality; missing API registration yields no invented routes. Unsupported contracts remain visible in inventory diagnostics without being advertised as successful executable operations.

JCB commands require approved plans. `CommandInput` freezes supplied/native environment inputs; command/source fingerprints and a JCB definition/configuration snapshot protect against stale execution. Registered native implementations execute in isolated PHP children. Package operations remain writes, including `get`; a missing native handler is reported rather than counted as successful coverage.

`job` and `artifact` state and 0.1.1 MySQL/PostgreSQL migrations support owned asynchronous work. Jobs retain original authority, encrypted frozen payload, one-use worker ticket, execution claim, progress and lease. Cancellation distinguishes never-started work from potentially partial effects; only unstarted queued jobs can be redispatched. Expired/ambiguous work remains uncertain until inspected. Compiler outputs are validated and retained under opaque IDs with size, hash, chunk-integrity and expiry checks.

The administrator Operations screen includes jobs/artifact metadata and cancellation. The `joomla_job_*` tools expose owned listing, status, cancellation, queued redispatch and bounded artifact reads. An API worker reloads the requesting Joomla user and rechecks authority; it does not become the trusted console owner.

Component, console and combined package ZIPs contain their required files and dependencies. `.octojpack`, immutable plugin pins, checksums, provenance and deterministic archives are implemented. Publication is manual from `main`, requires successful exact-commit CI and publishes verified update feeds only after released downloads match the built archives. See [RELEASE.md](RELEASE.md).

## Verification evidence

| Evidence | Current boundary |
| --- | --- |
| Source/behavioural suites | PHP 8.3/8.4 passed at `080e189`: [run 35614910698](https://github.com/joomengine/mcp_component/actions/runs/35614910698). The subsequent native style conversion also passed syntax, 31 preserved native cases, 7,211 catalogue checks and 19 action checks locally. |
| Distribution checks | Local PHP 8.4: 69 package checks, 14 positive/negative release-metadata checks and repeat-build checksums passed. Test release metadata is explicitly synthetic; no release was published. |
| Installed Joomla matrix | All four PHP 8.3/8.4 × MySQL/PostgreSQL combinations passed at `080e189`: [run 35614910810](https://github.com/joomengine/mcp_component/actions/runs/35614910810). This includes administrator/HTTP/ACL/stdio behaviour and component/combined-package install, upgrade and uninstall. |
| Installed JCB golden image | The workflow installs pinned JCB and the component/console plugin and captures actual registry and runtime evidence. New compiler/package/job acceptance is under validation; a registered command count alone is not an execution pass. |
| Client interoperability | Independent client tests consume the installed endpoint and discovered contracts; their current results belong to coordinated client/component CI. |

The latest complete CI run is authoritative. Earlier passing installation or isolated suites do not certify the new 0.1.1 runtime. No release publication or production deployment has occurred as part of this work.

## Remaining completion checks

1. Re-run the installed matrix against the final changes and resolve any new failures.
2. Compare every observed JCB route/command with synchronized bindings or explicit unsupported diagnostics. Execute actual compiler/package workflows through MCP, including artifacts, permissions, changed plans, cancellation and uncertain-effect recovery. A distribution without JCB API routes must report that fact without inventing endpoint coverage.
3. Confirm installed specialized JCB routes, filter/order contracts and command options fit their adapters. Missing upstream handlers must remain explicitly unavailable; an upstream no-op is not successful execution.
4. Record final installed-client interoperability and exact-commit CI evidence before declaring the existing PRs ready for review. Human review/merge and intentional release publication follow verification.

## Client ownership

The standalone PHP library, remote stdio executable, private per-site token configuration and Composer delivery belong to `joomengine/mcp_client`. The server retains its outbound API adapter in `admin/src/Http`, without a client-package dependency. Shared wire behaviour is recorded in [CLIENT-HANDOFF.md](CLIENT-HANDOFF.md).
