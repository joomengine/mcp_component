# JoomEngine MCP for Joomla

An installable PHP MCP server for Joomla 6.1–6.x, with database-defined tools, resources, prompts and actions, a native administrator application, Joomla API authentication and a shared local console runtime. Version 0.1.1 adds installed Joomla Component Builder discovery, confirmed background jobs and retained compiler artifacts. The current verification boundary is recorded in [implementation status](docs/IMPLEMENTATION.md).

**Component:** `com_joomengine_mcp`  
**Namespace:** `VDM\Component\JoomEngineMcp`  
**Console plugin:** `plg_console_joomengine_mcp` in [`joomengine/mcp_plugin`](https://github.com/joomengine/mcp_plugin)  
**External PHP client:** `joomengine/mcp-client` in [`joomengine/mcp_client`](https://github.com/joomengine/mcp_client)

## Repository boundary

This repository owns the installed server: catalogue, administrator MVC/forms, HTTP endpoint/routing glue, Joomla token/ACL integration, shared API/native execution, durable state, jobs and server distribution. Its composer.json installs **server dependencies**, under package identity `joomengine/mcp-component`. External MCP connection code, remote stdio bridging, client documentation and client releases belong exclusively to `mcp_client`; this server must not depend on that client package.

The component's outbound Joomla API transport is implemented in `admin/src/Http`. See [client separation and handoff](docs/CLIENT-HANDOFF.md).

## Build and install

Use PHP 8.3 or later and Composer 2 with the extensions in `composer.json`, plus DOM, SimpleXML and ZIP for packaging:

```bash
bash tools/build.sh
bash tools/build-distribution.sh
php tests/package.php --distribution
php tests/release.php
```

Install `build/pkg_joomengine_mcp-0.1.1.zip` through Joomla's extension installer. It includes the component, its owned webservices routing plugin and the thin console plugin. The component ZIP also supports an HTTP-only installation. Both include the PHP server dependencies; Composer is unnecessary on the Joomla server. Configure the canonical site API URL and Joomla permissions under **Components → JoomEngine MCP → Options**. The authenticated MCP endpoint is `/api/index.php/v1/joomengine-mcp`, relative to the Joomla installation.

The combined package pins the console plugin by immutable Git commit in `distribution.lock.json`. [Release instructions](docs/RELEASE.md) describe reproducibility, update feeds, `.octojpack` and manual main-only publication. Development builds do not publish releases.

## Required capabilities

HTTP uses Joomla's authenticated API user and intersects component permissions, viewing-access levels, row assets and target-resource ACL. Local console execution is a separate trusted-server track; remote requests cannot opt into it. Published database records define schemas, actions, bindings, tools, resources and prompts, while reviewed injected PHP handlers execute them. Database definitions are not executable dispatch code.

JCB remains a required integration alongside Joomla core. Install and enable JCB and its native command plugin, then synchronize actual installed contracts through the administrator Operations screen or local console:

```bash
php cli/joomla.php joomla:mcp:jcb-sync
```

Synchronization inspects JCB-owned API routes and registered command input definitions, then persists schemas, actions, bindings and targets. It preserves administrator customization and refuses unsupported contracts instead of silently omitting them. An absent API distribution produces no invented endpoints. Ordinary MCP discovery reads the stored catalogue and does not modify it. Repeat synchronization after changing JCB or its route/command plugins.

Package `get`, `init`, `pull`, `push`, `reset` and compilation are effectful operations requiring grants, plans and confirmation. Command options and definition/configuration fingerprints are frozen during planning. Execution uses isolated PHP workers and durable principal-owned jobs. Cancellation and uncertain outcomes retain recovery evidence; cancellation does not roll back side effects. Compiler archives become bounded, hash-verified artifact references. See the [JCB contract and acceptance matrix](docs/integrations/JCB.md).

Joomla core remains usable without JCB. Remote HTTP and stdio clients retain their authenticated Joomla user's authority, including inside background workers. Only the genuine local console creates server-owner authority.

## Current status

Development remains on `feature/jcb-mcp-runtime` and existing PR #1. Native administration, complete component packaging, combined distribution, installed core tests, JCB synchronization and job/artifact runtime are implemented. [Implementation status](docs/IMPLEMENTATION.md) separates implemented source, isolated checks and current installed Joomla/JCB acceptance. Release availability is established by GitHub releases, not the development branch.

The original migration source is pinned at `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`. It remains unchanged. Joomla 6 native contracts are authoritative; repository/MVC/XML placement follows JCB's extension-root layout. This is hand-authored JCB-aligned source, not an already imported JCB blueprint.

Read [architecture](docs/ARCHITECTURE.md), [database design](docs/DATABASE.md), [migration plan](docs/MIGRATION.md), [security](SECURITY.md), and [agent instructions](AGENTS.md) before changing the runtime.
