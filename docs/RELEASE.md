# Source installation and releases

This is the component repository. Its source ZIP installs directly in Joomla: production dependencies, installation data, licence and owned webservices plugin are already tracked. No downstream Composer or build step is required. The console plugin has its own installable repository.

OctoJPack combines the latest tagged component and console plugin using `.octojpack`. It owns package assembly and publication in the separate package repository. The package manifest and package update/changelog files belong there.

## GitHub setup

Follow the upstream [git-user](https://github.com/octoleo/git-user#workflows), [OctoShoom](https://github.com/octoleo/octoshoom#quick-start) and [OctoJPack](https://github.com/octoleo/octojpack#quick-start) examples. Configure these repository or organization Actions secrets:

| Secret | Value |
| --- | --- |
| `GIT_USER`, `GIT_EMAIL` | Release Git identity. |
| `GPG_KEY`, `GPG_USER` | Signing key and its user ID. |
| `SSH_KEY`, `SSH_PUB` | Matching SSH keypair for that identity, with write access to the component and package repositories. |
| `GIT_TOKEN` | GitHub API token for OctoJPack to read the source repositories. |

`git-user` runs once. OctoShoom and OctoJPack inherit its Git configuration; OctoJPack reads the token from `VDM_GLOBAL_TOKEN`. The workflow supplies each action's configuration input and enables OctoJPack's native `push` option. There are no per-release repository, tool-ref or package-URL variables.

## Release

Release the console plugin first, then run **Release component with OctoShoom and OctoJPack** on `main` with the next version, such as `1.2.3` or `v1.2.3`.

1. Freeze the pending changelogs and manifest versions, commit and create `v1.2.3`.
2. Add the tag archive URL to the component's Joomla update feed.
3. OctoShoom adds and commits the SHA-512 hashes.
4. After it succeeds, OctoJPack reads `.octojpack` and builds/pushes the combined package.

Both shared actions do their own work. There is no local packaging, hash calculation, checksum recheck or configuration rendering. `tools/release.php` only updates this component's release metadata. Existing tags are retained when rerunning an interrupted release; existing feed entries and hashes are preserved.

`.octojpack` is also the standalone OctoJPack configuration. Its stable repository identities, raw GitHub feed/changelog URLs and raw licence URL belong in that file. Native latest-tag selection and `version_id` determine the package version. Workflow-only placeholders and generated configuration copies do not belong here.

## Changelogs

Keep `CHANGELOG.md` and `changelog.xml` consistent. Record new changes under one literal `[[[NEXT_VERSION]]]` section in each file. The release replaces that marker with the entered version. Create the next pending section when subsequent changes begin; preserve released history.

Joomla XML uses `changelogs/changelog`, `element` = `com_joomengine_mcp`, `type` = `component`, and `security`, `fix`, `language`, `addition`, `change`, `remove` or `note` categories containing `item` children. Use matching Markdown headings. The manifest points to the raw GitHub changelog and update feed.

## Dependencies and checks

When dependencies change, maintainers run Composer and commit the lock and complete production runtime under `admin/vendor`, including licences. Do not edit vendor source by hand. CI checks source archives, relocated autoloading and the runtime; `php tests/release.php` checks version/changelog/feed edits without publishing.
