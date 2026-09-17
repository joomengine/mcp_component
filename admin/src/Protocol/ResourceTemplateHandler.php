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
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;


/**
 * SDK URI-template adapter sharing the same resource authorization boundary.
 *
 * @since 0.1.0
 */
final class ResourceTemplateHandler implements ResourceTemplateHandlerInterface
{
	/** @var ResourceHandler Shared data-only resource implementation. @since 0.1.0 */
	private ResourceHandler $resource;

	/** @param ResourceHandler $resource Authorized resource handler. @since 0.1.0 */
	public function __construct(ResourceHandler $resource)
	{
		$this->resource = $resource;
	}

	/** @inheritDoc */
	public function read(string $uri, array $variables, ClientGateway $gateway): mixed
	{
		return $this->resource->content($uri, $variables);
	}
}
