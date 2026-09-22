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
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Protocol;


use JsonException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\CapabilityResolverInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionRegistry;


/**
 * Serialize the native action catalogue with effective permission metadata.
 *
 * @since  0.1.0
 */
final class DescriptionService
{
	/**
	 * The registry of reviewed native action implementations.
	 *
	 * @var   ActionRegistry
	 *
	 * @since  0.1.0
	 */
	private ActionRegistry $registry;

	/**
	 * The current actor capability resolver.
	 *
	 * @var   CapabilityResolverInterface
	 *
	 * @since  0.1.0
	 */
	private CapabilityResolverInterface $capabilities;

	/**
	 * Initialize the reviewed dependencies and configuration.
	 *
	 * @param   ActionRegistry               $registry      The registry of reviewed native action implementations.
	 * @param   CapabilityResolverInterface  $capabilities  The current actor capability resolver.
	 *
	 * @since  0.1.0
	 */
	public function __construct(
		ActionRegistry $registry,
		CapabilityResolverInterface $capabilities,
	)
	{
		$this->registry = $registry;
		$this->capabilities = $capabilities;
	}

	/**
	 * Encode the available native catalogue and effective capabilities.
	 *
	 *  @throws JsonException
	 * @return  string
	 *
	 * @since  0.1.0
	 */
	public function toJson(): string
	{
		$actions = [];

		foreach ($this->registry->all() as $action)
		{
			$descriptor = $action->descriptor();
			$actions[] = array_merge(
				$descriptor->jsonSerialize(),
				['effective' => $this->capabilities->resolve($descriptor)],
			);
		}

		return json_encode([
			'protocol' => RequestDecoder::PROTOCOL,
			'companion' => [
				'name' => 'pkg_joomlamcp',
				'version' => '0.7.0',
				'joomla' => defined('JVERSION') ? JVERSION : null,
				'php' => PHP_VERSION,
			],
			'actor' => $this->capabilities->actor(),
			'actions' => $actions,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}
}
