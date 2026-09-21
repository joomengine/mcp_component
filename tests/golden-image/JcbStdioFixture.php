<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Psr\Log\AbstractLogger;

/** Actual installed console entrypoint, or the client bridge selected by the fixture. */
final class JcbStdioFixture
{
	private Client $client;

	public function __construct()
	{
		$binary = getenv('MCP_TEST_STDIO_COMMAND') ?: PHP_BINARY;
		$override = getenv('MCP_TEST_STDIO_ARGS_JSON');
		$arguments = $override ? json_decode($override, true, 32, JSON_THROW_ON_ERROR) : [];

		if (!$override)
		{
			if (is_string(php_ini_loaded_file()))
			{
				$arguments = ['-c', php_ini_loaded_file()];
			}

			$arguments = array_merge($arguments, ['-d', 'extension_dir=' . ini_get('extension_dir'),
				JPATH_ROOT . '/cli/joomla.php', 'joomla:mcp:serve']);
		}

		if (!is_array($arguments) || !array_is_list($arguments)
			|| count(array_filter($arguments, 'is_string')) !== count($arguments))
		{
			throw new RuntimeException('Fixture stdio arguments must be a JSON string array.');
		}

		$logger = new class extends AbstractLogger
		{
			public function log($level, string|Stringable $message, array $context = []): void
			{
				if ((string) $message === 'Server stderr' && isset($context['output']))
				{
					fwrite(STDERR, $context['output'] . PHP_EOL);
				}
			}
		};
		$this->client = Client::builder()->setClientInfo('installed-jcb-acceptance', '1.0.0')
			->setInitTimeout(30)->setRequestTimeout(90)->setMaxRetries(0)->build();
		$this->client->connect(new StdioTransport($binary, $arguments, JPATH_ROOT, logger: $logger, maxBufferSize: 16777216));
	}

	public function sdk(): Client
	{
		return $this->client;
	}

	public function tool(string $name, array $arguments = []): array
	{
		$result = $this->client->callTool($name, $arguments);
		$data = json_decode(json_encode($result->structuredContent ?? [], JSON_THROW_ON_ERROR), true, 128, JSON_THROW_ON_ERROR);

		if ($result->isError)
		{
			throw new RuntimeException('Installed JCB tool ' . $name . ' failed: ' . ($data['error']['code'] ?? 'MCP_TOOL_ERROR'));
		}

		return $data;
	}

	public function disconnect(): void
	{
		$this->client->disconnect();
	}
}
