<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;


/**
 * Database prompt messages with non-executable, one-pass argument substitution.
 *
 * @since 0.1.0
 */
final class PromptHandler implements PromptHandlerInterface
{
	/** @var Catalogue Authorized definitions. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var SchemaValidator Inert argument schema. @since 0.1.0 */
	private SchemaValidator $schemas;
	/** @var string Stable prompt name. @since 0.1.0 */
	private string $name;

	/** @param Catalogue $catalogue Definitions. @param SchemaValidator $schemas Argument validator. @param string $name Prompt name. @since 0.1.0 */
	public function __construct(Catalogue $catalogue, SchemaValidator $schemas, string $name)
	{
		$this->catalogue = $catalogue;
		$this->schemas = $schemas;
		$this->name = $name;
	}

	/** @inheritDoc */
	public function get(array $arguments, ClientGateway $gateway): mixed
	{
		$this->catalogue->refresh();
		$row = $this->catalogue->get('prompt', $this->name);
		$this->catalogue->requireExecution($row);
		$arguments = $this->schemas->input($arguments, $this->catalogue->schema((int) $row['input_schema_id']));
		$templates = $row['configuration']['messages'] ?? [];

		if (!is_array($templates) || count($templates) > 64)
		{
			throw new OperationException('PROMPT_INVALID', 'The stored prompt is invalid.');
		}

		$messages = [];
		$size = 0;

		foreach ($templates as $message)
		{
			$role = is_string($message['role'] ?? null) ? Role::tryFrom($message['role']) : null;
			$text = $message['text'] ?? null;

			if ($role === null || !is_string($text))
			{
				throw new OperationException('PROMPT_INVALID', 'A stored prompt message is invalid.');
			}

			$text = preg_replace_callback('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', static function (array $match) use ($arguments): string
			{
				$value = $arguments[$match[1]] ?? '';

				if (!is_string($value))
				{
					throw new OperationException('INVALID_INPUT', 'Prompt arguments must be strings.');
				}

				return $value;
			}, $text);
			$size += strlen($text);

			if ($size > 1048576)
			{
				throw new OperationException('PROMPT_TOO_LARGE', 'The rendered prompt exceeds its size limit.');
			}

			$messages[] = new PromptMessage($role, new TextContent($text));
		}

		return new GetPromptResult($messages, $row['description']);
	}
}
