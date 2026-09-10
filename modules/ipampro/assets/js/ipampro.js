/* IPAM – Enterprise UI JavaScript */
(function () {
	'use strict';

	var COLORS = {
		blue: '#2563eb',
		green: '#16a34a',
		red: '#dc2626',
		orange: '#ea580c',
		muted: '#94a3b8',
		grid: 'rgba(148, 163, 184, 0.2)',
		text: '#64748b'
	};

	var charts = [];

	/* ── Theme detection (Zabbix 7.4 light/dark) ─────────────── */
	function isDarkTheme() {
		var body = document.body;
		var html = document.documentElement;
		if (body.classList.contains('dark-theme') ||
			body.classList.contains('theme-dark') ||
			html.classList.contains('theme-dark') ||
			html.getAttribute('data-theme') === 'dark') {
			return true;
		}
		var bg = getComputedStyle(body).backgroundColor;
		if (bg) {
			var m = bg.match(/\d+/g);
			if (m && m.length >= 3) {
				var lum = (parseInt(m[0], 10) * 0.299 + parseInt(m[1], 10) * 0.587 + parseInt(m[2], 10) * 0.114);
				return lum < 80;
			}
		}
		return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
	}

	function applyTheme() {
		var root = document.querySelector('.ipam-pro');
		if (!root) return;
		root.classList.toggle('ipam-theme-dark', isDarkTheme());
		updateChartThemes();
	}

	function chartColors() {
		var dark = isDarkTheme();
		return {
			text: dark ? '#94a3b8' : '#64748b',
			grid: dark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(148, 163, 184, 0.25)',
			surface: dark ? '#1e293b' : '#ffffff'
		};
	}

	function updateChartThemes() {
		var c = chartColors();
		charts.forEach(function (chart) {
			if (chart.options.plugins && chart.options.plugins.legend) {
				chart.options.plugins.legend.labels.color = c.text;
			}
			if (chart.options.scales) {
				Object.keys(chart.options.scales).forEach(function (key) {
					var scale = chart.options.scales[key];
					if (scale.ticks) scale.ticks.color = c.text;
					if (scale.grid) scale.grid.color = c.grid;
				});
			}
			chart.update('none');
		});
	}

	/* ── Confirm on dangerous forms ──────────────────────────── */
	document.addEventListener('submit', function (e) {
		if (!e.target.closest('.ipam-pro')) return;
		var msg = e.target.getAttribute('data-ipam-confirm');
		if (msg && !window.confirm(msg)) e.preventDefault();
	});

	/* ── Edit panel toggle ───────────────────────────────────── */
	/*
	 * The edit panel uses position:fixed (see CSS) so it always renders
	 * above table scroll containers instead of being clipped by
	 * overflow:auto on .ipam-table-wrap. Because it's fixed, we must
	 * compute its on-screen coordinates in JS relative to the toggle
	 * button, and re-clamp them to the viewport so the panel never
	 * runs off the right or bottom edge of the screen.
	 */
	function positionEditPanel(panel, toggle) {
		var rect = toggle.getBoundingClientRect();
		var panelWidth  = panel.offsetWidth  || 280;
		var panelHeight = panel.offsetHeight || 320;
		var margin = 8;

		var left = rect.right - panelWidth;
		var top  = rect.bottom + 6;

		// Clamp horizontally
		if (left < margin) left = margin;
		if (left + panelWidth > window.innerWidth - margin) {
			left = window.innerWidth - panelWidth - margin;
		}

		// If not enough room below, flip above the toggle button
		if (top + panelHeight > window.innerHeight - margin) {
			var above = rect.top - panelHeight - 6;
			top = above > margin ? above : margin;
		}

		panel.style.left = left + 'px';
		panel.style.top  = top  + 'px';
	}

	document.addEventListener('click', function (e) {
		if (!e.target.closest('.ipam-pro')) return;
		var toggle = e.target.closest('[data-edit-toggle]');
		if (toggle) {
			var wrap = toggle.closest('[data-edit-wrap]');
			var panel = wrap && wrap.querySelector('[data-edit-panel]');
			if (!panel) return;
			var isOpen = panel.classList.contains('open');
			document.querySelectorAll('.ipam-pro [data-edit-panel].open').forEach(function (p) {
				p.classList.remove('open');
			});
			if (!isOpen) {
				panel.classList.add('open');
				// Position after 'open' so offsetWidth/Height are accurate
				positionEditPanel(panel, toggle);
			}
			e.stopPropagation();
			return;
		}
		if (e.target.closest('[data-edit-close]')) {
			var p = e.target.closest('[data-edit-panel]');
			if (p) p.classList.remove('open');
			return;
		}
		if (!e.target.closest('[data-edit-wrap]')) {
			document.querySelectorAll('.ipam-pro [data-edit-panel].open').forEach(function (p) {
				p.classList.remove('open');
			});
		}
	});

	/* Reposition open panel on scroll/resize so it tracks its toggle */
	window.addEventListener('scroll', function () {
		var openPanel = document.querySelector('.ipam-pro [data-edit-panel].open');
		if (!openPanel) return;
		var wrap = openPanel.closest('[data-edit-wrap]');
		var toggle = wrap && wrap.querySelector('[data-edit-toggle]');
		if (toggle) positionEditPanel(openPanel, toggle);
	}, true);

	window.addEventListener('resize', function () {
		var openPanel = document.querySelector('.ipam-pro [data-edit-panel].open');
		if (!openPanel) return;
		var wrap = openPanel.closest('[data-edit-wrap]');
		var toggle = wrap && wrap.querySelector('[data-edit-toggle]');
		if (toggle) positionEditPanel(openPanel, toggle);
	});

	/* ── IP detail dialog ────────────────────────────────────── */
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.ipam-pro')) return;
		var block = e.target.closest('[data-ipam-detail]');
		if (!block) return;
		var dialog = document.querySelector('.ipam-pro [data-ipam-dialog]');
		if (!dialog) return;
		var dialogBody = dialog.querySelector('[data-ipam-dialog-body]');
		var dialogTitle = dialog.querySelector('[data-dialog-title]');
		var ip;
		try { ip = JSON.parse(block.getAttribute('data-ipam-detail')); }
		catch (err) { return; }

		if (dialogTitle) dialogTitle.textContent = ip.ip_address || 'IP Details';

		var fields = [
			['IP Address', ip.ip_address],
			['Subnet', (ip.subnet && ip.cidr) ? ip.subnet + '/' + ip.cidr : '—'],
			['Status', ip.status],
			['Hostname', ip.hostname || '—'],
			['MAC Address', ip.mac || '—'],
			['Owner', ip.owner || '—'],
			['VLAN', ip.vlan || '—'],
			['Description', ip.description || '—'],
			['Zabbix Host', ip.zabbix_hostid ? 'Monitored' : '—'],
			['Last Seen', ip.last_seen_at || '—'],
			['Reserved By', ip.reserved_by || '—'],
			['Device', ip.device || '—'],
			['Purpose', ip.purpose || '—']
		];

		var html = '<dl class="ipam-detail-grid">';
		fields.forEach(function (f) {
			var val = f[1];
			if (f[0] === 'Status') {
				val = '<span class="ipam-badge ' + (ip.status || '') + '">' + (ip.status || '—') + '</span>';
			}
			html += '<dt>' + f[0] + '</dt><dd>' + val + '</dd>';
		});
		html += '</dl>';

		if (dialogBody) dialogBody.innerHTML = html;
		if (typeof dialog.showModal === 'function') dialog.showModal();
	});

	/* ── KPI count-up animation ──────────────────────────────── */
	function animateStats() {
		document.querySelectorAll('.ipam-pro .ipam-stat-value[data-count]').forEach(function (el) {
			var raw = el.textContent.trim();
			var num = parseFloat(String(el.getAttribute('data-count')).replace('%', ''));
			var isPct = raw.indexOf('%') !== -1;
			if (isNaN(num) || num === 0) return;
			var startTime = null;
			var dur = 700;
			function step(ts) {
				if (!startTime) startTime = ts;
				var prog = Math.min((ts - startTime) / dur, 1);
				var ease = 1 - Math.pow(1 - prog, 3);
				var cur = isPct ? Math.round(ease * num * 10) / 10 : Math.round(ease * num);
				el.textContent = isPct ? cur + '%' : String(cur);
				if (prog < 1) requestAnimationFrame(step);
				else el.textContent = raw;
			}
			requestAnimationFrame(step);
		});
	}

	/* ── Progress bar animation ──────────────────────────────── */
	function animateProgressBars() {
		document.querySelectorAll('.ipam-pro .ipam-progress-bar').forEach(function (bar) {
			var w = bar.style.width;
			bar.style.width = '0';
			setTimeout(function () { bar.style.width = w; }, 100);
		});
	}

	/* ── Chart.js initialization ─────────────────────────────── */
	function loadChartJs(callback) {
		if (window.Chart) {
			callback();
			return;
		}
		var waited = 0;
		var timer = setInterval(function () {
			waited += 50;
			if (window.Chart) {
				clearInterval(timer);
				callback();
			} else if (waited > 5000) {
				clearInterval(timer);
			}
		}, 50);
	}

	function initDashboardCharts() {
		var dataEl = document.getElementById('ipam-chart-data');
		if (!dataEl || !window.Chart) return;

		var data;
		try { data = JSON.parse(dataEl.textContent); }
		catch (e) { return; }

		var c = chartColors();

		/* Donut */
		var donutEl = document.getElementById('ipam-chart-donut');
		if (donutEl && data.donut) {
			charts.push(new Chart(donutEl, {
				type: 'doughnut',
				data: {
					labels: ['Used', 'Free', 'Reserved'],
					datasets: [{
						data: [data.donut.used, data.donut.free, data.donut.reserved],
						backgroundColor: [COLORS.red, COLORS.green, COLORS.orange],
						borderWidth: 0,
						hoverOffset: 6
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					cutout: '68%',
					plugins: {
						legend: {
							position: 'bottom',
							labels: { color: c.text, padding: 14, usePointStyle: true, pointStyle: 'circle' }
						},
						tooltip: {
							callbacks: {
								label: function (ctx) {
									var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
									var pct = total ? Math.round(ctx.raw / total * 1000) / 10 : 0;
									return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
								}
							}
						}
					}
				}
			}));
		}

		/* Trend line */
		var trendEl = document.getElementById('ipam-chart-trend');
		if (trendEl && data.trend) {
			charts.push(new Chart(trendEl, {
				type: 'line',
				data: {
					labels: data.trend.labels.length ? data.trend.labels : ['No data'],
					datasets: [{
						label: 'Utilization %',
						data: data.trend.values.length ? data.trend.values : [0],
						borderColor: COLORS.blue,
						backgroundColor: 'rgba(37, 99, 235, 0.12)',
						fill: true,
						tension: 0.35,
						pointRadius: 4,
						pointHoverRadius: 6,
						pointBackgroundColor: COLORS.blue
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					scales: {
						y: {
							min: 0,
							max: 100,
							ticks: { color: c.text, callback: function (v) { return v + '%'; } },
							grid: { color: c.grid }
						},
						x: {
							ticks: { color: c.text, maxRotation: 45 },
							grid: { display: false }
						}
					},
					plugins: {
						legend: { display: false }
					}
				}
			}));
		}

		/* Bar chart */
		var barsEl = document.getElementById('ipam-chart-bars');
		if (barsEl && data.bars) {
			var barColors = (data.bars.values || []).map(function (v) {
				if (v >= 85) return COLORS.red;
				if (v >= 50) return COLORS.orange;
				return COLORS.green;
			});
			charts.push(new Chart(barsEl, {
				type: 'bar',
				data: {
					labels: data.bars.labels.length ? data.bars.labels : ['No subnets'],
					datasets: [{
						label: 'Utilization %',
						data: data.bars.values.length ? data.bars.values : [0],
						backgroundColor: barColors.length ? barColors : [COLORS.muted],
						borderRadius: 6,
						borderSkipped: false
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					indexAxis: 'y',
					scales: {
						x: {
							min: 0,
							max: 100,
							ticks: { color: c.text, callback: function (v) { return v + '%'; } },
							grid: { color: c.grid }
						},
						y: {
							ticks: { color: c.text },
							grid: { display: false }
						}
					},
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								label: function (ctx) { return ctx.raw + '% utilized'; }
							}
						}
					}
				}
			}));
		}
	}

	/* ── Overview gauge charts ───────────────────────────────── */
	function initGauges() {
		if (!window.Chart) return;
		document.querySelectorAll('.ipam-pro [data-ipam-gauge]').forEach(function (canvas) {
			var value = parseFloat(canvas.getAttribute('data-ipam-gauge')) || 0;
			var max = parseFloat(canvas.getAttribute('data-gauge-max')) || 100;
			var colorKey = canvas.getAttribute('data-gauge-color') || 'blue';
			var colorMap = { blue: COLORS.blue, green: COLORS.green, red: COLORS.red, orange: COLORS.orange };
			var color = colorMap[colorKey] || COLORS.blue;
			var pct = max > 0 ? Math.min(100, (value / max) * 100) : value;

			charts.push(new Chart(canvas, {
				type: 'doughnut',
				data: {
					datasets: [{
						data: [pct, 100 - pct],
						backgroundColor: [color, 'rgba(148, 163, 184, 0.2)'],
						borderWidth: 0
					}]
				},
				options: {
					responsive: false,
					cutout: '75%',
					plugins: { legend: { display: false }, tooltip: { enabled: false } },
					animation: { animateRotate: true, duration: 800 }
				}
			}));
		});
	}

	/* ── Table: filter, sort, pagination ─────────────────────── */
	function initTable(table) {
		var pageSize = parseInt(table.getAttribute('data-page-size'), 10) || 15;
		var tbody = table.querySelector('tbody');
		if (!tbody) return;

		var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search], tr[data-status]'));
		if (!allRows.length) return;

		var wrap = table.closest('.ipam-table-wrap');
		var toolbar = wrap && wrap.previousElementSibling && wrap.previousElementSibling.matches('[data-ipam-toolbar]')
			? wrap.previousElementSibling
			: document.querySelector('.ipam-pro [data-ipam-toolbar]');
		var filterInput = toolbar && toolbar.querySelector('[data-ipam-filter]');
		var pagination = toolbar && toolbar.querySelector('[data-ipam-pagination]');
		var pageInfo = pagination && pagination.querySelector('[data-page-info]');
		var prevBtn = pagination && pagination.querySelector('[data-page-prev]');
		var nextBtn = pagination && pagination.querySelector('[data-page-next]');
		var statusFilter = toolbar && toolbar.querySelector('[data-ipam-status-filter]');

		var state = { page: 1, filter: '', status: '', sortCol: -1, sortDir: 'asc' };

		function getVisibleRows() {
			return allRows.filter(function (row) {
				if (row.classList.contains('ipam-no-data')) return false;
				var matchFilter = !state.filter || (row.getAttribute('data-search') || '').indexOf(state.filter) !== -1;
				var matchStatus = !state.status || row.getAttribute('data-status') === state.status;
				return matchFilter && matchStatus;
			});
		}

		function render() {
			var visible = getVisibleRows();
			var totalPages = Math.max(1, Math.ceil(visible.length / pageSize));
			if (state.page > totalPages) state.page = totalPages;

			allRows.forEach(function (row) { row.classList.add('ipam-hidden'); });

			var start = (state.page - 1) * pageSize;
			visible.slice(start, start + pageSize).forEach(function (row) {
				row.classList.remove('ipam-hidden');
			});

			if (pageInfo) {
				pageInfo.textContent = visible.length
					? 'Page ' + state.page + ' of ' + totalPages + ' (' + visible.length + ')'
					: 'No results';
			}
			if (prevBtn) prevBtn.disabled = state.page <= 1;
			if (nextBtn) nextBtn.disabled = state.page >= totalPages;
		}

		if (filterInput) {
			filterInput.addEventListener('input', function () {
				state.filter = filterInput.value.toLowerCase().trim();
				state.page = 1;
				render();
			});
		}

		if (statusFilter) {
			statusFilter.addEventListener('click', function (e) {
				var chip = e.target.closest('[data-status]');
				if (!chip) return;
				statusFilter.querySelectorAll('.ipam-chip').forEach(function (c) {
					c.classList.remove('active');
				});
				chip.classList.add('active');
				state.status = chip.getAttribute('data-status') || '';
				state.page = 1;
				render();
			});
		}

		if (prevBtn) prevBtn.addEventListener('click', function () { if (state.page > 1) { state.page--; render(); } });
		if (nextBtn) nextBtn.addEventListener('click', function () { state.page++; render(); });

		table.querySelectorAll('thead th[data-sortable]').forEach(function (th, colIndex) {
			th.innerHTML += ' <span class="sort-icon">↕</span>';
			th.addEventListener('click', function () {
				var sortType = th.getAttribute('data-sort') || 'text';
				if (state.sortCol === colIndex) {
					state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
				} else {
					state.sortCol = colIndex;
					state.sortDir = 'asc';
				}

				table.querySelectorAll('thead th').forEach(function (h) {
					h.classList.remove('sorted-asc', 'sorted-desc');
				});
				th.classList.add(state.sortDir === 'asc' ? 'sorted-asc' : 'sorted-desc');
				th.querySelector('.sort-icon').textContent = state.sortDir === 'asc' ? '↑' : '↓';

				allRows.sort(function (a, b) {
					var cellA = a.children[colIndex];
					var cellB = b.children[colIndex];
					var valA = cellA && (cellA.getAttribute('data-value') || cellA.textContent.trim());
					var valB = cellB && (cellB.getAttribute('data-value') || cellB.textContent.trim());
					if (sortType === 'num') {
						valA = parseFloat(valA) || 0;
						valB = parseFloat(valB) || 0;
						return state.sortDir === 'asc' ? valA - valB : valB - valA;
					}
					valA = String(valA).toLowerCase();
					valB = String(valB).toLowerCase();
					if (valA < valB) return state.sortDir === 'asc' ? -1 : 1;
					if (valA > valB) return state.sortDir === 'asc' ? 1 : -1;
					return 0;
				});

				allRows.forEach(function (row) { tbody.appendChild(row); });
				render();
			});
		});

		render();
	}

	/* ── CSV export (client-side) ──────────────────────────── */
	function tableToCsv(table, rowFilter) {
		var headers = [];
		table.querySelectorAll('thead th').forEach(function (th, i) {
			if (i === 0 && th.querySelector('input[type="checkbox"]')) return;
			headers.push('"' + th.textContent.replace(/↕|↑|↓/g, '').trim().replace(/"/g, '""') + '"');
		});

		var rows = [headers.join(',')];
		table.querySelectorAll('tbody tr').forEach(function (tr) {
			if (tr.classList.contains('ipam-hidden')) return;
			if (rowFilter && !rowFilter(tr)) return;
			var cells = [];
			Array.prototype.forEach.call(tr.children, function (td, i) {
				if (i === 0 && td.querySelector('input[type="checkbox"]')) return;
				cells.push('"' + td.textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""') + '"');
			});
			if (cells.length) rows.push(cells.join(','));
		});
		return rows.join('\n');
	}

	function downloadCsv(filename, content) {
		var blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = filename;
		link.click();
		URL.revokeObjectURL(link.href);
	}

	function initBulkActions() {
		var table = document.querySelector('.ipam-pro [data-ipam-table="addresses"]');
		if (!table) return;

		var bulkBar = document.querySelector('.ipam-pro [data-ipam-bulk-bar]');
		var bulkCount = document.querySelector('.ipam-pro [data-ipam-bulk-count]');
		var selectAll = table.querySelector('[data-ipam-select-all]');
		var exportAll = document.querySelector('.ipam-pro [data-ipam-export-all]');
		var exportSelected = document.querySelector('.ipam-pro [data-ipam-bulk-export]');

		function getChecked() {
			return Array.prototype.slice.call(table.querySelectorAll('[data-ipam-row-check]:checked'));
		}

		function updateBulkBar() {
			var checked = getChecked();
			if (bulkBar) bulkBar.classList.toggle('visible', checked.length > 0);
			if (bulkCount) bulkCount.textContent = checked.length + ' selected';
		}

		table.addEventListener('change', function (e) {
			if (e.target.matches('[data-ipam-row-check]') || e.target.matches('[data-ipam-select-all]')) {
				if (e.target.matches('[data-ipam-select-all]')) {
					var visible = table.querySelectorAll('tbody tr:not(.ipam-hidden) [data-ipam-row-check]');
					visible.forEach(function (cb) { cb.checked = e.target.checked; });
				}
				updateBulkBar();
			}
		});

		if (exportAll) {
			exportAll.addEventListener('click', function () {
				downloadCsv('ipam-addresses.csv', tableToCsv(table));
			});
		}

		if (exportSelected) {
			exportSelected.addEventListener('click', function () {
				var selected = getChecked().map(function (cb) { return cb.value; });
				downloadCsv('ipam-addresses-selected.csv', tableToCsv(table, function (tr) {
					var cb = tr.querySelector('[data-ipam-row-check]');
					return cb && selected.indexOf(cb.value) !== -1;
				}));
			});
		}
	}

	/* ── Init ────────────────────────────────────────────────── */
	function init() {
		var root = document.querySelector('.ipam-pro');
		if (!root) return;

		const logoSvg = document.querySelector('.ipam-page-logo svg');
		if (logoSvg) {
			console.log({
				width: logoSvg.getBoundingClientRect().width,
				height: logoSvg.getBoundingClientRect().height,
				computed: getComputedStyle(logoSvg)
			});
		}

		applyTheme();
		animateStats();
		animateProgressBars();

		root.querySelectorAll('[data-ipam-table]').forEach(initTable);
		initBulkActions();

		loadChartJs(function () {
			initDashboardCharts();
			initGauges();
		});

		if (window.matchMedia) {
			window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);
		}

		var observer = new MutationObserver(applyTheme);
		observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
		observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
