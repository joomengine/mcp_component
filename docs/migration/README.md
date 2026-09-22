# Immutable migration provenance

The one-time import workflow exports the original MCP catalogue by executing its own discovery code, and copies the original PHP native handlers and behavioural tests with an explicit namespace/path migration. `native-source-map.json` records the source and target SHA-256 of each imported PHP file.

The original hard-coded `CoreEntityCatalogue` and `JoomlaActionRegistryFactory` are retained **only as test/migration references**, not installed runtime catalogue providers. The new runtime must instantiate reviewed handler primitives from installed database bindings and must never fall back to these reference factories.

The installed native classes now use the JCB PHP standard: tabs, Allman braces, explicit typed dependency properties and documented class/member contracts. The conversion preserves all PHP string and protocol literals and passes the preserved native behaviour and catalogue-parity suites. Each converted source-map entry records its target transformations and refreshed target hash; the original source hashes remain unchanged. The original reference factories and behavioural fixtures remain test-only migration evidence. Copied source retains its original licence in `LICENSES/joomla-mcp.txt`.

The five `sourceOnlyGates` in `parity.json` preserve deliberate restrictions already present in the pinned TypeScript implementation: raw component configuration reads/writes, language package installation and site/administrator language override updates. The original source rejected these operations before execution or planning. They remain explicit diagnostics, not regressions or newly claimed executable capabilities.

The temporary Node exporter is development-only and is never included in a Composer or Joomla artifact. Production runtime, installation and package building are PHP-only. Exported descriptors establish inventory and contracts, not evidence that all target operations are live-tested.
