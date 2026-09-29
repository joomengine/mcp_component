<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSES/joomla-mcp.txt
 * @since      0.1.0
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Joomla;


use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;


/**
 * Preserve zero-based offset slices across Joomla's administrator pagination.
 *
 * @since  0.1.0
 */
final class ModelListPage
{
	/**
	 * Read the requested slice without Joomla ListModel::getStart() rewinding it.
	 *
	 * The final native limit must fit the remaining rows. Joomla otherwise moves
	 * the start back to the last full page, including for a partial final slice.
	 * An empty dataset and offsets at or beyond the total are valid empty pages.
	 *
	 * @param   object  $model   The native model with the reviewed filters applied.
	 * @param   int     $offset  The requested zero-based offset.
	 * @param   int     $limit   The requested maximum number of rows.
	 * @param   string  $getter  The fixed reviewed list primitive.
	 * @return  array{items: array, total: int}
	 *
	 * @since  0.1.0
	 */
	public static function read(object $model, int $offset, int $limit, string $getter = 'getItems'): array
	{
		if (!method_exists($model, 'setState') || !method_exists($model, $getter))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla model cannot provide a bounded list.');
		}

		if ($getter === 'getData')
		{
			// CacheModel caches its full collection but returns a slice on the first
			// getData() call. Its getTotal() can therefore count that slice alone.
			$model->setState('list.start', 0);
			$model->setState('list.limit', 0);
			$items = $model->getData();
			self::requireItems($items);

			return ['items' => array_slice($items, $offset, $limit), 'total' => count($items)];
		}

		if (!method_exists($model, 'getTotal'))
		{
			throw new ActionException('MODEL_INCOMPATIBLE', 'The Joomla model cannot report its list total.');
		}

		$model->setState('list.start', $offset);
		$model->setState('list.limit', $limit);
		$total = $model->getTotal();

		if (!is_int($total) || $total < 0)
		{
			throw new ActionException('MODEL_RESULT_INVALID', 'The Joomla model returned an invalid list total.');
		}

		if ($offset >= $total)
		{
			return ['items' => [], 'total' => $total];
		}

		$nativeLimit = min($limit, $total - $offset);
		$model->setState('list.limit', $nativeLimit);
		$items = $model->getItems();
		self::requireItems($items);

		if (count($items) > $nativeLimit)
		{
			throw new ActionException('MODEL_RESULT_INVALID', 'The Joomla model returned more rows than its bounded list permits.');
		}

		return ['items' => $items, 'total' => $total];
	}

	/**
	 * Reject failed model reads instead of disguising them as empty collections.
	 *
	 * @param   mixed  $items  The native model result.
	 * @return  void
	 *
	 * @since  0.1.0
	 */
	private static function requireItems(mixed $items): void
	{
		if (!is_array($items))
		{
			throw new ActionException('MODEL_RESULT_INVALID', 'The Joomla model returned an invalid list.');
		}
	}
}
