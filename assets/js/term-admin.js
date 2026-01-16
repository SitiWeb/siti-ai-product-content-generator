(function () {
	if (!window.GroqAITermGenerator) {
		return;
	}

	const form = document.getElementById('groq-ai-term-form');
	if (!form) {
		return;
	}

	const promptField = document.getElementById('groq-ai-term-prompt');
	const outputTopField = document.getElementById('groq-ai-term-generated-top');
	const outputBottomField = document.getElementById('groq-ai-term-generated-bottom');
	const outputMetaTitleField = document.getElementById('groq-ai-term-generated-meta-title');
	const outputMetaDescriptionField = document.getElementById('groq-ai-term-generated-meta-description');
	const outputFocusKeywordsField = document.getElementById('groq-ai-term-generated-focus-keywords');
	const rawField = document.getElementById('groq-ai-term-raw');
	const statusField = document.getElementById('groq-ai-term-status');
	const applyButton = document.getElementById('groq-ai-term-apply');
	const includeTopProducts = document.getElementById('groq-ai-term-include-top-products');
	const topProductsLimit = document.getElementById('groq-ai-term-top-products-limit');

	function setStatus(message, type) {
		if (!statusField) {
			return;
		}
		statusField.textContent = message || '';
		statusField.setAttribute('data-status', type || '');
	}

	function setLoading(isLoading) {
		form.classList.toggle('is-loading', !!isLoading);
		const buttons = form.querySelectorAll('button, input[type="submit"]');
		buttons.forEach((btn) => {
			btn.disabled = !!isLoading;
		});
	}

	function buildPayload(prompt) {
		const payload = new URLSearchParams();
		payload.append('action', 'groq_ai_generate_term_text');
		payload.append('nonce', GroqAITermGenerator.nonce);
		payload.append('taxonomy', GroqAITermGenerator.taxonomy);
		payload.append('term_id', GroqAITermGenerator.termId);
		payload.append('prompt', prompt);
		payload.append('include_top_products', includeTopProducts && includeTopProducts.checked ? '1' : '0');
		payload.append('top_products_limit', topProductsLimit ? String(topProductsLimit.value || '') : '10');
		return payload;
	}

	if (applyButton) {
		applyButton.addEventListener('click', () => {
			const descriptionField = document.getElementById('description');
			const bottomDescriptionField = document.getElementById('groq-ai-term-bottom-description');
			const rankmathTitleField = document.getElementById('groq-ai-rankmath-title');
			const rankmathDescriptionField = document.getElementById('groq-ai-rankmath-description');
			const rankmathKeywordsField = document.getElementById('groq-ai-rankmath-keywords');
			if (!outputTopField) {
				return;
			}

			if (descriptionField) {
				descriptionField.value = outputTopField.value || '';
			}
			if (bottomDescriptionField && outputBottomField) {
				bottomDescriptionField.value = outputBottomField.value || '';
			}
			if (rankmathTitleField && outputMetaTitleField) {
				rankmathTitleField.value = outputMetaTitleField.value || '';
			}
			if (rankmathDescriptionField && outputMetaDescriptionField) {
				rankmathDescriptionField.value = outputMetaDescriptionField.value || '';
			}
			if (rankmathKeywordsField && outputFocusKeywordsField) {
				rankmathKeywordsField.value = outputFocusKeywordsField.value || '';
			}

			setStatus('Tekst ingevuld. Vergeet niet op "Opslaan" te klikken.', 'success');
		});
	}

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		const prompt = promptField ? (promptField.value || '').trim() : '';
		if (!prompt) {
			setStatus('Vul eerst een prompt in.', 'error');
			return;
		}

		setLoading(true);
		setStatus('AI is bezig met schrijven...', 'loading');
		if (rawField) {
			rawField.textContent = '';
		}

		if (outputTopField) outputTopField.value = '';
		if (outputBottomField) outputBottomField.value = '';
		if (outputMetaTitleField) outputMetaTitleField.value = '';
		if (outputMetaDescriptionField) outputMetaDescriptionField.value = '';
		if (outputFocusKeywordsField) outputFocusKeywordsField.value = '';

		fetch(GroqAITermGenerator.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: buildPayload(prompt).toString(),
		})
			.then((response) => response.json())
			.then((json) => {
				if (!json.success) {
					const errorMessage = json.data && json.data.message ? json.data.message : 'Onbekende fout';
					throw new Error(errorMessage);
				}

				if (outputTopField) {
					const top = json.data && (json.data.top_description || json.data.description) ? (json.data.top_description || json.data.description) : '';
					outputTopField.value = String(top).trim();
				}
				if (outputBottomField) {
					const bottom = json.data && json.data.bottom_description ? json.data.bottom_description : '';
					outputBottomField.value = String(bottom).trim();
				}
				if (outputMetaTitleField) {
					const metaTitle = json.data && json.data.meta_title ? json.data.meta_title : '';
					outputMetaTitleField.value = String(metaTitle).trim();
				}
				if (outputMetaDescriptionField) {
					const metaDescription = json.data && json.data.meta_description ? json.data.meta_description : '';
					outputMetaDescriptionField.value = String(metaDescription).trim();
				}
				if (outputFocusKeywordsField) {
					const keywords = json.data && json.data.focus_keywords ? json.data.focus_keywords : '';
					outputFocusKeywordsField.value = String(keywords).trim();
				}
				if (rawField) {
					rawField.textContent = (json.data && json.data.raw ? String(json.data.raw) : '').trim();
				}

				setStatus('Tekst gegenereerd. Je kunt hem toepassen en opslaan.', 'success');
			})
			.catch((error) => {
				setStatus(error && error.message ? error.message : 'Er ging iets mis bij het genereren.', 'error');
			})
			.finally(() => {
				setLoading(false);
			});
	});
})();
