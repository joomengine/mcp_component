# JoomEngine MCP for Joomla

PHP implementation of an installable Joomla 6 MCP server with database-defined capabilities. The objective is full migration of `joomengine/joomla-mcp` **and complete first-class coverage of Joomla Component Builder's API and CLI surface**, not Joomla core alone.

**Component:** `com_joomengine_mcp`  
**Namespace:** `VDM\Component\JoomEngineMcp`  
**Console plugin:** `plg_console_joomengine_mcp` in [`joomengine/mcp_plugin`](https://github.com/joomengine/mcp_plugin)  
**External PHP client:** `joomengine/mcp-client` in [`joomengine/mcp_client`](https://github.com/joomengine/mcp_client)

## Repository boundary

This repository owns the installed server: catalogue, administrator MVC/forms, HTTP endpoint/routing glue, Joomla token/ACL integration, shared API/native execution, durable state, jobs and server distribution. Its composer.json installs **server dependencies**, under package identity `joomengine/mcp-component`. External MCP connection code, remote stdio bridging, client documentation and client releases belong exclusively to `mcp_client`; this server must not depend on that client package.

The component still needs an outbound HTTP adapter to call Joomla's API. That server infrastructure belongs in `admin/src/Http`, not a client library tree. See [client separation and handoff](docs/CLIENT-HANDOFF.md).

## Required capabilities

HTTP uses Joomla's authenticated API user and intersects component permissions, viewing-access levels, row assets and target-resource ACL. Local console execution is a separate trusted-server track; remote requests cannot opt into it. Published database records define schemas, actions, bindings, tools, resources and prompts, while reviewed injected PHP handlers execute them. Database definitions are not executable dispatch code.

JCB is a required integration alongside Joomla core. Cover every actual JCB API route and registered CLI command, including entity operations, fields/field types, compiler options and get/init/pull/push/reset package workflows. Follow the [JCB integration roadmap](docs/integrations/JCB.md) and its [source inventory](docs/integrations/jcb-surface.json). This integration is documented and inventoried in part; it is **not yet implemented by new active JCB seed rows**. Core Joomla must continue working on sites without JCB, but absence of JCB from a test fixture cannot be used to declare JCB coverage complete.

## Current status

Development remains on `feature/jcb-mcp-runtime`, draft PR #1. Runtime services, protocol adapters and SQL are committed; installer infrastructure has also been started. The complete component manifest, administrator application, distribution and real installed-site acceptance remain unfinished. [Implementation status](docs/IMPLEMENTATION.md) distinguishes implemented code from passing isolated tests and missing live evidence. No production release is advertised.

The original migration source is pinned at `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`. It remains unchanged. Joomla 6 native contracts are authoritative; repository/MVC/XML placement follows JCB's extension-root layout. This is hand-authored JCB-aligned source, not an already imported JCB blueprint.

Read [architecture](docs/ARCHITECTURE.md), [database design](docs/DATABASE.md), [migration plan](docs/MIGRATION.md), [security](SECURITY.md), and [agent instructions](AGENTS.md) before changing the runtime.
