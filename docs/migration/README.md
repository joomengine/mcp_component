# Immutable migration provenance

The one-time import workflow exports the original MCP catalogue by executing its own discovery code, and copies the original PHP native handlers and behavioural tests with an explicit namespace/path migration. `native-source-map.json` records the source and target SHA-256 of each imported PHP file.

The original hard-coded `CoreEntityCatalogue` and `JoomlaActionRegistryFactory` are retained **only as test/migration references**, not installed runtime catalogue providers. The new runtime must instantiate reviewed handler primitives from installed database bindings and must never fall back to these reference factories.

This first preservation increment intentionally retains the original PHP formatting and declarations so behavioural equivalence can be tested independently. A subsequent isolated conversion applies the requested JCB PHP standard before release. Copied source retains its original licence in `LICENSES/joomla-mcp.txt`.

The temporary Node exporter is development-only and is never included in a Composer or Joomla artifact. Production runtime, installation and package building are PHP-only. Exported descriptors establish inventory and contracts, not evidence that all target operations are live-tested.
