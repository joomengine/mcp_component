<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Protocol;


use LogicException;
use Mcp\Capability\Registry;
use Mcp\Capability\RegistryInterface;
use Mcp\Capability\Registry\Loader\ExplicitElementLoader;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaDocument;
use VDM\Component\JoomEngineMcp\Administrator\Security\SchemaValidator;
use VDM\Component\JoomEngineMcp\Administrator\Service\ActionExecutor;
use VDM\Component\JoomEngineMcp\Administrator\Service\Catalogue;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * SDK registry backed exclusively by authorized database definitions.
 *
 * Every lookup refreshes the catalogue, so publication and ACL changes take
 * effect on persistent stdio connections as well as separate PHP HTTP workers.
 * SDK reference objects and schema normalization remain owned by the SDK.
 *
 * @since 0.1.0
 */
final class DatabaseRegistry implements RegistryInterface
{
	/** @var Catalogue Installed authorized definitions. @since 0.1.0 */
	private Catalogue $catalogue;
	/** @var ToolDispatcher Reviewed protocol primitives. @since 0.1.0 */
	private ToolDispatcher $tools;
	/** @var ActionExecutor Shared action engine. @since 0.1.0 */
	private ActionExecutor $actions;
	/** @var SchemaValidator Local inert schema validator. @since 0.1.0 */
	private SchemaValidator $schemas;

	/** @param Catalogue $catalogue Definitions. @param ToolDispatcher $tools Primitives. @param ActionExecutor $actions Action engine. @param SchemaValidator $schemas Validation. @since 0.1.0 */
	public function __construct(Catalogue $catalogue, ToolDispatcher $tools, ActionExecutor $actions, SchemaValidator $schemas)
	{
		$this->catalogue = $catalogue;
		$this->tools = $tools;
		$this->actions = $actions;
		$this->schemas = $schemas;
	}

	/** @param string $entity Requested capability family. @param ?string $name Optional exact tool or prompt. @return Registry Fresh authorized SDK references for this lookup only. @since 0.1.0 */
	private function snapshot(string $entity, ?string $name = null): Registry
	{
		$this->catalogue->refresh();
		$tools = [];
		$resources = [];
		$templates = [];
		$prompts = [];

		foreach ($entity === 'tool' ? $this->rows('tool', $name) : [] as $row)
		{
			$data = $row['definition'];
			$data['name'] = $row['name'];
			$data['title'] = $row['title'];
			$data['description'] = $row['description'];
			$data['inputSchema'] = SchemaDocument::decode($this->catalogue->schema((int) $row['input_schema_id']));

			if (!empty($row['output_schema_id']))
			{
				$data['outputSchema'] = SchemaDocument::decode($this->catalogue->schema((int) $row['output_schema_id']));
			}

			$tools[] = ['definition' => Tool::fromArray($data), 'handler' => new ToolHandler($this->tools, $row['name'])];
		}

		foreach ($entity === 'resource' ? $this->catalogue->all('resource') : [] as $row)
		{
			$data = ['name' => $row['name'], 'title' => $row['title'], 'description' => $row['description'], 'mimeType' => $row['mime_type']];
			$handler = new ResourceHandler($this->catalogue, $this->tools, $this->actions, $row['name']);

			if ((int) $row['is_template'])
			{
				$templates[] = ['definition' => ResourceTemplate::fromArray($data + ['uriTemplate' => $row['uri']]),
					'handler' => new ResourceTemplateHandler($handler), 'completionProviders' => []];
			}
			else
			{
				$resources[] = ['definition' => ResourceDefinition::fromArray($data + ['uri' => $row['uri']]), 'handler' => $handler];
			}
		}

		foreach ($entity === 'prompt' ? $this->rows('prompt', $name) : [] as $row)
		{
			$schema = Json::decode($this->catalogue->schema((int) $row['input_schema_id']));
			$arguments = [];

			foreach ($schema['properties'] ?? [] as $name => $property)
			{
				$arguments[] = ['name' => $name, 'description' => $property['description'] ?? '', 'required' => in_array($name, $schema['required'] ?? [], true)];
			}

			$prompts[] = ['definition' => Prompt::fromArray(['name' => $row['name'], 'title' => $row['title'],
				'description' => $row['description'], 'arguments' => $arguments]),
				'handler' => new PromptHandler($this->catalogue, $this->schemas, $row['name']), 'completionProviders' => []];
		}

		$registry = new Registry();
		(new ExplicitElementLoader($tools, $resources, $templates, $prompts))->load($registry);

		return $registry;
	}

	/** @param string $entity Requested table. @param ?string $name Optional exact identifier. @return array Authorized rows without normalizing unrelated schemas. @since 0.1.1 */
	private function rows(string $entity, ?string $name): array
	{
		if ($name === null)
		{
			return $this->catalogue->all($entity);
		}

		try
		{
			return [$this->catalogue->get($entity, $name)];
		}
		catch (OperationException $error)
		{
			if ($error->getIdentifier() !== 'DEFINITION_UNAVAILABLE')
			{
				throw $error;
			}

			return [];
		}
	}

	/** @inheritDoc */
	public function hasTool(string $name): bool
	{
		return $this->snapshot('tool', $name)->hasTool($name);
	}

	/** @inheritDoc */
	public function hasResource(string $uri): bool
	{
		return $this->snapshot('resource')->hasResource($uri);
	}

	/** @inheritDoc */
	public function hasResourceTemplate(string $uriTemplate): bool
	{
		return $this->snapshot('resource')->hasResourceTemplate($uriTemplate);
	}

	/** @inheritDoc */
	public function hasPrompt(string $name): bool
	{
		return $this->snapshot('prompt', $name)->hasPrompt($name);
	}

	/** @inheritDoc */
	public function hasTools(): bool
	{
		return $this->snapshot('tool')->hasTools();
	}

	/** @inheritDoc */
	public function getTools(?int $limit = null, ?string $cursor = null): Page
	{
		return $this->snapshot('tool')->getTools($limit, $cursor);
	}

	/** @inheritDoc */
	public function getTool(string $name): ToolReference
	{
		return $this->snapshot('tool', $name)->getTool($name);
	}

	/** @inheritDoc */
	public function hasResources(): bool
	{
		return $this->snapshot('resource')->hasResources();
	}

	/** @inheritDoc */
	public function getResources(?int $limit = null, ?string $cursor = null): Page
	{
		return $this->snapshot('resource')->getResources($limit, $cursor);
	}

	/** @inheritDoc */
	public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
	{
		return $this->snapshot('resource')->getResource($uri, $includeTemplates);
	}

	/** @inheritDoc */
	public function hasResourceTemplates(): bool
	{
		return $this->snapshot('resource')->hasResourceTemplates();
	}

	/** @inheritDoc */
	public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
	{
		return $this->snapshot('resource')->getResourceTemplates($limit, $cursor);
	}

	/** @inheritDoc */
	public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
	{
		return $this->snapshot('resource')->getResourceTemplate($uriTemplate);
	}

	/** @inheritDoc */
	public function hasPrompts(): bool
	{
		return $this->snapshot('prompt')->hasPrompts();
	}

	/** @inheritDoc */
	public function getPrompts(?int $limit = null, ?string $cursor = null): Page
	{
		return $this->snapshot('prompt')->getPrompts($limit, $cursor);
	}

	/** @inheritDoc */
	public function getPrompt(string $name): PromptReference
	{
		return $this->snapshot('prompt', $name)->getPrompt($name);
	}

	/** @inheritDoc */
	public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = []): ResourceTemplateReference
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = []): PromptReference
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function unregisterTool(string $name): void
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function unregisterResource(string $uri): void
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function unregisterResourceTemplate(string $uriTemplate): void
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}

	/** @inheritDoc */
	public function unregisterPrompt(string $name): void
	{
		throw new LogicException('MCP definitions must be maintained through the Joomla catalogue.');
	}
}
