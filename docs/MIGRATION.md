# Migration plan and acceptance

Preserve `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36` contracts and notices. The source repo is unchanged. The 18 September 2026 scope adds complete JCB API/CLI integration and separates the external client into `joomengine/mcp_client`.

## Server workstreams

1. Preserve exact identities, ownership, JCB-aligned layout, source pins and resumable status. Read integrations/JCB.md before designing new handlers or seeds.
2. Complete typed Joomla DI, authority boundaries, database repositories, schema/binding validation and protocol dispatch. Existing foundations are implemented; do not restart them unnecessarily.
3. Preserve every original tool/resource/prompt/action, schema/effect/compatibility condition and supported API/CLI behaviour. Retain source-to-target parity evidence, not just totals.
4. Complete API and native handlers with Joomla model events/filtering, typed input, preflight, honest errors/partial effects and persisted read-back. Do not substitute generic SQL or mock success for native behaviour.
5. Complete durable principal-bound grants (once, 30 minutes, permitted indefinite), expiry/revocation, signed/encrypted one-shot plans, stale-state checks, atomic idempotency/locks, audit, verification and reconciliation.
6. Complete installed HTTP endpoint authentication/routing/configuration, version/framing/notification/origin/content/size validation, safe token forwarding and consistent ACL discovery/calls. The server-side controller/SDK composition is already committed; installation verification remains required.
7. Complete administrator MVC/XML forms/subforms and configuration/access files for all definition entities plus audit/execution/job views, grant revocation and recovery. Use Joomla native ACL, CSRF, assets and checkout.
8. Complete console installation and original legacy-command compatibility plus direct local PHP MCP stdio. Extend shared handlers/targets for actual registered JCB commands without shadowing or duplicating JCB's command plugin.
9. Complete component manifest/installer, versioned MySQL/PostgreSQL SQL, dependency shipping, customization-preserving seed upgrades, update/changelog metadata, .octojpack and reproducible component/plugin/package archives. Installer infrastructure is now present; that is not complete package certification.
10. Implement all JCB API routes and CLI families through the database-driven integration: source/runtime inventory, definition/schema mapping, entity API operations, package get/init/pull/push/reset, compiler flags/targets/install, jobs/artifacts and native verification. The full scoped backlog is in integrations/JCB.md; no part is an optional post-core enhancement.
11. Run disposable Joomla core/JCB installation, update/uninstall, true HTTP-token and ACL matrix, real API/CLI writes/read-back/cleanup, dependency/missing-extension cases, long-job concurrency/cancellation/recovery and package interoperability. Fix failures on these same branches.

## Separate client handoff

All external Composer-client/remote-stdio implementation now belongs to `joomengine/mcp_client`. The component owns a stable authenticated wire contract, not client source or a client executable. Coordinate site/subdirectory URL, tokens, SDK revisions, discovery, grants and JCB jobs through CLIENT-HANDOFF.md. The owner permits the server to finish before final client certification. The client must discover new JCB definitions without embedding a catalogue.

## Evidence and completion

Each source capability is inventoried, mapped, implemented, unit-tested and live-tested separately. A declared row or an absent upstream handler silently skipped by JCB is not an implementation pass. API-generator support is not proof of an installed route; a package entity map is not proof of registered commands.

Tests use valid actual fixtures and verify persisted records/artifacts, not a returned success flag. Exercise denied discovery and direct invocation, group/view-level differences, revocation, cross-principal plans, changed catalogue/options, malformed input, stale plans, partial effects and ambiguous writes. Clean up data. Distinguish core-only fixtures from JCB-present acceptance.

Inventory upstream SaaS integration ports, multisite configuration, edge authentication, approvals/auditing and compatibility. Where Joomla replaces a standalone boundary, document the replacement and compatibility consequences; no silently lost feature.

Both server PRs stay drafts until required server implementation, full Joomla/JCB coverage and acceptance pass. Missing infrastructure is not a pass and humans need not prove functionality that automated fixtures can prove. External client completion is tracked independently, with shared interoperability evidence. Never claim production readiness from source counts, syntax or isolated doubles.
