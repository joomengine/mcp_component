# MCP component architecture

## Authority and maintainability

Joomla's supported extension contracts are authoritative. The component follows the generated layout and MVC/form conventions of `joomengine/Joomla-Component-Builder`, branch `6.x`, so its application logic can subsequently be maintained in JCB. This is hand-authored, JCB-aligned source; it is not represented as an already imported or compiler-generated JCB blueprint.

The repository is the extension project root, not an extra `component/` or `administrator/components/com_mcp/` wrapper. Use root `mcp.xml`, `McpInstallerScript.php`, `admin/`, `api/`, `site/`, `media/`, changelogs, update metadata and `.octojpack`. Install destinations are declared in the manifest. Administration uses Joomla MVC factories, dependency injection, singular edit models/controllers, plural list models/controllers, XML forms, tables, layouts and language keys. Custom reusable logic belongs in services, not generated controllers or XML strings containing business logic.

## Repositories

- `joomengine/mcp_component`: `com_mcp`, administration, policy and consent persistence, audit, endpoint/application services, installation, update metadata and distribution packaging.
- `joomengine/mcp_plugin`: thin Joomla integration plugin; the component owns business logic. No duplicated policy, protocol or action engine in the plugin.
- `joomengine/joomla-mcp`: existing MCP contracts and native-first security reference. This work does not silently replace or modify its runtime or dependency PRs.

## Baseline under investigation

The existing MCP project's documented baseline is Joomla 6.1 and PHP 8.3, with Joomla 6.2 as a compatibility target. The two new repositories initially contain only a licence and README and no independent runtime specification. The existing semantic-action, Joomla ACL, explicit approval, bounded input and audit requirements are the compatibility reference while the implementation is developed. Additional unsupported action families must not be advertised as implemented.

## Security and release requirements

All remote execution must use authenticated Joomla identities, server-side ACL, an explicit allowlist and bounded validated input. Human consent cannot be inferred from client-supplied confirmation booleans. Privileged operations must not bypass Joomla native models or introduce arbitrary PHP, SQL, shell, filesystem, model-name or URL execution. Administrative mutations require Joomla CSRF validation. Sensitive credentials and raw request payloads must not enter audit output.

The release must include install/upgrade/uninstall handling, schema migrations, discoverable administrator views, complete language keys, standard update/changelog XML and reproducible component/plugin/package archives. Update feeds must not advertise release archives that have not been published.

Tests must distinguish contract/unit evidence from installation and real Joomla runtime evidence. Production readiness must not be claimed from syntax or mocked tests alone. Record actual test commands and outcomes in the pull request.

## Source references

- https://github.com/joomengine/Joomla-Component-Builder/tree/6.x
- https://github.com/joomengine/Joomla-Component-Builder/blob/6.x/.octojpack
- https://github.com/joomengine/joomla-mcp
- https://manual.joomla.org/docs/building-extensions/
