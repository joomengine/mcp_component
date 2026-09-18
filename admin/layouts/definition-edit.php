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
Text::script('JGLOBAL_VALIDATION_FORM_FAILED');
$entity = $this->entity();
?>
<form action="<?php echo Route::_('index.php?option=com_joomengine_mcp&view=' . $entity . '&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">
	<div class="main-card p-4">
		<?php foreach ($this->form->getFieldsets() as $name => $fieldset) : ?>
			<fieldset class="options-form mb-4">
				<legend><?php echo $this->escape(Text::_($fieldset->label)); ?></legend>
				<?php echo $this->form->renderFieldset($name); ?>
			</fieldset>
		<?php endforeach; ?>
	</div>
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
