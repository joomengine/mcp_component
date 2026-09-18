# Changelog

## Unreleased

### Added

- Documentation-first PHP/Joomla-native database-driven MCP architecture and original-source parity contracts.
- Runtime catalogue, safe API/native execution, permission/execution/audit state, PHP SDK protocol adapters, HTTP and console composition, portable SQL and isolated behavioural suites.
- Installer/asset/seed-update infrastructure and request-body handling work; complete installed lifecycle verification remains pending.
- Required first-class JCB API and CLI roadmap, pinned source/entity inventory, compiler/package effect classification, long-job/artifact requirements and full acceptance criteria.

### Changed

- External Composer client and remote stdio ownership moves to `joomengine/mcp_client` / `joomengine/mcp-client`.
- Component Composer identity is server-only `joomengine/mcp-component`; its outbound Joomla API transport moves into `admin/src/Http`, with no dependency on the external client.
- Implementation status now records committed runtime and outstanding work instead of the original documentation-only state.

No production release, complete JCB runtime coverage or live installation certification is advertised.
