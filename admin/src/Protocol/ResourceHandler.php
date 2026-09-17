<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Inert stored content or an authorized read action behind a database resource.
 *
 * @since 0.1.0
 */
final class ResourceHandler implements ResourceHandlerInterface
{
	/** @var Catalogue Current database definitions. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var ToolDispatcher Public filtered catalogue. @since 0.1.0 */
	private ToolDispatcher $tools;
	/** @var ActionExecutor Authorized read engine. @since 0.1.0 */
	private ActionExecutor $actions;
	/** @var string Stable database resource name. @since 0.1.0 */
	private string $name;

	/** @param Catalogue $catalogue Definitions. @param ToolDispatcher $tools Catalogue renderer. @param ActionExecutor $actions Read engine. @param string $name Resource name. @since 0.1.0 */
	public function __construct(Catalogue $catalogue, ToolDispatcher $tools, ActionExecutor $actions, string $name)
	{
		$this->catalogue = $catalogue;
		$this->tools = $tools;
		$this->actions = $actions;
		$this->name = $name;
	}

	/** @inheritDoc */
	public function read(string $uri, ClientGateway $gateway): mixed
	{
		return $this->content($uri);
	}

	/**
	 * Re-authorize before reading; template variables are inert action arguments.
	 *
	 * @param string $uri Requested resource URI.
	 * @param array<string,string> $variables Parsed template arguments.
	 * @return string Bounded resource text, never executed or fetched as a URL.
	 * @since 0.1.0
	 */
	public function content(string $uri, array $variables = []): string
	{
		$this->catalogue->refresh();
		$row = $this->catalogue->get('resource', $this->name);
		$this->catalogue->requireExecution($row);

		if (!(int) $row['is_template'] && $row['uri'] !== $uri)
		{
			throw new OperationException('RESOURCE_UNAVAILABLE', 'The requested resource is unavailable.');
		}

		$config = $row['configuration'];

		if ($row['handler'] === 'catalog.core')
		{
			return Json::encode($this->tools->catalog());
		}

		if ($row['handler'] === 'resource.text')
		{
			$text = $config['text'] ?? '';

			if (!is_string($text) || strlen($text) > 1048576)
			{
				throw new OperationException('RESOURCE_INVALID', 'The stored resource text is invalid.');
			}

			return $text;
		}

		if ($row['handler'] !== 'action.read' || !is_string($config['action'] ?? null))
		{
			throw new OperationException('RESOURCE_UNAVAILABLE', 'The requested resource is unavailable.');
		}

		$input = $config['input'] ?? [];

		foreach ($config['argument_map'] ?? [] as $argument => $variable)
		{
			if (!array_key_exists($variable, $variables))
			{
				throw new OperationException('INVALID_INPUT', 'A required resource variable is missing.');
			}

			$value = $variables[$variable];

			if (in_array($argument, $config['integer_arguments'] ?? [], true))
			{
				$value = filter_var($value, FILTER_VALIDATE_INT);

				if ($value === false)
				{
					throw new OperationException('INVALID_INPUT', 'A resource variable must be an integer.');
				}
			}

			$input[$argument] = $value;
		}

		return Json::encode($this->actions->read($config['action'], $input));
	}
}
