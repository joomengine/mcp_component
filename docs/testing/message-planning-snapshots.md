# Private-message planning snapshots

## Contract

Message planning must observe a record without marking it read. The component's
`joomla.message-owned-record.v1` snapshot contract uses Joomla's native Message
table, independently of the public item GET. It is a precondition source only;
the requested mutation and its post-write verification still use the configured
Joomla API. Native post-delete errors remain uncertain outcomes.

Both the shipped write binding and its configured item-read binding explicitly
declare this contract. The component verifies the local core provider, complete
native request configuration, both definitions' permissions and revisions, and
the authenticated API identity before reading. Raw installer ownership hashes
include the referenced schemas, so an unflagged edit also opts out. The configured
API origin, scheme, script mount and actual script filename must identify this
installation; unproven proxy or alias configurations retain the HTTP path. The native table load matches
both message ID and recipient ID, covers every state, and rechecks both IDs after
normal table observers run. Missing and other-recipient records are not
distinguished. Persisted fields, including the original state, are compared
strictly between planning and apply. No item GET is needed before mutation.

## Customization boundary

This is an explicitly declared native precondition contract, not an HTTP GET
replacement. Native table observers remain active. HTTP-only route middleware,
controller overrides and API field plugins are not executed by this snapshot.
Installations relying on such custom policy must remove `snapshot_contract` from
the relevant binding. Customized action, binding or provider rows retain their
existing configured read path; they are never silently taken over by the native
snapshot service. That path retains its declared behavior, including any native
GET side effect. A TLS-terminating reverse proxy without server-normalized HTTPS
metadata is an unproven origin even if it sends X-Forwarded-Proto: https; an alias
or mismatched API mount is likewise not silently treated as local. A changed definition after approval invalidates the plan before
another snapshot is acquired.

Public message GET remains callable and marks an owned message read. Joomla may
return the representation loaded before that marking step. Its metadata states
this side effect; planning metadata identifies the distinct native snapshot.
There is no Joomla-version exception, state normalization, extra grant, console
privilege, list scan, new API endpoint or post-delete success coercion.

## Verification

Tests cover unread, read and trashed records; unchanged plans reaching the native
mutation; actual external changes still invalidating plans; recipient isolation;
identity, read/write ACL and definition drift; customized binding opt-out; table
observer identity revalidation; public GET marking; native post-delete errors;
and idempotent replay. Installed results are recorded after execution, rather
than inferred from the isolated contract tests.

### Verified isolated installation

On 2026-10-01, the focused suite passed 93 checks against Joomla 6.1.4, PHP
8.4.26 and MariaDB 11.8.6. States 0, 1 and -2 all reached native DELETE 204
without a planning conflict. Their subsequent native missing-item GET returned
500 and correctly retained uncertainty. Independent database checks, exact owned
fixture reconciliation and cleanup were verified. The recording-contract suite
passed 127 checks, including customization, identity, catalogue revision and
local-origin/mount boundaries. These counts do not represent the separate CI
platform matrix; that matrix runs the same focused suites independently.
