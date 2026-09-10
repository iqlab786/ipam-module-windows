<?php
declare(strict_types = 1);

$tab      = $data['tab'];
$is_admin = $data['is_admin'];
$sid      = htmlspecialchars($data['sid'], ENT_QUOTES, 'UTF-8');

$h   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$url = static fn(array $p = []): string =>
	'zabbix.php?'.http_build_query(array_merge(['action' => 'ipampro.view'], $p));
$pct = static fn($v): string => min(100, max(0, (float) $v)).'%';

$progressClass = static function(float $util): string {
	if ($util >= 85) return 'high';
	if ($util >= 50) return 'medium';
	return 'low';
};

$networkInfo = static function(string $subnet, int $cidr): array {
	$mask    = $cidr === 0 ? 0 : ((0xffffffff << (32 - $cidr)) & 0xffffffff);
	$network = ip2long($subnet) & $mask;
	$broadcast = $network | (~$mask & 0xffffffff);
	if ($cidr >= 31) {
		$first = $network;
		$last  = $broadcast;
	} else {
		$first = $network + 1;
		$last  = $broadcast - 1;
	}
	return [
		'network'    => long2ip($network),
		'broadcast'  => long2ip($broadcast),
		'gateway'    => long2ip($cidr >= 31 ? $network : $network + 1),
		'first_host' => long2ip($first),
		'last_host'  => long2ip($last),
		'size'       => max(0, $last - $first + 1),
	];
};

$vlanColor = static fn(int $vlan_number): string =>
	'hsl('.($vlan_number * 47 % 360).', 65%, 45%)';

$tabs = [
	'dashboard' => ['label' => 'Dashboard', 'icon' => 'dashboard'],
	'subnets'   => ['label' => 'Subnets', 'icon' => 'subnets'],
	'addresses' => ['label' => 'IP Addresses', 'icon' => 'addresses'],
	'overview'  => ['label' => 'Network Overview', 'icon' => 'overview'],
	'vlans'     => ['label' => 'VLANs', 'icon' => 'vlans'],
	'reports'   => ['label' => 'Reports', 'icon' => 'reports'],
];

$icons = [
	'logo'      => '',
	'search'    => '🔍',
	'dashboard' => '📊',
	'subnets'   => '🌐',
	'addresses' => '🔌',
	'overview'  => '🗺️',
	'vlans'     => '🏷️',
	'reports'   => '📄',
	'subnet'    => '🌐',
	'ip'        => '📋',
	'used'      => '🔴',
	'free'      => '🟢',
	'reserved'  => '🟡',
	'util'      => '📈',
	'export'    => '⬇',
	'scan'      => '📡',
	'chart'     => '📊',
	'empty'     => '📭',
];

/* Build utilization trend from scan history */
$trend_labels = [];
$trend_values = [];
foreach (array_reverse($data['scans']) as $scan) {
	$total = (int)$scan['responding_count'] + (int)$scan['free_count'] + (int)$scan['reserved_count'];
	if ($total <= 0) continue;
	$ts = $scan['finished_at'] ?: $scan['started_at'];
	$trend_labels[] = substr((string)$ts, 0, 10);
	$trend_values[] = round(((int)$scan['responding_count'] + (int)$scan['reserved_count']) / $total * 100, 1);
}
if (empty($trend_values) && !empty($data['dashboard']['totals']['utilization'])) {
	$trend_labels = ['Current'];
	$trend_values = [(float)$data['dashboard']['totals']['utilization']];
}

/* Reports: recently discovered (used IPs with last_seen) */
$recent_devices = array_values(array_filter($data['ips'], static fn($ip) =>
	$ip['status'] === 'used' && !empty($ip['last_seen_at'])
));
usort($recent_devices, static fn($a, $b) => strcmp((string)$b['last_seen_at'], (string)$a['last_seen_at']));
$recent_devices = array_slice($recent_devices, 0, 8);

$unused_networks = array_values(array_filter($data['subnets'], static fn($s) =>
	(float)($s['utilization'] ?? 0) === 0.0
));
?>

<div class="ipam-pro" data-ipam-tab="<?= $h($tab) ?>">
<style>
<?= file_get_contents(dirname(__DIR__) . '/assets/css/ipampro.css') ?>
</style>

	<div class="ipam-page-header">
		<div class="ipam-page-brand">
			<div class="ipam-brand-icon">🌐</div>
			<div class="ipam-page-title">
				<h1>IPAM</h1>
				<p class="ipam-subtitle">IP Address Management · Zabbix Integrated</p>
			</div>
		</div>
		<form class="ipam-search-bar" method="get">
			<input type="hidden" name="action" value="ipampro.view">
			<input type="hidden" name="tab" value="<?= $h($tab) ?>">
			<div class="ipam-search-wrap">
				<input name="search" value="<?= $h($data['search']) ?>"
				       placeholder="🔍 Search IP, hostname, MAC, VLAN…">
			</div>
			<button type="submit" class="ipam-btn ipam-btn-primary">Search</button>
		</form>
	</div>

	<nav class="ipam-nav">
		<?php foreach ($tabs as $key => $t): ?>
			<?php
			$href = $key === 'overview'
				? $url(['tab' => $key, 'subnetid' => (int)($data['selected_subnet']['subnetid'] ?? 0)])
				: $url(['tab' => $key]);
			?>
			<a href="<?= $href ?>" class="<?= $tab === $key ? 'active' : '' ?>">
				<span class="ipam-nav-icon"><?= $icons[$t['icon']] ?></span>
				<?= $h($t['label']) ?>
			</a>
		<?php endforeach ?>
	</nav>

	<div class="ipam-content">

		<?php foreach ($data['messages'] as $msg): ?>
			<div class="ipam-alert <?= $h($msg['type']) ?>">
				<?= $h($msg['text']) ?>
			</div>
		<?php endforeach ?>

		<?php if (!empty($data['setup_required'])): ?>
			<div class="ipam-setup-notice">
				<h2>Setup Required – Database Tables Missing</h2>
				<p>The IPAM module is installed but its database tables have not been created yet. Run the command below on your Zabbix server:</p>
				<pre>mysql -u &lt;zabbix_user&gt; -p &lt;zabbix_database&gt; \
  &lt; /usr/share/zabbix/modules/zabbix-ipam-module/sql/schema.sql</pre>
				<p>Your DB credentials are in <code>/etc/zabbix/zabbix_server.conf</code> — look for <code>DBUser</code>, <code>DBPassword</code>, <code>DBName</code>. Then reload this page.</p>
			</div>

		<?php else: ?>

		<!-- DASHBOARD -->
		<?php if ($tab === 'dashboard'):
			$t = $data['dashboard']['totals']; ?>

			<div class="ipam-stats-row">
				<div class="ipam-stat kpi-card blue">
					<div class="ipam-stat-header">
						<div class="ipam-stat-icon"><?= $icons['subnet'] ?></div>
					</div>
					<div class="ipam-stat-label">Total Subnets</div>
					<div class="ipam-stat-value" data-count="<?= (int)$t['subnets'] ?>"><?= (int)$t['subnets'] ?></div>
					<div class="ipam-stat-meta">Managed networks</div>
				</div>
				<div class="ipam-stat kpi-card blue">
					<div class="ipam-stat-header">
						<div class="ipam-stat-icon"><?= $icons['ip'] ?></div>
					</div>
					<div class="ipam-stat-label">Total IPs</div>
					<div class="ipam-stat-value" data-count="<?= (int)$t['ips'] ?>"><?= (int)$t['ips'] ?></div>
					<div class="ipam-stat-meta">Host addresses</div>
				</div>
				<div class="ipam-stat kpi-card red">
					<div class="ipam-stat-header">
						<div class="ipam-stat-icon"><?= $icons['used'] ?></div>
					</div>
					<div class="ipam-stat-label">Used IPs</div>
					<div class="ipam-stat-value" data-count="<?= (int)$t['used'] ?>"><?= (int)$t['used'] ?></div>
					<div class="ipam-stat-meta">Actively in use</div>
				</div>
				<div class="ipam-stat kpi-card green">
					<div class="ipam-stat-header">
						<div class="ipam-stat-icon"><?= $icons['free'] ?></div>
					</div>
					<div class="ipam-stat-label">Free IPs</div>
					<div class="ipam-stat-value" data-count="<?= (int)$t['free'] ?>"><?= (int)$t['free'] ?></div>
					<div class="ipam-stat-meta">Available to assign</div>
				</div>
				<div class="ipam-stat kpi-card orange">
					<div class="ipam-stat-header">
						<div class="ipam-stat-icon"><?= $icons['reserved'] ?></div>
					</div>
					<div class="ipam-stat-label">Reserved IPs</div>
					<div class="ipam-stat-value" data-count="<?= (int)$t['reserved'] ?>"><?= (int)$t['reserved'] ?></div>
					<div class="ipam-stat-meta">Held for allocation</div>
				</div>
				<div class="ipam-stat kpi-card blue">
					<div class="ipam-stat-header">
						<div class="ipam-stat-icon"><?= $icons['util'] ?></div>
					</div>
					<div class="ipam-stat-label">Utilization</div>
					<div class="ipam-stat-value" data-count="<?= $h($t['utilization']) ?>"><?= $h($t['utilization']) ?>%</div>
					<div class="ipam-stat-meta">Overall usage</div>
				</div>
			</div>

			<div class="ipam-dash-grid">
				<div class="ipam-card">
					<div class="ipam-card-header">
						<h3 class="ipam-card-title">Address Utilization</h3>
					</div>
					<div class="ipam-card-body">
						<div class="ipam-chart-wrap sm">
							<canvas id="ipam-chart-donut" aria-label="Used vs Free vs Reserved"></canvas>
						</div>
					</div>
				</div>

				<div class="ipam-card">
					<div class="ipam-card-header">
						<h3 class="ipam-card-title">Utilization Trend</h3>
					</div>
					<div class="ipam-card-body">
						<div class="ipam-chart-wrap sm">
							<canvas id="ipam-chart-trend" aria-label="Utilization trend over time"></canvas>
						</div>
					</div>
				</div>

				<div class="ipam-card span-2">
					<div class="ipam-card-header">
						<h3 class="ipam-card-title">Top Utilized Subnets</h3>
						<a href="<?= $url(['tab' => 'subnets']) ?>" class="ipam-btn ipam-btn-secondary ipam-btn-sm">View All</a>
					</div>
					<div class="ipam-card-body">
						<div class="ipam-chart-wrap">
							<canvas id="ipam-chart-bars" aria-label="Top utilized subnets"></canvas>
						</div>
					</div>
				</div>

				<div class="ipam-card span-2">
					<div class="ipam-card-header">
						<h3 class="ipam-card-title">Recent Scans</h3>
					</div>
					<div class="ipam-card-body" style="padding-top:8px">
						<?php if ($data['dashboard']['recent_scans']): ?>
							<table class="ipam-scan-table">
								<thead>
									<tr>
										<th style="text-align:left;color:var(--ipam-text-muted);font-size:11px;text-transform:uppercase">Subnet</th>
										<th style="text-align:left;color:var(--ipam-text-muted);font-size:11px;text-transform:uppercase">Status</th>
										<th style="text-align:left;color:var(--ipam-text-muted);font-size:11px;text-transform:uppercase">Responding</th>
										<th style="text-align:right;color:var(--ipam-text-muted);font-size:11px;text-transform:uppercase">Finished</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($data['dashboard']['recent_scans'] as $sc): ?>
									<tr>
										<td><span class="ipam-scan-subnet"><?= $h($sc['subnet_label']) ?></span></td>
										<td><span class="ipam-badge <?= $h($sc['status']) ?>"><?= $h($sc['status']) ?></span></td>
										<td><?= (int)$sc['responding_count'] ?> hosts</td>
										<td style="text-align:right"><span class="ipam-scan-time"><?= $h(substr($sc['finished_at'] ?: $sc['started_at'], 0, 16)) ?></span></td>
									</tr>
								<?php endforeach ?>
								</tbody>
							</table>
						<?php else: ?>
							<div class="ipam-empty">
								<span class="ipam-empty-icon"><?= $icons['scan'] ?></span>
								<span class="ipam-empty-text">No scans yet — run a subnet scan to populate history.</span>
							</div>
						<?php endif ?>
					</div>
				</div>
			</div>

			<script type="application/json" id="ipam-chart-data"><?= json_encode([
				'donut' => [
					'used'     => (int)$t['used'],
					'free'     => (int)$t['free'],
					'reserved' => (int)$t['reserved'],
				],
				'trend' => [
					'labels' => $trend_labels,
					'values' => $trend_values,
				],
				'bars' => [
					'labels' => array_map(static fn($s) => $s['subnet'].'/'.$s['cidr'], $data['dashboard']['top_subnets']),
					'values' => array_map(static fn($s) => (float)$s['utilization'], $data['dashboard']['top_subnets']),
				],
			], JSON_THROW_ON_ERROR) ?></script>

		<?php endif ?>


		<!-- SUBNETS -->
		<?php if ($tab === 'subnets'): ?>

			<?php if ($is_admin): ?>
				<div class="ipam-card ipam-form-panel">
					<div class="ipam-card-header">
						<h3 class="ipam-card-title">Add New Subnet</h3>
					</div>
					<form class="ipam-form-inline" method="post">
						<input type="hidden" name="sid" value="<?= $sid ?>">
						<input type="hidden" name="action" value="ipampro.view">
						<input type="hidden" name="tab" value="subnets">
						<input type="hidden" name="task" value="save_subnet">
						<div class="ipam-form-field">
							<label>Subnet CIDR *</label>
							<input name="subnet_cidr" placeholder="192.168.1.0/24" required>
						</div>
						<div class="ipam-form-field">
							<label>VLAN</label>
							<select name="vlanid">
								<option value="0">None</option>
								<?php foreach ($data['vlans'] as $vlan): ?>
									<option value="<?= (int)$vlan['vlanid'] ?>">VLAN <?= (int)$vlan['vlan_number'] ?> – <?= $h($vlan['name']) ?></option>
								<?php endforeach ?>
							</select>
						</div>
						<div class="ipam-form-field">
							<label>Site</label>
							<input name="site" placeholder="e.g. HQ, DC1">
						</div>
						<div class="ipam-form-field">
							<label>Status</label>
							<select name="status">
								<option value="active">Active</option>
								<option value="planned">Planned</option>
								<option value="reserved">Reserved</option>
								<option value="disabled">Disabled</option>
							</select>
						</div>
						<div class="ipam-form-field wide">
							<label>Description</label>
							<input name="description" placeholder="Optional description">
						</div>
						<div class="ipam-form-field">
							<label>&nbsp;</label>
							<button type="submit" class="ipam-btn ipam-btn-primary">Add Subnet</button>
						</div>
					</form>
				</div>
			<?php endif ?>

			<div class="ipam-toolbar" data-ipam-toolbar="subnets">
				<div class="ipam-toolbar-group">
					<label>Filter</label>
					<input type="search" data-ipam-filter placeholder="Filter subnets…" style="min-width:200px">
				</div>
				<div class="ipam-toolbar-sep"></div>
				<a href="<?= $url(['task' => 'export', 'report' => 'utilization', 'tab' => 'subnets']) ?>"
				   class="ipam-btn ipam-btn-secondary ipam-btn-sm">
					<?= $icons['export'] ?> Export CSV
				</a>
				<div class="ipam-pagination" data-ipam-pagination>
					<button type="button" data-page-prev disabled>&lsaquo;</button>
					<span class="ipam-page-info" data-page-info>Page 1</span>
					<button type="button" data-page-next>&rsaquo;</button>
				</div>
			</div>

			<div class="ipam-table-wrap">
				<table class="ipam-table" data-ipam-table="subnets" data-page-size="15">
					<thead>
						<tr>
							<th data-sortable data-sort="text">Subnet</th>
							<th data-sortable data-sort="text">CIDR</th>
							<th data-sortable data-sort="text">Description</th>
							<th data-sortable data-sort="text">VLAN</th>
							<th data-sortable data-sort="num">Used</th>
							<th data-sortable data-sort="num">Free</th>
							<th data-sortable data-sort="num">Utilization</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php if ($data['subnets']): ?>
						<?php foreach ($data['subnets'] as $s):
							$util = (float)$s['utilization'];
							$pclass = $progressClass($util);
						?>
							<tr data-search="<?= $h(strtolower($s['subnet'].'/'.$s['cidr'].' '.$s['description'].' '.$s['vlan_name'].' '.$s['site'])) ?>">
								<td>
									<span class="ipam-td-primary ipam-td-mono"><?= $h($s['subnet']) ?></span>
								</td>
								<td><span class="ipam-td-mono">/<?= (int)$s['cidr'] ?></span></td>
								<td><?= $s['description'] ? $h($s['description']) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td>
									<?php if ($s['vlan_number']): ?>
										<span class="ipam-badge planned">VLAN <?= (int)$s['vlan_number'] ?></span>
										<?php if ($s['vlan_name']): ?>
											<span class="ipam-td-sub"><?= $h($s['vlan_name']) ?></span>
										<?php endif ?>
									<?php else: ?>
										<span style="color:var(--ipam-text-muted)">—</span>
									<?php endif ?>
								</td>
								<td data-value="<?= (int)$s['used_ips'] ?>"><span style="color:var(--ipam-red);font-weight:700"><?= (int)$s['used_ips'] ?></span></td>
								<td data-value="<?= (int)$s['free_ips'] ?>"><span style="color:var(--ipam-green);font-weight:700"><?= (int)$s['free_ips'] ?></span></td>
								<td data-value="<?= $util ?>">
									<div class="ipam-util-cell">
										<div class="ipam-progress" style="flex:1">
											<div class="ipam-progress-bar <?= $pclass ?>" style="width:<?= $pct($util) ?>"></div>
										</div>
										<span class="ipam-util-pct"><?= $h($s['utilization']) ?>%</span>
									</div>
								</td>
								<td>
									<div class="ipam-row-actions">
										<a href="<?= $url(['tab' => 'overview', 'subnetid' => (int)$s['subnetid']]) ?>" class="ipam-btn ipam-btn-secondary ipam-btn-sm">Details</a>
										<?php if ($is_admin): ?>
											<form method="post" style="margin:0">
												<input type="hidden" name="sid" value="<?= $sid ?>">
												<input type="hidden" name="action" value="ipampro.view">
												<input type="hidden" name="tab" value="subnets">
												<input type="hidden" name="task" value="scan">
												<input type="hidden" name="subnetid" value="<?= (int)$s['subnetid'] ?>">
												<button type="submit" class="ipam-btn ipam-btn-scan ipam-btn-sm"><?= $icons['scan'] ?> Scan</button>
											</form>
											<div class="ipam-edit-wrap" data-edit-wrap>
												<button type="button" class="ipam-edit-toggle" data-edit-toggle>Edit</button>
												<div class="ipam-edit-panel" data-edit-panel>
													<h4>Edit Subnet</h4>
													<form method="post">
														<input type="hidden" name="sid" value="<?= $sid ?>">
														<input type="hidden" name="action" value="ipampro.view">
														<input type="hidden" name="tab" value="subnets">
														<input type="hidden" name="task" value="save_subnet">
														<input type="hidden" name="subnetid" value="<?= (int)$s['subnetid'] ?>">
														<div class="ipam-field-row"><label>Subnet CIDR</label><input name="subnet_cidr" value="<?= $h($s['subnet'].'/'.$s['cidr']) ?>"></div>
														<div class="ipam-field-row"><label>Site</label><input name="site" value="<?= $h($s['site']) ?>"></div>
														<div class="ipam-field-row">
															<label>Status</label>
															<select name="status">
																<?php foreach (['active','planned','reserved','disabled'] as $st): ?>
																	<option value="<?= $h($st) ?>" <?= $s['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
																<?php endforeach ?>
															</select>
														</div>
														<div class="ipam-field-row"><label>Description</label><input name="description" value="<?= $h($s['description']) ?>"></div>
														<div class="ipam-edit-actions">
															<button type="submit" class="ipam-btn ipam-btn-primary ipam-btn-sm">Save</button>
															<button type="button" class="ipam-btn ipam-btn-secondary ipam-btn-sm" data-edit-close>Cancel</button>
														</div>
													</form>
												</div>
											</div>
											<form method="post" data-ipam-confirm="Delete subnet <?= $h($s['subnet'].'/'.$s['cidr']) ?>?" style="margin:0">
												<input type="hidden" name="sid" value="<?= $sid ?>">
												<input type="hidden" name="action" value="ipampro.view">
												<input type="hidden" name="tab" value="subnets">
												<input type="hidden" name="task" value="delete_subnet">
												<input type="hidden" name="subnetid" value="<?= (int)$s['subnetid'] ?>">
												<button type="submit" class="ipam-btn ipam-btn-danger ipam-btn-sm">Delete</button>
											</form>
										<?php endif ?>
									</div>
								</td>
							</tr>
						<?php endforeach ?>
					<?php else: ?>
						<tr><td colspan="8"><div class="ipam-empty"><span class="ipam-empty-icon"><?= $icons['subnet'] ?></span><span class="ipam-empty-text">No subnets found. Add one above.</span></div></td></tr>
					<?php endif ?>
					</tbody>
				</table>
			</div>
		<?php endif ?>


		<!-- NETWORK OVERVIEW -->
		<?php if ($tab === 'overview'): ?>

			<div class="ipam-subnet-picker">
				<span class="ipam-subnet-picker-label">Select subnet</span>
				<?php foreach ($data['subnets'] as $s): ?>
					<a href="<?= $url(['tab' => 'overview', 'subnetid' => (int)$s['subnetid']]) ?>"
					   class="<?= (int)($data['selected_subnet']['subnetid'] ?? 0) === (int)$s['subnetid'] ? 'active' : '' ?>">
						<?= $h($s['subnet'].'/'.$s['cidr']) ?>
					</a>
				<?php endforeach ?>
			</div>

			<?php if ($data['selected_subnet']):
				$sn = $data['selected_subnet'];
				$ni = $networkInfo($sn['subnet'], (int)$sn['cidr']);
				$ip_counts = ['used' => 0, 'free' => 0, 'reserved' => 0];
				foreach ($data['ips'] as $ip) {
					if (isset($ip_counts[$ip['status']])) $ip_counts[$ip['status']]++;
				}
				$sn_util = count($data['ips']) > 0
					? round((($ip_counts['used'] + $ip_counts['reserved']) / count($data['ips'])) * 100, 1)
					: 0;
			?>
				<div class="ipam-card">
					<div class="ipam-card-header">
						<div class="ipam-overview-header">
							<h3 class="ipam-card-title" style="margin:0">
								<?= $h($sn['subnet'].'/'.$sn['cidr']) ?>
								<?php if ($sn['site']): ?>
									<span style="color:var(--ipam-text-muted);font-weight:400;font-size:13px"> · <?= $h($sn['site']) ?></span>
								<?php endif ?>
							</h3>
							<div class="ipam-overview-legend">
								<span class="ipam-legend-item"><span class="ipam-legend-dot free"></span> Free</span>
								<span class="ipam-legend-item"><span class="ipam-legend-dot used"></span> Used</span>
								<span class="ipam-legend-item"><span class="ipam-legend-dot reserved"></span> Reserved</span>
							</div>
						</div>
					</div>
					<div class="ipam-card-body">
						<?php if ($data['queue_status']): $qs = $data['queue_status']; ?>
							<?php
								$qs_map = [
									'queued'    => ['label' => '⏳ Scan queued — waiting for scanner container', 'class' => 'planned'],
									'running'   => ['label' => '📡 Scan in progress…',                          'class' => 'planned'],
									'completed' => ['label' => '✅ Scan completed — '.$h($qs['message'] ?? ''),  'class' => 'active'],
									'failed'    => ['label' => '⚠️ Scan failed — '.$h($qs['message'] ?? ''),     'class' => 'disabled'],
								];
								$qs_info = $qs_map[$qs['status']] ?? null;
							?>
							<?php if ($qs_info): ?>
								<div style="margin-bottom:12px"><span class="ipam-badge <?= $qs_info['class'] ?>"><?= $qs_info['label'] ?></span></div>
							<?php endif ?>
						<?php endif ?>
						<div class="ipam-netinfo-grid">
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">Network Size</div>
								<div class="ipam-netinfo-value"><?= (int)$ni['size'] ?> hosts</div>
							</div>
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">Gateway</div>
								<div class="ipam-netinfo-value"><?= $h($ni['gateway']) ?></div>
							</div>
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">Broadcast</div>
								<div class="ipam-netinfo-value"><?= $h($ni['broadcast']) ?></div>
							</div>
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">First Host</div>
								<div class="ipam-netinfo-value"><?= $h($ni['first_host']) ?></div>
							</div>
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">Last Host</div>
								<div class="ipam-netinfo-value"><?= $h($ni['last_host']) ?></div>
							</div>
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">DNS</div>
								<div class="ipam-netinfo-value"><?= $sn['description'] ? $h($sn['description']) : '—' ?></div>
							</div>
							<div class="ipam-netinfo-card">
								<div class="ipam-netinfo-label">DHCP</div>
								<div class="ipam-netinfo-value"><?= $sn['status'] === 'active' ? 'Managed' : '—' ?></div>
							</div>
						</div>

						<div class="ipam-gauge-grid">
							<div class="ipam-gauge">
								<div class="ipam-gauge-ring"><canvas data-ipam-gauge="<?= $sn_util ?>" data-gauge-label="Utilization"></canvas></div>
								<div class="ipam-gauge-label">Utilization</div>
								<div class="ipam-gauge-value"><?= $h($sn_util) ?>%</div>
							</div>
							<div class="ipam-gauge">
								<div class="ipam-gauge-ring"><canvas data-ipam-gauge="<?= $ip_counts['used'] ?>" data-gauge-max="<?= max(1, count($data['ips'])) ?>" data-gauge-label="Used" data-gauge-color="red"></canvas></div>
								<div class="ipam-gauge-label">Used</div>
								<div class="ipam-gauge-value"><?= (int)$ip_counts['used'] ?></div>
							</div>
							<div class="ipam-gauge">
								<div class="ipam-gauge-ring"><canvas data-ipam-gauge="<?= $ip_counts['free'] ?>" data-gauge-max="<?= max(1, count($data['ips'])) ?>" data-gauge-label="Free" data-gauge-color="green"></canvas></div>
								<div class="ipam-gauge-label">Free</div>
								<div class="ipam-gauge-value"><?= (int)$ip_counts['free'] ?></div>
							</div>
							<div class="ipam-gauge">
								<div class="ipam-gauge-ring"><canvas data-ipam-gauge="<?= $ip_counts['reserved'] ?>" data-gauge-max="<?= max(1, count($data['ips'])) ?>" data-gauge-label="Reserved" data-gauge-color="orange"></canvas></div>
								<div class="ipam-gauge-label">Reserved</div>
								<div class="ipam-gauge-value"><?= (int)$ip_counts['reserved'] ?></div>
							</div>
						</div>
					</div>
					<div class="ipam-block-grid">
						<?php foreach ($data['ips'] as $ip): ?>
							<button class="ipam-ip-block <?= $h($ip['status']) ?>"
							        data-ipam-detail="<?= $h(json_encode($ip)) ?>"
							        title="<?= $h($ip['ip_address'].($ip['hostname'] ? ' · '.$ip['hostname'] : '')) ?>">
								<?= $h(substr($ip['ip_address'], strrpos($ip['ip_address'], '.') + 1)) ?>
							</button>
						<?php endforeach ?>
					</div>
				</div>
			<?php else: ?>
				<div class="ipam-card">
					<div class="ipam-card-body">
						<div class="ipam-empty">
							<span class="ipam-empty-icon"><?= $icons['overview'] ?></span>
							<span class="ipam-empty-text">Select a subnet above to view its network map.</span>
						</div>
					</div>
				</div>
			<?php endif ?>
		<?php endif ?>


		<!-- IP ADDRESSES -->
		<?php if ($tab === 'addresses'): ?>

			<?php if ($data['subnets']): ?>
				<div class="ipam-subnet-picker">
					<span class="ipam-subnet-picker-label">Filter by subnet</span>
					<a href="<?= $url(['tab' => 'addresses']) ?>"
					   class="<?= !(int)($data['selected_subnet']['subnetid'] ?? 0) ? 'active' : '' ?>">All</a>
					<?php foreach ($data['subnets'] as $s): ?>
						<a href="<?= $url(['tab' => 'addresses', 'subnetid' => (int)$s['subnetid']]) ?>"
						   class="<?= (int)($data['selected_subnet']['subnetid'] ?? 0) === (int)$s['subnetid'] ? 'active' : '' ?>">
							<?= $h($s['subnet'].'/'.$s['cidr']) ?>
						</a>
					<?php endforeach ?>
				</div>
			<?php endif ?>

			<div class="ipam-bulk-bar" data-ipam-bulk-bar>
				<span class="ipam-bulk-count" data-ipam-bulk-count>0 selected</span>
				<button type="button" class="ipam-btn ipam-btn-secondary ipam-btn-sm" data-ipam-bulk-export><?= $icons['export'] ?> Export Selected</button>
			</div>

			<div class="ipam-toolbar" data-ipam-toolbar="addresses">
				<div class="ipam-toolbar-group">
					<label>Search</label>
					<input type="search" data-ipam-filter placeholder="Filter addresses…" style="min-width:200px">
				</div>
				<div class="ipam-toolbar-sep"></div>
				<div class="ipam-filter-chips" data-ipam-status-filter>
					<button type="button" class="ipam-chip active" data-status="">All</button>
					<button type="button" class="ipam-chip" data-status="used">Used</button>
					<button type="button" class="ipam-chip" data-status="free">Free</button>
					<button type="button" class="ipam-chip" data-status="reserved">Reserved</button>
				</div>
				<div class="ipam-toolbar-sep"></div>
				<button type="button" class="ipam-btn ipam-btn-secondary ipam-btn-sm" data-ipam-export-all><?= $icons['export'] ?> Export CSV</button>
				<div class="ipam-pagination" data-ipam-pagination>
					<button type="button" data-page-prev disabled>&lsaquo;</button>
					<span class="ipam-page-info" data-page-info>Page 1</span>
					<button type="button" data-page-next>&rsaquo;</button>
				</div>
			</div>

			<div class="ipam-table-wrap">
				<table class="ipam-table" data-ipam-table="addresses" data-page-size="25">
					<thead>
						<tr>
							<th style="width:36px"><input type="checkbox" data-ipam-select-all title="Select all"></th>
							<th data-sortable data-sort="text">IP Address</th>
							<th data-sortable data-sort="text">Hostname</th>
							<th data-sortable data-sort="text">MAC Address</th>
							<th data-sortable data-sort="text">Owner</th>
							<th data-sortable data-sort="text">VLAN</th>
							<th data-sortable data-sort="text">Status</th>
							<th data-sortable data-sort="text">Last Seen</th>
							<th data-sortable data-sort="text">Zabbix Host</th>
							<?php if ($is_admin): ?><th>Actions</th><?php endif ?>
						</tr>
					</thead>
					<tbody>
					<?php if ($data['ips']): ?>
						<?php foreach ($data['ips'] as $ip): ?>
							<tr data-search="<?= $h(strtolower($ip['ip_address'].' '.$ip['hostname'].' '.$ip['mac'].' '.$ip['owner'].' '.$ip['vlan'])) ?>"
							    data-status="<?= $h($ip['status']) ?>">
								<td><input type="checkbox" data-ipam-row-check value="<?= $h($ip['ip_address']) ?>"></td>
								<td>
									<span class="ipam-td-primary ipam-td-mono"><?= $h($ip['ip_address']) ?></span>
									<span class="ipam-td-sub"><?= $h($ip['subnet'].'/'.$ip['cidr']) ?></span>
								</td>
								<td><?= $ip['hostname'] ? $h($ip['hostname']) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td class="ipam-td-mono"><?= $ip['mac'] ? $h($ip['mac']) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td><?= $ip['owner'] ? $h($ip['owner']) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td><?= $ip['vlan'] ? $h($ip['vlan']) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td><span class="ipam-badge <?= $h($ip['status']) ?>"><?= $h($ip['status']) ?></span></td>
								<td><?= $ip['last_seen_at'] ? $h(substr($ip['last_seen_at'], 0, 16)) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td>
									<?php if ($ip['zabbix_hostid']): ?>
										<span class="ipam-badge zabbix">Monitored</span>
									<?php else: ?>
										<span style="color:var(--ipam-text-muted)">—</span>
									<?php endif ?>
								</td>
								<?php if ($is_admin): ?>
									<td>
										<div class="ipam-row-actions">
											<div class="ipam-edit-wrap" data-edit-wrap>
												<button type="button" class="ipam-edit-toggle" data-edit-toggle>Edit</button>
												<div class="ipam-edit-panel" data-edit-panel>
													<h4>Edit IP</h4>
													<form method="post">
														<input type="hidden" name="sid" value="<?= $sid ?>">
														<input type="hidden" name="action" value="ipampro.view">
														<input type="hidden" name="tab" value="addresses">
														<input type="hidden" name="task" value="save_ip">
														<input type="hidden" name="subnetid" value="<?= (int)$ip['subnetid'] ?>">
														<input type="hidden" name="ipid" value="<?= (int)$ip['ipid'] ?>">
														<div class="ipam-field-row"><label>Hostname</label><input name="hostname" value="<?= $h($ip['hostname']) ?>" placeholder="hostname"></div>
														<div class="ipam-field-row"><label>MAC Address</label><input name="mac" value="<?= $h($ip['mac']) ?>" placeholder="00:11:22:33:44:55"></div>
														<div class="ipam-field-row"><label>Owner</label><input name="owner" value="<?= $h($ip['owner']) ?>"></div>
														<div class="ipam-field-row"><label>Description</label><input name="description" value="<?= $h($ip['description']) ?>"></div>
														<div class="ipam-field-row">
															<label>Status</label>
															<select name="status">
																<?php foreach (['free','used','reserved'] as $st): ?>
																	<option value="<?= $h($st) ?>" <?= $ip['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
																<?php endforeach ?>
															</select>
														</div>
														<div class="ipam-edit-actions">
															<button type="submit" class="ipam-btn ipam-btn-primary ipam-btn-sm">Save</button>
															<button type="button" class="ipam-btn ipam-btn-secondary ipam-btn-sm" data-edit-close>Cancel</button>
														</div>
													</form>
													<?php if ($ip['status'] !== 'reserved'): ?>
														<form method="post" style="margin-top:12px;border-top:1px solid var(--ipam-border);padding-top:12px">
															<h4>Reserve IP</h4>
															<input type="hidden" name="sid" value="<?= $sid ?>">
															<input type="hidden" name="action" value="ipampro.view">
															<input type="hidden" name="tab" value="addresses">
															<input type="hidden" name="task" value="reserve_ip">
															<input type="hidden" name="subnetid" value="<?= (int)$ip['subnetid'] ?>">
															<input type="hidden" name="ipid" value="<?= (int)$ip['ipid'] ?>">
															<div class="ipam-field-row"><label>Reserved By *</label><input name="reserved_by" placeholder="Name / team" required></div>
															<div class="ipam-field-row"><label>Device</label><input name="device" placeholder="e.g. router-01"></div>
															<div class="ipam-field-row"><label>Purpose</label><input name="purpose" placeholder="e.g. Gateway"></div>
															<div class="ipam-edit-actions">
																<button type="submit" class="ipam-btn ipam-btn-primary ipam-btn-sm">Reserve</button>
															</div>
														</form>
													<?php endif ?>
												</div>
											</div>
										</div>
									</td>
								<?php endif ?>
							</tr>
						<?php endforeach ?>
					<?php else: ?>
						<tr><td colspan="<?= $is_admin ? 10 : 9 ?>"><div class="ipam-empty"><span class="ipam-empty-icon"><?= $icons['addresses'] ?></span><span class="ipam-empty-text">No IP addresses found.</span></div></td></tr>
					<?php endif ?>
					</tbody>
				</table>
			</div>
		<?php endif ?>


		<!-- VLANs -->
		<?php if ($tab === 'vlans'): ?>

			<?php if ($is_admin): ?>
				<div class="ipam-card ipam-form-panel">
					<div class="ipam-card-header">
						<h3 class="ipam-card-title">Add New VLAN</h3>
					</div>
					<form class="ipam-form-inline" method="post">
						<input type="hidden" name="sid" value="<?= $sid ?>">
						<input type="hidden" name="action" value="ipampro.view">
						<input type="hidden" name="tab" value="vlans">
						<input type="hidden" name="task" value="save_vlan">
						<div class="ipam-form-field">
							<label>VLAN ID (1–4094) *</label>
							<input name="vlan_number" type="number" min="1" max="4094" placeholder="100" required>
						</div>
						<div class="ipam-form-field">
							<label>Name *</label>
							<input name="name" placeholder="e.g. Management" required>
						</div>
						<div class="ipam-form-field">
							<label>Site</label>
							<input name="site" placeholder="e.g. HQ">
						</div>
						<div class="ipam-form-field wide">
							<label>Description</label>
							<input name="description" placeholder="Optional">
						</div>
						<div class="ipam-form-field">
							<label>&nbsp;</label>
							<button type="submit" class="ipam-btn ipam-btn-primary">Add VLAN</button>
						</div>
					</form>
				</div>
			<?php endif ?>

			<div class="ipam-toolbar" data-ipam-toolbar="vlans">
				<div class="ipam-toolbar-group">
					<input type="search" data-ipam-filter placeholder="Filter VLANs…" style="min-width:220px">
				</div>
				<div class="ipam-pagination" data-ipam-pagination style="margin-left:auto">
					<button type="button" data-page-prev disabled>&lsaquo;</button>
					<span class="ipam-page-info" data-page-info>Page 1</span>
					<button type="button" data-page-next>&rsaquo;</button>
				</div>
			</div>

			<div class="ipam-table-wrap">
				<table class="ipam-table" data-ipam-table="vlans" data-page-size="20">
					<thead>
						<tr>
							<th data-sortable data-sort="num">VLAN ID</th>
							<th data-sortable data-sort="text">Name</th>
							<th data-sortable data-sort="text">Subnet</th>
							<th data-sortable data-sort="text">Description</th>
							<th data-sortable data-sort="num">Device Count</th>
							<?php if ($is_admin): ?><th>Actions</th><?php endif ?>
						</tr>
					</thead>
					<tbody>
					<?php if ($data['vlans']): ?>
						<?php foreach ($data['vlans'] as $vlan):
							$vlan_subnets = array_filter($data['subnets'], static fn($s) => (int)($s['vlanid'] ?? 0) === (int)$vlan['vlanid']);
							$device_count = array_sum(array_map(static fn($s) => (int)($s['used_ips'] ?? 0), $vlan_subnets));
							$subnet_labels = array_map(static fn($s) => $s['subnet'].'/'.$s['cidr'], $vlan_subnets);
							$color = $vlanColor((int)$vlan['vlan_number']);
						?>
							<tr data-search="<?= $h(strtolower($vlan['vlan_number'].' '.$vlan['name'].' '.$vlan['description'].' '.implode(' ', $subnet_labels))) ?>">
								<td data-value="<?= (int)$vlan['vlan_number'] ?>">
									<span class="ipam-vlan-color" style="background:<?= $h($color) ?>"></span>
									<span class="ipam-vlan-badge" style="background:<?= $h($color) ?>">VLAN <?= (int)$vlan['vlan_number'] ?></span>
								</td>
								<td><span class="ipam-td-primary"><?= $h($vlan['name']) ?></span>
									<?php if ($vlan['site']): ?><span class="ipam-td-sub"><?= $h($vlan['site']) ?></span><?php endif ?>
								</td>
								<td class="ipam-td-mono">
									<?php if ($subnet_labels): ?>
										<?= $h(implode(', ', array_slice($subnet_labels, 0, 3))) ?>
										<?php if (count($subnet_labels) > 3): ?><span class="ipam-td-sub">+<?= count($subnet_labels) - 3 ?> more</span><?php endif ?>
									<?php else: ?>
										<span style="color:var(--ipam-text-muted)">—</span>
									<?php endif ?>
								</td>
								<td><?= $vlan['description'] ? $h($vlan['description']) : '<span style="color:var(--ipam-text-muted)">—</span>' ?></td>
								<td data-value="<?= $device_count ?>"><strong><?= (int)$device_count ?></strong> <span style="color:var(--ipam-text-muted);font-size:12px">used IPs</span></td>
								<?php if ($is_admin): ?>
									<td>
										<div class="ipam-row-actions">
											<div class="ipam-edit-wrap" data-edit-wrap>
												<button type="button" class="ipam-edit-toggle" data-edit-toggle>Edit</button>
												<div class="ipam-edit-panel" data-edit-panel>
													<h4>Edit VLAN</h4>
													<form method="post">
														<input type="hidden" name="sid" value="<?= $sid ?>">
														<input type="hidden" name="action" value="ipampro.view">
														<input type="hidden" name="tab" value="vlans">
														<input type="hidden" name="task" value="save_vlan">
														<input type="hidden" name="vlanid" value="<?= (int)$vlan['vlanid'] ?>">
														<div class="ipam-field-row"><label>VLAN ID</label><input name="vlan_number" type="number" min="1" max="4094" value="<?= (int)$vlan['vlan_number'] ?>"></div>
														<div class="ipam-field-row"><label>Name</label><input name="name" value="<?= $h($vlan['name']) ?>"></div>
														<div class="ipam-field-row"><label>Site</label><input name="site" value="<?= $h($vlan['site']) ?>"></div>
														<div class="ipam-field-row"><label>Description</label><input name="description" value="<?= $h($vlan['description']) ?>"></div>
														<div class="ipam-edit-actions">
															<button type="submit" class="ipam-btn ipam-btn-primary ipam-btn-sm">Save</button>
															<button type="button" class="ipam-btn ipam-btn-secondary ipam-btn-sm" data-edit-close>Cancel</button>
														</div>
													</form>
												</div>
											</div>
											<form method="post" data-ipam-confirm="Delete VLAN <?= (int)$vlan['vlan_number'] ?>?" style="margin:0">
												<input type="hidden" name="sid" value="<?= $sid ?>">
												<input type="hidden" name="action" value="ipampro.view">
												<input type="hidden" name="tab" value="vlans">
												<input type="hidden" name="task" value="delete_vlan">
												<input type="hidden" name="vlanid" value="<?= (int)$vlan['vlanid'] ?>">
												<button type="submit" class="ipam-btn ipam-btn-danger ipam-btn-sm">Delete</button>
											</form>
										</div>
									</td>
								<?php endif ?>
							</tr>
						<?php endforeach ?>
					<?php else: ?>
						<tr><td colspan="<?= $is_admin ? 6 : 5 ?>"><div class="ipam-empty"><span class="ipam-empty-icon"><?= $icons['vlans'] ?></span><span class="ipam-empty-text">No VLANs yet. Add one above.</span></div></td></tr>
					<?php endif ?>
					</tbody>
				</table>
			</div>
		<?php endif ?>


		<!-- REPORTS -->
		<?php if ($tab === 'reports'): ?>
			<div class="ipam-report-grid">

				<div class="ipam-report-card">
					<div class="ipam-report-header">
						<div class="ipam-report-icon"><?= $icons['chart'] ?></div>
						<div>
							<div class="ipam-report-title">Top Utilized Networks</div>
							<p class="ipam-report-desc">Subnets with the highest address utilization.</p>
						</div>
					</div>
					<div class="ipam-report-list">
						<?php foreach (array_slice($data['dashboard']['top_subnets'], 0, 6) as $s): ?>
							<div class="ipam-report-item">
								<span class="ipam-report-item-label"><?= $h($s['subnet'].'/'.$s['cidr']) ?></span>
								<span class="ipam-report-item-value"><?= $h($s['utilization']) ?>%</span>
							</div>
						<?php endforeach ?>
						<?php if (!$data['dashboard']['top_subnets']): ?>
							<div class="ipam-empty" style="padding:24px"><span class="ipam-empty-text">No data</span></div>
						<?php endif ?>
					</div>
					<div class="ipam-report-footer">
						<a href="<?= $url(['task' => 'export', 'report' => 'utilization', 'tab' => 'reports']) ?>" class="ipam-btn ipam-btn-primary ipam-btn-sm"><?= $icons['export'] ?> Export CSV</a>
					</div>
				</div>

				<div class="ipam-report-card">
					<div class="ipam-report-header">
						<div class="ipam-report-icon"><?= $icons['free'] ?></div>
						<div>
							<div class="ipam-report-title">Unused Networks</div>
							<p class="ipam-report-desc">Subnets with zero utilization — candidates for reclamation.</p>
						</div>
					</div>
					<div class="ipam-report-list">
						<?php foreach (array_slice($unused_networks, 0, 6) as $s): ?>
							<div class="ipam-report-item">
								<span class="ipam-report-item-label"><?= $h($s['subnet'].'/'.$s['cidr']) ?></span>
								<span class="ipam-report-item-value"><?= (int)$s['free_ips'] ?> free</span>
							</div>
						<?php endforeach ?>
						<?php if (!$unused_networks): ?>
							<div class="ipam-empty" style="padding:24px"><span class="ipam-empty-text">All networks have usage</span></div>
						<?php endif ?>
					</div>
					<div class="ipam-report-footer">
						<a href="<?= $url(['task' => 'export', 'report' => 'free', 'tab' => 'reports']) ?>" class="ipam-btn ipam-btn-primary ipam-btn-sm"><?= $icons['export'] ?> Export Free IPs</a>
					</div>
				</div>

				<div class="ipam-report-card">
					<div class="ipam-report-header">
						<div class="ipam-report-icon"><?= $icons['used'] ?></div>
						<div>
							<div class="ipam-report-title">Recently Discovered Devices</div>
							<p class="ipam-report-desc">Hosts detected via scan or Zabbix sync.</p>
						</div>
					</div>
					<div class="ipam-report-list">
						<?php foreach ($recent_devices as $ip): ?>
							<div class="ipam-report-item">
								<span class="ipam-report-item-label"><?= $h($ip['ip_address']) ?><?= $ip['hostname'] ? ' · '.$h($ip['hostname']) : '' ?></span>
								<span class="ipam-report-item-value"><?= $h(substr((string)$ip['last_seen_at'], 0, 10)) ?></span>
							</div>
						<?php endforeach ?>
						<?php if (!$recent_devices): ?>
							<div class="ipam-empty" style="padding:24px"><span class="ipam-empty-text">No recent discoveries</span></div>
						<?php endif ?>
					</div>
					<div class="ipam-report-footer">
						<a href="<?= $url(['tab' => 'addresses']) ?>" class="ipam-btn ipam-btn-secondary ipam-btn-sm">View All IPs</a>
					</div>
				</div>

				<div class="ipam-report-card">
					<div class="ipam-report-header">
						<div class="ipam-report-icon"><?= $icons['scan'] ?></div>
						<div>
							<div class="ipam-report-title">Scan History</div>
							<p class="ipam-report-desc">Recent subnet discovery scans and results.</p>
						</div>
					</div>
					<div class="ipam-report-list">
						<?php foreach (array_slice($data['scans'], 0, 8) as $sc): ?>
							<div class="ipam-report-item">
								<span class="ipam-report-item-label"><?= $h($sc['subnet_label'] ?? 'Subnet #'.$sc['subnetid']) ?></span>
								<span class="ipam-badge <?= $h($sc['status']) ?>" style="font-size:10px"><?= $h($sc['status']) ?></span>
								<span class="ipam-report-item-value"><?= $h(substr($sc['finished_at'] ?: $sc['started_at'], 0, 10)) ?></span>
							</div>
						<?php endforeach ?>
						<?php if (!$data['scans']): ?>
							<div class="ipam-empty" style="padding:24px"><span class="ipam-empty-text">No scan history</span></div>
						<?php endif ?>
					</div>
					<div class="ipam-report-footer">
						<?php if ($is_admin): ?>
							<form method="post" style="margin:0;display:inline">
								<input type="hidden" name="sid" value="<?= $sid ?>">
								<input type="hidden" name="action" value="ipampro.view">
								<input type="hidden" name="tab" value="reports">
								<input type="hidden" name="task" value="sync_zabbix">
								<button type="submit" class="ipam-btn ipam-btn-primary ipam-btn-sm">Sync Zabbix Hosts</button>
							</form>
						<?php endif ?>
						<a href="<?= $url(['task' => 'export', 'report' => 'reserved', 'tab' => 'reports']) ?>" class="ipam-btn ipam-btn-secondary ipam-btn-sm" style="margin-left:8px"><?= $icons['export'] ?> Reserved Report</a>
					</div>
				</div>

			</div>
		<?php endif ?>

		<?php endif ?>

	</div>

	<dialog class="ipam-dialog" data-ipam-dialog>
		<div class="ipam-dialog-header">
			<h3 data-dialog-title>IP Details</h3>
			<form method="dialog"><button class="ipam-dialog-close" aria-label="Close">&times;</button></form>
		</div>
		<div class="ipam-dialog-body" data-ipam-dialog-body></div>
	</dialog>

	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
	<script>
	<?= file_get_contents(dirname(__DIR__) . '/assets/js/ipampro.js') ?>
	</script>
</div>
