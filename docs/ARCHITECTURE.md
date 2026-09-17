# Architecture — JoomEngine MCP

## Scope and identities

The component is `com_joomengine_mcp` with base namespace `VDM\Component\JoomEngineMcp`. Joomla maps administrator, site and API code to `Administrator`, `Site` and `Api` namespace suffixes. The separate console plugin is `plg_console_joomengine_mcp`, namespace `VDM\Plugin\Console\JoomEngineMcp`. The Composer distribution belongs to this repository and exposes an independent PHP client/stdio bridge; it must not duplicate the server's catalogue or business rules.

Joomla 6's supported contracts are authoritative. Repository placement follows JCB's `6.x` component project: `joomengine_mcp.xml`, `Joomengine_mcpInstallerScript.php`, `admin/`, `api/`, `site/`, `media/`, root changelog and update-server metadata. Administrator code uses `src/Controller`, `src/Model`, `src/View`, `src/Table`, `forms`, `tmpl`, `layouts`, `language`, `services/provider.php`, `access.xml`, `config.xml` and versioned SQL. The API has its own controller/dispatcher composition rather than bypassing Joomla with a root PHP endpoint. This is hand-authored JCB-aligned source, not an imported JCB blueprint.

## HTTP path

AI client → Joomla API entry point → API authentication plugins → authenticated Joomla identity → non-public MCP route → component controller → transport validation → protocol engine → database catalogue → authorizer → registered action binding → Joomla API/native services → verification/audit → JSON-RPC response.

Joomla registers API routes through a webservices plugin. A minimal `webservices/joomengine_mcp` routing extension is therefore bundled in the component distribution; it is distinct from the console plugin. It contains routing glue only. The installation/package must install and enable this glue atomically and remove it safely on uninstall. Do not claim that a component's API folder alone registers a route.

HTTP authentication reuses Joomla's API-token machinery, not a second user/password database. Both `Authorization: Bearer` and Joomla's supported token header must be tested against the real API-authentication plugin. A configured static Joomla-token transport is not an OAuth authorization server. Clients requiring OAuth discovery need a separately documented standards-compliant authorization integration; do not publish fake OAuth metadata.

The installed site's canonical API origin is server configuration, not request input. Any same-site API forwarding preserves the authenticated token, allows only validated relative registered routes, verifies TLS, refuses redirects and bounds bytes/time. It must not forward tokens to arbitrary hosts or redirect targets. Native services use the same authenticated identity and Joomla ACL. Do not turn HTTP requests into privileged local CLI calls.

## Trusted console path

Joomla console application → `VDM\Plugin\Console\JoomEngineMcp` → CLI-only composition root → shared component catalogue/engine → registered native/stock-console handlers.

Owning the server is the explicit authority boundary requested by the project owner. Local CLI does not require a Joomla API token or row viewing permission. It still validates input, bounds execution, records provenance and retains action/verification/recovery semantics. The privileged context cannot be constructed from remote request fields. API and CLI tracks are separate from wire transports: a local PHP client may speak stdio while connecting to the ACL-restricted HTTP server, and remains restricted.

## Database-defined capabilities

Providers own namespaced records. Published action, schema, binding, resource and prompt records are queried from the database. A registry of reviewed DI handler services implements reusable primitives. Action rows supply names/descriptions, input/output schemas, effects, risk, examples, permissions and compatibility. Binding rows supply the supported track and constrained mapping to Joomla APIs/native models. Shared schemas and provider relationships remove repetitive declarations.

New third-party extensions normally install provider/action/binding/schema rows. Their schema and bindings pass the same validation as administrator edits. Where a genuinely new primitive is needed, an extension registers a service implementing the handler contract under an explicit key; rows reference that key. Never instantiate an arbitrary class named by a row or evaluate row content.

Discovery, search, describe and call all use the same authorization predicate. `access` is a Joomla view-level ID; view levels resolve groups through Joomla. Each editable definition also has an `asset_id` for action permissions. Publication, provider state, compatibility, track availability and target ACL are independent predicates. Visibility alone does not authorize mutation.

## Protocol and state

Pin advertised MCP protocol versions to tested implementations. The July 2026 specification changed HTTP initialization/session behaviour; older and newer modes must not be mixed. Stateless JSON-response Streamable HTTP is acceptable where specified; optional SSE, notifications, resource subscriptions or async tasks must only be advertised if implemented and tested. Stdio is newline-delimited JSON-RPC and stdout must contain no Joomla banners, PHP notices or logs.

Cross-request plans, grants, replay protection, idempotency, locks and audit belong in durable database records. They must survive multiple PHP workers and cannot be process-local arrays. Re-authorize at execution time. Bind approvals to identity, action, canonical validated input, catalogue revision and expiry. Claim one-shot state atomically. An ambiguous write outcome must be retained for reconciliation rather than blindly retried. Verified partial effects must be reported honestly.

## Administrator experience

Provide singular edit and plural list screens for providers, actions, schemas, bindings, resources and prompts, with Joomla XML forms, relation fields/subforms, filtering, ordering, publication, access, assets, validation and checkout. Provide appropriate read-only audit and execution views plus grant revocation. Keep reusable logic outside generated-style controllers and avoid storing executable administrator-provided code. Use Joomla language keys and Web Asset Manager for assets.

## Distribution and compatibility

Build three kinds of artifacts: standalone component distribution (including required HTTP glue/dependencies), standalone console plugin, and a combined Joomla package. Supply Composer autoload/bin metadata, installer preflight, MySQL/MariaDB and PostgreSQL schema/update paths, changelog, update server, checksums and `.octojpack`. Update feeds remain empty until real archives are published. Pin component/plugin versions together for reproducible package assembly. Keep source licences/notices.

## References

- Original source: https://github.com/joomengine/joomla-mcp/tree/2cff50f4f6b440da3c684f9995a77efad32e1a36
- Joomla component reference: https://github.com/joomengine/Joomla-Component-Builder/tree/6.x
- PHP style: https://github.com/extension-builder/joomla/blob/main/docs/development/php-code-style.md
- Packaging reference: https://github.com/joomengine/Joomla-Component-Builder/blob/6.x/.octojpack
- Joomla webservices: https://manual.joomla.org/docs/general-concepts/webservices/
- MCP HTTP revisions: https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
