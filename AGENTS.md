# Agent contract

## Objective and branch discipline

Complete the entire PHP migration of `joomengine/joomla-mcp` into this component and `joomengine/mcp_plugin`. Preserve all documented tools, resources, prompts, core API operations, CLI actions, approval/plan semantics, compatibility, verification, recovery and distribution behaviours unless an explicit requirement below supersedes them. Do not equate catalogue presence with executable parity.

Use the existing `feature/jcb-mcp-runtime` branch and PR #1 in each repository. Commit and push cohesive increments. Never create replacement branches, rewrite others' commits, modify the TypeScript source repository, merge the PRs, or publish a release without a separate instruction. Documentation is the first migration commit; runtime follows. Update `docs/IMPLEMENTATION.md` with actual test results and exact remaining work at every handoff.

## Names and authority

Use `com_joomengine_mcp`, `VDM\Component\JoomEngineMcp`, `plg_console_joomengine_mcp`, and `VDM\Plugin\Console\JoomEngineMcp` exactly. Joomla 6 native extension contracts are the greater authority; match the JCB `6.x` extension-root/MVC/XML form layout. This is JCB-aligned hand-authored code, not a claim that a JCB blueprint has already been imported or generated.

The PHP style authority is https://github.com/extension-builder/joomla/blob/main/docs/development/php-code-style.md. Use tabs, LF, Allman braces, explicit typed dependency properties and constructor injection, meaningful class/property/method docblocks, external imports before VDM imports, no closing PHP tag and no isolated strict_types/property-promotion/readonly/enum changes. Joomla inherited signatures must remain compatible. Preserve output-bearing XML, JSON, SQL and protocol spelling/case.

## Architecture invariants

1. Joomla DI composes substitutable catalogue, authorizer, handler, persistence, transport, client and audit boundaries. Do not hide mutable containers in service locators.
2. HTTP identity comes only from Joomla's API authentication. `access` is a Joomla viewing-access-level ID, not a user-group ID. Check `getAuthorisedViewLevels()` plus component/action/asset permissions; Joomla's underlying target ACL remains the ceiling. Denied rows must not leak through discovery, search, describe, execution, counts or errors.
3. Trusted CLI is created exclusively by the console application after a real CLI application/SAPI check. Neither JSON input, database content, HTTP headers nor a token may request privileged mode.
4. Database rows select registered handler keys and declarative bindings, never arbitrary classes, PHP, SQL, files, remote URLs or shell. Runtime discovery comes from the installed database, not a fallback hard-coded tool catalogue.
5. Administrative writes use Joomla models, forms, checkout, ACL, CSRF and assets. Privileged configuration requires `core.admin`, not merely permission to invoke tools.
6. Writes retain plans, explicit grants, expiry/revocation, principal/operation/input binding, concurrency protection, idempotency, read-back and partial-application/recovery reporting. Do not silently retry ambiguous non-idempotent writes.
7. Keep the console plugin thin and share the component engine. Preserve original companion behaviour by migrating audited handlers and contracts, not replacing complex operations with generic SQL or mock success.
8. Install packages include runtime dependencies. A Joomla administrator must not run npm or Composer after uploading the distributable ZIP. Composer client consumers must not need Joomla installed locally.
9. No fake update download links, advertised unsupported protocol features, hidden skipped tests, generated success-only evidence, or claims of production readiness without actual runtime evidence.

## Verification

Run PHP syntax/style/unit tests, schema/manifest/language/package validation, source-to-PHP parity comparison, protocol conformance, and disposable Joomla 6 integration tests. Cover API and CLI, stdio and HTTP, real CRUD read-back and cleanup, view-level/group distinctions, cross-user plans, revocation, stale writes, concurrent execution, errors and partial effects. Use generic behavioural contracts rather than brittle issue-number or vulnerability-specific assertions. Retain redacted machine-readable evidence. A live fixture unavailable to the current environment is an explicit unverified result, not a pass.
