# Installation and releases

This is exclusively the component repository. Its source ZIP installs directly in Joomla with production dependencies and installation data already included. The [console plugin](https://github.com/joomengine/mcp_plugin) and [webservices plugin](https://github.com/joomengine/mcp_webservices) are independent extensions. The component installer does not install, update or remove them.

[OctoJPack](https://github.com/octoleo/octojpack#quick-start) combines all three extensions using the concrete `.octojpack` file and publishes to **joomengine/mcp_package**, branch **main**. Native `version_id: com_joomengine_mcp` makes the package version follow the component tag. Default tag selection works both in the workflow and from a standalone OctoJPack installation.

## Fixed update URLs

The package files and generated package manifest belong in `mcp_package`. The package update server and shared changelog deliberately remain in this component repository:

- Update server: https://raw.githubusercontent.com/joomengine/mcp_component/main/joomengine_mcp_update_server.xml
- Changelog: https://raw.githubusercontent.com/joomengine/mcp_component/main/changelog.xml
- Licence: https://raw.githubusercontent.com/joomengine/mcp_component/main/LICENSE

The feed identifies `pkg_joomengine_mcp`, type `package`, client `site` (Joomla installs packages with client ID 0). Its download is the matching `mcp_package/archive/refs/tags/vVERSION.zip`, never a component-only ZIP. The feed keeps the current supported package version; changelogs retain release history. The initial 0.1.1 entry is prepared metadata. The first release run publishes the selected package version before replacing that entry and adding its real checksum.

Joomla updates through this feed require the combined package to be installed. Standalone component installation remains available; HTTP routing requires the separately installed webservices plugin.

## GitHub setup

Follow the native [git-user](https://github.com/octoleo/git-user#workflows), OctoJPack and [OctoShoom](https://github.com/octoleo/octoshoom#quick-start) examples. Configure these Actions secrets:

| Secret | Value |
| --- | --- |
| `GIT_USER`, `GIT_EMAIL` | Release Git identity. |
| `GPG_KEY`, `GPG_USER` | Signing key and its user ID. |
| `SSH_KEY`, `SSH_PUB` | Matching SSH keypair with write access to component and package repositories. |
| `GIT_TOKEN` | GitHub API token for OctoJPack to read the source repositories. |

`git-user` runs once. Both actions inherit its Git setup; OctoJPack reads `VDM_GLOBAL_TOKEN`. Configuration is passed directly to the actions. No repository placeholders, temporary action checkouts, configuration rendering or local package builders are needed.

## Release sequence

All three extension repositories provide a manual version release. In each repository, open **Actions**, select the workflow below, choose **Run workflow**, select `main` and enter the release version. The workflow creates the tag; pushing a tag by itself does not start a release.

For the first package, run these in order and wait for each to succeed:

| Order | Repository and workflow | Result |
| --- | --- | --- |
| 1 | [mcp_plugin — Release console plugin with OctoShoom](https://github.com/joomengine/mcp_plugin/actions/workflows/release.yml) | Console tag, plugin update entry and checksum. |
| 2 | [mcp_webservices — Release webservices plugin with OctoShoom](https://github.com/joomengine/mcp_webservices/actions/workflows/release.yml) | Webservices tag, plugin update entry and checksum. |
| 3 | [mcp_component — Release component with OctoJPack and OctoShoom](https://github.com/joomengine/mcp_component/actions/workflows/release.yml) | Component tag, combined package tag, package update entry and checksum. |

Choose an unused version above the development baselines. For example, `0.1.2` is suitable for all three first releases; `v0.1.2` is also accepted. The component's `0.1.0` and `0.1.1` changelog entries already describe development baselines and cannot be reused. Plugin versions may differ from the component version; the combined package always follows the component.

The six Git identity/signing/SSH secrets above must be available to **each extension repository**. An organization secret can be shared with all three. The component additionally needs `GIT_TOKEN`; its SSH identity must be able to push to both `mcp_component` and `mcp_package`. Each plugin's SSH identity must be able to push to its own repository. The package repository needs no separate release workflow or secrets: OctoJPack publishes it from the component workflow.

Once plugin tags exist, subsequent package releases can reuse them. Release a plugin again only when its source changes. Keep a pending changelog section in each repository being released.

The component workflow performs these steps:

1. Freeze component/changelog metadata, commit and create its version tag.
2. OctoJPack builds and publishes the three-extension package using the component version.
3. Update this repository's feed with the package version and tagged package URL, clearing the previous archive hash.
4. OctoShoom hashes the published package archive and commits the checksum here.

The package must exist before its hash can be calculated. A failed packaging step stops feed publication and hashing. Neither shared tool's work is duplicated locally. Rerunning keeps existing tags and preserves a matching feed entry/hash. A successful component workflow makes the package tag ZIP installable from `mcp_package` and publishes its checksum in this repository's update feed; no manual packaging or XML editing is needed.

## Changelogs and dependencies

Keep `CHANGELOG.md` and `changelog.xml` consistent, with new changes under one literal `[[[NEXT_VERSION]]]` section in each file. The release replaces the marker with its version. Create another pending section when subsequent changes begin; preserve released history. The shared XML keeps component identity and Joomla selects the changelog by version.

Use Joomla categories `security`, `fix`, `language`, `addition`, `change`, `remove` and `note`, with `item` children and matching Markdown headings.

Maintainers resolve changed dependencies with Composer and commit the lock and complete production runtime under `admin/vendor`. CI checks source installation, relocated dependencies, native metadata, and separately installed plugins. No downstream Composer run is required.
