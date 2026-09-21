<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Extension;


use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\MVCComponent;
use VDM\Component\JoomEngineMcp\Administrator\Contract\ConsoleRuntimeInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\ConsoleRuntimeProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Service\RuntimeFactory;


/**
 * Native Joomla extension exposing the shared console runtime to its plugin.
 *
 * @since 0.1.0
 */
final class JoomEngineMcpComponent extends MVCComponent implements ConsoleRuntimeProviderInterface
{
	/** @var RuntimeFactory Injected application composition root. @since 0.1.0 */
	private RuntimeFactory $runtime;

	/** @param ComponentDispatcherFactoryInterface $dispatcher Native dispatcher factory. @param RuntimeFactory $runtime Shared composition. @since 0.1.0 */
	public function __construct(ComponentDispatcherFactoryInterface $dispatcher, RuntimeFactory $runtime)
	{
		parent::__construct($dispatcher);
		$this->runtime = $runtime;
	}

	/** @inheritDoc */
	public function getConsoleRuntime(ConsoleApplication $application): ConsoleRuntimeInterface
	{
		return $this->runtime->console($application);
	}

	/**
	 * Enter the fixed worker using its one-time server-issued dispatch capability.
	 *
	 * @param ConsoleApplication $application Isolated local bootstrap.
	 * @param string $id Durable job identifier.
	 * @param string $ticket One-time worker secret delivered through stdin.
	 * @return array<string,mixed> Principal-owned observed outcome.
	 * @since 0.1.1
	 */
	public function runJobWorker(ConsoleApplication $application, string $id, string $ticket): array
	{
		return $this->runtime->work($application, $id, $ticket);
	}
}
