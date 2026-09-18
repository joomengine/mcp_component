# External client separation — 18 September 2026

## Ownership

The owner created `joomengine/mcp_client` for the external Composer client and remote stdio bridge. That repository exclusively owns package `joomengine/mcp-client`, namespace `VDM\Joomla\Mcp\Client`, client examples/tests, the planned `joomengine-mcp` executable and independent releases. Earlier statements assigning that work to this component are superseded.

This component owns server dependencies through composer.json (`joomengine/mcp-component`), not a distributable external client. The console plugin owns direct local server stdio only. Neither server repository depends on the client package. The client must not load this component's administrator classes, SQL or Joomla installation locally.

## Code disposition

At the inspected server head `a3fb48c680c520fe3b81c6e40fc8aa8cc427f36e`, no completed external MCP client existed to extract. The only generic library files were `libraries/src/Http/CurlClient.php` and `NetworkException.php`; RuntimeFactory uses that transport for server-side Joomla API forwarding.

Move the required server transport to `admin/src/Http` under `VDM\Component\JoomEngineMcp\Administrator\Http`, update its imports and remove the old libraries/src autoload prefix. The client repository retains a separately namespaced transport foundation with the original licence/authorship plus its new Connection and SDK ClientFactory. This deliberate transport separation avoids a server dependency on its external client. Do not remove the server's necessary outbound API capability or claim an unimplemented client was moved wholesale.

## Public wire contract

The current webservices plugin registers `v1/joomengine-mcp`; external clients derive `<Joomla base URL>/api/index.php/v1/joomengine-mcp`, preserving subdirectories. HTTP authentication uses the site's Joomla API token and native Joomla identity/ACL. Tools, schemas, resources and prompts are server-discovered. The HTTP client does not require the console plugin; direct local console serving does.

A workstation stdio-to-HTTP bridge does not acquire the server's trusted CLI authority. JCB API and CLI-derived functionality must be represented by reviewed server bindings appropriate to that authority, with no generic HTTP-to-shell privilege bridge.

## Handoff and acceptance

The owner permits completing the component first and finalizing client interoperability afterwards. Stabilize installation, endpoint/authentication/protocol/version behaviour, grants, JCB jobs and artifacts; then run the shared live acceptance matrix from the standalone client. No client catalogue changes should be required when new JCB database definitions are added. Final remote stdio execution and stable Packagist release remain client tasks, not missing component source files.

Client plan: https://github.com/joomengine/mcp_client/pull/1
