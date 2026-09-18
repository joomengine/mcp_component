<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Psr\Log\AbstractLogger;

/**
 * Real SDK-to-installed-component stdio fixture without HTTP credentials.
 *
 * The child process boots Joomla normally and returns JSON-RPC, never mocked
 * native operation results. The explicit fixture bootstrap guards every child.
 *
 * @since  0.1.0
 */
final class StdioFixture
{
	/** @var Client Actual official SDK connection. @since 0.1.0 */
	private Client $client;

	/**
	 * Launch the same PHP configuration as the current disposable test process.
	 *
	 * @param   int  $timeout  Maximum seconds per MCP request.
	 * @since   0.1.0
	 */
	public function __construct(int $timeout = 60)
	{
		$arguments = [];
		if (is_string(php_ini_loaded_file()))
		{
			$arguments = ['-c', php_ini_loaded_file()];
		}
		$arguments = array_merge($arguments, ['-d', 'extension_dir=' . ini_get('extension_dir'), __DIR__ . '/serve-cli.php']);
		/** Only native child diagnostics are printed; JSON-RPC frames are not logged. */
		$logger = new class extends AbstractLogger
		{
			/** @return void Preserve child failure diagnostics without SDK request logging. */
			public function log($level, string|Stringable $message, array $context = []): void
			{
				if ((string) $message === 'Server stderr' && isset($context['output']))
				{
					fwrite(STDERR, $context['output'] . PHP_EOL);
				}
			}
		};
		$this->client = Client::builder()->setClientInfo('installed-stdio-fixture', '1.0.0')
			->setInitTimeout(15)->setRequestTimeout($timeout)->setMaxRetries(0)->build();
		$this->client->connect(new StdioTransport(PHP_BINARY, $arguments, JPATH_ROOT, logger: $logger, maxBufferSize: 16777216));
	}

	/** @return Client The SDK connection for protocol assertions. @since 0.1.0 */
	public function sdk(): Client
	{
		return $this->client;
	}

	/**
	 * Require one structured MCP tool result from the installed child.
	 *
	 * @param   string               $name       Discovered tool name.
	 * @param   array<string,mixed>   $arguments  Valid tool arguments.
	 * @return  array<string,mixed>
	 * @since   0.1.0
	 */
	public function tool(string $name, array $arguments = []): array
	{
		$result = $this->client->callTool($name, $arguments);
		$data = json_decode(json_encode($result->structuredContent ?? [], JSON_THROW_ON_ERROR), true, 128, JSON_THROW_ON_ERROR);
		if ($result->isError)
		{
			throw new RuntimeException('Installed stdio tool ' . $name . ' failed; inspect the server audit for its execution outcome.');
		}
		return $data;
	}

	/** @return void Close the child without leaving a long-lived fixture process. @since 0.1.0 */
	public function disconnect(): void
	{
		$this->client->disconnect();
	}
}
