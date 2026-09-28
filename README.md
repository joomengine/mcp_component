# JoomEngine MCP for Joomla

An installable PHP MCP server for Joomla 6.1–6.x, with database-defined tools, resources, prompts and actions, a native administrator application, Joomla API authentication and a shared local console runtime. Version 0.1.1 adds installed Joomla Component Builder discovery, confirmed background jobs and retained compiler artifacts. The current verification boundary is recorded in [implementation status](docs/IMPLEMENTATION.md).

**Component:** `com_joomengine_mcp`  
**Namespace:** `VDM\Component\JoomEngineMcp`  
**Console plugin:** `plg_console_joomengine_mcp` in [`joomengine/mcp_plugin`](https://github.com/joomengine/mcp_plugin)  
**External PHP client:** `joomengine/mcp-client` in [`joomengine/mcp_client`](https://github.com/joomengine/mcp_client)

## Repository boundary

This repository owns the installable component: catalogue, administrator MVC/forms, HTTP endpoint controllers, Joomla token/ACL integration, shared API/native execution, durable state and jobs. Its locked production dependencies are committed under `admin/vendor`; Composer is a maintainer tool, never an installation step. External MCP connection code, remote stdio bridging, client documentation and client releases belong exclusively to `mcp_client`.

The component's outbound Joomla API transport is implemented in `admin/src/Http`. See [client separation and handoff](docs/CLIENT-HANDOFF.md).

## Download and install

Download this repository using **Code → Download ZIP**, or download a release tag's source ZIP, and upload it in Joomla's extension installer. The source tree already contains its production PHP dependencies and installation data. No build, Composer run or repacking is required. The supported host is Joomla 6.1–6.x with PHP 8.3 or later and the extensions listed in `composer.json`.

Configure the canonical site API URL and Joomla permissions under **Components → JoomEngine MCP → Options**. The authenticated MCP endpoint is `/api/index.php/v1/joomengine-mcp`, relative to the Joomla installation. HTTP route registration comes from the separate [webservices plugin](https://github.com/joomengine/mcp_webservices); local console commands come from the [console plugin](https://github.com/joomengine/mcp_plugin). Install the [combined package](https://github.com/joomengine/mcp_package) to get all three extensions together.

For native JCB background jobs, provide a PHP CLI executable compatible with the installed Joomla version, with `pcntl_fork`, `posix_setsid` and `proc_open` available. Select its absolute path in the component's **PHP CLI binary** setting when it differs from the automatically detected PHP executable. The web-server account must be able to launch it and write the private artifact directory outside the served Joomla tree. Worker prerequisites are checked before a write is claimed. The golden-image Dockerfile demonstrates the required CLI extension setup; ordinary Joomla API operations remain available without that job runtime.

Remote AI applications can use the independent client's [Docker Compose launcher](https://github.com/joomengine/mcp_client/blob/feature/standalone-php-client/docs/DOCKER.md). Supply the HTTPS Joomla installation URL and native API token; the client discovers capabilities from this component. The console plugin is required for direct local Joomla MCP commands, while the remote client connects to the component's authenticated HTTP endpoint.

The combined package is built exclusively by [OctoJPack](https://github.com/octoleo/octojpack), using the fixed `.octojpack` configuration, and published to [mcp_package](https://github.com/joomengine/mcp_package). The component version determines the package version. Run the manual **Release** workflow to freeze the changelogs, tag the component, publish the package, update the package feed here, and let OctoShoom hash that package ZIP. [Release instructions](docs/RELEASE.md) list the required secrets.

## Required capabilities

HTTP uses Joomla's authenticated API user and intersects component permissions, viewing-access levels, row assets and target-resource ACL. Local console execution is a separate trusted-server track; remote requests cannot opt into it. Published database records define schemas, actions, bindings, tools, resources and prompts, while reviewed injected PHP handlers execute them. Database definitions are not executable dispatch code.

JCB remains a required integration alongside Joomla core. Install and enable JCB and its native command plugin, then synchronize actual installed contracts through the administrator Operations screen or local console:

```bash
php cli/joomla.php joomla:mcp:jcb-sync
```

Synchronization inspects JCB-owned API routes and registered command input definitions, then persists schemas, actions, bindings and targets. It preserves administrator customization and refuses unsupported contracts instead of silently omitting them. An absent API distribution produces no invented endpoints. Ordinary MCP discovery reads the stored catalogue and does not modify it. Repeat synchronization after changing JCB or its route/command plugins.

Synchronized definitions record their actual registration-plugin dependencies. Disabling or uninstalling that plugin hides its operations from discovery and direct execution without requiring a catalogue rewrite. Permission requests accept only currently authorized executable scopes, including installed provider scopes such as `jcb.execute`.

Package `get`, `init`, `pull`, `push`, `reset` and compilation are effectful operations requiring grants, plans and confirmation. Command options and definition/configuration fingerprints are frozen during planning. Execution uses isolated PHP workers and durable principal-owned jobs. Cancellation and uncertain outcomes retain recovery evidence; cancellation does not roll back side effects. Compiler archives become bounded, hash-verified artifact references. See the [JCB contract and acceptance matrix](docs/integrations/JCB.md).

Plans describe the selected native definitions, frozen option layers, repository identity, possible effects and revision fingerprints before approval. Credentials and server paths remain private. Native API validation failures retain bounded field and checkout diagnostics so clients can correct rejected input.

Joomla core remains usable without JCB. Remote HTTP and stdio clients retain their authenticated Joomla user's authority, including inside background workers. Only the genuine local console creates server-owner authority.

## Current status

Native administration, installed core tests, JCB synchronization and job/artifact runtime are implemented. [Implementation status](docs/IMPLEMENTATION.md) separates historical installed Joomla/JCB evidence from verification of the current source and release changes. Published tags identify component releases; package publication belongs to `joomengine/mcp_package`.

The original migration source is pinned at `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`. It remains unchanged. Joomla 6 native contracts are authoritative; repository/MVC/XML placement follows JCB's extension-root layout. This is hand-authored JCB-aligned source, not an already imported JCB blueprint.

Imported native handlers retain the original behavioural contracts and source attribution while following the JCB PHP style. The original implementation's deliberately unavailable operations remain explicit diagnostics; they are documented in the [migration provenance](docs/migration/README.md).

Read [architecture](docs/ARCHITECTURE.md), [database design](docs/DATABASE.md), [migration plan](docs/MIGRATION.md), [security](SECURITY.md), and [agent instructions](AGENTS.md) before changing the runtime.

See [CHANGELOG.md](CHANGELOG.md) for human-readable changes and [changelog.xml](changelog.xml) for Joomla's categorized changelog. Changes awaiting release use `[[[NEXT_VERSION]]]` in both files; the release workflow assigns their version.
