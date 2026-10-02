# Get started on an existing Joomla site

Install the stable [JoomEngine MCP package](https://github.com/joomengine/mcp_package/tags), configure Joomla, then connect an AI application or use the client directly. This guide covers the Joomla side; [client connections](CLIENT-CONNECTIONS.md) covers workstation installation, Claude and other stdio hosts, ChatGPT connection requirements, Docker and direct PHP tool calls.

## Install the package

Use **Joomla 6.1–6.x** with **PHP 8.3 or later** and the PHP extensions `curl`, `fileinfo`, `json`, `mbstring` and `sodium`. Production PHP dependencies are included in the extension ZIPs; Composer and Node.js are not installation steps on the Joomla server.

1. Open the [package tags](https://github.com/joomengine/mcp_package/tags) and download the ZIP for the latest stable version.
2. In Joomla administrator, open **System → Install → Extensions** and upload that package ZIP.
3. Confirm the installation completed, then open **Components → JoomEngine MCP**.

The package installs these three extensions together:

| Extension | Repository | Purpose |
| --- | --- | --- |
| `com_joomengine_mcp` | [mcp_component](https://github.com/joomengine/mcp_component) | Server, database catalogue, administrator interface, permissions, actions, jobs and artifacts |
| `plg_webservices_joomengine_mcp` | [mcp_webservices](https://github.com/joomengine/mcp_webservices) | Authenticated HTTP endpoint |
| `plg_console_joomengine_mcp` | [mcp_plugin](https://github.com/joomengine/mcp_plugin) | Direct local Joomla console commands and stdio server |

[Joomla Component Builder (JCB)](https://github.com/joomengine/pkg-component-builder) and the [external MCP client](https://github.com/joomengine/mcp_client) are separate installations. JCB is needed for JCB operations; Joomla core operations remain usable without it.

The package is refreshed by the component's release build using the tagged component and plugins. Prefer a stable package tag for installation and updates. Individual repository ZIPs remain installable for development or independent extension maintenance.

## Enable the plugins

Open **System → Manage → Plugins**. Check these entries:

| Plugin | Required for |
| --- | --- |
| **Web Services - JoomEngine MCP** (`webservices/joomengine_mcp`) | All remote HTTP clients and AI connections |
| **Console - JoomEngine MCP** (`console/joomengine_mcp`) | Direct commands on the Joomla server |
| **API Authentication - Web Services Joomla Token** (`api-authentication/token`) | Joomla API token authentication |
| **User - Joomla API Token** (`user/token`) | Creating and managing users' API tokens |

The MCP plugins are enabled on a fresh installation. Updates preserve their existing enabled or disabled state, so check them after an update if a connection is unavailable. Enable the Joomla core Web Services plugins for the resources you intend to use, such as **Web Services - Content** for articles.

The MCP plugins register routes and commands; configure the server in the **component Options**, and credentials in the **Joomla user's token profile** and **external client**.

## Configure the component

Open **Components → JoomEngine MCP → Options → Endpoint and limits**.

| Setting | What to enter |
| --- | --- |
| **Canonical Joomla API URL** | The trusted HTTPS URL of this installation ending in `/api/index.php`. Include any Joomla subdirectory. |
| **Site alias** | Keep `default` initially. This is the server's site identity in tool arguments and grants. |
| **Allowed browser origins** | Only explicit origins needed by a browser client, one per line, such as `https://console.example.com`. No paths or wildcards. Non-browser clients can omit `Origin`; the site's own origin is allowed automatically. |
| **PHP CLI executable** | Leave blank for ordinary Joomla API use. For JCB background jobs, set an absolute executable path if automatic detection does not find the compatible PHP CLI. |
| **Private artifact directory** | Optional existing writable directory outside the public Joomla installation. Blank uses the system temporary directory. |
| Other limits and lifetimes | Keep the defaults initially. Indefinite grants are disabled by default. |

For a Joomla installation at `https://example.com/joomla`, the three addresses have different purposes:

| Address | Use |
| --- | --- |
| `https://example.com/joomla` | Site base URL supplied to `mcp_client` |
| `https://example.com/joomla/api/index.php` | Component's **Canonical Joomla API URL** |
| `https://example.com/joomla/api/index.php/v1/joomengine-mcp` | MCP Streamable HTTP endpoint |

Save the options. The canonical HTTPS host must be reachable both by the client and by Joomla's own API forwarding. A reverse proxy must preserve the public host and forward authentication headers. Production configuration requires HTTPS; see [client connections](CLIENT-CONNECTIONS.md) for local development with a trusted certificate.

## Create an API identity and token

Use a Joomla account whose permissions match the work you intend to expose.

1. In **Users**, create or select the account and its user group. The account must be active and unblocked.
2. In **User - Joomla API Token**, set **Allowed User Groups** to include that account's group. Joomla defaults this setting to Super Users; a dedicated group can be configured instead.
3. In **System → Global Configuration → Permissions**, allow that group **Web Services Login** (`core.login.api`).
4. In **JoomEngine MCP → Options → Permissions**, allow **Access the MCP endpoint** (`mcp.access`) and **Discover and execute definitions** (`mcp.execute`).
5. Grant the native Joomla permissions for the intended resource. For example, article access still follows `com_content` permissions. Also check the provider/definition **Viewing access level** and asset permissions.
6. Log in as that account and edit its own profile. If no token exists yet, save the profile and reopen it to generate one. Open **Joomla API Token**, set **Active** to **Yes** and save, then copy your own **Token**.

Joomla only displays your own token. An administrator editing another account can reset or disable its token but cannot copy it. Configure the client with this token using the private configuration procedure in [client connections](CLIENT-CONNECTIONS.md); never commit it to a repository.

For confirmed writes, the identity also needs **Request confirmed mutations** (`mcp.write`), the target resource's create/edit/delete permission, and **Approve requested MCP write grants** (`mcp.approve`) to approve its own permission requests. Administrator catalogue editing additionally requires native administration permissions. A grant cannot extend any Joomla permission.

## Connect and make a first read

Follow [client connections](CLIENT-CONNECTIONS.md) to install `mcp_client` on the workstation or bridge host and provide the **site base URL** and **API token**. The client discovers the server's tools, resources and prompts. Its configuration nickname is local to the client; an optional tool `site` argument must use the component's **Site alias**, not a different client nickname.

Choose the connection supported by your application:

- An AI host with local MCP stdio support can launch `joomengine-mcp serve` through its MCP server configuration.
- A host with Streamable HTTP and configurable token headers can connect to the authenticated endpoint.
- A cloud chatbot that cannot launch local commands or supply the required authentication needs a compatible bridge or adapter. Connection support depends on the host; a static Joomla token is not an OAuth login flow.
- Without an AI host, use the [direct PHP client example](CLIENT-CONNECTIONS.md#use-tools-directly-without-an-ai) or the local console below.

Once connected, use this small discovery and read sequence. These are MCP tool names and JSON arguments, usable through an AI host or a direct client.

| Step | Tool or MCP method | Arguments |
| --- | --- | --- |
| Discover available tools | `tools/list` | Follow `nextCursor` until all pages are read |
| Check the installation identity | `joomla_sites_list` | `{}` |
| Inspect the available catalogue | `joomla_capabilities` | `{}` |
| Find article actions | `joomla_actions_search` | `{"text":"articles"}` |
| Read the exact action contract | `joomla_action_describe` | `{"action":"content.articles.list"}` |
| Read a small article page | `joomla_content_articles_list` | `{"offset":0,"limit":5}` |

Successful identity/capability results and an article list establish discovery and resource access. An empty article list is a valid result on a fresh site. Discovery is filtered by installed extensions, publication, viewing levels and the current user's permissions; another identity may see a different catalogue.

Use the discovered schema for each call. Send JSON objects as objects (`{}`), preserve arrays as arrays, and use the exact action identifier returned by search. Generic `joomla_action_read` executes a discovered read action with its action-specific `input`. Inspect the action description first: some native GET operations also perform Joomla bookkeeping.

## Use the administrator areas

Open **Components → JoomEngine MCP** to manage what the server exposes.

| Area | What it controls |
| --- | --- |
| **Providers** | Integrations and required extension dependencies; publication, viewing access and permissions |
| **Schemas** | Reusable JSON input and output contracts |
| **Actions** | Semantic operations, domain, effect, risk and permission scope |
| **Bindings** | Reviewed API or local CLI execution track, handler and mappings for an action |
| **Tools** | Published MCP entry points and their schema/handler configuration |
| **Resources** | Readable MCP documents identified by URI or URI template |
| **Prompts** | Administrator-defined prompt templates; the initial shipped catalogue has no prompts |
| **Targets** | Registered local console command metadata and availability |
| **Operations** | Executions, jobs, artifacts, permission grants and audit metadata; authorized recovery controls |
| **Options** | Endpoint, limits, worker settings and component permissions |

The **Tools** screen edits definitions. Run tools through an MCP client or the local console. Read resources using MCP `resources/list` and `resources/read`; the shipped core catalogue URI is `joomla://catalog/core`. Discover published prompts using `prompts/list` and retrieve them with `prompts/get` when configured.

Joomla **Users → Groups** and **Users → Viewing Access Levels** determine identity and visibility. Providers organize integrations, while action **Permission scope** values group write permissions; these are distinct from Joomla user groups.

Ordinary use requires no catalogue edits. Changing a shipped definition marks it as customized; seed updates and JCB refresh preserve such changes. Unpublishing a provider or definition removes affected capabilities from discovery and execution. Definitions select registered handlers and contain data; they cannot introduce an arbitrary PHP or shell executor. See [database design](DATABASE.md) before extending the catalogue.

In **Operations**, authorized administrators can revoke grants, request job cancellation and reconcile uncertain executions after inspecting the actual effects. Inspection needs `core.manage` and `mcp.audit`; revocation needs `mcp.grants`, and cancellation/reconciliation needs `mcp.reconcile`. Reconciliation records your assessment and releases the retained lock; it does not replay or undo the operation.

## Plan and confirm writes

Remote writes use a separate grant and confirmation flow:

1. Discover and describe the write action to read its schema, permission scope and effects.
2. Optionally call `joomla_action_write_plan` with `dryRun: true` and a UUID `idempotencyKey`. This preview performs no mutation and returns no confirmation token.
3. Check `joomla_permissions_list`. If the required scope has no usable grant, call `joomla_permission_request` with that scope, a reason and duration `once` or `30-minutes`.
4. Show the returned request and exact `acknowledgement` to the operator. Call `joomla_permission_approve` with its `requestId` and the exact phrase only after the operator supplies it.
5. Call `joomla_action_write_plan`, or the discovered typed plan tool, with the reviewed input and the operation's UUID `idempotencyKey`. Inspect the returned plan, effects and expiry.
6. After confirmation, call `joomla_write_apply` with that plan's `confirmationToken`.
7. Inspect the returned verification and read back the affected record. Revoke temporary grants with `joomla_permission_revoke` when finished.

Plans bind the identity, input, definitions and relevant state. Expired or changed plans require a fresh plan. Retain the original idempotency key and execution evidence for the same operation; creating a new key after a lost response can duplicate a write. Inspect **Operations** and any owned job before retrying an uncertain result.

## Enable JCB operations and background jobs

Install and enable [JCB](https://github.com/joomengine/pkg-component-builder) and its native command plugin separately. If you use JCB API operations, install/enable the JCB webservices distribution that actually supplies those routes. The MCP package contains its own component and plugins, not JCB.

Refresh the installed definitions with **Operations → Refresh JCB definitions**, or run this from the Joomla installation root:

```bash
php cli/joomla.php joomla:mcp:jcb-sync
```

Administrator refresh requires native administration permission on both `com_joomengine_mcp` and `com_componentbuilder`. Repeat it after changing JCB or its route/command plugins. Refresh inspects the actual installed contracts and preserves administrator customization. A distribution without JCB API routes yields no invented API endpoints; disabling a registration plugin makes its affected operations unavailable.

Package `get`, `init`, `pull`, `push`, `reset` and compilation have effects. Discover their current schemas and scopes, then use the grant, plan and confirmation flow. Do not treat package `get` as a normal read.

For background operations, the server needs a compatible PHP CLI with `pcntl_fork`, `posix_setsid` and `proc_open` available. The web-server account must be able to launch it and write private artifact storage. Restricted shared hosting can still use ordinary Joomla API operations when these worker facilities are unavailable.

An accepted background execution returns a job reference, not proof that compilation or a repository operation has finished. Use `joomla_job_status` with the returned `jobId`; list retained output with `joomla_job_artifacts` and read bounded chunks with `joomla_job_artifact_read`. Preserve the returned size and SHA-256 verification when assembling downloads. Jobs and artifacts remain owned by the requesting identity.

Use `joomla_job_cancel` to request cancellation. Queued cancellation prevents execution; running cancellation may leave partial effects. `joomla_job_redispatch` applies only to never-started queued jobs using the existing claim. Inspect uncertain/partial results before further writes. See [JCB contracts and current acceptance boundaries](integrations/JCB.md) for native limitations and tested scenarios.

## Use the local Joomla console without an AI

With server shell access, run these from the Joomla installation root:

```bash
php cli/joomla.php joomla:mcp:self-test
php cli/joomla.php joomla:mcp:describe
php cli/joomla.php joomla:mcp:cli-inventory
```

Execute a read through the component's local dispatch interface:

```bash
php cli/joomla.php joomla:mcp:dispatch --input=- --format=json <<'JSON'
{"protocol":"joomla-mcp/1","id":"system-check","action":"system.info","input":{}}
JSON
```

For a local MCP stdio host, use `php cli/joomla.php joomla:mcp:serve`. That process waits for MCP messages on stdin. This is direct trusted server access; the external client's remote stdio bridge retains the API user's permissions and does not acquire local console authority.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Installation rejected | Joomla/PHP versions, required PHP extensions and that the ZIP is the package tag download |
| HTTP 404 at the MCP endpoint | Package/component installed, **Web Services - JoomEngine MCP** enabled, correct subdirectory and proxy routing |
| HTTP 401 / token rejected | Both Joomla token plugins enabled, allowed user group, active account and token, `core.login.api`, and token headers forwarded |
| HTTP 403 / denied or missing capability | Canonical host, explicit browser Origin, `mcp.access`/`mcp.execute`, viewing levels, row/provider assets, target-resource ACL and required extension plugins |
| Endpoint not configured / HTTP 503 | Save **Canonical Joomla API URL**, ending in `/api/index.php` |
| AI host cannot connect | Supported transport/authentication, client logs and [host connection requirements](CLIENT-CONNECTIONS.md) |
| JCB actions absent | JCB and its registration plugins enabled; refresh definitions after installation/update |
| Background worker unavailable | Compatible CLI executable, process/pcntl/POSIX support, launch permissions and private storage; leave optional fields blank for API-only use |
| Lost write response / uncertain execution | Inspect owned job and **Operations → Executions** before retrying; record inspected effects through authorized reconciliation |

For resource-specific behavior, read [custom fields](CUSTOM-FIELDS.md), [native Joomla API limitations](testing/native-api-limitations.md), [implementation evidence](IMPLEMENTATION.md) and [security](../SECURITY.md).
