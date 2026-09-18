# Security model

HTTP is authenticated by Joomla API tokens and authorized by Joomla identities. Require API login permission, component permission, published provider/action, Joomla viewing access level, per-record asset permission and target-resource ACL. Recheck on every request and execution. Do not cache another user's catalogue or allow a hidden action through direct invocation.

Joomla access values are viewing-access-level IDs, not group IDs. Asset rules govern actions independently of visibility. Administrator catalogue/configuration changes require explicit native administration permission and CSRF protection.

Trusted server CLI is locally constructed only by the real console application. No request parameter, token, database row or forwarded HTTP call may enable it. The external client and remote stdio bridge in mcp_client remain restricted HTTP callers. Local trust does not remove input validation, observability or safeguards against accidental destructive execution.

Database bindings select reviewed handler primitives, never arbitrary classes, eval, shell commands, unrestricted SQL, hosts or filesystem access. Validate schemas, routes, mappings and relations before publication. Treat Joomla/JCB resource content as untrusted data, not instructions to grant permissions.

JCB source-code fields are legitimate inert definition data and must not be stripped indiscriminately. Compilation can execute configured hooks and installation changes executable site code; require explicit high-risk scope, reviewed effective options and native privileges. Package get/init/pull/reset can change local state and push affects configured remote repositories. Do not classify them by their English verb alone. Keep credentials in server configuration, not caller-provided repository URLs/tokens or audit payloads.

Preserve grants, expiry/revocation, principal/action/input/revision binding, stale-plan checks, atomic consumption, concurrency/idempotency and read-back. A timeout, nonzero command exit or lost worker can leave real effects: retain partial/uncertain state and reconcile before replay. HTTP-originated jobs retain the requesting user's authority even when a local worker executes them. Cancellation is not rollback. Restrict artifact and job reads by owner/ACL, bound logs/storage, and never expose arbitrary file reads.

Validate present Origin headers, media/protocol types and bounded I/O. Same-site API forwarding uses the configured canonical origin, verified TLS and no redirects. Never log tokens, passwords, credential-bearing definition fields or raw sensitive exceptions/payloads. Serialize only necessary redacted audit metadata.

Static Joomla tokens are not OAuth discovery. Do not advertise an authorization flow that is not implemented. Release archives contain dependencies/checksums, and update feeds refer only to published immutable assets. Keep source licences/notices. Report issues privately to maintainers without posting secrets or working exploit payloads in public issues.
