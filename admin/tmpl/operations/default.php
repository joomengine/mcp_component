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
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;
$kind = $this->state->get('filter.kind');
$user = $this->getCurrentUser();
?>
<nav class="mb-3">
	<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=providers'); ?>"><?php echo Text::_('COM_JOOMENGINE_MCP_PROVIDERS'); ?></a>
	<?php foreach (['execution' => 'EXECUTIONS', 'job' => 'JOBS', 'artifact' => 'ARTIFACTS', 'grant' => 'GRANTS', 'audit' => 'AUDIT'] as $name => $label) : ?>
		<a class="btn <?php echo $kind === $name ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=operations&kind=' . $name); ?>"><?php echo Text::_('COM_JOOMENGINE_MCP_' . $label); ?></a>
	<?php endforeach; ?>
</nav>
<?php if ($user->authorise('core.admin', 'com_joomengine_mcp') && $user->authorise('core.admin', 'com_componentbuilder')) : ?>
<form class="mb-3" action="<?php echo Route::_('index.php?option=com_joomengine_mcp&task=operations.synchronizeJcb'); ?>" method="post">
	<?php echo HTMLHelper::_('form.token'); ?>
	<button type="submit" class="btn btn-outline-primary"><?php echo Text::_('COM_JOOMENGINE_MCP_SYNCHRONIZE_JCB'); ?></button>
	<span class="form-text"><?php echo Text::_('COM_JOOMENGINE_MCP_SYNCHRONIZE_JCB_DESC'); ?></span>
</form>
<?php endif; ?>
<?php if ($kind === 'execution') : ?><p class="alert alert-warning"><?php echo Text::_('COM_JOOMENGINE_MCP_RECONCILE_WARNING'); ?></p><?php endif; ?>
<?php if ($kind === 'job') : ?><p class="alert alert-info"><?php echo Text::_('COM_JOOMENGINE_MCP_JOB_CANCELLATION_DESC'); ?></p><?php endif; ?>
<div class="table-responsive">
<table class="table">
	<caption><?php echo $this->escape(Text::_('COM_JOOMENGINE_MCP_OPERATIONS')); ?></caption>
	<thead><tr><th>ID</th><th><?php echo Text::_('JDETAILS'); ?></th><th><?php echo Text::_('JDATE'); ?></th><th><?php echo Text::_('JACTIONS'); ?></th></tr></thead>
	<tbody>
	<?php foreach ($this->items as $item) : ?>
		<tr>
			<td><?php echo (int) $item->id; ?></td>
			<td><code><?php echo $this->escape($item->uuid); ?></code><br>
				<?php echo $this->escape($item->status ?? $item->outcome ?? ($kind === 'grant' ? ($item->revoked ? 'revoked' : 'active') : 'retained')); ?>
				<?php if ($kind === 'audit') : ?><br><?php echo $this->escape($item->event . ' ' . $item->action_name); ?><?php endif; ?>
				<?php if ($kind === 'job') : ?>
					<br><?php echo $this->escape($item->action_name . ' · ' . $item->principal_id . ' · ' . $item->track); ?>
					<br><?php echo (int) $item->progress; ?>% — <?php echo $this->escape($item->message); ?>
					<br><?php echo Text::_('COM_JOOMENGINE_MCP_EXECUTION_REFERENCE'); ?> <code><?php echo $this->escape($item->execution_uuid); ?></code>
				<?php elseif ($kind === 'artifact') : ?>
					<br><?php echo $this->escape($item->name . ' · ' . $item->mime_type); ?> · <?php echo (int) $item->size; ?> <?php echo Text::_('COM_JOOMENGINE_MCP_BYTES'); ?>
					<br><?php echo Text::_('COM_JOOMENGINE_MCP_JOB_REFERENCE'); ?> <code><?php echo $this->escape($item->job_uuid); ?></code>
					<br>SHA-256: <code><?php echo $this->escape($item->sha256); ?></code>
					<br><?php echo Text::_('COM_JOOMENGINE_MCP_EXPIRES'); ?> <?php echo $this->escape(gmdate('Y-m-d H:i:s', (int) $item->expires_at)); ?> UTC
				<?php endif; ?>
			</td>
			<td><?php echo $this->escape(gmdate('Y-m-d H:i:s', (int) $item->created_at)); ?> UTC</td>
			<td>
			<?php if ($kind === 'grant' && !$item->revoked && $user->authorise('mcp.grants', 'com_joomengine_mcp')) : ?>
				<form action="<?php echo Route::_('index.php?option=com_joomengine_mcp&task=operations.revoke'); ?>" method="post">
					<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>"><input type="hidden" name="version" value="<?php echo (int) $item->version; ?>">
					<?php echo HTMLHelper::_('form.token'); ?><button type="submit" class="btn btn-danger"><?php echo Text::_('COM_JOOMENGINE_MCP_REVOKE'); ?></button>
				</form>
			<?php elseif ($kind === 'job' && in_array($item->status, ['queued', 'running'], true) && !$item->cancel_requested && $user->authorise('mcp.reconcile', 'com_joomengine_mcp')) : ?>
				<form action="<?php echo Route::_('index.php?option=com_joomengine_mcp&task=operations.cancelJob'); ?>" method="post">
					<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>"><input type="hidden" name="version" value="<?php echo (int) $item->version; ?>">
					<?php echo HTMLHelper::_('form.token'); ?><button type="submit" class="btn btn-warning"><?php echo Text::_('COM_JOOMENGINE_MCP_CANCEL_JOB'); ?></button>
				</form>
			<?php elseif ($kind === 'execution' && in_array($item->status, ['uncertain', 'running'], true) && $user->authorise('mcp.reconcile', 'com_joomengine_mcp')) : ?>
				<details><summary><?php echo Text::_('COM_JOOMENGINE_MCP_RECONCILE'); ?></summary>
				<form action="<?php echo Route::_('index.php?option=com_joomengine_mcp&task=operations.reconcile'); ?>" method="post">
					<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>"><input type="hidden" name="version" value="<?php echo (int) $item->version; ?>">
					<label><?php echo Text::_('COM_JOOMENGINE_MCP_RECONCILE_NOTE'); ?><textarea class="form-control" name="note" required minlength="10" maxlength="4000"></textarea></label>
					<select class="form-select" name="outcome" aria-label="Inspected outcome"><option value="verified_complete">Verified complete</option><option value="verified_no_effect">Verified no effect</option><option value="partial">Partial effects recorded</option></select>
					<label><input type="checkbox" name="acknowledged" value="1" required> <?php echo Text::_('COM_JOOMENGINE_MCP_RECONCILE_ACK'); ?></label>
					<?php echo HTMLHelper::_('form.token'); ?><button type="submit" class="btn btn-warning"><?php echo Text::_('COM_JOOMENGINE_MCP_RECONCILE'); ?></button>
				</form></details>
			<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<form action="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=operations&kind=' . $kind); ?>" method="post" id="adminForm" name="adminForm">
	<?php echo $this->pagination->getListFooter(); ?>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
