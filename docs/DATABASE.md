# Database and extension contract

Tables use `#__joomengine_mcp_`. Shared queries use Joomla DatabaseInterface and bound parameters; database-specific DDL supplies MySQL/MariaDB and PostgreSQL installation/update paths. The current executable structure is `admin/src/Database/Structure.php`; this document distinguishes existing entities from proposed JCB long-job additions.

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

Current state entities are `permission_request`, `grant`, `plan`, `execution`, `lease`, `session` and `audit`. They preserve principal isolation, requested/approved scope and duration, one-shot/revocation/expiry, encrypted validated input/results, definition/input fingerprints, idempotency, optimistic versions, lease ownership and redacted append-only events. Protocol sessions are separate from authentication and consent. Never treat a session or job identifier as a credential.

JCB long-running work requires extending execution/lease persistence and, where needed, normalized `job` and `artifact` relationships. Those additions are planned in integrations/JCB.md and are not present merely because named here. Store job principal/provider/execution references, state/lease/timestamps, bounded progress, immutable approved inputs and partial/uncertain outcomes. Artifacts use authorized IDs, MIME/size/hash/retention metadata and managed paths, not arbitrary remote filesystem access.

## Seed ownership and upgrades

Initial SQL contains reviewed configuration rows; runtime reads installed records. Stable natural identities and revision/hash/customized metadata separate shipped definitions from administrator edits and third-party providers. Upgrades must be idempotent, preserve local customization/relations and record conflicts rather than overwrite. The installer now contains SeedUpdater infrastructure; full native install/update/uninstall tests remain required.

Original-core parity is source-pinned. JCB is a separate required provider expansion: capture actual API route and CLI registration contracts, then generate validated schemas/actions/bindings/targets and portable install/update SQL. Do not treat JCB's package entity map or API-generator templates as executable route evidence. `docs/integrations/jcb-surface.json` is planning evidence only and must not be loaded as runtime seed data.

## Extension by rows

A new integration adds provider/schema/action/binding and relevant tool/resource/prompt/target records using existing reviewed handler keys. Values describe native contracts, never executable PHP, arbitrary SQL/class names, shell or untrusted origins. A genuinely new primitive requires a reviewed DI handler before its binding is executable. The separate external client simply discovers those capabilities; no client-library code belongs in this database layer.

For JCB, preserve source-code fields as inert definition data and exact ID/GUID/relationship/subform semantics. Package `get` may mutate local state, `push` affects configured remote repositories, and compiler/install effects require separately approved plans. Schema reuse must not erase differences between API and CLI semantics, publication, field-level permissions or missing capabilities. Full requirements and acceptance: integrations/JCB.md.
