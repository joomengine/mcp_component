# Database and extension contract

All tables use Joomla's `#__joomengine_mcp_` prefix. Portable application queries use Joomla DatabaseInterface and bound parameters. Installation and updates provide database-specific DDL. Do not rely on MySQL-specific upsert or JSON operators in shared runtime code.

## Configuration graph

| Entity | Relationships and role |
| --- | --- |
| provider | Unique code; extension identifier; enabled/published state; compatibility/version; provenance and seed revision. Parent for namespaced definitions. |
| schema | Provider FK; stable code; validated JSON Schema document; schema dialect and revision. Reused by action input/output and resource content. |
| action | Provider FK; unique protocol/action name; title/description/help/examples; input/output schema FKs; read/write effect, risk and required permission; published/access/asset metadata. |
| binding | Action FK; API or CLI track; registered handler key; declarative route/model/parameter/result mapping; compatibility and priority. No PHP/SQL/shell source. |
| resource | Provider FK; unique URI or template; description/MIME; schema/action reference; published/access/asset metadata. |
| prompt | Provider FK; unique name; argument schema; message templates/subform; published/access/asset metadata. Templates are inert data, not executable code. |

Editable rows carry Joomla standard `id`, `asset_id`, `published`, `access`, `ordering`, `checked_out`, `checked_out_time`, `created`, `created_by`, `modified`, `modified_by`, `version` and `params` where appropriate. Access values reference Joomla viewing access levels, which aggregate groups; they are never compared to user group IDs. Application-level relation validation complements physical FKs where installation portability permits them. Unique provider/name and action/track/binding keys prevent ambiguous dispatch. Restrict deletion while dependent records exist; administrator APIs must not leave dangling definitions.

## Execution state

| Entity | Purpose and safety invariant |
| --- | --- |
| plan | Opaque random ID/hash, principal/context, action and definition revision, canonical input hash, preview/preconditions, expiry and state. No token or arbitrary serialized object. |
| grant | Principal and bounded scope; one-shot, expiring or explicitly permitted indefinite duration; approval evidence; revoked/consumed timestamps. Atomically claim one-shot grants. |
| execution | Principal, plan and idempotency key; claim/lease/state; verified result or uncertain/partial failure; timestamps. Unique principal/idempotency binding. |
| lock | Resource key, owner nonce and lease expiry; atomic acquisition/release; protection across PHP workers. |
| audit | Append-only event ID, time, principal, transport/track, action/plan/execution identifiers, outcome and redacted metadata. No credentials, sensitive request bodies or raw exception dumps. |

Protocol session storage is separate from action/grant state and is only needed for an advertised protocol revision that actually supports it. Never reuse an MCP session ID as authentication or authorization.

## Seeding and migration

The initial SQL contains the reviewed default configuration rows; runtime discovery reads these installed rows. Seed records use stable codes and source revisions. Schema migration and seed upgrades are idempotent, preserve administrator customizations and third-party providers, and do not blindly overwrite changed records. Changes to schemas/bindings invalidate old execution plans. Track shipped revision versus administrator-modified revision explicitly.

Generate seed data from the immutable original action contracts, review the resulting semantic mapping, and retain a machine-readable source-to-target parity manifest. Do not infer action support merely from its name or count. Unsupported upstream operations remain explicitly described as such; implemented upstream operations cannot be silently downgraded to placeholders.

## Extending by rows

A third-party integration installs a provider, reusable input/output schemas, actions and track-specific bindings using existing registered handler keys. It supplies Joomla ACL/view-level defaults and validates exact field names/types, API routes and response mapping. No protocol-engine change is needed. New handler code is only required when the integration needs a primitive the existing adapters cannot express safely.
