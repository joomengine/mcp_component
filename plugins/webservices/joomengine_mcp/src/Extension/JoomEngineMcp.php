<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Plugin\Webservices\JoomEngineMcp\Extension;


use Joomla\CMS\Event\Application\AfterApiRouteEvent;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;


/**
 * Minimal native webservices registration for the installed MCP component.
 *
 * All protocol requests are non-public Joomla API routes. The sole public route
 * is a CORS OPTIONS preflight which returns no definitions, data or execution.
 *
 * @since 0.1.0
 */
final class JoomEngineMcp extends CMSPlugin implements SubscriberInterface
{
	/** @inheritDoc */
	public static function getSubscribedEvents(): array
	{
		return ['onBeforeApiRoute' => 'onBeforeApiRoute', 'onAfterApiRoute' => 'onAfterApiRoute'];
	}

	/** @param BeforeApiRouteEvent $event Joomla native routing event. @return void @since 0.1.0 */
	public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
	{
		$defaults = ['component' => 'com_joomengine_mcp', 'public' => false, 'format' => ['application/json', 'text/event-stream']];
		$event->getRouter()->addRoutes([
			new Route(['POST', 'GET', 'DELETE', 'HEAD'], 'v1/joomengine-mcp', 'mcp.handle', [], $defaults),
			new Route(['OPTIONS'], 'v1/joomengine-mcp', 'mcp.options', [], array_replace($defaults, ['public' => true])),
		]);
	}

	/**
	 * Preserve Joomla's native API authentication error-to-status mapping.
	 *
	 * Negotiation still accepts the MCP media types. Before authentication Joomla
	 * needs its JSON:API error renderer to map AuthenticationFailed to HTTP 401;
	 * the generic JSON renderer instead turns that code-less exception into 500.
	 * Successful MCP requests emit their protocol response in the controller and
	 * do not use this native error document. Authentication itself is unchanged.
	 *
	 * @param   AfterApiRouteEvent  $event  Native route event before authentication.
	 * @return  void
	 * @since   0.1.0
	 */
	public function onAfterApiRoute(AfterApiRouteEvent $event): void
	{
		$input = $event->getApplication()->getInput();

		if ($input->getCmd('option') === 'com_joomengine_mcp' && $input->getCmd('controller') === 'mcp')
		{
			$input->set('format', 'jsonapi');
		}
	}
}
