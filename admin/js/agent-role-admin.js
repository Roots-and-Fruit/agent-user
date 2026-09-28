(function () {
	var dialog = document.getElementById('ar-agent-modal');
	var openButton = document.getElementById('ar-new-agent');
	var createStep = document.getElementById('ar-agent-step-create');
	var resultStep = document.getElementById('ar-agent-step-result');
	var createButton = document.getElementById('ar-agent-create');
	var cancelButton = document.getElementById('ar-agent-cancel');
	var errorBox = document.getElementById('ar-agent-error');

	function bindCopy(root) {
		(root || document).querySelectorAll('.ar-rf-copy__button').forEach(function (button) {
			if (button.dataset.bound) {
				return;
			}
			button.dataset.bound = '1';
			button.addEventListener('click', function () {
				var value = button.parentElement.querySelector('.ar-rf-copy__value');
				if (!value) {
					return;
				}
				var text = value.textContent.trim();
				var done = function () {
					var original = button.getAttribute('data-label') || button.textContent;
					button.setAttribute('data-label', original);
					button.textContent = (window.agentRoleAdmin && agentRoleAdmin.copied) || 'Copied';
					window.setTimeout(function () {
						button.textContent = original;
					}, 1600);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done);
					return;
				}
				var area = document.createElement('textarea');
				area.value = text;
				document.body.appendChild(area);
				area.select();
				document.execCommand('copy');
				document.body.removeChild(area);
				done();
			});
		});

		(root || document).querySelectorAll('.rf-code__copy').forEach(function (button) {
			if (button.dataset.bound) {
				return;
			}
			button.dataset.bound = '1';
			button.addEventListener('click', function () {
				var block = button.closest('.rf-code');
				var code = block ? block.querySelector('.rf-code__body code') : null;
				if (!code) {
					return;
				}
				var text = code.textContent;
				var done = function () {
					button.setAttribute('data-copied', '1');
					window.setTimeout(function () {
						button.removeAttribute('data-copied');
					}, 1600);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done);
					return;
				}
				var area = document.createElement('textarea');
				area.value = text;
				document.body.appendChild(area);
				area.select();
				document.execCommand('copy');
				document.body.removeChild(area);
				done();
			});
		});
	}

	function showStep(name) {
		if (!createStep || !resultStep) {
			return;
		}
		createStep.hidden = name !== 'create';
		resultStep.hidden = name !== 'result';
	}

	if (dialog && resultStep && !resultStep.hidden && typeof dialog.showModal === 'function') {
		bindCopy(resultStep);
		dialog.showModal();
	}

	if (openButton && dialog) {
		openButton.addEventListener('click', function () {
			showStep('create');
			if (errorBox) {
				errorBox.hidden = true;
				errorBox.textContent = '';
			}
			dialog.showModal();
		});
	}

	if (cancelButton && dialog) {
		cancelButton.addEventListener('click', function () {
			dialog.close();
		});
	}

	if (createButton && window.agentRoleAdmin) {
		createButton.addEventListener('click', function () {
			var username = document.getElementById('agent_role_username');
			var displayName = document.getElementById('agent_role_display_name');
			var body = new window.FormData();
			body.append('action', 'agent_role_create_agent');
			body.append('nonce', agentRoleAdmin.nonce);
			body.append('agent_role_username', username ? username.value : '');
			body.append('agent_role_display_name', displayName ? displayName.value : '');
			createButton.disabled = true;
			window.fetch(agentRoleAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (payload) {
					createButton.disabled = false;
					if (!payload || !payload.success) {
						if (errorBox) {
							errorBox.hidden = false;
							errorBox.textContent = payload && payload.data && payload.data.message ? payload.data.message : '';
						}
						return;
					}
					createStep.classList.add('is-leaving');
					window.setTimeout(function () {
						resultStep.innerHTML = payload.data.html;
						showStep('result');
						createStep.classList.remove('is-leaving');
						bindCopy(resultStep);
					}, 180);
				})
				.catch(function () {
					createButton.disabled = false;
				});
		});
	}

	var mcpDialog = document.getElementById('ar-mcp-modal');
	var mcpBody = document.getElementById('ar-mcp-modal-body');
	document.querySelectorAll('.ar-rf-mcp-view').forEach(function (button) {
		button.addEventListener('click', function () {
			var template = document.getElementById(button.getAttribute('data-template'));
			if (!template || !mcpBody || !mcpDialog || typeof mcpDialog.showModal !== 'function') {
				return;
			}
			mcpBody.innerHTML = template.innerHTML;
			bindCopy(mcpBody);
			mcpDialog.showModal();
		});
	});

	document.querySelectorAll('.ar-rf-mcp-copy').forEach(function (button) {
		button.addEventListener('click', function () {
			var text = button.getAttribute('data-copy') || '';
			var status = button.querySelector('.ar-rf-mcp-copy__status');
			var done = function () {
				if (status) {
					status.textContent = (window.agentRoleAdmin && agentRoleAdmin.copied) || 'Copied';
				}
				window.setTimeout(function () {
					if (status) {
						status.textContent = '';
					}
				}, 1600);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done);
				return;
			}
			var area = document.createElement('textarea');
			area.value = text;
			document.body.appendChild(area);
			area.select();
			document.execCommand('copy');
			document.body.removeChild(area);
			done();
		});
	});

	document.querySelectorAll('.ar-rf-toggle__input').forEach(function (input) {
		var state = input.parentElement.querySelector('.ar-rf-toggle__state');
		if (!state) {
			return;
		}
		input.addEventListener('change', function () {
			state.textContent = input.checked ? state.getAttribute('data-enabled') : state.getAttribute('data-disabled');
		});
	});

	function checkedValues(form, name) {
		var values = [];
		form.querySelectorAll('input[name="' + name + '"]:checked').forEach(function (input) {
			values.push(input.value);
		});
		return values;
	}

	function fillInstructions(button, action, nonce, waiting, doneMessage) {
		var form = button.form;
		var instructions = document.getElementById('agent_role_instructions');
		var draftStatus = document.getElementById('ar-draft-instructions-status');
		if (!form || !instructions || !window.agentRoleAdmin) {
			return;
		}
		button.disabled = true;
		if (draftStatus) {
			draftStatus.textContent = waiting || '';
		}
		var body = new window.FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		body.append('user_id', button.getAttribute('data-user') || '');
		checkedValues(form, 'agent_role_caps[]').forEach(function (value) {
			body.append('agent_role_caps[]', value);
		});
		checkedValues(form, 'agent_role_abilities[]').forEach(function (value) {
			body.append('agent_role_abilities[]', value);
		});
		window.fetch(agentRoleAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (payload) {
				button.disabled = false;
				if (!payload || !payload.success) {
					if (draftStatus) {
						draftStatus.textContent = payload && payload.data && payload.data.message ? payload.data.message : '';
					}
					return;
				}
				instructions.value = payload.data.text || '';
				if (draftStatus) {
					draftStatus.textContent = doneMessage || '';
				}
			})
			.catch(function () {
				button.disabled = false;
				if (draftStatus) {
					draftStatus.textContent = '';
				}
			});
	}

	var draftButton = document.getElementById('ar-draft-instructions');
	var resetButton = document.getElementById('ar-reset-instructions');
	if (draftButton && window.agentRoleAdmin) {
		draftButton.addEventListener('click', function () {
			fillInstructions(draftButton, 'agent_role_draft_instructions', agentRoleAdmin.draftNonce, agentRoleAdmin.drafting, agentRoleAdmin.draftDone);
		});
	}
	if (resetButton && window.agentRoleAdmin) {
		resetButton.addEventListener('click', function () {
			fillInstructions(resetButton, 'agent_role_reset_instructions', agentRoleAdmin.resetNonce, agentRoleAdmin.resetting, agentRoleAdmin.resetDone);
		});
	}

	bindCopy(document);
}());
