#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
plugin_ref="$(php -r '$lock=json_decode(file_get_contents("distribution.lock.json"),true,64,JSON_THROW_ON_ERROR); echo $lock["console"]["ref"];')"
[[ "$plugin_ref" =~ ^[a-f0-9]{40}$ ]]
plugin_source="${1:-$root/build/distribution-plugin-source}"
if [[ $# -eq 0 && ! -d "$plugin_source/.git" ]]; then
  mkdir -p "$plugin_source"
  git -C "$plugin_source" init --quiet
  git -C "$plugin_source" remote add origin https://github.com/joomengine/mcp_plugin.git
  git -C "$plugin_source" fetch --depth=1 origin "$plugin_ref"
  git -C "$plugin_source" checkout --detach FETCH_HEAD
fi
[[ -e "$plugin_source/.git" && -f "$plugin_source/build.php" ]]
[[ "$(git -C "$plugin_source" rev-parse HEAD)" == "$plugin_ref" ]]
if [[ -n "$(git -C "$plugin_source" status --porcelain --untracked-files=all)" ]]; then
  echo 'The console source has changes; build from the immutable locked commit.' >&2
  exit 1
fi
component_revision="$(git rev-parse HEAD)"
component_modified=0
if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
  component_modified=1
fi
SOURCE_DATE_EPOCH=946684800 php "$plugin_source/build.php"
php tools/distribution.php "$plugin_source" "$component_revision" "$plugin_ref" "$component_modified"
