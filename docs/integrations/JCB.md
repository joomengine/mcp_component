# Required integration: Joomla Component Builder

## 1. Objective and completion boundary

JoomEngine MCP must support **the entire actual API and CLI surface of Joomla Component Builder (JCB)** in addition to the preserved Joomla core capabilities. JCB support is a first-class delivery requirement, not a sample third-party integration or an optional improvement after the core release.

Cover JCB's definition/entity APIs, fields and field types, reusable powers and code, component/module/plugin/view relationships, compiler, and all registered package get/init/pull/push/reset commands. Retain the exact native input semantics, permissions, dependencies, outputs and failure behaviour. Extend the existing database graph and reviewed handler primitives; do not write a separate hard-coded MCP server for JCB.

This document records the required contract and acceptance matrix. Version 0.1.1 implements installed-route/command inventory, explicit catalogue synchronization, reviewed command planning/execution, durable jobs and artifacts. JCB bindings are materialized from the installed registries through the administrator Operations screen or `joomla:mcp:jcb-sync`; ordinary discovery reads persisted rows. `jcb-surface.json` remains source-backed planning evidence, not a runtime catalogue. Current installed execution evidence and remaining checks are recorded in ../IMPLEMENTATION.md. Unsupported native contracts or absent handlers require explicit diagnostics; they are not successful executable coverage.

JCB may be absent on an individual Joomla site. In that case the server must retain Joomla core support and hide unavailable JCB operations. That installation behaviour must not be confused with project acceptance: acceptance requires a JCB-present fixture exercising the complete integration.

## 2. Source evidence and unresolved boundaries

Use these pinned references and refresh the inventory deliberately when their revisions change:

| Evidence | Pinned source / meaning |
| --- | --- |
| JCB implementation | `extension-builder/joomla@5ee658dd07eb749dca43ed4722f6cca7eb8208cf` |
| CLI documentation | `joomengine/jcb-documentation@ecd3670232d344295fc4f673b2d3dc40a64b3bf6`, `english/CLI-Command-Suite.md` |
| Entity/factory routing | `libraries/vendor_jcb/VDM.Joomla/src/Componentbuilder/Factory.php`; 45 canonical distribution entities |
| Native compiler command | `libraries/vendor_jcb/VDM.Joomla/src/Componentbuilder/Console/Compiler.php` |
| Package command implementations | `.../Componentbuilder/Console/Package/{Get,Init,Pull,Push,Reset}.php` and their abstraction classes |
| Package semantics | `docs/architecture/package-distribution.md`, Package Factory/Builder/Get/Builder/Set and entity remote configuration |
| Generated API design | `docs/architecture/api-generation.md`, compiler API renderers, administrator forms/models and access definitions |
| CLI registration dependency | JCB's package manifest names the console plugin `ComponentBuilderCommands`; inspect its installed registration and command InputDefinitions before binding |

### API generation is not an endpoint inventory

The inspected `extension-builder/joomla` root does not contain an installed `api/` tree or the JCB API webservices registration plugin. Its `Componentbuilder/Api/Network.php` is not an enumeration of inbound entity endpoints. The API-generation document describes output generated for configured views and route placeholders inserted into a separately linked webservices plugin; it does not supply a verified installed route list for this JCB build.

Therefore the required next inventory must inspect the actual JCB API distribution, its manifest, enabled webservices plugin, route defaults, API controllers/serializers and administrator models/forms. A separately generated or installed JCB API may exist; absence of those files in this source root is **not a claim that JCB has no API**. Record the exact distribution/plugin source and version instead of guessing `/v1/componentbuilder/...` paths.

### Entity map is not a command registry

The 45-entity factory map routes package definitions to 40 Package areas and five specialized factories. It is neither the complete API resource list nor proof that all five package verbs are registered for every entity. Do not manufacture a list of 225 working commands. Enumerate the installed CLI registry after JCB's console plugin has registered its commands; preserve exact names, aliases, arguments, options, required/default values and implementation identity. Additional real commands outside the six documented families are also in scope.

## 3. Repository responsibilities

| Repository | Required responsibility |
| --- | --- |
| `mcp_component` | JCB provider/schema/action/binding/tool/resource/target rows, reviewed handlers, API identity/ACL, grants/plans, jobs/artifacts, verification, administrator management and seed upgrades |
| `mcp_plugin` | Thin local console integration, correct JCB command discovery/availability, typed invocation, stdout/stderr/exit isolation and direct MCP stdio; no duplicate business catalogue |
| `mcp_client` | Discover the authenticated server's definitions, forward protocol requests/results, respect job/grant contracts and remain independent of Joomla/JCB code; no per-JCB action catalogue |
| JCB implementation and command plugin | Remain the authority for compiler, package and model behaviour; consume them as installed dependencies rather than porting another copy into MCP |

The local MCP plugin must not shadow or replace JCB's command plugin. A remote client cannot request trusted CLI mode. An HTTP counterpart to a CLI-only operation requires its own explicitly reviewed JCB service/job adapter that retains the authenticated Joomla user's permissions; spawning a privileged console command is not an acceptable implementation of that counterpart.

## 4. Database expansion

Introduce a stable JCB provider identified by `com_componentbuilder`, with version bounds, dependency/plugin availability, provenance and the JCB definition revision. Seed records use stable natural identities and existing seed ownership markers, not installation-specific numeric user/group IDs.

Reuse the current configuration graph:

- Shared `schema` rows describe exact API/CLI inputs and outputs, including GUIDs, unique keys, arrays, subforms, code fields, typed options, paginated responses, diagnostics and artifacts.
- `action` rows identify semantic operations, descriptions/examples, domain/toolset, effect/risk, source references and native permission requirements. API and CLI bindings may share an action only where their semantics actually agree.
- `binding` rows select a registered handler and track with constrained route/command/parameter/result mappings, availability and compatibility. A database row never names an arbitrary executable class or shell program.
- `tool`, `resource`, `prompt` and `target` rows describe discoverable entry points, help, reviewed workflows and exact CLI targets. Tools may use existing semantic-action search/describe/call mechanisms rather than generating hundreds of top-level tools.
- Provider, action, schema and binding publication/availability are enforced consistently. HTTP discovery and calls must intersect Joomla viewing levels, row assets and native JCB resource/field permissions. Local CLI follows the existing server-owner boundary.

Proposed semantic names such as `jcb.api.<entity>.<operation>`, `jcb.package.<verb>.<entity>` and `jcb.compiler.compile` are design examples, not currently advertised operations. Preserve original JCB route/command names in binding metadata; do not recase its contract fields or infer one name from another.

Ordinary additions should require validated database rows using existing primitives. Add new PHP handler services only for genuinely new primitives: for example JCB compiler execution, dependency synchronization, safe registered-command invocation and job/artifact access. New handlers must be available before corresponding rows can be published as executable. Existing reserved handlers cannot be replaced by arbitrary extensions.

## 5. Complete JCB API coverage

The authoritative inventory must include every actual exposed list/item resource, mutation and specialized action, including read-only dynamic site/custom-admin resources where present. Do not limit API coverage to the package entity map. For each endpoint capture method, route variables/defaults, controller/model, identifier forms, filters/order/pagination, content type, JSON shape, schema, effects, exact Joomla ACL/field permissions and version constraints.

The installed adapter publishes the reviewed native method/task pairs (`GET displayList/displayItem`, `POST add`, `PATCH/PUT edit`, `DELETE delete`). Specialized tasks, nonstandard identifier rules and routing defaults remain explicit unavailable diagnostics until their native contract is reviewed. A GET method alone never establishes a safe read. Fixed route defaults are preserved, and mutation read-back must match the exact collection, controller and defaults. This does not invent endpoints for distributions without a webservices plugin.

List bindings expose bounded `offset`/`limit`, a scalar `filter` object, and `ordering`/`direction`, mapped to Joomla's native `page[...]`, `filter[...]` and `list[...]` query keys. The installed controller defines accepted filter names and ordering columns; unknown names may be ignored by Joomla. Router registrations do not contain controller-specific filter schemas, so discovery does not claim an invented list of supported fields. Native ACL, validation and form handling remain authoritative.

Use the native API/model contract for create/update/delete and other supported actions. Preserve GUID-to-ID or alternate unique-key resolution, relationship fields, nested/subform values and source-code/string encoding. Do not guess that missing create IDs behave like update IDs. Preserve published-state, checkout, conflicts and errors. Verify mutations through native read-back, not only status codes.

JCB definition payloads legitimately include PHP, JavaScript, XML and SQL source strings used later by its compiler. They remain inert domain data while stored or returned. Schema/field ACL and appropriate secret classification apply; blanket removal of code fields would destroy JCB parity. Executing compilation, import hooks or installation is a distinct higher-risk operation requiring explicit authority.

Prefer authenticated same-site API forwarding for HTTP bindings, using the already verified caller token and native JCB ACL ceiling. Do not use administrator session cookies or turn an API request into the trusted CLI identity. Only inspect registered/approved same-site routes; neither tool input nor a definition may choose arbitrary hosts, credentials or SQL tables.

## 6. Complete JCB CLI coverage

The documentation uses `componentbuilder:<action>:<area>` and explicitly documents `componentbuilder:compile:component`. The binding generator must use actual installed registration, not only this naming pattern.

| Family | Required semantics and effect classification |
| --- | --- |
| `get` | Identifier resolution and package synchronization, potentially mutating definitions and dependencies. **Not a read-only list/get API operation.** Preserve native categorized results. |
| `init` | Initialize/fetch selected definitions from an explicitly configured repository, including force/resolve options where actually supported. Treat persistence/dependency effects as writes. |
| `pull` | Remote-to-local synchronization with overwrite semantics; show destructive impact and retain recovery/partial-apply evidence. |
| `push` | Publish local definitions and dependencies to configured repositories. Preserve synchronous native behaviour/message bus. Remote multi-object updates are not automatically an atomic transaction. |
| `reset` | Preserve native tracking/reset and dependency traversal semantics; do not replace with generic entity deletion or describe it as rollback. |
| `compile` | Resolve selected components, dependencies, target version and effective options; generate output/archive artifacts and optionally install/export/backup. High-risk filesystem, repository and installation effects require explicit plans. |

Package item selectors support the native CSV/newline/JSON forms, `--items`, `--items-file`, documented short aliases and environment fallbacks as confirmed by the actual command definition. `init` repository JSON/file selectors, `--force` and `--resolve` must be inventoried exactly. `push` must preserve GUID requirements and reject an empty set before execution. An upstream void return or skipped missing service is not proof of successful publication.

For trusted local CLI, retain reviewed native file-input forms, including `@file` where supported. HTTP callers must use bounded inline data or server-owned input/artifact references, not arbitrary server paths. Do not silently remove a CLI option simply because it cannot be exposed unchanged over HTTP; represent each track's actual contract and intentional safe counterpart explicitly.

### Compiler contract

The pinned compiler accepts `--component/-c`, `--components`, `--components-file` and `--options/-o`, with CSV/newline/JSON and `@file` forms where documented. Capture these options from the actual command, with exact types:

| Option | Native meaning to preserve |
| --- | --- |
| `--backup/-b` | Add compiled package to configured backup/sales destination |
| `--repository/-r` | Move generated output to configured local repository folder; not the same option shape as package repository JSON |
| `--add-placeholders`, `--debug-line-nr`, `--minify/-m`, `--powers/-p`, `--powers-repository` | Native 2=global, 1=yes, 0=no selectors; omission is not false |
| `--joomla-version/-j` | Target Joomla 3, 4, 5 or 6; target differs from the Joomla 6 host version |
| `--indentation-value` | 1=tab, 2=two spaces, 4=four spaces |
| `--add-build-date` | 1=default, 2=manual, 3=component |
| `--build-date/-d` | Manual YYYY-MM-DD value |
| `--install/-i` | Install generated extension output, not just compile it |

Preserve explicit-flag versus options-bundle precedence and the `JCB_COMPILE_*`, `JCB_COMPILER_OPTIONS`, and per-option `JCB_*` environment fallbacks. Omitted options deliberately leave Joomla input unset so global behaviour resolves downstream. Freeze the resolved effective values and relevant configuration revision in a reviewed plan; a later environment/configuration change must not silently alter approved work.

Capture machine archive paths separately from human messages: the compiler deliberately writes machine results to stdout and SymfonyStyle diagnostics to stderr. Preserve validation/unexpected-error exit codes, per-component outcomes and verified artifact existence/hash. Redact credentials and internal sensitive paths before returning remote messages. Do not turn a nonzero exit or a missing archive into successful MCP output.

## 7. Package graph and lifecycle

The canonical 45 entities are recorded in jcb-surface.json. They cover components and linked admin/custom/site views, router/config/placeholders/updates/files/menus/dashboard/module/plugin relations; modules/plugins and associated rows; admin field/condition/relation/tab data; templates/layouts/dynamic gets/custom code; fields/validation/field types; libraries; class method/property/extends definitions; placeholders, powers, Joomla powers, repositories and snippets.

Forty entities use the Package factory. Fieldtype, Power, JoomlaPower, Repository and Snippet have specialized factories. File and Folder are additional asset-queue pseudo-entities, not evidence of separately registered commands. Consume JCB's canonical routing rather than duplicating its mappings in arbitrary handler switches.

Preserve `local`, `added`, `not_found` result categories, dependency/message queues, inbound reset traversal, repository selection and mirror behaviour. Get and Set builders may skip missing capabilities; report those explicitly. Resolve entity handlers and their tracker/message bus from the same native factory. In long-lived stdio/worker processes, use fresh or deliberately reset operation state and restore Joomla identity/input/configuration so one invocation cannot contaminate the next.

Plans must identify the selected definitions and the dependency closure/effective revisions relevant to their effects. Recheck before execution. Repository credentials stay in the existing server configuration; requests refer to authorized configured repository identities, not arbitrary URL/token pairs. A push affects external repositories and may not be undoable atomically. Keep per-stage evidence and reconciliation instructions.

### Pinned native filesystem reset limitation

At the pinned JCB revision, `Componentbuilder/Package/{File,Folder}/Remote/Config.php` defines an index containing only `name`, `path` and `guid`. The native writer's inherited `Abstraction/Remote/Base::getIndexItem()` emits that index, and `Package/GrepContent::getRemote()` adds the fetched content without a local destination. However, `Package/Remote/GetContent::reset()` calls `item()`, which requires the index's `value` and `target` before invoking the file/folder store. These paths are relative to `libraries/vendor_jcb/VDM.Joomla/src/`.

`tests/jcb-files.php` confirms this boundary using the pinned native writer, Grep and reader classes with only repository I/O substituted: both reset item helpers return false and leave divergent local bytes unchanged with the writer-generated index. Adding only the missing `value` and `target` makes the same native methods restore the file and folder; independent MCP byte verification then passes. Missing or divergent assets remain incomplete in MCP read-back and cannot establish verified package completion. The adapter preserves this native behaviour and does not rewrite upstream code or silently repair remote indexes.

The installed `tests/golden-image/package-roundtrip.php` proves get/init/pull/push/reset of a component and its linked definition dependency, including overwrite, remote failure and reconciliation. That result does not establish successful filesystem reset for native indexes missing destination metadata. Filesystem reset remains subject to this recorded upstream limitation; its required coverage is retained.

## 8. Durable jobs, artifacts and recovery

Version 0.1.1 extends execution/lease/audit persistence with normalized `job` and `artifact` tables and explicit MySQL/PostgreSQL migrations. Jobs are related to their execution and principal; artifacts retain their owned job reference. The following requirements remain the acceptance contract for the implemented services.

A job must retain an opaque ID, requesting principal, action/binding/definition revision, immutable validated input reference, approved effects, execution/idempotency key, worker/lease owner, timestamps, bounded progress, state and final/partial/uncertain outcome. Re-authorize relevant privileges at start and resource reads; an HTTP-created job never becomes unrestricted merely because a local worker runs it. Purely local jobs retain separately recorded server-owner provenance.

Use safe worker dispatch without arbitrary shell command construction. Preserve at-most-one execution claiming under concurrency, but do not promise exactly-once effects across a network failure. Cancellation means requested/acknowledged termination, not rollback. Expired worker leases and ambiguous native/remote results require inspection before retry, particularly compile-install and repository push.

Artifacts use server-owned IDs, authorized reads, MIME/size/hash/retention metadata and controlled paths; never publish arbitrary filesystem reads through tool arguments. Track generated archives, diagnostics and per-extension install results. Bound output and storage growth. Multiple PHP workers and restarts must preserve job visibility and state. Do not simply raise the current HTTP timeout until a compiler happens to finish.

## 9. Implementation sequence and acceptance

1. Complete the server's installable Joomla shell: manifest, administrator forms/configuration/access, installer and seed-upgrade verification, package/routing/plugin deployment and real core acceptance. Preserve current runtime commits.
2. Capture/pin JCB's actual API distribution and installed command registry. Expand the planning inventory into exact source-to-schema/action/binding records. Report source/version differences without deleting required surfaces.
3. Add JCB provider/schema/entity API bindings and ordinary reviewed native adapters. Test pagination, IDs/GUIDs, relationships/code fields, Joomla field/asset permissions and persisted CRUD read-back.
4. Add every registered package family/area binding, result categorization and dependency-aware plans/verification. Exercise configured test repositories, missing definitions, overwrites, dependency failures and remote partial outcomes.
5. Add full compiler options/target/install bindings, durable jobs/artifacts/cancellation/recovery and output verification. Verify native command registration order and process/factory isolation in the MCP plugin.
6. Extend administrator configuration, diagnostics, audit/job/artifact/reconciliation views and customization-preserving upgrades. JCB updates must invalidate stale definitions/plans without overwriting administrator customizations.
7. Run the complete installed Joomla/JCB matrix and then finalize the independent client's remote stdio/interoperability/release work against that stable server contract.

Acceptance compares **all actual API routes and registered JCB commands** against implemented database bindings; no unaccounted required surface, unreported no-op or count-only success. Test JCB absent, disabled, incompatible, installed and upgraded; preserve Joomla core regressions. Test two HTTP users with different groups/viewing levels/asset permissions, hidden direct calls, revoked grants, cross-principal jobs/artifacts, stale plans and changed compiler/repository configuration.

Use valid real entity fixtures, temporary repositories and generated package installation/read-back, including error and partial-effect cases, not only mocks or syntax. Exercise multiple requests/workers, stdio output contamination, option/ID validation, dependency queues, job cancellation/restart and cleanup. Record exact source/runtime versions and each capability's inventoried/mapped/implemented/unit-tested/live-tested status. Only mark the full JCB objective complete after this evidence exists.

## References

- https://github.com/extension-builder/joomla/tree/5ee658dd07eb749dca43ed4722f6cca7eb8208cf
- https://github.com/joomengine/jcb-documentation/blob/ecd3670232d344295fc4f673b2d3dc40a64b3bf6/english/CLI-Command-Suite.md
- https://github.com/extension-builder/joomla/blob/5ee658dd07eb749dca43ed4722f6cca7eb8208cf/libraries/vendor_jcb/VDM.Joomla/src/Componentbuilder/Factory.php
- https://github.com/extension-builder/joomla/blob/5ee658dd07eb749dca43ed4722f6cca7eb8208cf/libraries/vendor_jcb/VDM.Joomla/src/Componentbuilder/Console/Compiler.php
- https://github.com/extension-builder/joomla/blob/5ee658dd07eb749dca43ed4722f6cca7eb8208cf/docs/architecture/package-distribution.md
- https://github.com/extension-builder/joomla/blob/5ee658dd07eb749dca43ed4722f6cca7eb8208cf/docs/architecture/api-generation.md
- https://github.com/joomengine/pkg-component-builder/blob/6.x/pkg_component_builder.xml (inspected 18 September 2026; blob ea5f4cefcfbd43392c3f5f3b8163be8bc4f9b3f1)
