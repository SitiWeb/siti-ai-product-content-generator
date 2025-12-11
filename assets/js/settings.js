(function () {
	function onReady(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
		} else {
			callback();
		}
	}

	onReady(function () {
		const data = window.GroqAISettingsData || {};
		const optionKey = data.optionKey || '';
		if (!optionKey) {
			return;
		}

		const providerSelect = document.querySelector('select[name="' + optionKey + '[provider]"]');
		const modelSelect = document.getElementById('groq-ai-model-select');
		const refreshButton = document.getElementById('groq-ai-refresh-models');
		const refreshStatus = document.getElementById('groq-ai-refresh-models-status');
		const excludedModels = data.excludedModels || {};
		let currentModelValue = (modelSelect && modelSelect.dataset.currentModel) || data.currentModel || '';

		function toggleProviderRows() {
			if (!data.providerRows || !providerSelect) {
				return;
			}

			Object.keys(data.providerRows).forEach(function (provider) {
				const rowId = data.providerRows[provider];
				let row = rowId ? document.getElementById(rowId) : null;
				if (!row) {
					const field = document.querySelector('[data-provider-row="' + provider + '"]');
					if (field) {
						row = field.closest('tr') || field;
					}
				}
				if (!row) {
					return;
				}
				const target = row.tagName && row.tagName.toLowerCase() === 'tr' ? row : row.closest('tr') || row;
				target.style.display = provider === providerSelect.value ? '' : 'none';
			});
		}

		function isModelAllowed(model, providerOverride) {
			if (!model) {
				return true;
			}

			const provider = providerOverride || (providerSelect ? providerSelect.value : data.currentProvider);
			if (!provider || !excludedModels[provider]) {
				return true;
			}

			return excludedModels[provider].indexOf(model) === -1;
		}

		function ensureCurrentModelAllowed(providerOverride) {
			if (!currentModelValue) {
				return;
			}

			if (!isModelAllowed(currentModelValue, providerOverride)) {
				currentModelValue = '';
				if (modelSelect) {
					modelSelect.dataset.currentModel = '';
				}
			}
		}

		function buildModelOptions() {
			if (!modelSelect || !data.providers) {
				return;
			}

			const provider = providerSelect ? providerSelect.value : data.currentProvider;
			const providerData = data.providers[provider];
			if (!providerData) {
				return;
			}

			ensureCurrentModelAllowed(provider);

			const models = Array.isArray(providerData.models) ? providerData.models : [];
			const frag = document.createDocumentFragment();
			const placeholder = document.createElement('option');
			placeholder.value = '';
			const defaultLabel = providerData.default_label || (data.placeholders && data.placeholders.selectModel) || 'Selecteer een model via "Live modellen ophalen"';
			placeholder.textContent = defaultLabel;
			frag.appendChild(placeholder);

			let hasCurrent = false;
			models.forEach(function (model) {
				if (!isModelAllowed(model, provider)) {
					return;
				}
				const option = document.createElement('option');
				option.value = model;
				option.textContent = model;
				if (model === currentModelValue) {
					hasCurrent = true;
				}
				frag.appendChild(option);
			});

			if (currentModelValue && !hasCurrent && isModelAllowed(currentModelValue, provider)) {
				const extraOption = document.createElement('option');
				extraOption.value = currentModelValue;
				extraOption.textContent = currentModelValue;
				frag.appendChild(extraOption);
			}

			modelSelect.innerHTML = '';
			modelSelect.appendChild(frag);
			modelSelect.value = currentModelValue || '';
			modelSelect.dataset.currentModel = currentModelValue || '';
		}

		function setRefreshStatus(message, type) {
			if (!refreshStatus) {
				return;
			}
			refreshStatus.textContent = message || '';
			refreshStatus.dataset.status = type || '';
		}

		function updateRefreshButtonVisibility() {
			if (!refreshButton) {
				return;
			}
			const provider = providerSelect ? providerSelect.value : data.currentProvider;
			const providerData = data.providers && data.providers[provider] ? data.providers[provider] : null;
			const supports = providerData && providerData.supports_live;
			refreshButton.style.display = supports ? '' : 'none';
			if (!supports) {
				setRefreshStatus('', '');
			}
		}

		function handleModelChange() {
			if (!modelSelect) {
				return;
			}
			currentModelValue = modelSelect.value;
			modelSelect.dataset.currentModel = currentModelValue;
		}

		function handleProviderChange() {
			currentModelValue = '';
			if (modelSelect) {
				modelSelect.dataset.currentModel = '';
			}
			buildModelOptions();
			handleModelChange();
			toggleProviderRows();
			updateRefreshButtonVisibility();
		}

		function handleRefreshModels() {
			if (!refreshButton || !data.ajaxUrl) {
				return;
			}
			const provider = providerSelect ? providerSelect.value : data.currentProvider;
			const providerData = data.providers && data.providers[provider] ? data.providers[provider] : null;
			if (!providerData || !providerData.supports_live) {
				setRefreshStatus('Deze aanbieder ondersteunt dit niet.', 'error');
				return;
			}

			const keyField = document.querySelector('[data-provider-row="' + provider + '"] input');
			const apiKey = keyField ? keyField.value.trim() : '';
			if (!apiKey) {
				setRefreshStatus('Vul eerst de API-sleutel in.', 'error');
				return;
			}

			refreshButton.disabled = true;
			setRefreshStatus('Modellen worden opgehaald…', 'loading');

			const payload = new URLSearchParams();
			payload.append('action', 'groq_ai_refresh_models');
			payload.append('nonce', data.refreshNonce || '');
			payload.append('provider', provider);
			payload.append('apiKey', apiKey);

			fetch(data.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: payload.toString(),
			})
				.then((response) => response.json())
				.then((json) => {
					if (!json.success || !json.data || !Array.isArray(json.data.models)) {
						throw new Error((json.data && json.data.message) || 'Onbekende fout');
					}
					data.providers[provider].models = json.data.models;
					buildModelOptions();
					setRefreshStatus('Modellen bijgewerkt.', 'success');
				})
				.catch((error) => {
					setRefreshStatus(error.message || 'Ophalen mislukt.', 'error');
				})
				.finally(() => {
					refreshButton.disabled = false;
				});
		}

		if (modelSelect) {
			modelSelect.addEventListener('change', handleModelChange);
		}
		if (providerSelect) {
			providerSelect.addEventListener('change', handleProviderChange);
		}
		if (refreshButton) {
			refreshButton.addEventListener('click', handleRefreshModels);
		}

		buildModelOptions();
		handleModelChange();
		toggleProviderRows();
		updateRefreshButtonVisibility();
	});
})();
