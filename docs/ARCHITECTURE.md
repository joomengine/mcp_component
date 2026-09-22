# Architecture — JoomEngine MCP

## Scope, identities and ownership

`com_joomengine_mcp` uses `VDM\Component\JoomEngineMcp` with Joomla's Administrator, Site and Api suffixes. `plg_console_joomengine_mcp` uses `VDM\Plugin\Console\JoomEngineMcp` in `joomengine/mcp_plugin`. This repository owns the installed server and its Composer-managed server dependencies only. External PHP client/remote stdio work belongs to `joomengine/mcp_client`; see CLIENT-HANDOFF.md.

The delivery target includes all preserved Joomla core MCP capabilities **and the complete JCB API and CLI surface**. Read integrations/JCB.md for the source-pinned integration and remaining inventory/implementation work. JCB is optional on a particular installation, but its full support is mandatory for completion of this project.

Joomla 6 contracts are authoritative. Repository placement follows JCB: root `joomengine_mcp.xml`, installer, `admin/`, `api/`, `site/`, `media/`, changelog/update metadata and `.octojpack`. Administrator code uses native MVCFactory, src/Controller/Model/View/Table, forms, tmpl, layouts, language, services/provider.php, access.xml/config.xml and versioned SQL. This is hand-authored JCB-aligned source, not an imported blueprint.

## HTTP path

AI/MCP client → Joomla API entry point → Joomla API-token authentication → non-public MCP route → component controller/transport boundary → protocol engine → database catalogue/authorizer → reviewed action binding → Joomla/JCB API or explicitly authorized native service → verification/audit → JSON-RPC response.

Joomla route registration requires a webservices plugin. Minimal `webservices/joomengine_mcp` glue is bundled with the component and is distinct from the console plugin. Install/enable it through the native installer, preserve operator state on updates and remove only owned glue on uninstall. An API directory alone does not register routes.

Reuse Joomla token authentication, not a second credential store. Verify both supported Joomla token header forms against the installed API authentication plugin. Static token authentication is not OAuth discovery; do not advertise an OAuth authorization server that is not implemented.

Outbound API calls use a server-configured canonical same-site origin, reviewed relative routes, bounded bytes/time, verified TLS and no redirects. They preserve the caller's token and target ACL. This server-only HTTP adapter lives in admin/src/Http. HTTP requests cannot invoke the trusted local CLI context. JCB command jobs use a reviewed service boundary that retains and rechecks the original API user's authority inside the worker.

## Trusted console path

Joomla console application → console plugin → CLI-only composition root → shared database catalogue/engine → reviewed Joomla/JCB native or registered-console adapter.

The owner explicitly defines server possession as the local authority boundary. No API token or row viewing permission is needed locally, but input/schema validation, bounded execution, provenance, grants/action semantics, verification and recovery remain. HTTP data cannot construct that authority. Wire transport and execution authority are different concepts: a remote stdio bridge is still an HTTP-token-restricted client.

JCB command registration must remain owned by its installed console plugin. The MCP adapter discovers and invokes exact reviewed command objects with typed arguments after registration is complete, preserving names, stdout/stderr and exit/result semantics. Definitions and handlers remain shared with the component; do not duplicate the JCB catalogue in the console plugin.

## Database-defined capabilities and JCB

Published provider, schema, action, binding, tool, resource, prompt and CLI-target records define discovery. Explicit DI services implement reusable primitives. Action/binding records carry schemas, effects, permissions, compatibility and mappings; no row can instantiate arbitrary code. Existing primitives enable extension by validated rows. New primitives require reviewed handler services before corresponding rows can become executable.

The JCB provider uses `com_componentbuilder` dependency/version metadata and stable source identities. Reuse schemas and binding mechanics across actual routes and registered command families. Do not generate routes from table names or a get/init/pull/push/reset Cartesian product from the package entity map. Source inventory, runtime availability and tested support are separate. Missing/disabled JCB hides its runnable definitions without harming Joomla core.

Viewing levels resolve groups through Joomla; each editable row has an asset_id. Provider state, record publication, authorized view levels, asset/action ACL, handler availability, target ACL, version compatibility and track are independent checks. Apply the same predicate to discovery/search/describe/direct calls and recheck at execution.

## Protocol, durable state and long-running work

Advertise only protocol revisions/features actually implemented and tested. The existing PHP SDK integration supports multiple protocol eras; do not mix legacy session/initialize requirements with the stateless revision. Optional SSE subscriptions, notifications and tasks must not be advertised merely because a dependency offers them. Stdio stdout contains only protocol frames.

Plans, grants, idempotency, locks and audit persist in the database across PHP workers. Approvals bind principal, action, canonical validated input, definition revision and expiry. Atomically claim one-shot state, re-authorize before execution and preserve ambiguous/partial effects for reconciliation instead of replaying writes.

JCB compilation and dependency synchronization use durable owned jobs, one-use worker tickets, lease state, progress, cancellation and artifact IDs/hashes. A timed-out connection is neither cancellation nor rollback. Planning freezes native options/environment values and definition/configuration fingerprints; separate PHP execution processes isolate mutable JCB containers. Expired or ambiguous work remains uncertain for reconciliation; only never-started queued jobs can be redispatched. Artifacts are retained privately with ownership, bounded byte reads, chunk integrity and expiry enforcement.

## Administrator experience

Native singular edit/plural list screens cover providers, schemas, actions, bindings, tools, resources, prompts and CLI targets, including relationships/subforms, filtering, ordering, publication, access/assets, validation and checkout. Provide audit/execution/job inspection, grant revocation and uncertain-execution reconciliation. Keep reusable logic out of generated-style controllers and use native language/Web Asset Manager conventions.

JCB source-code fields remain permitted inert definition data under appropriate permissions. They are not executable MCP dispatch strings. Compiling/installing extensions or running JCB hooks is a separate high-risk permission and confirmation boundary.

## Distribution and references

The builder produces the component (with routing glue/dependencies), console plugin and combined Joomla package. External client packaging remains independent. Native installer preflight, MySQL/MariaDB and PostgreSQL updates, customization-preserving seeds, changelog/update metadata, checksums and .octojpack are included. The distribution lock pins compatible component/plugin versions and immutable console source. Release automation publishes feeds only after verifying published archives. See RELEASE.md.

- Original MCP: https://github.com/joomengine/joomla-mcp/tree/2cff50f4f6b440da3c684f9995a77efad32e1a36
- Joomla/JCB layout: https://github.com/joomengine/Joomla-Component-Builder/tree/6.x
- JCB required integration: https://github.com/extension-builder/joomla/tree/5ee658dd07eb749dca43ed4722f6cca7eb8208cf
- CLI documentation: https://github.com/joomengine/jcb-documentation/blob/ecd3670232d344295fc4f673b2d3dc40a64b3bf6/english/CLI-Command-Suite.md
- PHP style: https://github.com/extension-builder/joomla/blob/main/docs/development/php-code-style.md
