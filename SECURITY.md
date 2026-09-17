# Security model

HTTP is authenticated by Joomla API tokens and authorized by Joomla identities. Require API login permission, component permission, published provider/action, Joomla viewing access level, per-record asset permission and target-resource ACL. Recheck on every request and execution. Do not cache another user's catalogue or allow a hidden action through direct invocation.

Joomla `access` values are viewing-access-level IDs, not group IDs. A view level can contain multiple groups; ask Joomla for authorized view levels. Asset rules govern actions independently of visibility. Administrative catalogue/configuration editing requires explicit Joomla administration permission and CSRF protection.

Trusted server CLI is a separate locally constructed authority available only to the real console application. A remote user cannot select it with a parameter, token claim, configured database row or forwarded request. Local trust does not remove validation, observability or safeguards against accidental destructive execution.

Never evaluate database definitions as PHP, arbitrary class names, shell commands, unrestricted SQL, filesystem paths or URLs. Bindings select registered, reviewed handler primitives. Validate JSON Schema, routes, parameter maps and relationships before publishing definitions. Treat resource content and Joomla content as untrusted data, not instructions to grant permissions.

Preserve explicit consent, least-privilege scope, expiry/revocation, principal and canonical-input binding, stale-plan checks, atomic one-shot consumption, concurrency control, idempotency and verification. Never claim a write failed cleanly when it may have persisted; retain an uncertain/partial result and reconciliation instructions. Never automatically replay a non-idempotent write after an ambiguous timeout.

Validate present Origin headers, request media types, protocol versions and bounded request/response sizes. Reject redirects when credentials are attached. Any API forwarding uses the configured canonical same-site origin and validated relative routes; no request-supplied origin. Never log tokens, secrets, password fields or raw sensitive payloads. Audit only necessary redacted metadata.

Static Joomla API-token support is not OAuth discovery. Do not advertise an OAuth flow that is not implemented. A client requiring OAuth must use an explicitly implemented and verified compatible integration, not a fake metadata endpoint.

Release artifacts contain dependencies and checksums. Update feeds must refer only to published immutable versioned assets. Keep original source licences and notices. Report security issues privately to the repository maintainers rather than posting credentials or working exploit payloads in public issues.
