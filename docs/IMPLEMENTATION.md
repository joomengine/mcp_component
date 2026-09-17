# Implementation status

## Baseline

- Source pinned: `joomengine/joomla-mcp@2cff50f4f6b440da3c684f9995a77efad32e1a36`.
- Component and plugin repositories already have documentation-first draft PR #1 on `feature/jcb-mcp-runtime`; continue those branches, do not duplicate them.
- Correct component identity: `com_joomengine_mcp`; correct base namespace: `VDM\Component\JoomEngineMcp`.
- Correct console identity: `plg_console_joomengine_mcp`; correct base namespace: `VDM\Plugin\Console\JoomEngineMcp`.

## Completed in documentation

Exact objectives, architecture/security boundaries, database graph, handler extension rules, JCB repository placement, migration sequence and acceptance/evidence contract are preserved in this repository.

## Runtime status

Runtime implementation has not yet been verified at this documentation commit. No catalogue count, installation claim, live test pass or production-readiness claim is made.

## Next executable increment

Implement PHP contracts, identity/authorization boundary, database-backed catalogue and schema/protocol tests; obtain the pinned source inventory and migrate the existing companion and declarative catalogue without dropping behaviour. Continue through every increment in `docs/MIGRATION.md` and update this file with actual commands/results.
