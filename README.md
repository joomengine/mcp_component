# JoomEngine MCP for Joomla

The actively maintained PHP successor to the original [Joomla MCP TypeScript proof of concept](https://github.com/joomengine/joomla-mcp). This is the project's **fourth MCP build/iteration**, with expanded functionality, native Joomla administration, database-defined capabilities and Joomla Component Builder (JCB) integration. Its stable package is distributed alongside JCB and designed to work with it. “Fourth build” describes the project's evolution; release tags use their own version numbers.

**Start with the [stable MCP package](https://github.com/joomengine/mcp_package/tags).** Install its ZIP on your Joomla site to get the component, HTTP webservices plugin and local console plugin together. Then use the independent [MCP client](https://github.com/joomengine/mcp_client) to connect an AI host or call tools directly.

## Start here

| Task | Guide |
| --- | --- |
| Install on an existing Joomla site; enable plugins; configure URL, token and permissions | [Getting started](docs/GETTING-STARTED.md) |
| Connect Claude, ChatGPT or another compatible AI host; use Docker or the PHP client without an AI | [Client connections](docs/CLIENT-CONNECTIONS.md) |
| Understand administrator areas and manage the catalogue | [Administrator areas](docs/GETTING-STARTED.md#use-the-administrator-areas) |
| Read tools, plan confirmed writes and inspect jobs | [First read](docs/GETTING-STARTED.md#connect-and-make-a-first-read), [write flow](docs/GETTING-STARTED.md#plan-and-confirm-writes), [jobs](docs/GETTING-STARTED.md#enable-jcb-operations-and-background-jobs) |
| Set up JCB capabilities or use the local Joomla console | [JCB setup](docs/GETTING-STARTED.md#enable-jcb-operations-and-background-jobs), [console use](docs/GETTING-STARTED.md#use-the-local-joomla-console-without-an-ai) |
| Check an installation or connection problem | [Troubleshooting](docs/GETTING-STARTED.md#troubleshooting) |

Support requires **Joomla 6.1–6.x**, **PHP 8.3 or later**, and the PHP extensions listed in [composer.json](composer.json). Production dependencies and installation data are already included; no Composer, Node.js or build step is required on the Joomla server.

## How the repositories fit together

| Repository | Role |
| --- | --- |
| [mcp_package](https://github.com/joomengine/mcp_package) | Recommended installable bundle of the three Joomla extensions below; refreshed by release builds |
| **mcp_component** (this repository) | `com_joomengine_mcp`: server, catalogue, administrator application, authentication/ACL, actions, durable plans/jobs and artifacts |
| [mcp_webservices](https://github.com/joomengine/mcp_webservices) | `plg_webservices_joomengine_mcp`: registers the authenticated HTTP endpoint |
| [mcp_plugin](https://github.com/joomengine/mcp_plugin) | `plg_console_joomengine_mcp`: direct local Joomla console commands and stdio |
| [mcp_client](https://github.com/joomengine/mcp_client) | External PHP SDK and remote stdio bridge for AI hosts and direct tool calls |
| [joomla-mcp](https://github.com/joomengine/joomla-mcp) | Original TypeScript proof of concept; new users should follow the package and guides above |

JCB is a separate installation. The MCP package contains its own component and plugins; the client runs on the workstation or bridge host. Remote HTTP clients do not require server shell access.

Configure **Canonical Joomla API URL** under **Components → JoomEngine MCP → Options** with the site's HTTPS URL ending in `/api/index.php`. The authenticated MCP endpoint adds `/v1/joomengine-mcp`, preserving any Joomla subdirectory. Supply the site base URL and a native Joomla API token to `mcp_client`. See [getting started](docs/GETTING-STARTED.md) for the complete setup.

## Capabilities and authority

Published database records define providers, schemas, actions, bindings, tools, resources, prompts and targets. Reviewed PHP handlers execute them; database definitions are not executable dispatch code. The administrator application manages these definitions and exposes execution, grant, audit, job and artifact metadata through **Operations**.

HTTP uses Joomla's authenticated API user and intersects component permissions, viewing-access levels, row assets and target-resource ACL. Remote stdio through the external client retains that identity. Direct local Joomla console execution is a separate trusted server track; remote requests cannot select it. Confirmed remote writes require explicit grants, reviewed plans, confirmation, idempotency and read-back. See [security](SECURITY.md).

Joomla core remains usable without JCB. JCB is a required project integration alongside Joomla core. For JCB capabilities, install and enable JCB and its native registration plugins. Synchronize installed component APIs and JCB commands using **Operations → Refresh JCB definitions** or:

```bash
php cli/joomla.php joomla:mcp:jcb-sync
```

Synchronization inspects registered routes for enabled installed third-party components under native administration permission, plus JCB command input definitions when JCB and its command plugin are available. Joomla's native core-extension inventory identifies core components, which retain their existing catalogue; the MCP component is also excluded. Extension protection/locking flags do not determine API permissions. Synchronization persists component-owned schemas, actions, bindings and targets, preserves administrator customization and reports unsupported contracts. An absent API distribution produces no invented endpoints. Ordinary MCP discovery reads the stored catalogue and does not modify it. Repeat synchronization after changing a component or its route/command plugins. Disabling or uninstalling a required registration plugin hides its affected operations from discovery and execution.

Generated API bindings describe literal native forms, including defaults, conditional rules, relationships and subforms; they support reviewed numeric, GUID and unique-key item routes. Defaults are descriptive and omitted PATCH fields stay omitted. Plans disclose and freeze an automatically generated primary GUID only when the installed create form explicitly requires it without a native default or detected server generation. Independent API read-back verifies resource identity and declared observable values. Native validation, ACL and errors remain authoritative.

JCB package `get`, `init`, `pull`, `push`, `reset` and compilation are effectful operations. Plans freeze inputs, options and relevant definition/configuration fingerprints. Native background execution uses isolated PHP workers and durable principal-owned jobs; compiler archives become bounded, hash-verified artifact references. Cancellation and uncertain outcomes retain recovery evidence; cancellation does not roll back side effects. [JCB setup](docs/GETTING-STARTED.md#enable-jcb-operations-and-background-jobs) covers worker prerequisites; the [JCB contract and acceptance matrix](docs/integrations/JCB.md) records tested scenarios and native limitations.

## Source, maintenance and release

This repository owns the installable component, namespace `VDM\Component\JoomEngineMcp`. Its locked production dependencies are committed under `admin/vendor`; Composer is a maintainer tool. External connection code, remote stdio bridging, client documentation and releases belong to `mcp_client`, package `joomengine/mcp-client`. The server's outbound Joomla API transport lives in `admin/src/Http`. See [client separation and handoff](docs/CLIENT-HANDOFF.md).

For development or independent component maintenance, a GitHub source ZIP of a reviewed branch or release tag installs directly in Joomla. Installing this component alone does not install either plugin; use the combined package for a complete server installation.

[OctoJPack](https://github.com/octoleo/octojpack) builds the combined package using the fixed `.octojpack` configuration and each extension's latest tag, then publishes it to `mcp_package`. The component version determines the package version. The manual **Release** workflow freezes component changelogs, creates its immutable tag, updates and hashes its own feed with OctoShoom, then publishes the package. The package tag starts its separate feed/hash workflow. [Release instructions](docs/RELEASE.md) cover workflow order and secrets.

Native administration, installed core tests, JCB synchronization and job/artifact runtime are implemented. [Implementation status](docs/IMPLEMENTATION.md) separates historical installed Joomla/JCB evidence from verification of current source and releases. Package availability does not enlarge those runtime-specific verification boundaries. The generic adapter supports installed component APIs, native form contracts and GUID/unique-key routes. The schema-only upgrade regression has 130 behavioral checks, invoked by the existing catalogue CI suite; readiness requires the current PR head to pass all checks. Fresh live generated JCB GUID CRUD acceptance and four historical native POST failures remain explicit evidence boundaries.

The original migration snapshot is pinned at `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`. Imported native handlers retain behavioral contracts and source attribution. Joomla 6 native contracts are authoritative; repository/MVC/XML placement follows JCB's extension-root layout. This is hand-authored JCB-aligned source, not a claim of an imported JCB blueprint. Explicitly unavailable inherited operations are recorded in [migration provenance](docs/migration/README.md).

Before changing runtime code, read [architecture](docs/ARCHITECTURE.md), [database design](docs/DATABASE.md), [migration plan](docs/MIGRATION.md), [security](SECURITY.md) and [agent instructions](AGENTS.md). User-facing resource details include [custom fields](docs/CUSTOM-FIELDS.md) and [native Joomla API limitations](docs/testing/native-api-limitations.md).

Changes are recorded in [CHANGELOG.md](CHANGELOG.md) and [changelog.xml](changelog.xml). Pending entries use `[[[NEXT_VERSION]]]`; the release workflow assigns their version.
