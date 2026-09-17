# Migration plan and acceptance

Source baseline: `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`. Do not modify that repository. Preserve contracts and notices from the PHP companion and TypeScript server, not only their README summaries.

## Ordered implementation increments

1. Documentation: objective, exact names, architecture, database graph, security boundaries, source pin, JCB layout, acceptance and resumable status. This precedes runtime commits.
2. Runtime foundations: typed contracts, Joomla DI composition, principal boundary, database repository, schema/binding validation, protocol dispatch and unit tests. Start executable code immediately after documentation.
3. Catalogue migration: inventory every source tool/resource/prompt/action and wire schema; convert repetitive declarations to portable install SQL; record source file/symbol and target record/handler for each item. Compare exact names, schemas, effects, capability/compatibility conditions and documented unsupported cases.
4. Execution: migrate audited native companion handlers and API adapters; preserve Joomla model events/filtering, preflight, input normalization, error/partial-apply diagnostics and read-back. Complete API/CLI track parity rather than a generic CRUD approximation.
5. Safety state: durable plans, principal-bound explicit permissions, one-operation/30-minute/indefinite grants where allowed, revocation, stale-plan detection, shared locking/idempotency, audit redaction, verification and recovery contracts.
6. HTTP component: Joomla-authenticated route glue, JSON-RPC controller, tested protocol/version/notification handling, content/size/origin checks, token-safe forwarding and ACL-filtered discovery/calls. Preserve supported resources/prompts and capability reporting.
7. Administrator MVC: component configuration, provider/schema/action/binding/resource/prompt CRUD, XML forms/subforms, filters, row access/assets, CSRF/checkout, audit/execution inspection and grant revocation.
8. Console plugin: move original plugin with exact new namespace/name, retain compatibility aliases where justified, add direct PHP MCP stdio serving, and use shared component services with a non-forgeable local trusted context. Test every legacy CLI entry point.
9. Composer client: PHP-only remote client and stdio bridge, multi-site/connection configuration, token injection, protocol negotiation, bounded I/O/timeouts, structured errors, examples and tests. No embedded server catalogue or local Joomla dependency for remote clients.
10. Installation/distribution: complete manifests, installer preflight, versioned MySQL/PostgreSQL SQL, dependency shipping, update/changelog XML, `.octojpack`, reproducible component/plugin/package archives and secure release automation. Feed entries only follow actual published assets.
11. Verification: source parity, schema/style/unit/security/transport tests, disposable Joomla install/update/uninstall, real token and ACL matrix, API and CLI CRUD with post-write reads and cleanup, concurrency/recovery, client bridging and package smoke tests. Fix failures on the same branches.

## Evidence contract

For each feature distinguish: inventoried, mapped, implemented, unit-tested, live-tested. Each source item has its own record; counts alone are not evidence. Report real failures and skips with reasons. Live scenarios use actual valid fixtures and verify persisted records through Joomla, not only a returned success flag. Do not leave test data behind. Tests must exercise denied discovery, denied direct invocation, removed group membership, changed view levels, revoked grants, cross-user plans, malformed protocol, injection attempts, stale plans and ambiguous writes.

The upstream server's SaaS integration ports, multi-site handling, optional HTTP authentication, approvals, auditing and compatibility behaviours must be inventoried explicitly. Where hosting inside Joomla replaces a boundary (for example Joomla token authentication replacing a standalone edge authenticator), record the intentional replacement and its compatibility implications. No silently lost feature.

## Completion

Both PRs remain drafts while any implemented-upstream feature is unported or required runtime evidence is missing. Mark ready for review only after the implementation is complete and required verification has passed; humans review then. Never make progress depend on a human proving runtime that the implementation/tests can prove. Conversely, unavailable infrastructure is not evidence of a pass. The status file must give exact remaining tasks so the same branches can be resumed without reconstructing the plan.
