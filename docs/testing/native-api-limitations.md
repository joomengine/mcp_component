# Native API limitations after the installed audit

## Scope and disposition

The October 1, 2026 audit separated component defects from behavior reproduced
directly in Joomla 6.1.4. PR #35 fixes the component defects in #22, #23, #24, #26
and #30. The maintainer requested closure of the eight remaining reports as
outside this repository's fix scope, rather than retaining upstream trackers.
Those issues are closed as **not planned**, not as fixed. Their native acceptance
criteria remain unfulfilled on the tested Joomla version.

No currently callable operation is disabled to conceal a native failure. The
component continues to send the intended Joomla API request, preserve actual
errors or verification limitations, and prevent an uncertain mutation from being
automatically repeated. A future corrected native API can satisfy the same
contract without a version-specific workaround in this component.

## Observed limitations and component responsibility

- **#25, menu trash:** PATCH persists trash, but the native collection excludes
  the resulting row. Item GET derives the component ID and cannot prove its raw
  stored value. MCP retains exact identity/client/component checks and truthful
  uncertainty, rather than relaxing the earlier menu guarantee. The 74 contract
  and 22 installed checks cover retained mutation evidence, lease and replay.
  See [native menu trash verification](menu-trash-native-contract.md).
- **#27, deleted item GET:** contacts, newsfeeds and messages can be deleted with
  HTTP 204, after which native item GET returns 500. MCP retains an uncertain
  read-back; a generic server error is not proof of absence. Message tests also
  reproduce this on MySQL and PostgreSQL in CI. The separate message-planning
  self-conflict is fixed under #30.
- **#28, message POST:** Joomla stores the message but returns 404 through its
  saved-item response path. MCP retains uncertainty instead of inventing the new
  identity or automatically resending. Related Joomla work: [#41017](https://github.com/joomla/joomla-cms/pull/41017).
- **#29, message PATCH:** Joomla inserts another message while returning the
  unchanged original identity. MCP independently detects changed-field mismatch
  and preserves uncertainty and replay safety. The pure planning snapshot does
  not repair native save behavior. Related Joomla work: [#41017](https://github.com/joomla/joomla-cms/pull/41017).
- **#31, site override POST:** the native site route writes the administrator
  override file. MCP preserves the requested client/language route; an
  acknowledged create with `verification.status: notPerformed` is not a claim
  of verified site-file persistence. Related Joomla work: [#41016](https://github.com/joomla/joomla-cms/pull/41016).
- **#32, override item GET:** valid digit-containing constants return empty
  identity/value data because native lookup loses the string key. MCP preserves
  the intended key and actual response, without fabricating a value or rejecting
  valid constants. Letters-only controls pass. Related Joomla work: [#41016](https://github.com/joomla/joomla-cms/pull/41016).
- **#33, override DELETE:** native client/model-state handling returns 500 and
  leaves the INI constant in place. MCP preserves the error and uncertain
  execution; it does not delete files itself or claim verified absence. Related
  Joomla work: [#41016](https://github.com/joomla/joomla-cms/pull/41016).
- **#34, content-language PATCH:** partial and full explicit-ID updates save,
  then native saved-ID/model-state handling returns a check-in 400. MCP retains
  the actual failure and uncertain execution without another PATCH. The fixed
  language-list ordering issue is a separate read contract.

Open or historical Joomla pull requests are context, not evidence that a fix is
merged, released, or present in the installed site. This component campaign does
not modify Joomla core or those upstream repositories.

## Comment review

The four database-tuning comments on #27, #28, #33 and #34 proposed generic
InnoDB settings, example `transactions`/`resources` queries and retry boundaries.
They supplied no matching trace, lock timeout or query-plan evidence. The native
diagnostics above concern missing-item preparation, saved-ID state or INI-file
client state; the missing-message 500 also reproduces on PostgreSQL. No database
tuning or automatic mutation retry was justified by those comments.

## Verification boundary

Green component, installed Joomla and installed JCB checks establish the tested
component contracts and truthful failure handling. They do not certify that the
native limitations above are repaired. Uncertain writes require inspection and
reconciliation; missing independent verification stays explicit.

The #30 snapshot fix is limited to its explicitly declared, unchanged local
native bindings. Customized definitions and unproven origins retain their
configured read path. See the [snapshot contract and deployment boundary](message-planning-snapshots.md),
including HTTP-only policy middleware and TLS-terminating proxy considerations.

Merge, release and subsequent installed-site testing remain separate actions.
