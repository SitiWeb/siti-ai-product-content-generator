(function ($) {
	const modal = document.getElementById('groq-ai-modal');
	if (!modal) {
		return;
	}

	const openButtons = document.querySelectorAll('.groq-ai-open-modal');
	const closeButton = modal.querySelector('.groq-ai-modal__close');
	const form = document.getElementById('groq-ai-form');
	const promptField = document.getElementById('groq-ai-prompt');
	const statusField = modal.querySelector('.groq-ai-modal__status');
	const resultWrapper = modal.querySelector('.groq-ai-modal__result');
	const resultField = document.getElementById('groq-ai-output');
	const jsonCopyButton = modal.querySelector('.groq-ai-copy-json');
	const contextToggles = modal.querySelectorAll('.groq-ai-context-toggle');
	const attributeToggles = modal.querySelectorAll('.groq-ai-attribute-toggle');
	const resultFields = {};
	modal.querySelectorAll('.groq-ai-result-field').forEach((field) => {
		const key = field.getAttribute('data-field');
		if (!key) {
			return;
		}
		resultFields[key] = {
			key,
			container: field,
			textarea: field.querySelector('textarea'),
			target: field.getAttribute('data-target-input') || '',
			label: field.getAttribute('data-label') || key,
			rankMathAction: field.getAttribute('data-rankmath-action') || '',
			status: field.querySelector('.groq-ai-apply-status') || null,
			statusTimer: null,
			suggestionWrapper: field.querySelector('[data-title-suggestions]') || null,
			suggestionOptions: field.querySelector('[data-title-suggestions-options]') || null,
		};
	});

	const advancedToggle = modal.querySelector('.groq-ai-advanced-toggle');
	const advancedPanel = document.getElementById('groq-ai-advanced-panel');

	function setAdvancedState(isOpen) {
		if (!advancedToggle || !advancedPanel) {
			return;
		}
		advancedToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
		advancedToggle.classList.toggle('is-open', isOpen);
		advancedPanel.hidden = !isOpen;
	}

	if (advancedToggle && advancedPanel) {
		advancedToggle.addEventListener('click', () => {
			const expanded = advancedToggle.getAttribute('aria-expanded') === 'true';
			setAdvancedState(!expanded);
		});
		setAdvancedState(false);
	}

	function openModal() {
		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		if (promptField && !promptField.value && GroqAIGenerator.defaultPrompt) {
			promptField.value = GroqAIGenerator.defaultPrompt;
		}
		resetContextToggles();
		resetAttributeToggles();
		setTimeout(() => promptField.focus(), 50);
	}

	function closeModal() {
		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');
		statusField.textContent = '';
	}

	openButtons.forEach((button) => {
		button.addEventListener('click', openModal);
	});

	if (closeButton) {
		closeButton.addEventListener('click', closeModal);
	}

	modal.addEventListener('click', (event) => {
		if (event.target === modal) {
			closeModal();
		}
	});

	document.addEventListener('keyup', (event) => {
		if (event.key === 'Escape' && modal.classList.contains('is-open')) {
			closeModal();
		}
	});

	function setStatus(message, type = '') {
		statusField.textContent = message;
		statusField.setAttribute('data-status', type);
	}

	const loadingText = window.wp && wp.i18n ? wp.i18n.__('AI is bezig met schrijven...', 'siti-ai-product-content-generator') : 'AI is bezig met schrijven...';
	const retryText = window.wp && wp.i18n ? wp.i18n.__('Probeer het opnieuw of pas je prompt/context aan.', 'siti-ai-product-content-generator') : 'Probeer het opnieuw of pas je prompt/context aan.';

	function toggleLoading(isLoading) {
		modal.classList.toggle('is-loading', isLoading);
		if (isLoading) {
			setStatus(loadingText, 'loading');
		}
	}

	if (form) {
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			const prompt = promptField.value.trim();

			const payload = new URLSearchParams();
			payload.append('action', 'groq_ai_generate_text');
			payload.append('nonce', GroqAIGenerator.nonce);
			payload.append('prompt', prompt);
			payload.append('post_id', GroqAIGenerator.postId || 0);
			payload.append('context_fields', JSON.stringify(collectContextSelection()));
			payload.append('attribute_includes', JSON.stringify(collectAttributeSelection()));

			toggleLoading(true);
			resultWrapper.hidden = true;
			if (jsonCopyButton) {
				jsonCopyButton.disabled = true;
			}
			resetFieldStatuses();
			clearTitleSuggestions();

			fetch(GroqAIGenerator.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: payload.toString(),
			})
				.then((response) => response.json())
				.then((json) => {
					if (!json.success) {
						const errorMessage = json.data && json.data.message ? json.data.message : 'Onbekende fout';
						throw new Error(errorMessage);
					}

						const fields = json.data.fields || {};
						Object.keys(resultFields).forEach((key) => {
							const entry = resultFields[key];
							if (entry && entry.textarea) {
								entry.textarea.value = fields[key] || '';
							}
						});
						updateTitleSuggestions(fields.title_suggestions);
						resultField.textContent = (json.data.raw || '').trim();
						resultWrapper.hidden = false;
						if (jsonCopyButton) {
							jsonCopyButton.disabled = false;
						}
						setStatus('Structuur gegenereerd. Kopieer of vul velden in.', 'success');
					})
				.catch((error) => {
					const message = error && error.message ? error.message : 'Er ging iets mis bij het genereren.';
					setStatus(loadingText, 'error');
					const fullMessage = `${loadingText} ${message}. ${retryText}`;
					statusField.textContent = fullMessage;
				})
				.finally(() => {
					toggleLoading(false);
				});
		});
	}

	function copyToClipboard(text) {
		if (!text) {
			return Promise.reject();
		}
		if (navigator.clipboard) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise((resolve, reject) => {
			const temp = document.createElement('textarea');
			temp.value = text;
			document.body.appendChild(temp);
			temp.select();
			try {
				document.execCommand('copy');
				resolve();
			} catch (err) {
				reject(err);
			} finally {
				document.body.removeChild(temp);
			}
		});
	}

	function applyRankMathField(action, value) {
		if (!action || !window.wp || !window.wp.data || typeof window.wp.data.dispatch !== 'function') {
			return false;
		}
		const dispatcher = window.wp.data.dispatch('rank-math');
		if (!dispatcher || typeof dispatcher[action] !== 'function') {
			return false;
		}
		try {
			dispatcher[action](value);
			return true;
		} catch (error) {
			if (window.console && console.warn) {
				console.warn('[GroqAI] Rank Math veld kon niet worden bijgewerkt', error);
			}
		}
		return false;
	}

	function setFieldStatus(fieldKey, state) {
		const entry = resultFields[fieldKey];
		if (!entry || !entry.status) {
			return;
		}
		if (entry.statusTimer) {
			clearTimeout(entry.statusTimer);
			entry.statusTimer = null;
		}
		entry.status.textContent = '';
		entry.status.classList.remove('is-success', 'is-error');
		if (!state) {
			return;
		}
		if (state === 'success') {
			entry.status.textContent = '✓';
			entry.status.classList.add('is-success');
		} else if (state === 'error') {
			entry.status.textContent = '!';
			entry.status.classList.add('is-error');
		}
		entry.statusTimer = setTimeout(() => {
			setFieldStatus(fieldKey, null);
		}, 4000);
	}

	function resetFieldStatuses() {
		Object.keys(resultFields).forEach((key) => setFieldStatus(key, null));
	}

	function shouldUseTinyMCE(selector) {
		return selector === '#content' || selector === '#excerpt';
	}

	function applyTinyMCEContent(selector, value) {
		if (!shouldUseTinyMCE(selector) || !window.tinymce) {
			return false;
		}
		const editorId = selector.startsWith('#') ? selector.substring(1) : selector;
		const editor = window.tinymce.get(editorId);
		if (!editor) {
			return false;
		}
		try {
			editor.setContent(value);
			editor.save();
			return true;
		} catch (error) {
			if (window.console && console.warn) {
				console.warn('[GroqAI] TinyMCE update mislukt voor', selector, error);
			}
		}
		return false;
	}

	function applyToTarget(fieldKey) {
		const entry = resultFields[fieldKey];
		if (!entry) {
			return;
		}
		const value = entry.textarea ? entry.textarea.value : '';
		let applied = false;
		const fallbackSelectors = getFallbackSelectors(fieldKey);

		const allSelectors = [];
		if (entry.target) {
			allSelectors.push(entry.target);
		}
		Array.prototype.push.apply(allSelectors, fallbackSelectors);

		for (let i = 0; i < allSelectors.length && !applied; i += 1) {
			const selector = allSelectors[i];

			if (shouldUseTinyMCE(selector)) {
				applied = applyTinyMCEContent(selector, value);
				if (applied) {
					break;
				}
			}

			const target = document.querySelector(selector);
			if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
				target.value = value;
				target.dispatchEvent(new Event('input', { bubbles: true }));
				target.dispatchEvent(new Event('change', { bubbles: true }));
				applied = true;
			}

			if (!applied && shouldUseTinyMCE(selector)) {
				applied = applyTinyMCEContent(selector, value);
			}
		}

		if (!applied && entry.rankMathAction) {
			applied = applyRankMathField(entry.rankMathAction, value);
		}

		if (applied) {
			setStatus(entry.label + ' ingevuld.', 'success');
			setFieldStatus(fieldKey, 'success');
		} else {
			setStatus('Kon het veld niet automatisch invullen.', 'error');
			setFieldStatus(fieldKey, 'error');
		}
	}

	function getFallbackSelectors(fieldKey) {
		switch (fieldKey) {
			case 'meta_title':
				return ['input[name="rank_math_title"]'];
			case 'meta_description':
				return ['textarea[name="rank_math_description"]'];
			case 'focus_keywords':
				return ['input[name="rank_math_focus_keyword"]'];
			case 'slug':
				return ['#post_name', 'input[name="post_name"]', '#new-post-slug'];
			default:
				return [];
		}
	}

	modal.addEventListener('click', (event) => {
		if (event.target.classList.contains('groq-ai-copy-field')) {
			const field = event.target.getAttribute('data-field');
			const entry = resultFields[field];
			if (!entry || !entry.textarea) {
				return;
			}
			copyToClipboard(entry.textarea.value)
				.then(() => {
					setStatus(entry.label + ' gekopieerd naar het klembord.', 'success');
				})
				.catch(() => {
					setStatus('Kopiëren mislukt.', 'error');
				});
		}

		if (event.target.classList.contains('groq-ai-apply-field')) {
			const field = event.target.getAttribute('data-field');
			applyToTarget(field);
		}
	});

	if (jsonCopyButton) {
		jsonCopyButton.addEventListener('click', () => {
			const text = resultField ? resultField.textContent.trim() : '';
			copyToClipboard(text)
				.then(() => {
					setStatus('JSON gekopieerd naar het klembord.', 'success');
				})
				.catch(() => {
					setStatus('Kopiëren mislukt.', 'error');
				});
		});
	}

	function clearTitleSuggestions() {
		const entry = resultFields.title;
		if (!entry || !entry.suggestionWrapper || !entry.suggestionOptions) {
			return;
		}
		entry.suggestionOptions.innerHTML = '';
		entry.suggestionWrapper.hidden = true;
	}

	function updateTitleSuggestions(options) {
		const entry = resultFields.title;
		if (!entry || !entry.suggestionWrapper || !entry.suggestionOptions) {
			return;
		}

		entry.suggestionOptions.innerHTML = '';

		const sanitized = Array.isArray(options)
			? options
					.map((option) => (typeof option === 'string' ? option.trim() : ''))
					.filter((option) => option.length > 0)
					.slice(0, 3)
			: [];

		if (!sanitized.length) {
			entry.suggestionWrapper.hidden = true;
			return;
		}

		entry.suggestionWrapper.hidden = false;

		const currentValue = entry.textarea ? entry.textarea.value.trim() : '';
		const normalizedCurrent = currentValue.toLowerCase();
		let selectedValue = '';

		if (normalizedCurrent) {
			const matched = sanitized.find((text) => text.toLowerCase() === normalizedCurrent);
			if (matched) {
				selectedValue = matched;
				if (entry.textarea) {
					entry.textarea.value = matched;
				}
			}
		}

		if (!selectedValue) {
			selectedValue = sanitized[0];
			if (entry.textarea) {
				entry.textarea.value = sanitized[0];
			}
		}

		const groupName = `groq-ai-title-option-${Date.now()}`;

		sanitized.forEach((text, index) => {
			const optionId = `${groupName}-${index}`;
			const optionWrapper = document.createElement('label');
			optionWrapper.className = 'groq-ai-title-suggestions__option';

			const radio = document.createElement('input');
			radio.type = 'radio';
			radio.name = groupName;
			radio.id = optionId;
			radio.value = text;
			if (text === selectedValue) {
				radio.checked = true;
			}

			radio.addEventListener('change', () => {
				if (entry.textarea) {
					entry.textarea.value = text;
				}
			});

			const textSpan = document.createElement('span');
			textSpan.textContent = text;

			optionWrapper.appendChild(radio);
			optionWrapper.appendChild(textSpan);
			entry.suggestionOptions.appendChild(optionWrapper);
		});
	}

	function resetContextToggles() {
		const defaults = GroqAIGenerator.contextDefaults || {};
		contextToggles.forEach((toggle) => {
			const key = toggle.getAttribute('data-field');
			if (!key) {
				return;
			}
			const state = Object.prototype.hasOwnProperty.call(defaults, key) ? !!defaults[key] : true;
			toggle.checked = state;
		});
	}

	function resetAttributeToggles() {
		const defaults = Array.isArray(GroqAIGenerator.attributeIncludesDefaults)
			? GroqAIGenerator.attributeIncludesDefaults
			: [];

		attributeToggles.forEach((toggle) => {
			const key = toggle.getAttribute('data-attribute');
			if (!key) {
				return;
			}
			toggle.checked = defaults.includes(key);
		});
	}

	function collectContextSelection() {
		const selected = [];
		contextToggles.forEach((toggle) => {
			if (toggle.checked) {
				selected.push(toggle.getAttribute('data-field'));
			}
		});
		return selected;
	}

	function collectAttributeSelection() {
		const selected = [];
		attributeToggles.forEach((toggle) => {
			if (!toggle.checked) {
				return;
			}
			const key = toggle.getAttribute('data-attribute');
			if (key) {
				selected.push(key);
			}
		});
		return selected;
	}
})(jQuery);
