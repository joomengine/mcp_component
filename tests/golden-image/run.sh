#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
out="$root/build/evidence/golden"
mkdir -p "$out"
compose() { docker compose -f "$root/tests/golden-image/compose.yml" "$@"; }
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  compose logs --no-color > "$out/container.log" 2>&1 || true
  compose down -v --remove-orphans > "$out/cleanup.log" 2>&1 || true
  exit "$status"
}
trap cleanup EXIT INT TERM
compose up -d
ready=0
for attempt in $(seq 1 180); do
  compose logs --no-color joomla > "$out/startup.log" 2>&1
  if grep -qF 'Joomla CLI command failed:' "$out/startup.log"; then
    tail -80 "$out/startup.log" >&2
    exit 1
  fi
  if grep -qF 'Joomla CLI command succeeded: extension:install --path /usr/src/joomengine/jcb.zip' "$out/startup.log"; then
    ready=1
    break
  fi
  sleep 5
done
if [[ "$ready" != 1 ]]; then
  tail -80 "$out/startup.log" >&2
  echo 'The golden image did not finish its native Joomla/JCB installation.' >&2
  exit 1
fi
version="$(php -r 'echo (string) simplexml_load_file($argv[1])->version;' joomengine_mcp.xml)"
compose exec -T joomla mkdir -p /tmp/mcp-component/tests /tmp/mcp-evidence
compose cp tests/. joomla:/tmp/mcp-component/tests/
compose cp "build/com_joomengine_mcp-$version.zip" joomla:/tmp/mcp-component.zip
# JCB installs its library tree through its own installer, not a test-side copy.
(cd build/jcb-source && zip -qr "$root/build/jcb-under-test.zip" . -x '.git/*' '.github/*' 'libraries/vendor_jcb/tests/*')
compose cp build/jcb-under-test.zip joomla:/tmp/jcb-under-test.zip
compose exec -T joomla php /var/www/html/cli/joomla.php extension:install --path=/tmp/jcb-under-test.zip --no-interaction --no-ansi > "$out/install-jcb.log" 2>&1
expected="$(sha256sum build/jcb-source/libraries/vendor_jcb/VDM.Joomla/src/Componentbuilder/Console/Compiler.php | cut -d' ' -f1)"
actual="$(compose exec -T joomla sha256sum /var/www/html/libraries/vendor_jcb/VDM.Joomla/src/Componentbuilder/Console/Compiler.php | cut -d' ' -f1 | tr -d '\r')"
[[ "$actual" == "$expected" ]] || { echo 'The native JCB install did not deploy the tested source.' >&2; exit 1; }
printf '%s\n' "$actual" > "$out/installed-jcb-compiler.sha256"
compose exec -T joomla php /var/www/html/cli/joomla.php extension:install --path=/tmp/mcp-component.zip --no-interaction --no-ansi > "$out/install-mcp.log" 2>&1
compose exec -T joomla touch /var/www/html/.mcp-test-fixture
fixture() {
  compose exec -T -e MCP_TEST_ALLOW_DESTRUCTIVE=1 -e JOOMLA_ROOT=/var/www/html \
    -e MCP_TEST_BASE_URL=http://127.0.0.1:80 -e MCP_TEST_TOKEN_FILE=/tmp/mcp-fixture-token \
    joomla php "$@"
}
fixture /tmp/mcp-component/tests/golden-image/prepare.php > "$out/prepare.log" 2>&1
fixture /tmp/mcp-component/tests/integration/prepare-http.php >> "$out/prepare.log" 2>&1
for suite in installation administration http browser; do
  fixture "/tmp/mcp-component/tests/integration/$suite.php" > "$out/$suite.log" 2>&1
done
fixture /tmp/mcp-component/tests/golden-image/registry.php > "$out/jcb-command-registry.json" 2> "$out/registry-errors.log"
php -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $c=array_filter($v["commands"]??[],static fn($c)=>str_starts_with($c["name"],"componentbuilder:")); if(count($c)<2)throw new RuntimeException("The installed JCB command registry is empty."); echo "Verified ",count($c)," native JCB command definitions\n";' "$out/jcb-command-registry.json" > "$out/registry.log"
compose exec -T joomla php /var/www/html/cli/joomla.php extension:install --path=/tmp/mcp-component.zip --no-interaction --no-ansi > "$out/upgrade-mcp.log" 2>&1
fixture /tmp/mcp-component/tests/integration/installation.php > "$out/upgraded-installation.log" 2>&1
printf '%s\n' 'Native golden-image installation, administration, HTTP, command-registry and upgrade tests passed.' > "$out/summary.txt"
cat "$out/summary.txt"
