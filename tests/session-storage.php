<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Mcp\Server;
use Mcp\Server\Transport\BaseTransport;
use Symfony\Component\Uid\Uuid;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\SessionStore;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Tests\Support\MemoryStore;
use VDM\Component\JoomEngineMcp\Tests\Support\Principal;

require dirname(__DIR__) . '/admin/autoload.php';
require __DIR__ . '/Support/MemoryStore.php';
require __DIR__ . '/Support/Principal.php';
$check = static function (bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
};
$store = new MemoryStore();
$principal = new Principal('session-size-fixture');
$envelope = new Envelope(str_repeat('session-fixture-', 3));
$sessions = new SessionStore($store, $envelope, $principal, 3600, static fn (): int => 1900000000);
$id = Uuid::v4();
$data = json_encode(['queuedResponse' => str_repeat('catalogue-data-', 170000)], JSON_THROW_ON_ERROR);
$check(strlen($data) > 1048576 && $sessions->write($id, $data), 'A valid multi-megabyte SDK response was rejected.');
$check($sessions->read($id) === $data, 'The large encrypted response did not round trip.');
$check(strlen($store->one('session', ['uuid' => $id->toRfc4122()])['data_cipher']) < 16777215, 'The encrypted response exceeds MEDIUMTEXT capacity.');
$foreign = new SessionStore($store, $envelope, new Principal('foreign-session-owner'), 3600, static fn (): int => 1900000000);
$check($foreign->read($id) === false, 'Large responses lost principal isolation.');
try
{
	$sessions->write($id, str_repeat('x', SessionStore::MAX_BYTES + 1));
	throw new RuntimeException('Oversized session state was silently accepted.');
}
catch (OperationException $error)
{
	$check($error->getIdentifier() === 'SESSION_LIMIT', 'Session size rejection lost its diagnostic.');
}
$check($sessions->read($id) === $data, 'An oversized write altered the prior encrypted session.');
unset($data);

// Exercise the SDK's response queue and the actual failed-save recovery path.
// These tool responses duplicate text + structured content inside session JSON.
$messages = [
	'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"session-fixture","version":"1"}}}',
	'{"jsonrpc":"2.0","method":"notifications/initialized"}',
	'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"large"}}',
	'{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"excessive"}}',
	'{"jsonrpc":"2.0","id":4,"method":"ping"}',
];
$transport = new class($messages) extends BaseTransport
{
	/** @var string[] Bounded request fixture. */
	private array $messages;
	/** @var array<int,array<string,mixed>> Observed protocol messages. */
	public array $responses = [];
	/** @param string[] $messages Requests. */
	public function __construct(array $messages)
	{
		parent::__construct();
		$this->messages = $messages;
	}
	/** @inheritDoc */
	public function send(string $data, array $context): void
	{
		$this->responses[] = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
	}
	/** @inheritDoc */
	public function listen(): int
	{
		foreach ($this->messages as $message)
		{
			$this->handleMessage($message, $this->sessionId);

			foreach ($this->getOutgoingMessages($this->sessionId) as $response)
			{
				$this->send($response['message'], $response['context']);
			}
		}

		return 0;
	}
};
$server = Server::builder()->setServerInfo('session-contract', '1')->setSession($sessions)
	->addTool(static fn (): array => ['description' => str_repeat('c', 700000)], name: 'large', inputSchema: ['type' => 'object'])
	->addTool(static fn (): array => ['description' => str_repeat('c', 6500000)], name: 'excessive', inputSchema: ['type' => 'object'])
	->withoutInputRequiredShim()->build();
$server->run($transport);
$responses = array_column($transport->responses, null, 'id');
$check(isset($responses[2]['result']) && !isset($responses[2]['error']), 'A response over the former session bound disappeared.');
$check(($responses[3]['error']['code'] ?? null) === -32603, 'A response beyond session storage capacity did not produce a bounded protocol error.');
$check(isset($responses[4]['result']), 'Session limit rejection corrupted the following request.');
echo json_encode(['sessionStorage' => 'passed', 'largeResponseDelivered' => true, 'oversizedResponse' => 'bounded protocol error', 'subsequentPing' => 'passed'], JSON_THROW_ON_ERROR) . PHP_EOL;
