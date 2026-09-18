# Implementation status — 18 September 2026

## Repository and source baseline

Continue `feature/jcb-mcp-runtime`, draft PR #1, in mcp_component and mcp_plugin. Original MCP source: `2cff50f4f6b440da3c684f9995a77efad32e1a36`. The inspected component head before client separation was `a3fb48c680c520fe3b81c6e40fc8aa8cc427f36e`; plugin head was `fb6a72a515859d198b47acb6fdac932e5dae92ab`.

## Committed implementation before this scope update

Database-backed catalogue/authorization, schema validation, API/native handlers, durable permission/execution/audit services, PHP SDK registry/session/tool/resource/prompt adapters, Joomla runtime/MVC composition, API controller and webservices routing glue, shared console runtime and native contracts are present. Portable install/uninstall SQL and reproducible source-mapped core seeds are committed.

The a3fb48c commit added native installer composition, asset synchronization, customization-preserving SeedUpdater infrastructure and an HTTP request-body fix. Do not overwrite those changes or repeat the older claim that no installer code exists. This is not proof of complete installable packaging or live Joomla operation.

## Changes owned by this increment

External Composer-client/remote-bridge scope moves to `joomengine/mcp_client` / PR #1. The component keeps only server dependency metadata and its required outbound API transport, relocated from libraries/src/Http into admin/src/Http under its Administrator namespace. Server/client do not depend on one another.

Complete JCB API and CLI coverage is now an explicit required objective in README, AGENTS, architecture and migration docs. integrations/JCB.md and jcb-surface.json preserve pinned evidence, the 45 canonical package entities, compiler/options and package families, missing exact route/registration inventories, database mapping, security, jobs and acceptance requirements. **No runnable JCB integration or active JCB seed rows are introduced by this documentation update.**

## Verification evidence

After the outbound transport relocation, the seven existing isolated suites pass locally on PHP 8.4 with SDK 0.8.1 dependencies: foundation 34, catalogue 5912, execution 51, actions 19, protocol 34, HTTP boundary 30, plus the imported native companion suite. These reruns cover the available runtime baseline and relocation, not an installed Joomla/JCB fixture or the new installer's full lifecycle. The full committed head must also pass its PR CI; inspect the exact head/run rather than treating an earlier pass as current.

## Remaining server implementation

Finish component manifest and installation packaging, administrator MVC/forms/languages/configuration/access, deployment/update/changelog/.octojpack, installer lifecycle tests, full imported-code style alignment, recovery/job screens and actual Joomla installation/ACL/API/CLI acceptance. Complete every workstream in integrations/JCB.md, including verified actual API route and CLI registration inventories, full database binding coverage and long-running compiler/package execution.

## Client dependency

The standalone connection library has its own implementation/tests/status. Remote stdio completion, per-site credential configuration, final installed-server interoperability and Packagist publication are tracked there. The owner explicitly permits finishing the installed component first. They are not missing source files to reintroduce into this component.
