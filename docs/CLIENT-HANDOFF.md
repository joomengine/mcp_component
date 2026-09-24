# External client separation — 24 September 2026

## Ownership

The owner created `joomengine/mcp_client` for the external Composer client and remote stdio bridge. That repository exclusively owns package `joomengine/mcp-client`, namespace `VDM\Joomla\Mcp\Client`, client examples/tests, the `joomengine-mcp` executable and independent releases. Earlier statements assigning that work to this component are superseded.

This component owns server dependencies through composer.json (`joomengine/mcp-component`), not a distributable external client. The console plugin owns direct local server stdio only. Neither server repository depends on the client package. The client must not load this component's administrator classes, SQL or Joomla installation locally.

## Code disposition

At the inspected server head `a3fb48c680c520fe3b81c6e40fc8aa8cc427f36e`, no completed external MCP client existed to extract. The only generic library files were `libraries/src/Http/CurlClient.php` and `NetworkException.php`; RuntimeFactory uses that transport for server-side Joomla API forwarding.

The server transport has moved to `admin/src/Http` under `VDM\Component\JoomEngineMcp\Administrator\Http`; imports and Composer mapping use that boundary. The client maintains its separately namespaced transport and connection/SDK integration. The component has no external-client dependency.

## Public wire contract

The current webservices plugin registers `v1/joomengine-mcp`; external clients derive `<Joomla base URL>/api/index.php/v1/joomengine-mcp`, preserving subdirectories. HTTP authentication uses the site's Joomla API token and native Joomla identity/ACL. Tools, schemas, resources and prompts are server-discovered. The HTTP client does not require the console plugin; direct local console serving does.

A workstation stdio-to-HTTP bridge does not acquire the server's trusted CLI authority. JCB API and CLI-derived functionality must be represented by reviewed server bindings appropriate to that authority, with no generic HTTP-to-shell privilege bridge.

## Handoff and acceptance

The independent client provides the Composer SDK, remote stdio executable, Docker Compose URL/token launcher and private per-site token configuration. Shared installed tests pass against the real authenticated endpoint through both the SDK and executable, with trusted TLS and server-discovered contracts. The JCB golden-image run additionally passes actual package/compiler jobs, failure recovery and hash-verified artifact downloads through that generic HTTPS bridge; no client catalogue changes are required for synchronized JCB definitions. The client's PHP 8.3/8.4 container workflow separately verifies real Compose startup, TLS, authentication, discovery and session cleanup.

The component's [implementation status](IMPLEMENTATION.md) records server acceptance. Client checks and publishing automation remain in the client repository. Packagist publication and GitHub releases are intentional post-merge actions, not missing component source files.

Client plan: https://github.com/joomengine/mcp_client/pull/1
