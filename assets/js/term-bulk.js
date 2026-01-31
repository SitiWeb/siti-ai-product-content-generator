(function () {
	const data = window.GroqAITermBulk || {};
	const startButton = document.getElementById('groq-ai-bulk-generate');
	const stopButton = document.getElementById('groq-ai-bulk-cancel');
	const statusField = document.getElementById('groq-ai-bulk-status');
	const logList = document.getElementById('groq-ai-bulk-log');

	if (!data.ajaxUrl || !startButton || !statusField || !logList) {
		return;
	}

	const strings = data.strings || {};
	const allowRegenerate = !!data.allowRegenerate;
	const unknownErrorText = strings.unknownError || 'Onbekende fout';
	const unknownTermText = strings.unknownTerm || 'Onbekende term.';
	const confirmStopFallbackText = strings.confirmStopFallback || 'Stoppen?';
	const logErrorDefaultText = strings.logErrorDefault || '%1$s: %2$s';
	const logSuccessDefaultText = strings.logSuccessDefault || '%1$s gevuld.';
	const regenerateErrorDefaultText = strings.regenerateErrorDefault || '%1$s mislukt: %2$s';
	const regenerateDoneDefaultText = strings.regenerateDoneDefault || '%s is bijgewerkt.';
	const terms = (Array.isArray(data.terms) ? data.terms : [])
		.map((term) => {
			const id = parseInt(term.id, 10);
			if (!Number.isFinite(id)) {
				return null;
			}
			const words = typeof term.words === 'number' ? term.words : parseInt(term.words, 10) || 0;
			const hasDescription = !!term.hasDescription;
			return {
				id,
				name: term.name || '',
				slug: term.slug || '',
				count: typeof term.count === 'number' ? term.count : parseInt(term.count, 10) || 0,
				words,
				hasDescription,
				needsGeneration: !hasDescription,
			};
		})
		.filter(Boolean);

	const termMap = new Map();
	terms.forEach((term) => termMap.set(term.id, term));

	let queue = [];
	let totalCount = 0;
	let processed = 0;
	let successes = 0;
	let isRunning = false;
	let abortRequested = false;

	function formatString(template, values) {
		if (!template) {
			return '';
		}
		let autoIndex = 0;
		return template.replace(/%(\d+\$)?[sd]/g, (match, position) => {
			let valueIndex;
			if (position) {
				valueIndex = parseInt(position, 10) - 1;
			} else {
				valueIndex = autoIndex;
				autoIndex += 1;
			}
			const replacement = values[valueIndex];
			return typeof replacement === 'undefined' ? '' : String(replacement);
		});
	}

	function setStatus(message, type) {
		statusField.textContent = message || '';
		statusField.dataset.status = type || '';
	}

	function appendLog(message, type) {
		if (!message) {
			return;
		}
		const item = document.createElement('li');
		item.textContent = message;
		item.dataset.status = type || '';
		logList.appendChild(item);
	}

	function resetLog() {
		logList.innerHTML = '';
	}

	function toggleButtons(running) {
		isRunning = running;
		startButton.disabled = running;
		if (stopButton) {
			stopButton.hidden = !running;
		}
	}

	function getPendingTerms() {
		return terms.filter((term) => term.needsGeneration);
	}

	function updateRow(term) {
		const row = document.querySelector('[data-groq-ai-term-id="' + term.id + '"]');
		if (!row) {
			return;
		}
		row.classList.remove('groq-ai-term-missing');
		row.classList.add('groq-ai-term-updated');
		const wordCell = row.querySelector('.groq-ai-word-count');
		if (wordCell) {
			wordCell.textContent = String(term.words);
		}
	}

	function markTermCompleted(term, words) {
		term.hasDescription = true;
		term.needsGeneration = false;
		if (Number.isFinite(words)) {
			term.words = words;
		}
		updateRow(term);
	}

	function finish(state) {
		const summaryTemplate = state === 'done' ? strings.statusDone : state === 'stopped' ? strings.statusStopped : '';
		const summary = summaryTemplate ? formatString(summaryTemplate, [successes]) : '';
		const statusType = state === 'done' ? 'success' : state === 'stopped' ? 'info' : '';
		setStatus(summary, statusType);
		toggleButtons(false);
		queue = [];
		totalCount = 0;
		processed = 0;
		successes = 0;
		abortRequested = false;
	}

	function sendRequest(term, options = {}) {
		const payload = new URLSearchParams();
		payload.append('action', 'groq_ai_bulk_generate_terms');
		payload.append('nonce', data.nonce || '');
		payload.append('taxonomy', data.taxonomy || '');
		payload.append('term_id', term.id);
		if (options.force) {
			payload.append('force', '1');
		}

		return fetch(data.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: payload.toString(),
		}).then((response) => response.json());
	}

	function handleResponse(term, json, context) {
		if (!json || !json.success) {
			const errorMessage = (json && json.data && json.data.message) || unknownErrorText;
			appendLog(formatString(strings.logError || logErrorDefaultText, [term.name || term.id, errorMessage]), 'error');
			if (context === 'single') {
				setStatus(formatString(strings.regenerateError || regenerateErrorDefaultText, [term.name || term.id, errorMessage]), 'error');
			}
			return false;
		}

		const words = json.data && typeof json.data.words !== 'undefined' ? parseInt(json.data.words, 10) : term.words;
		markTermCompleted(term, Number.isFinite(words) ? words : term.words);
		appendLog(formatString(strings.logSuccess || logSuccessDefaultText, [term.name || term.id, term.words]), 'success');
		if (context === 'single') {
			setStatus(formatString(strings.regenerateDone || regenerateDoneDefaultText, [term.name || term.id]), 'success');
		}
		return true;
	}

	function processNext() {
		if (abortRequested) {
			finish('stopped');
			return;
		}

		if (!queue.length) {
			finish('done');
			return;
		}

		const term = queue.shift();
		const progressTemplate = strings.statusProgress;
		if (progressTemplate) {
			setStatus(formatString(progressTemplate, [processed + 1, totalCount, term.name || '']), 'loading');
		}

		sendRequest(term)
			.then((json) => {
				if (handleResponse(term, json, 'bulk')) {
					successes += 1;
				}
			})
			.catch((error) => {
				appendLog(
					formatString(strings.logError || '%1$s: %2$s', [term.name || term.id, error && error.message ? error.message : 'Onbekende fout']),
					'error'
				);
			})
			.finally(() => {
				processed += 1;
				if (abortRequested) {
					finish('stopped');
				} else {
					processNext();
				}
			});
	}

	function startBulk() {
		if (isRunning) {
			return;
		}

		const pending = getPendingTerms();
		if (!pending.length) {
			setStatus(strings.statusEmpty || '', 'info');
			return;
		}

		queue = pending.slice();
		totalCount = queue.length;
		processed = 0;
		successes = 0;
		abortRequested = false;
		resetLog();
		toggleButtons(true);
		if (strings.statusIdle) {
			setStatus(strings.statusIdle, 'info');
		}
		processNext();
	}

	startButton.addEventListener('click', startBulk);

	if (stopButton) {
		stopButton.addEventListener('click', () => {
			if (!isRunning) {
				return;
			}
			const confirmation = strings.confirmStop ? window.confirm(strings.confirmStop) : window.confirm(confirmStopFallbackText);
			if (confirmation) {
				abortRequested = true;
			}
		});
	}

	if (allowRegenerate) {
		const buttons = document.querySelectorAll('.groq-ai-regenerate-term');
		buttons.forEach((button) => {
			button.addEventListener('click', () => {
				if (isRunning) {
					setStatus(strings.regenerateBlocked || '', 'error');
					return;
				}
				const termId = parseInt(button.getAttribute('data-term-id'), 10);
				const term = termMap.get(termId);
				if (!term) {
					setStatus(unknownTermText, 'error');
					return;
				}
				if (strings.confirmRegenerate) {
					const confirmed = window.confirm(formatString(strings.confirmRegenerate, [term.name || term.id]));
					if (!confirmed) {
						return;
					}
				}
				button.classList.add('is-busy');
				button.disabled = true;
				if (strings.regenerateProgress) {
					setStatus(formatString(strings.regenerateProgress, [term.name || term.id]), 'loading');
				}
				sendRequest(term, { force: true })
					.then((json) => {
						handleResponse(term, json, 'single');
					})
					.catch((error) => {
						const message = error && error.message ? error.message : unknownErrorText;
						appendLog(formatString(strings.logError || logErrorDefaultText, [term.name || term.id, message]), 'error');
						setStatus(formatString(strings.regenerateError || regenerateErrorDefaultText, [term.name || term.id, message]), 'error');
					})
					.finally(() => {
						button.disabled = false;
						button.classList.remove('is-busy');
					});
			});
		});
	}

	if (getPendingTerms().length === 0) {
		setStatus(strings.statusEmpty || '', 'info');
	}
})();
