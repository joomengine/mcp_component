# Installation and releases

This repository contains only the component. Its source ZIP installs directly in Joomla with production dependencies and installation data already included. The [console plugin](https://github.com/joomengine/mcp_plugin) and [webservices plugin](https://github.com/joomengine/mcp_webservices) are independent extensions with their own installers and update feeds.

[OctoJPack](https://github.com/octoleo/octojpack#quick-start) combines all three extensions using the concrete `.octojpack` file and publishes to **joomengine/mcp_package**, branch **main**. Each source uses `mode: "tags"`, so OctoJPack selects its latest tag and bundles that exact tagged archive. `version_id: com_joomengine_mcp` makes the package version follow the component tag. This works both through the action and from a standalone OctoJPack installation.

## Separate update feeds

| Extension | Feed URL | Joomla identity | Download |
| --- | --- | --- | --- |
| Component | https://raw.githubusercontent.com/joomengine/mcp_component/main/joomengine_mcp_update_server.xml | `com_joomengine_mcp`, component, administrator | `mcp_component/archive/refs/tags/vVERSION.zip` |
| Package | https://raw.githubusercontent.com/joomengine/mcp_package/main/.github/joomengine_mcp_update_server.xml | `pkg_joomengine_mcp`, package, site | `mcp_package/archive/refs/tags/vVERSION.zip` |

The component manifest references the component feed. OctoJPack writes the package manifest with the package feed URL from `.octojpack`. OctoShoom hashes the exact GitHub tag ZIP named in each feed, so each checksum belongs to that extension's download.

The shared, versioned changelog remains https://raw.githubusercontent.com/joomengine/mcp_component/main/changelog.xml. The licence remains https://raw.githubusercontent.com/joomengine/mcp_component/main/LICENSE. Package versions follow component versions, so Joomla can use the same changelog by version.

## Preserved package automation

Merge the package repository's workflow before publishing the first package. Its maintained workflow, update feed, metadata helper and instructions live under `.github`. Current native OctoJPack replacement removes non-hidden package contents and leaves `.github` intact. It needs no ignore-folder input or wrapper. Keeping the feed in this preserved folder also retains earlier entries and hashes across package builds.

The package workflow triggers on `v*` tag pushes. OctoJPack pushes its generated package commit and tag together over SSH; the retained workflow starts from that tag. It reads the generated manifest from that exact tag, updates the package feed on `main`, and calls OctoShoom. Feed/hash commits on `main` do not retrigger the tag workflow. See [package maintenance instructions](https://github.com/joomengine/mcp_package/blob/main/.github/README.md).

## GitHub setup

Follow the native [git-user](https://github.com/octoleo/git-user#workflows), OctoJPack and [OctoShoom](https://github.com/octoleo/octoshoom#quick-start) examples. Make these Actions secrets available to **all four repositories** (both plugins, component and package), directly or through organization secrets:

| Secret | Value |
| --- | --- |
| `GIT_USER`, `GIT_EMAIL` | Release Git identity. |
| `GPG_KEY`, `GPG_USER` | Signing key and its user ID. |
| `SSH_KEY`, `SSH_PUB` | Matching SSH keypair. |

Each plugin and the package workflow needs permission to push to its own repository. The component's SSH identity needs permission to push to both `mcp_component` and `mcp_package`. OctoJPack reads the public source tags and archives using the workflow's built-in GitHub token with `contents: read`, exposed as `VDM_GLOBAL_TOKEN`. An optional `GIT_TOKEN` secret overrides that token. Git pushes still use the SSH identity. The package workflow does not invoke OctoJPack and needs no packaging token.

For standalone OctoJPack runs, export `VDM_GLOBAL_TOKEN` or set it in OctoJPack's environment file. The native loader requires that value even for public sources. Keep credentials out of `.octojpack`; its repository, feed, changelog and licence values remain fixed and usable from any machine.

Git User runs once per workflow. The shared actions inherit that Git setup; credentials stay in GitHub secrets. No repository placeholders, configuration rendering, local package builder or duplicate hash implementation is needed.

## First release

In each extension repository, open **Actions**, select its release workflow, choose **Run workflow**, select `main` and enter an unused version above the current manifest version. A `v` prefix is also accepted. Released versions and development-baseline changelog entries cannot be reused for new changes. The workflow creates the tag; pushing an extension tag manually does not start these manual release workflows.

| Order | Workflow | Result |
| --- | --- | --- |
| 1 | [Console release](https://github.com/joomengine/mcp_plugin/actions/workflows/release.yml) | Console tag, plugin feed and OctoShoom checksum. |
| 2 | [Webservices release](https://github.com/joomengine/mcp_webservices/actions/workflows/release.yml) | Webservices tag, plugin feed and OctoShoom checksum. |
| 3 | [Component release](https://github.com/joomengine/mcp_component/actions/workflows/release.yml) | Component tag, component feed, OctoShoom checksum, then OctoJPack package publication. |
| 4 (automatic) | [Package update](https://github.com/joomengine/mcp_package/actions/workflows/update.yml) | Package feed and OctoShoom checksum for the pushed package tag. |

Wait for both plugin releases before starting the component release. Wait for **both the component run and the automatically triggered package run** before declaring the combined release complete. A component hashing failure stops OctoJPack; a package hashing failure appears in the separate package run and can be retried there without rebuilding the package. Published tags remain unchanged.

Later component releases can reuse existing plugin tags when those plugins have not changed. Plugin versions may differ; the package always follows the component version. A completed release needs no manual ZIP upload, packaging or update-XML editing.

If a component release stops after tagging but before packaging, fix the workflow on `main`, then start a **new** manual run with that existing version. Re-running the failed job would reuse its old workflow revision. The new run preserves the existing component tag and checksum and resumes packaging. Publish any required plugin fixes first, so OctoJPack selects their corrected latest tags.

## Changelogs and dependencies

Keep `CHANGELOG.md` and `changelog.xml` consistent, with new changes under one literal `[[[NEXT_VERSION]]]` section in each file. Release replaces the marker with its version. Create another pending section when subsequent changes begin; preserve released history. Use Joomla categories `security`, `fix`, `language`, `addition`, `change`, `remove` and `note`, with `item` children and matching Markdown headings.

Maintainers resolve changed dependencies with Composer and commit the lock and complete production runtime under `admin/vendor`. CI checks source installation, relocated dependencies, native metadata and separately installed plugins. No downstream Composer run is required. Source and metadata checks do not claim an actual release or prove configured publication credentials.
