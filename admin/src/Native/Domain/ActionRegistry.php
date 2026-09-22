<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @git        JoomEngine MCP <https://github.com/joomengine/mcp_component>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSES/joomla-mcp.txt
 * @since      0.1.0
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Domain;


use InvalidArgumentException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;


/**
 * Register reviewed native actions under unique, deterministic names.
 *
 * @since  0.1.0
 */
final class ActionRegistry
{
	/**
	 * The reviewed actions indexed by stable public identifier.
	 *
	 * @var   array<string,
	 *
	 * @since  0.1.0
	 */
	private array $actions = [];

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   iterable<ActionInterface>  $actions  The reviewed actions indexed by stable public identifier.
	 *
	 * @since  0.1.0
	 */
	public function __construct(iterable $actions = [])
	{
		foreach ($actions as $action)
		{
			$this->add($action);
		}
	}

	/**
	 * Register an action and reject duplicate public identifiers.
	 *
	 * @param   ActionInterface  $action  The action value.
	 * @return  void
	 *
	 * @since  0.1.0
	 */
	public function add(ActionInterface $action): void
	{
		$name = $action->descriptor()->name;

		if (isset($this->actions[$name]))
		{
			throw new InvalidArgumentException(sprintf('Duplicate action "%s".', $name));
		}

		$this->actions[$name] = $action;
		ksort($this->actions);
	}

	/**
	 * Resolve one registered action or report an unknown identifier.
	 *
	 * @param   string  $name  The stable public action identifier.
	 * @return  ActionInterface
	 *
	 * @since  0.1.0
	 */
	public function get(string $name): ActionInterface
	{
		return $this->actions[$name] ?? throw new ActionException(
			'UNKNOWN_ACTION',
			sprintf('Action "%s" is not registered.', $name),
		);
	}

	/**
	 * Return registered actions in deterministic identifier order.
	 *
	 *  @return list<ActionInterface>
	 *
	 * @since  0.1.0
	 */
	public function all(): array
	{
		return array_values($this->actions);
	}
}
