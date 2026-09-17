# JoomEngine MCP for Joomla

A complete PHP migration of `joomengine/joomla-mcp`: an installable Joomla 6 component providing a database-driven Model Context Protocol endpoint, plus a reusable Composer client/stdio bridge. The companion console plugin lives in `joomengine/mcp_plugin`.

**Component:** `com_joomengine_mcp`  
**Namespace:** `VDM\Component\JoomEngineMcp`  
**Console plugin:** `plg_console_joomengine_mcp`  
**Plugin namespace:** `VDM\Plugin\Console\JoomEngineMcp`

## Delivery status

Development is on `feature/jcb-mcp-runtime`, pull request #1. Architecture documentation precedes runtime implementation. A checked-in declaration or passing syntax test is not proof of an implemented or live-tested Joomla operation. See [implementation status](docs/IMPLEMENTATION.md) for evidence and remaining work. No production release is advertised until installation, authentication, ACL, mutation/read-back, CLI and packaging checks pass.

## Design

- HTTP runs inside Joomla's API application, authenticates with Joomla API tokens, and intersects component permissions, row viewing access, operation ACL and Joomla resource permissions.
- Local console execution is a distinct trusted-server boundary. A remote request can never select, impersonate or enable this boundary.
- Published database records define discovery, schemas, actions, transport bindings, resources and prompts. Reusable, explicitly registered PHP handlers perform the work. Database content is never executable PHP, arbitrary SQL or shell commands.
- New component integrations using existing handlers require new validated database records, not changes to the MCP protocol server. Genuinely new execution primitives require a reviewed service-provider extension.
- The repository uses JCB's extension-root layout: `admin/`, `api/`, `site/`, `media/`, a root component manifest and installer, Joomla MVC/XML forms, changelog/update metadata and `.octojpack`.
- The Composer distribution and command-line bridge are PHP-only; installing or running the component must not require Node.js or TypeScript.

## Project contracts

Read [architecture](docs/ARCHITECTURE.md), [database design](docs/DATABASE.md), [migration and acceptance plan](docs/MIGRATION.md), [security boundaries](SECURITY.md), and [agent instructions](AGENTS.md) before changing runtime code.

The immutable migration source is `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`. Original licences and attribution must be retained. The source repository remains untouched.
