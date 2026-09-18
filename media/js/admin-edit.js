/** Native Joomla form submission; all server changes still validate ACL and CSRF. */
(() => {
	'use strict';
	Joomla.submitbutton = (task) => {
		const form = document.getElementById('adminForm');
		if (!form || typeof task !== 'string') return;
		if (task.endsWith('.cancel') || document.formvalidator.isValid(form)) {
			Joomla.submitform(task, form);
		} else {
			Joomla.renderMessages({error: [Joomla.Text._('JGLOBAL_VALIDATION_FORM_FAILED')]});
		}
	};
})();
