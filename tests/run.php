<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Security\Authorizer;
use VDM\Component\JoomEngineMcp\Administrator\Security\BindingValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\HandlerRegistry;


spl_autoload_register(static function (string $class): void
{
	$prefix = 'VDM\\Component\\JoomEngineMcp\\Administrator\\';

	if (str_starts_with($class, $prefix))
	{
		$path = dirname(__DIR__) . '/admin/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if (is_file($path))
		{
			require $path;
		}
	}
});

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $name) use (&$passed, &$failed): void
{
	if ($condition)
	{
		$passed++;
		echo 'PASS ' . $name . PHP_EOL;
	}
	else
	{
		$failed++;
		fwrite(STDERR, 'FAIL ' . $name . PHP_EOL);
	}
};
$rejects = static function (callable $operation, string $name) use ($check): void
{
	try
	{
		$operation();
		$check(false, $name);
	}
	catch (InvalidArgumentException | RuntimeException $exception)
	{
		$check(true, $name);
	}
};

/** Test-local identity exercises view levels separately from mutation ACL. */
$principal = new class implements PrincipalInterface
{
	/** @var bool Whether the fixture may mutate. */
	public bool $write = false;

	/** @return string Stable test identity. */
	public function getId(): string
	{
		return 'joomla:42';
	}

	/** @return string Restricted API track. */
	public function getTrack(): string
	{
		return 'api';
	}

	/** @return bool Never a trusted local identity. */
	public function isLocal(): bool
	{
		return false;
	}

	/** @return int[] Viewing levels, deliberately distinct from group IDs. */
	public function getViewLevels(): array
	{
		return [1, 7];
	}

	/** @return bool Fixture ACL with separately controlled writes. */
	public function authorise(string $action, string $asset): bool
	{
		return $action !== 'mcp.write' || $this->write;
	}
};

/** Test-local handler proves only registered objects can execute. */
$handler = new class implements HandlerInterface
{
	/** @return array<string,mixed> The validated fixture result. */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		return ['arguments' => $arguments, 'principal' => $principal->getId()];
	}
};
$registry = new HandlerRegistry(['api.request' => $handler, 'console.command' => $handler]);
$validator = new BindingValidator($registry);
$authorizer = new Authorizer();
$row = ['published' => 1, 'provider_published' => 1, 'access' => 7, 'provider_access' => 1, 'effect' => 'read'];
$check($authorizer->canView($row, $principal), 'authorized view-level discovery');
$hidden = array_replace($row, ['access' => 2]);
$check(!$authorizer->canView($hidden, $principal), 'group-like ID cannot substitute for authorized view level');
$check(!$authorizer->canView(array_replace($row, ['provider_published' => 0]), $principal), 'disabled provider is hidden');
$check(!$authorizer->canView(array_replace($row, ['published' => 0]), $principal), 'unpublished action is hidden');
$check(!$authorizer->canView(array_replace($row, ['provider_access' => 3]), $principal), 'provider access also constrains child');
$rejects(static function () use ($authorizer, $hidden, $principal): void
{
	$authorizer->requireExecution($hidden, $principal);
}, 'hidden direct invocation is denied');
$write = array_replace($row, ['effect' => 'write']);
$rejects(static function () use ($authorizer, $write, $principal): void
{
	$authorizer->requireExecution($write, $principal);
}, 'visibility alone cannot authorize writes');
$principal->write = true;
$authorizer->requireExecution($write, $principal);
$check(true, 'explicit write ACL authorizes execution predicate');
$binding = ['handler' => 'api.request', 'track' => 'api', 'method' => 'GET', 'route' => '/v1/content/articles/{id}'];
$validator->validate($binding, 'api');
$check($validator->expandRoute($binding['route'], ['id' => 42]) === '/v1/content/articles/42', 'typed route expansion');

foreach (['https://other.example/v1/users', '//other.example/v1/users', '/v1/../users', '/v1/%2e%2e/users', '/v1/users?token=x', "/v1/users\r\nHost:x", '/v1/users#x', '/v1/users//42'] as $route)
{
	$rejects(static function () use ($validator, $route): void
	{
		$validator->validateRoute($route);
	}, 'reject noncanonical route ' . json_encode($route));
}

foreach (['../users', '42/../users', '%2fusers', '', [], null] as $value)
{
	$rejects(static function () use ($validator, $binding, $value): void
	{
		$validator->expandRoute($binding['route'], ['id' => $value]);
	}, 'reject unsafe path argument ' . json_encode($value));
}

foreach (['php', 'sql', 'shell', 'class', 'file', 'url', 'baseUrl'] as $key)
{
	$rejects(static function () use ($validator, $binding, $key): void
	{
		$validator->validate(array_replace($binding, [$key => 'unsafe']), 'api');
	}, 'reject executable binding key ' . $key);
}

$rejects(static function () use ($validator, $binding): void
{
	$validator->validate(array_replace($binding, ['track' => 'cli']), 'api');
}, 'request cannot cross into local track');
$rejects(static function () use ($validator): void
{
	$validator->validate(['handler' => 'console.command', 'track' => 'api'], 'api');
}, 'console handler cannot bind to HTTP authority');
$rejects(static function () use ($registry): void
{
	$registry->get('unregistered.class');
}, 'unknown handler is never dynamically instantiated');
$rejects(static function (): void
{
	new HandlerRegistry(['arbitrary' => new stdClass()]);
}, 'registry rejects non-handler objects');

echo json_encode(['passed' => $passed, 'failed' => $failed, 'liveJoomla' => 'not run'], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
