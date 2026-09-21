<?php
/**
 * Minimal bootstrap double for real detached-process tests, never a Joomla fixture.
 */
namespace Joomla\CMS\Session;
class Session
{
}

namespace Joomla\Session;
interface SessionInterface
{
}

namespace Joomla\CMS\Uri;
class Uri
{
	public static function getInstance(string $url): self
	{
		return new self();
	}
	public function toString(array $parts): string
	{
		return 'fixture.invalid';
	}
	public function getPath(): string
	{
		return '/';
	}
	public function getScheme(): string
	{
		return 'https';
	}
}

namespace Joomla\CMS;
class Factory
{
	public static mixed $application;
	public static function getContainer(): object
	{
		return new class
		{
			public function alias(string $name, string $target): self
			{
				return $this;
			}
			public function get(string $class): object
			{
				return new \Joomla\CMS\Application\ConsoleApplication();
			}
		};
	}
}

namespace Joomla\CMS\Application;
class ConsoleApplication
{
	public function get(string $name): string
	{
		return '';
	}
	public function createExtensionNamespaceMap(): void
	{
	}
	public function bootComponent(string $component): object
	{
		return new class
		{
			public function runJobWorker(ConsoleApplication $application, string $id, string $ticket): array
			{
				usleep(250000);
				echo 'Native diagnostics must never contaminate the parent IPC response.';
				$result = ['jobId' => $id, 'ticketHash' => hash('sha256', $ticket), 'sapi' => PHP_SAPI,
					'stdin' => is_resource(STDIN), 'stdout' => is_resource(STDOUT), 'stderr' => is_resource(STDERR)];
				file_put_contents(JPATH_BASE . '/completed.json', json_encode($result, JSON_THROW_ON_ERROR));

				return $result;
			}
		};
	}
}
