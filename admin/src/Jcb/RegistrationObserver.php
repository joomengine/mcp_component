<?php
/**
 * @package    JoomEngine.Mcp
 * @created    24 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use Closure;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\EventInterface;
use ReflectionFunction;
use ReflectionProperty;


/**
 * Observe native registration provenance during explicit catalogue synchronization.
 * Normal discovery uses the persisted dependency and Joomla's extension registry;
 * it neither reruns native registration nor writes to the catalogue.
 *
 * @since 0.1.1
 */
final class RegistrationObserver
{
	/**
	 * Dispatch the normal event while attributing new objects to native plugins.
	 *
	 * Original listener ordering, priority and event-stop semantics stay with the
	 * Joomla dispatcher. Unknown listeners acquire no fabricated plugin identity.
	 *
	 * @param DispatcherInterface $dispatcher Installed event dispatcher.
	 * @param EventInterface $event Native registration event.
	 * @param callable $snapshot Read-only list of currently registered objects.
	 * @return array<int,string[]> Plugin dependencies indexed by registered object ID.
	 * @since 0.1.1
	 */
	public function dispatch(DispatcherInterface $dispatcher, EventInterface $event, callable $snapshot): array
	{
		$owners = [];
		$listeners = [];
		$name = $event->getName();

		foreach ($dispatcher->getListeners($name) as $listener)
		{
			$extension = $this->extension($listener);
			$wrapper = static function (EventInterface $native) use ($listener, $extension, $snapshot, &$owners): void
			{
				$before = array_fill_keys(array_map('spl_object_id', $snapshot()), true);
				$listener($native);

				foreach ($snapshot() as $object)
				{
					$id = spl_object_id($object);

					if (!isset($before[$id]) && $extension !== null)
					{
						$owners[$id] = [$extension];
					}
				}
			};
			$listeners[] = [$listener, $wrapper, $dispatcher->getListenerPriority($name, $listener)];
		}

		foreach ($listeners as [$listener, $wrapper, $priority])
		{
			$dispatcher->removeListener($name, $listener);
			$dispatcher->addListener($name, $wrapper, $priority);
		}

		try
		{
			$dispatcher->dispatch($name, $event);
		}
		finally
		{
			foreach ($listeners as [$listener, $wrapper, $priority])
			{
				$dispatcher->removeListener($name, $wrapper);
				$dispatcher->addListener($name, $listener, $priority);
			}
		}

		return $owners;
	}

	/** @param callable $listener Actual registered listener. @return ?string Native plugin group/element, preserving case. @since 0.1.1 */
	private function extension(callable $listener): ?string
	{
		$object = is_array($listener) ? $listener[0] : $listener;

		if ($object instanceof Closure)
		{
			$object = (new ReflectionFunction($object))->getClosureThis();
		}

		if (!$object instanceof CMSPlugin)
		{
			return null;
		}

		$name = (new ReflectionProperty(CMSPlugin::class, '_name'))->getValue($object);
		$type = (new ReflectionProperty(CMSPlugin::class, '_type'))->getValue($object);

		return is_string($type) && is_string($name)
			&& preg_match('/\A[a-z][a-z0-9_-]*\z/D', $type) === 1
			&& preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $name) === 1
			? $type . '/' . $name : null;
	}
}
