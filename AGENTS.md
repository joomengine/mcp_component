# Agent contract

## Objective and branch discipline

Complete the PHP migration of `joomengine/joomla-mcp` into this component and `joomengine/mcp_plugin`, preserving all documented tools, resources, prompts, core API operations, CLI actions, approval/plan semantics, compatibility, verification, recovery and distribution behaviours unless explicitly superseded. **Also implement complete first-class Joomla Component Builder API and CLI coverage as specified in docs/integrations/JCB.md.** Joomla core alone does not satisfy this project. Catalogue presence is not executable parity.

The migration PR #1 has been merged. Branch new work from current `main`, or continue the relevant open PR branch. Commit and push cohesive increments. Do not rewrite others' commits, change the TypeScript or JCB source repositories, merge PRs or run a production release without instruction. Documentation precedes new runtime objectives. Maintain docs/IMPLEMENTATION.md with actual outcomes and exact remaining work.

## Repository ownership

Every improvement to `joomengine/joomla-mcp` must advance this PHP implementation
in the same change cycle. Use linked branches and PRs, reference the originating
issue, and test equivalent behavior and authorization boundaries. Document any
transport-specific limitations explicitly; catalogue presence alone is not
executable parity. This standing requirement does not authorize unrelated edits
to the TypeScript repository unless that work is included in the active request.

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

## Source installation and release contract

- This is exclusively the component repository. A GitHub source ZIP of any reviewed branch or tag must install directly in Joomla, with every manifest file, production Composer dependency, licence and seed already tracked. No downstream Composer run, staging, compilation or packaging is allowed.
- Keep production dependencies in `admin/vendor` and the installation seed in `admin/data`. When changing dependencies, run Composer as a maintainer, commit the lock and complete resolved runtime, and verify its autoload paths after relocation to Joomla's administrator component directory. Never hand-edit third-party vendor source.
- Preserve SQL installation/schema updates and the JSON-driven customization-preserving seed updater. They are approved installation mechanisms; do not replace them merely to change the release process.
- OctoJPack alone combines `mcp_component`, `mcp_plugin` (console) and `mcp_webservices` (HTTP routing), then writes to `joomengine/mcp_package` on `main`. Every plugin belongs in its own repository. Component installation, update and uninstall must never install, change or remove either plugin. The generated package manifest, package update feed and package hash workflow belong in the package repository. This repository's `joomengine_mcp_update_server.xml` serves only the component. Keep `.octojpack` concrete and usable from any machine: hardcode stable source/destination repositories, raw GitHub update/changelog URLs and the raw licence URL. Use explicit `mode: "tags"` on each extension and `version_id: com_joomengine_mcp`; the destination branch is independent of source-tag selection. Never render placeholders or rewrite the configuration during releases. Never change the shared Octo engines without an explicit request.
- The manual Release workflow freezes component metadata and creates its tag, updates the component feed, calls `octoleo/octoshoom@master` to hash that component tag ZIP, then calls `octoleo/octojpack@master`. Configure `octoleo/git-user@v2` once; both actions inherit its setup. The component feed identifies `com_joomengine_mcp` / `component` / client `administrator` and downloads this repository's tag ZIP. Clear its previous checksum when advancing the feed; OctoShoom supplies the new one. The package tag independently triggers its package-repository workflow to update and hash its own feed. Keep credentials in GitHub secrets. Do not duplicate authentication, hashing, archive checks, packaging or repository preflight logic locally. Local release code only edits the component version, changelogs and component update entry. Never move a released tag.
- The package feed URL is `https://raw.githubusercontent.com/joomengine/mcp_package/main/.github/joomengine_mcp_update_server.xml`. Keep the package workflow, feed, helper and maintenance docs under `.github`: native OctoJPack replacement already preserves this hidden folder. There is no ignore-folder action input to invent. The package manifest uses this package-only feed. The shared versioned changelog may continue to use this repository's raw `changelog.xml`.
- Update **both** `CHANGELOG.md` and `changelog.xml` with every meaningful change. Put pending entries under the exact literal `[[[NEXT_VERSION]]]`; create a new pending section after the previous one is released. Do not invent a version or modify historical released entries. The workflow replaces this marker with its input version in both files.
- Joomla changelog identity is `com_joomengine_mcp` / `component`. Use native categories `security`, `fix`, `language`, `addition`, `change`, `remove`, and `note`, each containing `item` children. Use matching human headings in Markdown. Record compatibility warnings under Note, errors fixed under Fix, and security fixes under Security. Keep both changelogs consistent and the manifest's `changelogurl` valid.
- Check workflow syntax and changed metadata behavior. Source installation checks inspect the tracked source archive. Do not copy upstream action tests or restore package builders to make a test pass. See `docs/RELEASE.md`.
- First-package readiness includes working manual release workflows for both independent plugins. Run their version/update-feed/OctoShoom workflows before the component workflow; never leave manual manifest editing or tag creation as an undocumented prerequisite. Keep the exact first-release order and secret scope in `docs/RELEASE.md`. CI success verifies code and installation; only a successful release run verifies publication credentials and the published package.

## Verification

Run syntax/style/unit/schema/manifest/language/package tests, exact source-to-PHP parity and protocol conformance. Add disposable Joomla 6 core and JCB installation/update/uninstall, API token/view-level/asset tests, true CRUD read-back/cleanup, CLI package/compiler scenarios, job concurrency/cancellation/reconciliation and client interoperability. Verify both JCB-absent core functionality and JCB-present full coverage. Generic behavioural contracts are preferred to issue-specific fixtures. Record real failures and missing evidence; never turn missing infrastructure or upstream no-op behaviour into a pass.

Runtime protocol and transport version identifiers must read the native component manifest; never duplicate a hardcoded version that the release workflow could leave stale.
