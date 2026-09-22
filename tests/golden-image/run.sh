#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
: "${MCP_PLUGIN_SOURCE:?Set the reviewed console plugin checkout}"
MCP_PLUGIN_SOURCE="$(realpath -- "$MCP_PLUGIN_SOURCE")"
[[ -f "$MCP_PLUGIN_SOURCE/joomengine_mcp.xml" && -f "$MCP_PLUGIN_SOURCE/tests/installed.php" ]]
out="$root/build/evidence/golden"
mkdir -p "$out"
tls=''
work="$(mktemp -d)"
compose() { docker compose -f "$root/tests/golden-image/compose.yml" "$@"; }
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  if [[ -n "$tls" ]]; then
    kill "$tls" 2>/dev/null || true
    wait "$tls" 2>/dev/null || true
  fi
  rm -rf -- "$work"
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
php "$MCP_PLUGIN_SOURCE/build.php"
plugin_version="$(php -r 'echo (string) simplexml_load_file($argv[1])->version;' "$MCP_PLUGIN_SOURCE/joomengine_mcp.xml")"
git -C "$MCP_PLUGIN_SOURCE" rev-parse HEAD > "$out/console-plugin-source.txt"
compose exec -T joomla mkdir -p /tmp/mcp-plugin/tests
compose cp "$MCP_PLUGIN_SOURCE/tests/installed.php" joomla:/tmp/mcp-plugin/tests/installed.php
compose cp "$MCP_PLUGIN_SOURCE/build/plg_console_joomengine_mcp-$plugin_version.zip" joomla:/tmp/mcp-console-plugin.zip
compose exec -T joomla php /var/www/html/cli/joomla.php extension:install --path=/tmp/mcp-console-plugin.zip --no-interaction --no-ansi > "$out/install-console-plugin.log" 2>&1
compose exec -T joomla touch /var/www/html/.mcp-test-fixture
compose exec -T joomla php /var/www/html/cli/joomla.php joomla:mcp:jcb-sync --no-interaction --no-ansi > "$out/jcb-catalogue-sync.json" 2> "$out/jcb-catalogue-sync-errors.log"
fixture() {
  compose exec -T -e MCP_TEST_ALLOW_DESTRUCTIVE=1 -e JOOMLA_ROOT=/var/www/html \
    -e MCP_COMPONENT_SOURCE=/tmp/mcp-component \
    -e MCP_TEST_BASE_URL=http://127.0.0.1:80 -e MCP_TEST_TOKEN_FILE=/tmp/mcp-fixture-token \
    -e MCP_TEST_LIFECYCLE_FILE=/tmp/mcp-lifecycle.json \
    joomla php "$@"
}
fixture /tmp/mcp-component/tests/golden-image/prepare.php > "$out/prepare.log" 2>&1
fixture /tmp/mcp-component/tests/integration/prepare-http.php >> "$out/prepare.log" 2>&1
for suite in installation administration http catalogue-mcp acl-mcp stdio browser jcb-api job-worker; do
  fixture "/tmp/mcp-component/tests/integration/$suite.php" > "$out/$suite.log" 2>&1
done
fixture /tmp/mcp-plugin/tests/installed.php > "$out/console-plugin.log" 2>&1
fixture /tmp/mcp-component/tests/jcb-files.php > "$out/jcb-files.log" 2>&1
fixture /tmp/mcp-component/tests/jcb-output.php > "$out/jcb-output.log" 2>&1
fixture /tmp/mcp-component/tests/jcb-permissions.php > "$out/jcb-permissions.log" 2>&1
fixture /tmp/mcp-component/tests/jcb-workspace.php > "$out/jcb-workspace.log" 2>&1
fixture /tmp/mcp-component/tests/jcb-bootstrap.php > "$out/jcb-bootstrap.log" 2>&1
fixture /tmp/mcp-component/tests/jcb-results.php > "$out/jcb-results.log" 2>&1
fixture /tmp/mcp-component/tests/golden-image/jcb-acceptance.php > "$out/jcb-acceptance.log" 2>&1
fixture /tmp/mcp-component/tests/golden-image/package-roundtrip.php > "$out/package-roundtrip.log" 2>&1
if [[ -n "${MCP_CLIENT_SOURCE:-}" ]]; then
  MCP_CLIENT_SOURCE="$(realpath -- "$MCP_CLIENT_SOURCE")"
  [[ -f "$MCP_CLIENT_SOURCE/bin/joomengine-mcp" && -f "$MCP_CLIENT_SOURCE/vendor/autoload.php" ]]
  git -C "$MCP_CLIENT_SOURCE" rev-parse HEAD > "$out/client-source.txt"
  compose exec -T joomla mkdir -p /tmp/mcp-client
  for path in bin src vendor composer.json; do
    compose cp "$MCP_CLIENT_SOURCE/$path" "joomla:/tmp/mcp-client/$path"
  done
  openssl req -x509 -newkey rsa:2048 -nodes -keyout "$work/client.key" -out "$work/client.crt" \
    -days 2 -subj '/CN=host.docker.internal' -addext 'subjectAltName=DNS:host.docker.internal' \
    > "$out/client-tls-setup.log" 2>&1
  compose cp "$work/client.crt" joomla:/tmp/mcp-client-ca.crt
  # Joomla's canonical internal port is 80; the Docker host publishes it on 18080.
  node "$root/tests/integration/tls-proxy.mjs" "http://127.0.0.1:${MCP_TEST_GOLDEN_HTTP_PORT:-18080}" \
    "${MCP_TEST_GOLDEN_TLS_PORT:-18443}" "$work/client.key" "$work/client.crt" 0.0.0.0 127.0.0.1:80 > "$out/client-tls-proxy.log" 2>&1 &
  tls=$!
  tls_ready=0
  for attempt in $(seq 1 50); do
    if curl --silent --noproxy '*' --cacert "$work/client.crt" \
      --resolve "host.docker.internal:${MCP_TEST_GOLDEN_TLS_PORT:-18443}:127.0.0.1" \
      --output /dev/null "https://host.docker.internal:${MCP_TEST_GOLDEN_TLS_PORT:-18443}/api/index.php"; then
      tls_ready=1
      break
    fi
    sleep 0.2
  done
  [[ "$tls_ready" == 1 ]] || { echo 'The trusted client TLS fixture did not start.' >&2; exit 1; }
  bridge_args="$(php -r 'echo json_encode(["-d", "curl.cainfo=/tmp/mcp-client-ca.crt", "/tmp/mcp-client/bin/joomengine-mcp", "connect", $argv[1]], JSON_THROW_ON_ERROR);' "https://host.docker.internal:${MCP_TEST_GOLDEN_TLS_PORT:-18443}")"
  for scenario in jcb-acceptance package-roundtrip; do
  compose exec -T -e MCP_TEST_ALLOW_DESTRUCTIVE=1 -e JOOMLA_ROOT=/var/www/html \
    -e MCP_COMPONENT_SOURCE=/tmp/mcp-component -e MCP_TEST_BASE_URL=http://127.0.0.1:80 \
    -e MCP_TEST_TOKEN_FILE=/tmp/mcp-fixture-token -e MCP_TEST_JCB_TRANSPORT=api \
    -e MCP_TEST_STDIO_COMMAND=php -e "MCP_TEST_STDIO_ARGS_JSON=$bridge_args" \
    joomla sh -c 'export JOOMENGINE_MCP_TOKEN="$(cat /tmp/mcp-fixture-token)"; exec php "$1"' sh \
    "/tmp/mcp-component/tests/golden-image/$scenario.php" > "$out/client-$scenario.log" 2>&1
  done
fi
fixture /tmp/mcp-component/tests/golden-image/registry.php > "$out/jcb-command-registry.json" 2> "$out/registry-errors.log"
php -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $c=array_filter($v["commands"]??[],static fn($c)=>str_starts_with($c["name"],"componentbuilder:")); if(count($c)<2)throw new RuntimeException("The installed JCB command registry is empty."); echo "Verified ",count($c)," native JCB command definitions\n";' "$out/jcb-command-registry.json" > "$out/registry.log"
fixture /tmp/mcp-component/tests/integration/lifecycle.php prepare > "$out/upgrade-prepare.log" 2>&1
compose exec -T joomla php /var/www/html/cli/joomla.php extension:install --path=/tmp/mcp-component.zip --no-interaction --no-ansi > "$out/upgrade-mcp.log" 2>&1
fixture /tmp/mcp-component/tests/integration/lifecycle.php verify > "$out/upgrade-verify.log" 2>&1
fixture /tmp/mcp-component/tests/integration/installation.php > "$out/upgraded-installation.log" 2>&1
fixture /tmp/mcp-component/tests/integration/lifecycle.php uninstall > "$out/uninstall.log" 2>&1
printf '%s\n' 'Native golden-image installation, administrator-to-MCP, HTTP ACL, stdio CRUD, exact JCB inventory, actual package/compiler jobs, owned ZIP downloads, configured remote HTTPS bridge, upgrade and uninstall tests passed.' > "$out/summary.txt"
cat "$out/summary.txt"
