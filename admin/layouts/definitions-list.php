<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;

\defined('_JEXEC') or die;
$entity = $this->entity();
$list = $entity . 's';
$user = $this->getCurrentUser();
?>
<nav class="mb-3" aria-label="<?php echo $this->escape(Text::_('COM_JOOMENGINE_MCP')); ?>">
	<?php foreach (array_keys(Structure::definitions()) as $name) : ?>
		<a class="btn btn-sm <?php echo $name === $entity ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=' . $name . 's'); ?>"><?php echo $this->escape(Text::_('COM_JOOMENGINE_MCP_' . strtoupper($name . 's'))); ?></a>
	<?php endforeach; ?>
	<a class="btn btn-sm btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=operations'); ?>"><?php echo Text::_('COM_JOOMENGINE_MCP_OPERATIONS'); ?></a>
</nav>
<form action="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=' . $list); ?>" method="post" name="adminForm" id="adminForm">
	<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
	<table class="table itemList" id="definitionList">
		<caption class="visually-hidden"><?php echo $this->escape(Text::_('COM_JOOMENGINE_MCP_' . strtoupper($list))); ?></caption>
		<thead><tr>
			<th class="w-1"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
			<th><?php echo Text::_('JSTATUS'); ?></th>
			<th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
			<th scope="col"><?php echo Text::_('COM_JOOMENGINE_MCP_FIELD_NAME'); ?></th>
			<th scope="col"><?php echo Text::_('JFIELD_ACCESS_LABEL'); ?></th>
			<th scope="col"><?php echo Text::_('COM_JOOMENGINE_MCP_FIELD_VERSION'); ?></th>
			<th scope="col"><?php echo Text::_('JGRID_HEADING_ID'); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ($this->items as $i => $item) : ?>
			<tr>
				<td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
				<td><?php echo HTMLHelper::_('jgrid.published', $item->published, $i, $list . '.', $user->authorise('core.edit.state', 'com_joomengine_mcp.' . $entity . '.' . (int) $item->id)); ?></td>
				<th scope="row">
					<?php if ($item->checked_out) : ?>
						<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, $list . '.', true); ?>
					<?php endif; ?>
					<a href="<?php echo Route::_('index.php?option=com_joomengine_mcp&task=' . $entity . '.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->title); ?></a>
					<div class="small"><?php echo Text::_($item->customized ? 'COM_JOOMENGINE_MCP_CUSTOMIZED' : 'COM_JOOMENGINE_MCP_SHIPPED'); ?></div>
				</th>
				<td><code><?php echo $this->escape($item->name); ?></code></td>
				<td><?php echo $this->escape($item->access_title ?? ''); ?></td>
				<td><?php echo (int) $item->version; ?></td>
				<td><?php echo (int) $item->id; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php echo $this->pagination->getListFooter(); ?>
	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
