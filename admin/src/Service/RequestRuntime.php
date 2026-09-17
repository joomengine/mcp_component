<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Mcp\Server;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ServerFactory;
use VDM\Component\JoomEngineMcp\Administrator\Protocol\ToolDispatcher;


/**
 * Request-scoped composed services without a mutable service locator.
 *
 * @since 0.1.0
 */
final class RequestRuntime
{
	/** @var ServerFactory Protocol construction. @since 0.1.0 */
	private ServerFactory $servers;
	/** @var ToolDispatcher Database-selected tool primitives. @since 0.1.0 */
	private ToolDispatcher $tools;
	/** @var ActionExecutor Shared verified action engine. @since 0.1.0 */
	private ActionExecutor $actions;
	/** @var Catalogue Current authorized definitions. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var Settings Installation bounds. @since 0.1.0 */
	private Settings $settings;

	/** @param ServerFactory $servers Protocol construction. @param ToolDispatcher $tools Tool execution. @param ActionExecutor $actions Semantic actions. @param Catalogue $catalogue Definitions. @param Settings $settings Bounds. @since 0.1.0 */
	public function __construct(ServerFactory $servers, ToolDispatcher $tools, ActionExecutor $actions, Catalogue $catalogue, Settings $settings)
	{
		$this->servers = $servers;
		$this->tools = $tools;
		$this->actions = $actions;
		$this->catalogue = $catalogue;
		$this->settings = $settings;
	}

	/** @return Server Newly composed server over the same request authority. @since 0.1.0 */
	public function server(): Server
	{
		return $this->servers->create();
	}

	/** @return ToolDispatcher Protocol primitives. @since 0.1.0 */
	public function tools(): ToolDispatcher
	{
		return $this->tools;
	}

	/** @return ActionExecutor Verified operation services. @since 0.1.0 */
	public function actions(): ActionExecutor
	{
		return $this->actions;
	}

	/** @return Catalogue Principal-filtered definitions. @since 0.1.0 */
	public function catalogue(): Catalogue
	{
		return $this->catalogue;
	}

	/** @return Settings Validated bounds. @since 0.1.0 */
	public function settings(): Settings
	{
		return $this->settings;
	}
}
