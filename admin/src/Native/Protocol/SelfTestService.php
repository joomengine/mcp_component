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


use RuntimeException;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\CapabilityResolverInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionRegistry;


/**
 * Inspect native action contracts and permissions without executing actions.
 *
 * @since  0.1.0
 */
final class SelfTestService
{
	/**
	 * The preserved migration baseline for the native self-test catalogue.
	 *
	 * @since  0.1.0
	 */
	private const MINIMUM_ACTIONS = 231;

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
	 * Inspect required runtime capabilities without causing action side effects.
	 *
	 *  @return array<string, mixed>
	 *
	 * @since  0.1.0
	 */
	public function evaluate(): array
	{
		try
		{
			$description = json_decode(
				(new DescriptionService($this->registry, $this->capabilities))->toJson(),
				true,
				64,
				JSON_THROW_ON_ERROR,
			);
			$dispatch = json_decode(
				(new DispatchService($this->registry, $this->capabilities))->handleJson(
					'{"protocol":"joomla-mcp/1","id":"companion-self-test","action":"system.info","input":{}}',
				),
				true,
				64,
				JSON_THROW_ON_ERROR,
			);

			if (!is_array($description) || ($description['protocol'] ?? null) !== RequestDecoder::PROTOCOL)
			{
				throw new RuntimeException('Description protocol check failed.');
			}

			$actions = $description['actions'] ?? null;

			if (!is_array($actions) || count($actions) < self::MINIMUM_ACTIONS)
			{
				throw new RuntimeException('Companion catalogue check failed.');
			}

			if (!is_array($dispatch) || ($dispatch['protocol'] ?? null) !== RequestDecoder::PROTOCOL || ($dispatch['ok'] ?? null) !== true)
			{
				throw new RuntimeException('Companion dispatch check failed.');
			}

			$runtime = $dispatch['result'] ?? null;

			if (!is_array($runtime) || !is_string($runtime['joomlaVersion'] ?? null) || !is_string($runtime['phpVersion'] ?? null))
			{
				throw new RuntimeException('Companion runtime check failed.');
			}

			return [
				'protocol' => RequestDecoder::PROTOCOL,
				'ok' => true,
				'companion' => $description['companion'] ?? null,
				'checks' => [
					'pluginEnabled' => true,
					'catalogue' => ['ok' => true, 'actionCount' => count($actions)],
					'dispatch' => ['ok' => true, 'action' => 'system.info', 'result' => $runtime],
				],
			];
		}
		catch (Throwable)
		{
			return [
				'protocol' => RequestDecoder::PROTOCOL,
				'ok' => false,
				'error' => [
					'code' => 'SELF_TEST_FAILED',
					'message' => 'The Joomla MCP companion self-test failed.',
				],
			];
		}
	}
}
