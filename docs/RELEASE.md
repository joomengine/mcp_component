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

Tag reviewed releases of both plugins first. Then run **Release component with OctoJPack and OctoShoom** on `main`, entering the next unused version, such as `1.2.3` or `v1.2.3`.

1. Freeze component/changelog metadata, commit and create its version tag.
2. OctoJPack builds and publishes the three-extension package using the component version.
3. Update this repository's feed with the package version and tagged package URL, clearing the previous archive hash.
4. OctoShoom hashes the published package archive and commits the checksum here.

The package must exist before its hash can be calculated. A failed packaging step stops feed publication and hashing. Neither shared tool's work is duplicated locally. Rerunning keeps existing tags and preserves a matching feed entry/hash.

## Changelogs and dependencies

Keep `CHANGELOG.md` and `changelog.xml` consistent, with new changes under one literal `[[[NEXT_VERSION]]]` section in each file. The release replaces the marker with its version. Create another pending section when subsequent changes begin; preserve released history. The shared XML keeps component identity and Joomla selects the changelog by version.

Use Joomla categories `security`, `fix`, `language`, `addition`, `change`, `remove` and `note`, with `item` children and matching Markdown headings.

Maintainers resolve changed dependencies with Composer and commit the lock and complete production runtime under `admin/vendor`. CI checks source installation, relocated dependencies, native metadata, and separately installed plugins. No downstream Composer run is required.
