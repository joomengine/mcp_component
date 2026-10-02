# Connect AI applications and use MCP tools directly

First complete [Joomla installation and setup](GETTING-STARTED.md). Install the [MCP package](https://github.com/joomengine/mcp_package/tags) on the Joomla site; install the separate [MCP client](https://github.com/joomengine/mcp_client) on the computer or application that will connect to it.

The installed component executes Joomla/JCB operations. The client discovers its tools, resources and prompts, carries the Joomla user's permissions and presents a remote Joomla server as a local stdio MCP process. It does not contain a separate tool catalogue.

## Choose a connection

| Your application | Connection |
| --- | --- |
| Claude Desktop, Claude Code or another application that launches local MCP processes | PHP or Docker stdio bridge below |
| MCP application with Streamable HTTP and configurable authentication headers | Connect directly to the component's HTTP endpoint |
| ChatGPT web or another remote-only connector | See [ChatGPT and remote-only chat connectors](#chatgpt-and-remote-only-chat-connectors) before configuring a URL |
| PHP application or operator using tools without an AI | [Direct SDK tool use](#use-tools-directly-without-an-ai) |

The client is a PHP SDK and command-line bridge. The component administrator pages manage definitions and operations; they do not provide a browser chat client or a tool invocation dashboard.

## Install the PHP client and save a site

Use PHP 8.3+, Composer 2 and the required PHP extensions. Named-site storage requires a POSIX system with `ext-posix`; use the [environment-only connection](#environment-only-connection) on platforms without it. No Joomla installation is needed on the client computer.

In a new directory or existing PHP project:

```bash
composer require 'joomengine/mcp-client:^1.0'
composer check-platform-reqs
./vendor/bin/joomengine-mcp help

export JOOMENGINE_MCP_CONFIG_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/joomengine-mcp"
read -r -p 'Joomla HTTPS installation base URL: ' JOOMENGINE_MCP_SITE
read -r -s -p 'Joomla API token: ' JOOMENGINE_MCP_TOKEN
printf '\n'
export JOOMENGINE_MCP_TOKEN
./vendor/bin/joomengine-mcp configure default "$JOOMENGINE_MCP_SITE"
unset JOOMENGINE_MCP_TOKEN
./vendor/bin/joomengine-mcp sites
```

Enter the Joomla installation base URL, including an installation subdirectory if used. Do not enter `/administrator`, `/api/index.php` or the full MCP endpoint here. The client appends `/api/index.php/v1/joomengine-mcp` itself. HTTPS must be reachable from the computer running the client and have a trusted certificate.

`default` is the saved site alias in these examples. Credentials remain in a private configuration file, protected by ownership and mode 0600; its directory must be mode 0700. This is filesystem-protected plaintext, not encrypted storage. `JOOMENGINE_MCP_CONFIG_DIR` makes the same directory explicit for the AI launcher.

## PHP stdio launcher

The AI application starts the client process and communicates over stdin/stdout. From the same Composer project, this command prints a complete launcher configuration with the actual PHP, executable and configuration-directory paths; it does not print the token:

```bash
php -r '
$launcher = realpath("vendor/bin/joomengine-mcp");
$directory = getenv("JOOMENGINE_MCP_CONFIG_DIR");
if ($launcher === false || !is_string($directory) || $directory === "") {
    fwrite(STDERR, "Run the installation and named-site setup first.\n");
    exit(2);
}
echo json_encode(["mcpServers" => ["joomla" => [
    "command" => PHP_BINARY,
    "args" => [$launcher, "serve", "default"],
    "env" => ["JOOMENGINE_MCP_CONFIG_DIR" => $directory]
]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
'
```

Use the printed `joomla` entry in your application's MCP settings. Applications that use separate fields need the same command, arguments and environment values; their configuration format may differ. Keep this project at the recorded path and run the AI application as the same operating-system user that saved the credentials.

For **Claude Desktop**, open its desktop settings, select **Developer → Edit Config**, and merge the printed entry into `mcpServers` in `claude_desktop_config.json`. Preserve existing entries, save, fully quit and restart Claude, then inspect the connected server's tools. See the [official local-server guide](https://modelcontextprotocol.io/docs/develop/connect-local-servers).

For **Claude Code**, register the saved alias from the project directory:

```bash
claude mcp add --env "JOOMENGINE_MCP_CONFIG_DIR=$JOOMENGINE_MCP_CONFIG_DIR" --transport stdio --scope user joomla -- "$(php -r 'echo PHP_BINARY;')" "$(realpath vendor/bin/joomengine-mcp)" serve default
claude mcp list
```

This registers the explicit configuration directory with the launcher. Open `/mcp` inside Claude Code to inspect connection status. See the [official Claude Code MCP reference](https://code.claude.com/docs/en/mcp).

### Environment-only connection

Instead of saving an alias, set `JOOMENGINE_MCP_URL` to the HTTPS Joomla installation base URL and `JOOMENGINE_MCP_TOKEN` to its user's API token through the AI application's environment or secret settings. Launch the installed executable with the single argument `connect`.

| Launcher field | Value |
| --- | --- |
| Command | Absolute path to PHP |
| Arguments | Absolute path to `vendor/bin/joomengine-mcp`, then `connect` |
| Environment | `JOOMENGINE_MCP_URL`, `JOOMENGINE_MCP_TOKEN` |

This mode does not need POSIX credential storage. Obtain the actual paths with `php -r 'echo PHP_BINARY, PHP_EOL, realpath("vendor/bin/joomengine-mcp"), PHP_EOL;'`. Never pass the token as a command-line argument.

## Docker stdio launcher

Docker Engine and Compose v2 provide the PHP runtime. Clone the released client source, build it once and configure the AI application's launcher:

```bash
git clone --branch v1.0.0 --depth 1 https://github.com/joomengine/mcp_client.git
cd mcp_client
docker compose build --pull mcp
realpath compose.yaml
```

| Launcher field | Value |
| --- | --- |
| Command | `docker` |
| Arguments, in order | `compose`, `--file`, the absolute `compose.yaml` path printed above, `run`, `--rm`, `--no-deps`, `-T`, `mcp` |
| Environment/secret settings | `JOOMENGINE_MCP_URL` = HTTPS Joomla base URL; `JOOMENGINE_MCP_TOKEN` = that user's Joomla API token |

For an `mcpServers` configuration, generate the command and argument entry using PHP already inside the built image:

```bash
docker compose run --rm --no-deps -T --entrypoint php mcp -r '
echo json_encode(["mcpServers" => ["joomla" => [
    "command" => "docker",
    "args" => ["compose", "--file", $argv[1], "run", "--rm", "--no-deps", "-T", "mcp"]
]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
' "$(realpath compose.yaml)"
```

Merge the printed entry and supply the two environment values through your application's environment/secret configuration. The Docker process inherits those values and forwards them to the container. Build before connecting so build output does not enter the MCP protocol. Use `run -T`, which preserves stdio, rather than `up`. The image exposes no HTTP port. For private certificate authorities and full Docker operation, see [the client Docker guide](https://github.com/joomengine/mcp_client/blob/main/docs/DOCKER.md).

## Direct Streamable HTTP connection

A compatible MCP application can bypass the stdio bridge:

| Setting | Value |
| --- | --- |
| Transport | Streamable HTTP |
| URL | Joomla installation base URL followed by `/api/index.php/v1/joomengine-mcp` |
| Authentication header | `X-Joomla-Token` with the Joomla API token, or `Authorization: Bearer` followed by the token |
| Authority | The token user's Joomla API login, MCP access and action permissions |

The webservices plugin must be enabled and the component's configured API base must match this installation. The HTTP session ID is not a credential. This server uses Joomla's static-token authentication; it does not provide an MCP OAuth authorization flow.

## ChatGPT and remote-only chat connectors

ChatGPT web connects to remote MCP URLs; it cannot start `joomengine-mcp serve default` on your computer. Its [official developer-mode instructions](https://developers.openai.com/api/docs/guides/developer-mode) describe streaming HTTP/SSE and OAuth, no-authentication or mixed authentication. They do not document a field for this component's `X-Joomla-Token` header.

Consequently, the PHP/Docker launcher above is ready for local stdio applications, but is not by itself a ChatGPT web connector. A remote-only application must support Joomla's token header directly, or use a separately hosted compatibility adapter that implements the application's supported authentication and supplies the Joomla token upstream. This repository does not include that adapter or an HTTP listener for the standalone client. An OAuth-required connector also needs an OAuth-capable adapter.

Once a compatible remote endpoint and authentication are available, enable developer mode, create a connection with that endpoint, inspect the discovered tools and select it in the conversation. Follow the provider's current instructions and workspace controls. A URL to the client repository, a Joomla administrator page, or a local stdio command cannot replace that endpoint.

## Verify and use an AI connection

First ask the connected agent to list available sites and capabilities using discovered read tools. With the seeded catalogue, `joomla_sites_list` accepts `{}`; use the returned site identifiers for later calls rather than guessing one.

A useful first instruction is: “List the Joomla sites and capabilities available to this MCP connection. Show what you can read before making changes.” Then request a specific read or change. The installed definitions determine which Joomla and JCB features are available.

Writes retain Joomla ACL and server confirmation rules. A tool may return a plan or permission request before it can execute; an AI's own approval dialog does not replace the server's grant. Follow the returned tool schemas and challenges. For long-running operations, inspect the returned job/operation identifier and status; losing a connection does not prove cancellation or rollback.

## Use tools directly without an AI

The same PHP client can list and invoke tools without any chatbot. After the Composer installation and named `default` site setup above, save the following as `mcp-tools.php` in that Composer project's root:

```php
<?php

use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Configuration\SiteStore;

if (PHP_SAPI !== 'cli' || count($argv) > 3)
{
	fwrite(STDERR, "Usage: php mcp-tools.php [list|call] [site-alias]\n");
	exit(2);
}

require __DIR__ . '/vendor/autoload.php';

$mode = $argv[1] ?? 'list';
$alias = $argv[2] ?? 'default';

if (!in_array($mode, ['list', 'call'], true))
{
	fwrite(STDERR, "Use list or call.\n");
	exit(2);
}

$client = null;
$status = 0;

try
{
	$directory = getenv('JOOMENGINE_MCP_CONFIG_DIR');
	$store = new SiteStore(is_string($directory) && $directory !== '' ? $directory : null);
	$client = (new ClientFactory())->connect($store->connection($alias));
	$tools = [];
	$cursor = null;
	$seen = [];

	do
	{
		$page = $client->listTools($cursor);

		foreach ($page->tools as $tool)
		{
			$tools[$tool->name] = $tool;
		}

		$cursor = $page->nextCursor;

		if ($cursor !== null)
		{
			if (isset($seen[$cursor]) || count($seen) >= 100)
			{
				throw new RuntimeException('Tool discovery did not terminate within its bound.');
			}

			$seen[$cursor] = true;
		}
	}
	while ($cursor !== null);

	ksort($tools);

	foreach ($tools as $name => $tool)
	{
		echo $name, PHP_EOL;
	}

	if ($mode === 'call')
	{
		fwrite(STDERR, "Tool name from the list: ");
		$name = trim((string) fgets(STDIN));

		if (!isset($tools[$name]))
		{
			throw new RuntimeException('Choose a discovered tool.');
		}

		echo json_encode($tools[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			| JSON_THROW_ON_ERROR), PHP_EOL;
		fwrite(STDERR, "Arguments as one JSON object ({} if none): ");
		$arguments = json_decode((string) fgets(STDIN), false, 64, JSON_THROW_ON_ERROR);

		if (!$arguments instanceof stdClass)
		{
			throw new RuntimeException('Arguments must be a JSON object.');
		}

		fwrite(STDERR, "Type CALL to execute this tool with these arguments: ");

		if (trim((string) fgets(STDIN)) !== 'CALL')
		{
			throw new RuntimeException('Call not submitted.');
		}

		$result = $client->callTool($name, get_object_vars($arguments));
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			| JSON_THROW_ON_ERROR), PHP_EOL;
		$status = $result->isError ? 1 : 0;
	}
}
catch (Throwable)
{
	fwrite(STDERR, "MCP request failed or was not submitted. Check configuration, input and permissions.\n");
	$status = 1;
}
finally
{
	if ($client !== null)
	{
		try
		{
			$client->disconnect();
		}
		catch (Throwable)
		{
			fwrite(STDERR, "MCP session cleanup failed.\n");
			$status = 1;
		}
	}
}

exit($status);
```

Run `php mcp-tools.php` to list tools. Run `php mcp-tools.php call` to select a discovered tool, inspect its full schema, enter arguments and execute it explicitly. Start with `joomla_sites_list` and `{}`. To use another saved site alias, run `php mcp-tools.php call` followed by that alias.

The script sends one selected tool call and prints its content, structured result and `isError` value. Its local `CALL` prompt does not grant server write permission. For writes, use the server's discovered planning, approval and apply tools in the required order. Do not repeat a write after an ambiguous network failure; read its operation state first.

The SDK also exposes `listResources()`, `listResourceTemplates()`, `readResource()`, `listPrompts()` and `getPrompt()`; use discovered URIs, names and schemas. These capabilities can be empty on your site. See the [client PHP API](https://github.com/joomengine/mcp_client#php-api) and [server transport contract](https://github.com/joomengine/mcp_client/blob/main/docs/SERVER-CONTRACT.md).

## Connection troubleshooting

| Symptom | Check |
| --- | --- |
| Client waits with no ordinary output | `serve` and `connect` wait for MCP JSON-RPC; use an AI launcher or the SDK script rather than expecting a terminal menu |
| HTTP 404 | Package/webservices installation, enabled plugin, installation subdirectory and exact API route |
| HTTP 401/403 | Joomla API token, API authentication plugins, `core.login.api`, MCP access and action permissions |
| No tools, or a missing feature | Published definitions, enabled provider/extension and authenticated user's ACL; the client cannot add absent server features |
| Named-site startup failure | Same OS user, explicit configuration directory, POSIX support and private ownership/modes |
| Docker cannot reach the site | Container DNS/routing and trusted certificate; container `localhost` is the container itself |
| Timeout during a write/job | Read the resulting operation/job state before resubmitting |

See [site setup](GETTING-STARTED.md), [write confirmations](GETTING-STARTED.md#plan-and-confirm-writes), [JCB jobs](GETTING-STARTED.md#enable-jcb-operations-and-background-jobs), [administrator operations](GETTING-STARTED.md#use-the-administrator-areas), [security](../SECURITY.md) and the [client README](https://github.com/joomengine/mcp_client).

