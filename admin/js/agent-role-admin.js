(function () {
	var currentTab = document.querySelector('.ar-rf-settings a.rf-tabs__tab.is-current');
	if (currentTab && typeof currentTab.scrollIntoView === 'function') {
		currentTab.scrollIntoView({ inline: 'nearest', block: 'nearest' });
	}

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

	function bindModalClose(modal) {
		if (!modal || modal.dataset.closeBound || typeof modal.close !== 'function') {
			return;
		}
		modal.dataset.closeBound = '1';

		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				modal.close();
			}
		});

		modal.addEventListener('click', function (event) {
			var closeButton = event.target.closest('.ar-rf-modal__close, button[value="close"]');
			if (closeButton && modal.contains(closeButton)) {
				event.preventDefault();
				modal.close();
			}
		});

		var form = modal.querySelector('form[method="dialog"]');
		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				modal.close();
			});
		}
	}

	bindModalClose(dialog);

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
	bindModalClose(mcpDialog);
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
		var selectedPersona = form.querySelector('.ar-persona__input:checked');
		if (selectedPersona) {
			body.append('agent_role_persona', selectedPersona.value);
		}
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

	var instructionsToggle = document.getElementById('ar-instructions-toggle');
	var instructionsPanel = document.getElementById('ar-instructions-panel');
	if (instructionsToggle && instructionsPanel) {
		instructionsToggle.addEventListener('click', function () {
			var open = instructionsPanel.hasAttribute('hidden');
			if (open) {
				instructionsPanel.removeAttribute('hidden');
				instructionsToggle.setAttribute('aria-expanded', 'true');
			} else {
				instructionsPanel.setAttribute('hidden', '');
				instructionsToggle.setAttribute('aria-expanded', 'false');
			}
		});
	}

	if (window.jQuery && jQuery.fn.datepicker) {
		var $from = jQuery('.ar-rf-date--from');
		var $to = jQuery('.ar-rf-date--to');
		if ($from.length && $to.length) {
			var available = {};
			var logDates = (window.agentRoleAdmin && agentRoleAdmin.logDates) || [];
			logDates.forEach(function (day) {
				available[day] = true;
			});
			var dayKey = function (date) {
				return jQuery.datepicker.formatDate('yy-mm-dd', date);
			};
			var markRange = function (date) {
				if (!available[dayKey(date)]) {
					return [false, 'ar-rf-date-unavailable'];
				}
				var start = $from.datepicker('getDate');
				var end = $to.datepicker('getDate');
				if (start && end && date >= start && date <= end) {
					return [true, 'ar-rf-date-in-range'];
				}
				return [true, ''];
			};
			var earliest = null;
			Object.keys(available).sort().forEach(function (day) {
				if (!earliest) {
					earliest = jQuery.datepicker.parseDate('yy-mm-dd', day);
				}
			});
			$from.datepicker({
				minDate: earliest || 0,
				maxDate: 0,
				beforeShowDay: markRange,
				onSelect: function (dateText) {
					$to.datepicker('option', 'minDate', dateText);
				}
			});
			$to.datepicker({
				minDate: earliest || 0,
				maxDate: 0,
				beforeShowDay: markRange,
				onSelect: function (dateText) {
					$from.datepicker('option', 'maxDate', dateText);
				}
			});
			var startDate = $from.datepicker('getDate');
			var endDate = $to.datepicker('getDate');
			if (startDate) {
				$to.datepicker('option', 'minDate', startDate);
			}
			if (endDate) {
				$from.datepicker('option', 'maxDate', endDate);
			}
		}
	}

	var personaGrid = document.querySelector('.ar-persona-grid');
	if (personaGrid) {
		var stage = document.getElementById('ar-persona-stage');
		var adjust = document.getElementById('ar-persona-adjust');
		var customFlag = document.getElementById('ar-persona-custom-flag');
		var profile = document.querySelector('.ar-rf-profile');
		var profileLine = document.getElementById('ar-profile-persona-line');
		var profileName = document.getElementById('ar-profile-persona-name');
		var profileBadge = document.getElementById('ar-profile-custom-badge');

		var applying = false;

		function setChecked(name, value, on) {
			personaGrid.closest('form').querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
				if (input.value === value) {
					input.checked = on;
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});
		}

		function selectedInput() {
			return personaGrid.querySelector('.ar-persona__input:checked');
		}

		function panelOpen() {
			return !!(adjust && !adjust.hasAttribute('hidden'));
		}

		function setPanelOpen(open) {
			if (!adjust || !stage) {
				return;
			}
			if (open) {
				adjust.removeAttribute('hidden');
				stage.classList.add('is-open');
			} else {
				adjust.setAttribute('hidden', '');
				stage.classList.remove('is-open');
			}
			personaGrid.querySelectorAll('.ar-persona__customize').forEach(function (button) {
				var card = button.closest('.ar-persona');
				var selected = card && card.classList.contains('is-selected');
				button.setAttribute('aria-expanded', open && selected ? 'true' : 'false');
			});
		}

		function setDefaultButtons() {
			var hasPersona = !!selectedInput();
			['ar-persona-save-default', 'ar-persona-reset-default', 'ar-persona-factory-default'].forEach(function (id) {
				var button = document.getElementById(id);
				if (button) {
					button.disabled = !hasPersona;
				}
			});
			if (!hasPersona) {
				setPanelOpen(false);
			}
		}

		function applyAbilityMap(abilities) {
			adjust.querySelectorAll('input[name="agent_role_abilities[]"]').forEach(function (box) {
				box.checked = !!(abilities && abilities[box.value]);
				box.dispatchEvent(new Event('change', { bubbles: true }));
			});
		}

		function paintCards() {
			var section = personaGrid.closest('.ar-rf-section--persona');
			var selected = selectedInput();
			var cards = personaGrid.querySelectorAll('.ar-persona');
			var selectedIndex = -1;
			var color = '';
			var label = '';
			cards.forEach(function (card, index) {
				var input = card.querySelector('.ar-persona__input');
				var isSelected = !!(input && input.checked);
				var selectButton = card.querySelector('.ar-persona__select');
				var customizeButton = card.querySelector('.ar-persona__customize');
				card.classList.toggle('is-selected', isSelected);
				if (selectButton) {
					selectButton.hidden = isSelected;
				}
				if (customizeButton) {
					customizeButton.hidden = !isSelected;
					customizeButton.setAttribute('aria-expanded', isSelected && panelOpen() ? 'true' : 'false');
				}
				if (isSelected && input) {
					selectedIndex = index;
					color = card.style.getPropertyValue('--ar-persona');
					label = input.getAttribute('data-label') || '';
				}
			});
			if (stage) {
				stage.classList.toggle('is-tab-first', selectedIndex === 0);
				stage.classList.toggle('is-tab-last', selectedIndex === cards.length - 1 && selectedIndex >= 0);
			}
			if (selected && section) {
				section.style.setProperty('--ar-persona', color);
			}
			if (profile) {
				if (color) {
					profile.style.setProperty('--ar-persona', color);
				} else {
					profile.style.removeProperty('--ar-persona');
				}
			}
			if (profileLine) {
				if (selected) {
					profileLine.removeAttribute('hidden');
				} else {
					profileLine.setAttribute('hidden', '');
				}
			}
			if (profileName) {
				profileName.textContent = label;
			}
			if (profileBadge) {
				if (customFlag && customFlag.value === '1') {
					profileBadge.removeAttribute('hidden');
				} else {
					profileBadge.setAttribute('hidden', '');
				}
			}
			setDefaultButtons();
		}

		function applyShape(input, factory) {
			var caps = {};
			var actions = [];
			var abilities = {};
			var instructions = '';
			var capAttr = factory ? 'data-factory-caps' : 'data-caps';
			var actionAttr = factory ? 'data-factory-actions' : 'data-actions';
			var abilityAttr = factory ? 'data-factory-abilities' : 'data-abilities';
			var instructionAttr = factory ? 'data-factory-instructions' : 'data-instructions';
			applying = true;
			try {
				caps = JSON.parse(input.getAttribute(capAttr) || '{}');
				actions = JSON.parse(input.getAttribute(actionAttr) || '[]');
				abilities = JSON.parse(input.getAttribute(abilityAttr) || '{}');
				instructions = JSON.parse(input.getAttribute(instructionAttr) || '""');
			} catch (error) {
				caps = {};
				actions = [];
				abilities = {};
				instructions = '';
			}
			Object.keys(caps).forEach(function (cap) {
				setChecked('agent_role_caps[]', cap, !!caps[cap]);
			});
			adjust.querySelectorAll('input[name="agent_role_actions[]"]').forEach(function (box) {
				box.checked = actions.indexOf(box.value) !== -1;
				box.dispatchEvent(new Event('change', { bubbles: true }));
			});
			applyAbilityMap(abilities);
			var box = document.getElementById('agent_role_instructions');
			if (box && typeof instructions === 'string') {
				box.value = instructions;
			}
			if (customFlag) {
				customFlag.value = '0';
			}
			applying = false;
			paintCards();
		}

		personaGrid.querySelectorAll('.ar-persona__input').forEach(function (input) {
			input.addEventListener('change', function () {
				if (input.checked) {
					applyShape(input);
				}
			});
		});

		personaGrid.addEventListener('click', function (event) {
			var selectButton = event.target.closest('.ar-persona__select');
			if (selectButton) {
				var card = selectButton.closest('.ar-persona');
				var input = card ? card.querySelector('.ar-persona__input') : null;
				if (input && !input.checked) {
					input.checked = true;
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}
				return;
			}
			var customizeButton = event.target.closest('.ar-persona__customize');
			if (customizeButton) {
				if (!selectedInput()) {
					return;
				}
				setPanelOpen(!panelOpen());
				paintCards();
			}
		});

		if (adjust) {
			adjust.addEventListener('change', function (event) {
				if (applying) {
					return;
				}
				if (!event.target || event.target.type !== 'checkbox') {
					return;
				}
				if (customFlag) {
					customFlag.value = '1';
				}
				paintCards();
			});
		}

		var instructionBox = document.getElementById('agent_role_instructions');
		if (instructionBox) {
			instructionBox.addEventListener('input', function () {
				if (customFlag) {
					customFlag.value = '1';
				}
				paintCards();
			});
		}

		paintCards();
	}

	var brand = document.querySelector('.ar-rf-footer__brand');
	var about = document.getElementById('ar-rf-about');
	if (brand && about) {
		function setAbout(open) {
			about.hidden = !open;
			brand.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
		brand.addEventListener('click', function () {
			setAbout(about.hidden);
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !about.hidden) {
				setAbout(false);
				brand.focus();
			}
		});
		document.querySelectorAll('.ar-rf-footer a[href="#"], .ar-rf-about a[href="#"]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				event.preventDefault();
			});
		});
	}
}());
