# Distribution and releases

The Joomla package installs the component, its owned webservices routing plugin,
and the thin console plugin. The component ZIP remains independently installable
and includes the PHP dependency tree; installation never requires Composer on the
Joomla server. The external PHP client has its own repository and release process.

## Build and verify

Install PHP 8.3 or later, Composer 2, Git, and the PHP extensions required by
composer.json plus DOM, SimpleXML and ZIP. Then run:

```bash
bash tools/build.sh
php tests/package.php
bash tools/build-distribution.sh
php tests/package.php --distribution
php tests/release.php
```

The distribution builder reads `distribution.lock.json`, checks out the exact
console plugin commit, and refuses a checkout with source modifications or a
different manifest version. To use an existing checkout without downloading it:

```bash
bash tools/build-distribution.sh /absolute/path/to/mcp_plugin
```

`build/` contains the component ZIP, console plugin ZIP, combined Joomla package
ZIP, individual SHA-256 files, and `distribution.json` recording source revisions,
versions, component working-tree status and archive hashes. Development builds may
include local component edits; the release workflow requires a clean checkout.
The package installs the component first; that
component owns its webservices plugin, avoiding duplicate package ownership and
duplicate uninstall calls. The console plugin follows the component.

`.octojpack` follows the OctoJpack package/repository/files configuration used by
Joomla Component Builder. It selects built release assets, because raw Git source
archives omit the component's Composer dependencies. Component and console
versions may differ; the package version follows the component. Our PHP builder
uses the same package metadata and the pinned console source in the lock file.
Its deterministic archives are the authoritative release artifacts. OctoJpack's
release mode selects the first ZIP asset, so the release workflow uploads the
component ZIP first, followed by the combined package. Do not reorder those assets.
The optional upstream OctoJpack publisher targets the separate `distribution`
output branch: it replaces that branch with package contents. It must never target
`main` or a development branch. The PHP builder neither creates nor pushes that
branch; normal distribution uses the GitHub release workflow below.

## Publish after review and merge

Release publication is deliberately manual. After merging the reviewed changes
into `main`, run **Publish component and Joomla package** from the Actions tab on
`main`. The workflow refuses any other branch, an existing version tag, a changed
main revision, or missing successful component, installed-Joomla and installed-JCB
workflows for that exact commit. It builds and validates the archives again,
publishes a versioned GitHub release, downloads and compares its assets, and only
then uploads verified Joomla update feeds to that release.

The component and package manifests use the corresponding
`releases/latest/download/` feed assets. Each feed's download URL points to the
immutable `releases/download/vVERSION/` ZIP and contains its SHA-256. The source
tree contains empty feeds until a release exists; development branches do not
advertise unpublished downloads. Publishing does not write directly to `main`.

Before another release, update the component version, package version in
`.octojpack`, bundled webservices version, changelog, and any required SQL update;
update the console commit and version in `distribution.lock.json` when that
dependency changes. Review and merge these changes and wait for CI. The workflow
never overwrites an existing version. If publication fails after creating a
release, inspect and repair that release explicitly before choosing a new version;
the workflow does not silently delete tags or replace release assets.
