# Agent contract

## Objective and branch discipline

Complete the PHP migration of `joomengine/joomla-mcp` into this component and `joomengine/mcp_plugin`, preserving all documented tools, resources, prompts, core API operations, CLI actions, approval/plan semantics, compatibility, verification, recovery and distribution behaviours unless explicitly superseded. **Also implement complete first-class Joomla Component Builder API and CLI coverage as specified in docs/integrations/JCB.md.** Joomla core alone does not satisfy this project. Catalogue presence is not executable parity.

Use existing `feature/jcb-mcp-runtime` / PR #1 in both server repositories. Commit and push cohesive increments. Do not create replacement branches, rewrite others' commits, change the TypeScript or JCB source repositories, merge PRs or publish releases without a separate instruction. Documentation precedes new runtime objectives. Maintain docs/IMPLEMENTATION.md with actual outcomes and exact remaining work.

## Repository ownership

The external Composer MCP client and remote stdio bridge belong exclusively to `joomengine/mcp_client`, package `joomengine/mcp-client`, namespace `VDM\Joomla\Mcp\Client`. Do not implement or reintroduce them here. This component's composer.json is server-only (`joomengine/mcp-component`); its outbound Joomla API HTTP infrastructure lives in `admin/src/Http` under the component Administrator namespace. Neither component nor console plugin may depend on the external client. Coordinate wire contracts through docs/CLIENT-HANDOFF.md, not shared administrator code in the client.

## Names and authority

Use `com_joomengine_mcp`, `VDM\Component\JoomEngineMcp`, `plg_console_joomengine_mcp`, and `VDM\Plugin\Console\JoomEngineMcp` exactly. Joomla 6 native contracts are authoritative; match JCB's extension-root/MVC/XML forms. This is hand-authored JCB-aligned code, not a claim of an imported/generated JCB blueprint.

PHP style authority: https://github.com/extension-builder/joomla/blob/main/docs/development/php-code-style.md. Use tabs, LF, Allman braces, explicit typed dependency properties/constructor injection and meaningful class/property/method docblocks. External imports precede VDM imports. No closing PHP tag or isolated strict_types, property promotion, readonly or enum changes. Preserve inherited signatures and output-bearing XML/JSON/SQL/protocol spelling.

## Runtime invariants

1. Joomla DI composes substitutable catalogue, authorizer, handler, persistence, outbound transport and audit boundaries. Do not pass mutable service containers into handlers.
2. HTTP identity comes only from Joomla API authentication. `access` stores a viewing-access-level ID, not a group ID. Check authorized view levels, component/action/assets and native target ACL. Hide denied records consistently in discovery, counts, describe and direct execution.
3. Trusted CLI can only be constructed by the real console application under CLI SAPI. JSON, headers, database rows and tokens cannot select local privilege. Remote stdio remains restricted HTTP.
4. Rows select registered handler keys and constrained bindings, not arbitrary PHP classes, eval, SQL, shell, paths or hosts. Runtime discovery reads the database. JCB PHP/source fields are legitimate inert definition data; do not strip them as if they were dispatch code. Compilation/install/hook execution is a separate high-risk authorized operation.
5. Administrator writes use native models/forms, checkout, ACL, CSRF and assets. Privileged catalogue/configuration edits require core.admin, not just permission to invoke a tool.
6. Writes retain explicit grants, scope/expiry/revocation, principal/input/definition binding, plans, idempotency, atomic claiming, read-back and partial/uncertain recovery. Do not blindly retry ambiguous writes.
7. Keep the console plugin thin. JCB handlers and database mappings belong in the shared component, not duplicate plugin/client catalogues. Use actual registered JCB commands or reviewed native services, never a generic shell executor. Preserve identity, input and mutable JCB factory state across invocation boundaries.
8. JCB package get/init/pull/push/reset and compilation have effects; do not classify `get` as an ordinary read. Preserve compiler option/global/environment semantics, dependency queues and categorized results. Long operations require durable owned jobs/artifacts and verified completion, not enlarged HTTP timeouts or fabricated success.
9. JCB's 45-entity package map is neither a complete API route list nor proof of 225 registered commands. Pin and inspect actual API routing/plugin/controllers and installed CLI InputDefinitions. The API-generation source is not an installed endpoint inventory. Unresolved upstream surfaces remain required work, not invented executable rows.
10. Joomla ZIPs contain all server dependencies. Keep PHP-only production installation/runtime, complete manifests/SQL/update metadata and .octojpack. No false update URLs, unimplemented advertised features, silent test skips or production claims from mocks alone.

## Verification

Run syntax/style/unit/schema/manifest/language/package tests, exact source-to-PHP parity and protocol conformance. Add disposable Joomla 6 core and JCB installation/update/uninstall, API token/view-level/asset tests, true CRUD read-back/cleanup, CLI package/compiler scenarios, job concurrency/cancellation/reconciliation and client interoperability. Verify both JCB-absent core functionality and JCB-present full coverage. Generic behavioural contracts are preferred to issue-specific fixtures. Record real failures and missing evidence; never turn missing infrastructure or upstream no-op behaviour into a pass.
