# Source installation and releases

This repository is exclusively the component. **Code → Download ZIP** and tagged source ZIPs install directly through Joomla's extension installer. Production Composer dependencies, the catalogue seed, licences and owned webservices plugin are already tracked. No user or downstream packager runs Composer or a build script.

The console plugin has its own installable source repository and version. OctoJPack combines both extensions using `.octojpack` and publishes the Joomla package to a **separate repository**. The package manifest and package update/changelog files belong there, never in this component repository.

## Release sequence

After merging reviewed changes and checking CI, open Actions → **Release component with OctoShoom and OctoJPack**, select the configured release branch and enter the next stable version, such as `1.0.0`. An optional `v` prefix is accepted; tags always use `vX.Y.Z` and Joomla XML uses `X.Y.Z`.

1. Validate configuration, the previously released console tag and its OctoShoom checksum, and the separate package destination.
2. Replace `[[[NEXT_VERSION]]]` in both changelogs, update component/routing manifest versions and creation dates, and update `.octojpack`'s version. Commit those files and atomically push the release branch and new immutable tag.
3. Add the tag's `https://github.com/OWNER/REPO/archive/refs/tags/vVERSION.zip` URL to `joomengine_mcp_update_server.xml`, then commit the feed. Existing versions and checksums remain intact.
4. Run the configured OctoShoom composite action synchronously. It downloads the tagged archive and commits SHA-512 to the source feed. Fetch that committed feed and verify its hash against the real download. Any failure stops the workflow.
5. Render `.octojpack`'s repository/tag placeholders from GitHub configuration and invoke the configured OctoJPack composite action. It builds and pushes the combined package and version tag to the separate destination. The component workflow does not implement ZIP assembly or generate the package manifest.

A manually pushed tag alone does not start this process: use the version-input workflow so changelogs and manifests are frozen before the immutable tag is created. No GitHub Release asset uploads are required. The first run populates the initially empty component feed with a real existing tag; no nonexistent historical downloads are advertised.

The component manifest points at the live branch feed and Joomla XML changelog. The tag is created before the new feed entry/hash so hashing the immutable download cannot create a self-referential checksum.

## GitHub configuration

Set these under **Settings → Secrets and variables → Actions**. Values shown as descriptions must be supplied for your repositories; no destination repository is hardcoded in the workflow.

| Variable | Value |
| --- | --- |
| `RELEASE_BRANCH` | Optional source release branch; defaults to this repository's default branch. Run the workflow from that branch. |
| `OCTOSHOOM_REPOSITORY` | Shared hash action repository, normally `octoleo/octoshoom`. |
| `OCTOSHOOM_REF` | Full reviewed 40-character commit SHA for the action. Inspected compatible revision: `a4eba6191388335e0301f74969d92151bc0f520d`. |
| `OCTOJPACK_REPOSITORY` | Shared packaging action repository, normally `octoleo/octojpack`. |
| `OCTOJPACK_REF` | Full reviewed commit SHA containing GitHub tag-archive support and required-extension failure handling; see [OctoJPack PR #2](https://github.com/octoleo/octojpack/pull/2). Pin the reviewed result, not an older Gitea-only implementation. |
| `RELEASE_SSH_KNOWN_HOSTS` | Verified GitHub SSH `known_hosts` lines. Strict host-key checking remains enabled. |
| `CONSOLE_REPOSITORY` | Console plugin `owner/repository`, normally `joomengine/mcp_plugin`. |
| `CONSOLE_TAG` | Exact plugin tag, `vX.Y.Z`, whose plugin release and OctoShoom run have completed. It has an independent version with the same major as the component, as required by the plugin installer. |
| `PACKAGE_REPOSITORY` | Existing separate `owner/repository` for the combined Joomla package. It must differ from component, console and tool repositories. |
| `PACKAGE_BRANCH` | Existing branch in the package repository. OctoJPack owns its package contents. |
| `PACKAGE_UPDATE_SERVER` | HTTPS URL of the package repository's own Joomla update feed. |
| `PACKAGE_CHANGELOG_SERVER` | HTTPS URL of the package repository's own Joomla changelog. |

| Secret | Access needed |
| --- | --- |
| `RELEASE_TOKEN` | GitHub token for source Contents writes, tool checkouts and API reads of source/console/package repositories. It must also permit changes to workflow files if source release commits include such files. |
| `RELEASE_SSH_KEY` | Unencrypted SSH private key for OctoShoom's source-feed push and OctoJPack's package push. Use a machine/user identity with write access to both repositories; one GitHub deploy key cannot be reused across repositories. |

The release identity must be allowed to push metadata commits and tags under your branch/ruleset policy. This workflow does not bypass branch protections. Do not store tokens or private keys in `.octojpack` or committed files. Package feeds/changelogs are owned and maintained by the package repository; their configured URLs must resolve to that repository's real metadata.

`.octojpack` contains ordinary OctoJPack package identity and extension definitions. Its visible `[[[...]]]` configuration markers are resolved in a temporary runner configuration from the table above. They are not install-time placeholders. Exact component and console tags are selected instead of "latest" modes, so unrelated releases cannot change the chosen extension versions.

## Changelogs

Maintain `CHANGELOG.md` and `changelog.xml` together. New changes go at the top under the literal `[[[NEXT_VERSION]]]`. Use one pending section in each file, and create a new one after a release when subsequent changes begin. The release workflow assigns the version; agents must not guess it or relabel an already released section.

Joomla XML uses `changelogs/changelog`, component `element` = `com_joomengine_mcp`, `type` = `component`, and category elements `security`, `fix`, `language`, `addition`, `change`, `remove`, `note`, each containing `item` children. Markdown uses matching readable headings. Record compatibility warnings under Note, errors fixed under Fix, and security changes under Security. Preserve historical entries.

The current 0.1.0/0.1.1 entries identify development baselines, not published tags. Choose a new unused version for the first release; the workflow refuses duplicate changelog versions.

## Retry a failed release

Run the same workflow with the same version. An existing source tag must be an ancestor of the release branch and contain matching released metadata; it is never recreated or moved. Missing feed metadata can then be added, and OctoShoom can finish or verify its hash without changing older releases.

An existing matching package tag is preserved. A retry cannot replace a newer package branch with an older version. Keep the same console selection and package configuration while resuming an interrupted version. If an existing tag has conflicting metadata, resolve it explicitly rather than deleting or force-updating tags.

The component and plugin workflows serialize their own releases. A concurrent ordinary source push can cause a normal non-fast-forward rejection; the workflow stops and can be rerun safely.

## Maintainer verification

Dependency changes are resolved once by a maintainer using the root `composer.json`/lock and committed under `admin/vendor`. Use Composer 2 with PHP 8.3+ and the declared extensions:

```bash
composer validate --no-check-publish
composer install --no-dev --prefer-dist --no-interaction --no-scripts
php tests/package.php
php tests/release.php
php tests/run.php
```

Commit the complete dependency tree with its licences and lock. `admin/autoload.php` supplies the component namespace before and after Joomla relocates the administrator files. Do not manually patch third-party vendor code. CI checks a plain Git source archive, tests the relocated runtime, checks seed regeneration and runs the behavioral suites. Installed Joomla/MySQL/PostgreSQL and JCB workflows install source archives directly. These test archives are verification fixtures, not maintained distribution builders.

Release metadata tests exercise successive versions, invalid inputs, preserved history, retry behavior and missing/mismatched checksums without publishing anything. Running the real release workflow is the explicit publication action.
