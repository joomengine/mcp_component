# Database and extension contract

Tables use `#__joomengine_mcp_`. Shared queries use Joomla DatabaseInterface and bound parameters; database-specific DDL supplies MySQL/MariaDB and PostgreSQL installation/update paths. The current executable structure is `admin/src/Database/Structure.php`, including the 0.1.1 job/artifact additions.

## Configuration graph

| Entity | Relationships and responsibility |
| --- | --- |
| provider | Stable name, extension dependency, description, compatibility/provenance definition and publication/access/asset metadata |
| schema | Provider reference and reusable JSON Schema document |
| action | Provider and input/output schema references, semantic name, description/domain/toolset/effect/risk and source/native permission metadata |
| binding | Provider/action/schema references, API or CLI track, registered handler key and constrained configuration/definition mappings |
| tool | Provider/schema references and reviewed protocol-handler configuration; discovery metadata is stored, not a hard-coded tool list |
| resource | Provider, URI or URI template, MIME, registered handler and configuration |
| prompt | Provider/schema references and inert template/configuration data |
| target | Provider and exact registered CLI command identity, risk/status/description and definition metadata |

Editable rows include `id`, `asset_id`, `name`, `title`, `published`, `access`, `ordering`, checkout/creation/modification fields, `version`, `params`, `seed_revision`, `seed_hash` and `customized`. Viewing access levels resolve user groups through Joomla; they are not group IDs. Validate relations and native asset rules on administrative edits, prevent dangling references and ambiguous duplicate identities, and invalidate stale plans when relevant schemas/bindings change.

## Durable state

Current state entities are `permission_request`, `grant`, `plan`, `execution`, `lease`, `session`, `audit`, `job` and `artifact`. They preserve principal isolation, requested/approved scope and duration, one-shot/revocation/expiry, encrypted validated input/results, definition/input fingerprints, idempotency, optimistic versions, lease ownership and redacted append-only events. Protocol sessions are separate from authentication and consent. A session or job identifier is not a credential.

The normalized `job` table retains principal/execution references, encrypted approved input, claim/worker tickets, state/lease/timestamps, progress and partial/uncertain outcomes. `artifact` records hold owned job references, MIME/size/SHA-256, chunk hashes and retention metadata. Private artifact paths are selected by server composition; requests use opaque IDs and bounded byte ranges. Version 0.1.1 includes installation DDL and upgrade migrations for both supported database families.

## Seed ownership and upgrades

Initial SQL contains reviewed configuration rows; runtime reads installed records. Stable natural identities and revision/hash/customized metadata separate shipped definitions from administrator edits and third-party providers. SeedUpdater and native lifecycle tests preserve local customization and relations. Current installed verification is tracked in IMPLEMENTATION.md.

Original-core parity is source-pinned. JCB definitions are synchronized explicitly from installed API routes and CLI registrations into database rows, preserving customization and provenance. They are not fabricated in static installation SQL. The package entity map and API-generator templates are not executable route evidence. `docs/integrations/jcb-surface.json` remains planning evidence and is never loaded as runtime seed data.

## Extension by rows

A new integration adds provider/schema/action/binding and relevant tool/resource/prompt/target records using existing reviewed handler keys. Values describe native contracts, never executable PHP, arbitrary SQL/class names, shell or untrusted origins. A genuinely new primitive requires a reviewed DI handler before its binding is executable. The separate external client simply discovers those capabilities; no client-library code belongs in this database layer.

For JCB, preserve source-code fields as inert definition data and exact ID/GUID/relationship/subform semantics. Package `get` may mutate local state, `push` affects configured remote repositories, and compiler/install effects require separately approved plans. Schema reuse must not erase differences between API and CLI semantics, publication, field-level permissions or missing capabilities. Full requirements and acceptance: integrations/JCB.md.
