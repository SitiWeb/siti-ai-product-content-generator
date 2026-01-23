(function () {
	const data = window.GroqAICategoryBulk || {};
	const startButton = document.getElementById('groq-ai-bulk-generate');
	const stopButton = document.getElementById('groq-ai-bulk-cancel');
	const statusField = document.getElementById('groq-ai-bulk-status');
	const logList = document.getElementById('groq-ai-bulk-log');

	if (!startButton || !data.ajaxUrl) {
		return;
	}

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
		if (!statusField) {
			return;
		}
		statusField.textContent = message || '';
		statusField.dataset.status = type || '';
	}

	function appendLog(message, type) {
		if (!logList || !message) {
			return;
		}
		const item = document.createElement('li');
		item.textContent = message;
		item.dataset.status = type || '';
		logList.appendChild(item);
	}

	function resetLog() {
		if (!logList) {
			return;
		}
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
		if (!Array.isArray(data.terms)) {
			return [];
		}
		return data.terms.filter((term) => !term.processed);
	}

	function updateRow(termId, words) {
		const row = document.querySelector('[data-groq-ai-term-id="' + termId + '"]');
		if (!row) {
			return;
		}
		row.classList.remove('groq-ai-term-missing');
		row.classList.add('groq-ai-term-updated');
		const wordCell = row.querySelector('.groq-ai-word-count');
		if (wordCell) {
			wordCell.textContent = String(typeof words === 'number' ? words : wordCell.textContent);
		}
	}

	function finish(state) {
		const summaryTemplate =
			state === 'done'
				? data.strings && data.strings.statusDone
				: state === 'stopped'
				? data.strings && data.strings.statusStopped
				: '';

		const summary = summaryTemplate
			? formatString(summaryTemplate, [successes])
			: '';

		const statusType = state === 'done' ? 'success' : state === 'stopped' ? 'info' : '';
		setStatus(summary, statusType);
		toggleButtons(false);
		queue = [];
		totalCount = 0;
		abortRequested = false;
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
		const position = processed + 1;
		const progressTemplate = data.strings && data.strings.statusProgress;
		if (progressTemplate) {
			setStatus(formatString(progressTemplate, [position, totalCount, term.name || '']), 'loading');
		}

		const payload = new URLSearchParams();
		payload.append('action', 'groq_ai_bulk_generate_terms');
		payload.append('nonce', data.nonce || '');
		payload.append('taxonomy', data.taxonomy || 'product_cat');
		payload.append('term_id', term.id);

		fetch(data.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: payload.toString(),
		})
			.then((response) => response.json())
			.then((json) => {
				if (!json.success) {
					const errorMessage = (json.data && json.data.message) || 'Onbekende fout';
					appendLog(formatString((data.strings && data.strings.logError) || '%1$s: %2$s', [term.name || term.id, errorMessage]), 'error');
					return;
				}

				term.processed = true;
				successes += 1;
				const words = json.data && typeof json.data.words !== 'undefined' ? json.data.words : 0;
				updateRow(term.id, words);
				appendLog(formatString((data.strings && data.strings.logSuccess) || '%1$s gevuld.', [term.name || term.id, words]), 'success');
			})
			.catch((error) => {
				appendLog(
					formatString((data.strings && data.strings.logError) || '%1$s: %2$s', [term.name || term.id, error && error.message ? error.message : 'Onbekende fout']),
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
			setStatus((data.strings && data.strings.statusEmpty) || '', 'info');
			return;
		}

		queue = pending.slice();
		totalCount = queue.length;
		processed = 0;
		successes = 0;
		abortRequested = false;
		resetLog();
		toggleButtons(true);
		setStatus((data.strings && data.strings.statusIdle) || '', 'info');
		processNext();
	}

	startButton.addEventListener('click', startBulk);

	if (stopButton) {
		stopButton.addEventListener('click', () => {
			if (!isRunning) {
				return;
			}
			const confirmation = ! (data.strings && data.strings.confirmStop)
				? window.confirm('Stoppen?')
				: window.confirm(data.strings.confirmStop);
			if (confirmation) {
				abortRequested = true;
			}
		});
	}

	if (!Array.isArray(data.terms) || !data.terms.length) {
		setStatus((data.strings && data.strings.statusEmpty) || '', 'info');
	}
})();
