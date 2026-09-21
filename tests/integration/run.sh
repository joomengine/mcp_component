#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
: "${MCP_TEST_ALLOW_DESTRUCTIVE:?Set to 1 only for an explicitly disposable fixture}"
: "${JOOMLA_ROOT:?Choose an empty, disposable Joomla installation directory}"
: "${MCP_TEST_DB_TYPE:?Choose mysqli or pgsql}"
: "${MCP_TEST_DB_HOST:?Set the test database host and port}"
: "${MCP_TEST_DB_USER:?Set the isolated test database user}"
: "${MCP_TEST_DB_NAME:?Set the disposable database name}"
[[ "$MCP_TEST_ALLOW_DESTRUCTIVE" == 1 ]]
[[ "$MCP_TEST_DB_TYPE" == mysqli || "$MCP_TEST_DB_TYPE" == pgsql ]]
export JOOMLA_ROOT="$(realpath -m -- "$JOOMLA_ROOT")"
[[ "$JOOMLA_ROOT" != / && "$JOOMLA_ROOT" != "$root" && "$JOOMLA_ROOT" != "$HOME" ]]
if [[ -d "$JOOMLA_ROOT" ]] && [[ -n "$(find "$JOOMLA_ROOT" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
  echo 'Use an empty fixture directory and database; this runner will not erase an existing site.' >&2
  exit 2
fi
mkdir -p "$JOOMLA_ROOT" "$root/build/evidence"
work="$(mktemp -d)"
server=''
tls=''
cleanup() {
  if [[ -n "$tls" ]]; then
    kill "$tls" 2>/dev/null || true
    wait "$tls" 2>/dev/null || true
  fi
  if [[ -n "$server" ]]; then
    pkill -TERM -P "$server" 2>/dev/null || true
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT INT TERM
archive="${MCP_TEST_JOOMLA_ARCHIVE:-$work/joomla.tar.gz}"
if [[ ! -f "$archive" ]]; then
  curl --fail --location --proto '=https' --tlsv1.2 --retry 3 \
    https://github.com/joomla/joomla-cms/releases/download/6.1.3/Joomla_6.1.3-Stable-Full_Package.tar.gz -o "$archive"
fi
printf '%s  %s\n' '184f8c582cde5981693de7c28547c6e834c48c50cb377c7b8421bbfd33bbdf6f' "$archive" | sha256sum --check
tar --no-same-owner -xzf "$archive" -C "$JOOMLA_ROOT"
touch "$JOOMLA_ROOT/.mcp-test-fixture"
php "$JOOMLA_ROOT/installation/joomla.php" install --site-name='MCP integration fixture' \
  --admin-user='MCP Test Administrator' --admin-username=mcp_test_admin \
  --admin-password='Disposable!McpFixture321' --admin-email=mcp-fixture@example.test \
  --db-type="$MCP_TEST_DB_TYPE" --db-host="$MCP_TEST_DB_HOST" --db-user="$MCP_TEST_DB_USER" \
  --db-pass="${MCP_TEST_DB_PASS:-}" --db-name="$MCP_TEST_DB_NAME" --db-prefix=mcptest_ --no-interaction \
  > "$root/build/evidence/install-joomla.log" 2>&1
version="$(php -r 'echo (string) simplexml_load_file($argv[1])->version;' "$root/joomengine_mcp.xml")"
php "$JOOMLA_ROOT/cli/joomla.php" extension:install --path="$root/build/com_joomengine_mcp-$version.zip" --no-interaction --no-ansi \
  | tee "$root/build/evidence/install-component.log"
if [[ -n "${MCP_PLUGIN_SOURCE:-}" ]]; then
  export MCP_PLUGIN_SOURCE="$(realpath -- "$MCP_PLUGIN_SOURCE")"
  export MCP_COMPONENT_SOURCE="$root"
  [[ -f "$MCP_PLUGIN_SOURCE/joomengine_mcp.xml" && -f "$MCP_PLUGIN_SOURCE/tests/installed.php" ]]
  php "$MCP_PLUGIN_SOURCE/build.php"
  plugin_version="$(php -r 'echo (string) simplexml_load_file($argv[1])->version;' "$MCP_PLUGIN_SOURCE/joomengine_mcp.xml")"
  php "$JOOMLA_ROOT/cli/joomla.php" extension:install --path="$MCP_PLUGIN_SOURCE/build/plg_console_joomengine_mcp-$plugin_version.zip" --no-interaction --no-ansi \
    | tee "$root/build/evidence/install-console-plugin.log"
fi
export MCP_TEST_BASE_URL="http://127.0.0.1:${MCP_TEST_HTTP_PORT:-18080}"
export MCP_TEST_TOKEN_FILE="$work/token"
php "$root/tests/integration/prepare-http.php"
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${MCP_TEST_HTTP_PORT:-18080}" -t "$JOOMLA_ROOT" > "$root/build/evidence/http-server.log" 2>&1 &
server=$!
for attempt in $(seq 1 50); do
  if curl --silent --output /dev/null "$MCP_TEST_BASE_URL/api/index.php"; then break; fi
  sleep 0.2
done
for suite in installation administration http catalogue-mcp acl-mcp stdio browser; do
  php "$root/tests/integration/$suite.php" | tee "$root/build/evidence/live-$suite.log"
done
if [[ -n "${MCP_PLUGIN_SOURCE:-}" ]]; then
  php "$MCP_PLUGIN_SOURCE/tests/installed.php" | tee "$root/build/evidence/live-console-plugin.log"
fi
if [[ -n "${MCP_CLIENT_SOURCE:-}" ]]; then
  MCP_CLIENT_SOURCE="$(realpath -- "$MCP_CLIENT_SOURCE")"
  [[ -f "$MCP_CLIENT_SOURCE/tests/live.php" && -f "$MCP_CLIENT_SOURCE/vendor/autoload.php" ]]
  openssl req -x509 -newkey rsa:2048 -nodes -keyout "$work/localhost.key" -out "$work/localhost.crt" \
    -days 2 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
    > "$root/build/evidence/client-tls-setup.log" 2>&1
  node "$root/tests/integration/tls-proxy.mjs" "$MCP_TEST_BASE_URL" "${MCP_TEST_TLS_PORT:-18443}" \
    "$work/localhost.key" "$work/localhost.crt" > "$root/build/evidence/client-tls-proxy.log" 2>&1 &
  tls=$!
  export JOOMENGINE_MCP_URL="https://127.0.0.1:${MCP_TEST_TLS_PORT:-18443}"
  for attempt in $(seq 1 50); do
    if curl --silent --cacert "$work/localhost.crt" --output /dev/null "$JOOMENGINE_MCP_URL/api/index.php"; then break; fi
    sleep 0.2
  done
  JOOMENGINE_MCP_TOKEN="$(cat "$MCP_TEST_TOKEN_FILE")" \
    php -d "curl.cainfo=$work/localhost.crt" "$MCP_CLIENT_SOURCE/tests/live.php" \
    | tee "$root/build/evidence/live-client.log"
fi
export MCP_TEST_LIFECYCLE_FILE="$work/lifecycle.json"
php "$root/tests/integration/lifecycle.php" prepare | tee "$root/build/evidence/live-upgrade-prepare.log"
# Upgrading the same package must retain definitions and operator configuration.
php "$JOOMLA_ROOT/cli/joomla.php" extension:install --path="$root/build/com_joomengine_mcp-$version.zip" --no-interaction --no-ansi \
  | tee "$root/build/evidence/upgrade-component.log"
php "$root/tests/integration/lifecycle.php" verify | tee "$root/build/evidence/live-upgrade.log"
php "$root/tests/integration/installation.php" | tee "$root/build/evidence/live-upgrade-seed.log"
php "$root/tests/integration/lifecycle.php" uninstall | tee "$root/build/evidence/live-uninstall.log"
php "$root/tests/integration/package.php" | tee "$root/build/evidence/live-package-lifecycle.log"
