<?php
declare(strict_types = 1);

namespace Modules\IPAMPro\Includes\IpamPro;

final class Repository {

	/**
	 * Run a DB query safely. Zabbix calls trigger_error() on failure instead
	 * of throwing, so we temporarily install an error handler that converts
	 * E_USER_ERROR / E_ERROR into an exception we can catch.
	 */
	private function safeQuery(string $sql): mixed {
		set_error_handler(static function(int $errno, string $errstr): bool {
			throw new \RuntimeException($errstr, $errno);
		}, E_ALL);

		try {
			$result = DBselect($sql);
		} finally {
			restore_error_handler();
		}

		return $result;
	}

	/**
	 * Check whether the IPAM tables exist in the Zabbix database.
	 */
	public function tablesExist(): bool {
		try {
			$this->safeQuery('SELECT 1 FROM ipam_subnets LIMIT 1');
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public function dashboard(): array {
		$totals = [
			'subnets'     => 0,
			'ips'         => 0,
			'used'        => 0,
			'free'        => 0,
			'reserved'    => 0,
			'utilization' => 0
		];

		try {
			$row = DBfetch($this->safeQuery('SELECT COUNT(*) AS total FROM ipam_subnets'));
			$totals['subnets'] = (int) ($row['total'] ?? 0);

			$row = DBfetch($this->safeQuery(
				"SELECT COUNT(*) AS total,
					SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) AS used_count,
					SUM(CASE WHEN status='free' THEN 1 ELSE 0 END) AS free_count,
					SUM(CASE WHEN status='reserved' THEN 1 ELSE 0 END) AS reserved_count
				FROM ipam_ips"
			));

			if ($row) {
				$totals['ips']         = (int) $row['total'];
				$totals['used']        = (int) $row['used_count'];
				$totals['free']        = (int) $row['free_count'];
				$totals['reserved']    = (int) $row['reserved_count'];
				$totals['utilization'] = $totals['ips'] > 0
					? round((($totals['used'] + $totals['reserved']) / $totals['ips']) * 100, 1)
					: 0;
			}
		} catch (\Throwable $e) {
			// Tables not yet created – return zeroed totals silently.
		}

		return [
			'totals'      => $totals,
			'top_subnets' => $this->safeSubnets('', 5, 'utilization_desc'),
			'recent_scans' => $this->safeRecentScans(6)
		];
	}

	public function subnets(string $search = '', int $limit = 50, string $sort = 'label'): array {
		$where = '';
		if ($search !== '') {
			$q     = $this->like($search);
			$where = "WHERE CONCAT(s.subnet,'/',s.cidr,' ',IFNULL(v.name,''),' ',s.site,' ',IFNULL(s.description,'')) LIKE ".$q;
		}

		$order = $sort === 'utilization_desc'
			? 'ORDER BY utilization DESC, total_ips DESC'
			: 'ORDER BY INET_ATON(s.subnet), s.cidr';

		$result = $this->safeQuery(
			"SELECT s.*, v.vlan_number, v.name AS vlan_name,
				COUNT(i.ipid) AS total_ips,
				SUM(CASE WHEN i.status='used' THEN 1 ELSE 0 END) AS used_ips,
				SUM(CASE WHEN i.status='free' THEN 1 ELSE 0 END) AS free_ips,
				SUM(CASE WHEN i.status='reserved' THEN 1 ELSE 0 END) AS reserved_ips,
				CASE WHEN COUNT(i.ipid) = 0 THEN 0
					ELSE ROUND(((SUM(CASE WHEN i.status IN ('used','reserved') THEN 1 ELSE 0 END) / COUNT(i.ipid)) * 100), 1)
				END AS utilization
			FROM ipam_subnets s
			LEFT JOIN ipam_vlans v ON v.vlanid=s.vlanid
			LEFT JOIN ipam_ips i ON i.subnetid=s.subnetid
			$where
			GROUP BY s.subnetid, v.vlan_number, v.name
			$order
			LIMIT ".(int) $limit
		);

		$rows = [];
		while ($row = DBfetch($result)) {
			$rows[] = $row;
		}

		return $rows;
	}

	public function subnet(int $subnetid): ?array {
		$row = DBfetch($this->safeQuery(
			'SELECT s.*, v.vlan_number, v.name AS vlan_name
			FROM ipam_subnets s
			LEFT JOIN ipam_vlans v ON v.vlanid=s.vlanid
			WHERE s.subnetid='.(int) $subnetid
		));

		return $row ?: null;
	}

	public function ips(int $subnetid = 0, string $search = '', int $limit = 1024): array {
		$where = [];
		if ($subnetid > 0) {
			$where[] = 'i.subnetid='.(int) $subnetid;
		}
		if ($search !== '') {
			$q       = $this->like($search);
			$where[] = "CONCAT(i.ip_address,' ',i.hostname,' ',i.mac,' ',IFNULL(i.description,''),' ',i.owner,' ',i.vlan) LIKE ".$q;
		}

		$sql_where = $where ? 'WHERE '.implode(' AND ', $where) : '';
		$result    = $this->safeQuery(
			"SELECT i.*, s.subnet, s.cidr, r.reserved_by, r.device, r.purpose
			FROM ipam_ips i
			INNER JOIN ipam_subnets s ON s.subnetid=i.subnetid
			LEFT JOIN ipam_reservations r ON r.ipid=i.ipid
			$sql_where
			ORDER BY INET_ATON(i.ip_address)
			LIMIT ".(int) $limit
		);

		$rows = [];
		while ($row = DBfetch($result)) {
			$rows[] = $row;
		}

		return $rows;
	}

	public function vlans(): array {
		$result = $this->safeQuery(
			'SELECT v.*,
				COUNT(s.subnetid) AS subnet_count
			FROM ipam_vlans v
			LEFT JOIN ipam_subnets s ON s.vlanid=v.vlanid
			GROUP BY v.vlanid
			ORDER BY v.vlan_number, v.site'
		);

		$rows = [];
		while ($row = DBfetch($result)) {
			$rows[] = $row;
		}

		return $rows;
	}

	public function recentScans(int $limit = 20): array {
		$result = $this->safeQuery(
			"SELECT h.*, CONCAT(s.subnet,'/',s.cidr) AS subnet_label
			FROM ipam_scan_history h
			INNER JOIN ipam_subnets s ON s.subnetid=h.subnetid
			ORDER BY h.started_at DESC
			LIMIT ".(int) $limit
		);

		$rows = [];
		while ($row = DBfetch($result)) {
			$rows[] = $row;
		}

		return $rows;
	}

	public function saveSubnet(array $data): int {
		$parsed   = Cidr::parse($data['subnet_cidr']);
		$subnetid = (int) ($data['subnetid'] ?? 0);
		$vlanid   = (int) ($data['vlanid'] ?? 0);
		$fields   = [
			'subnet'      => $parsed['subnet'],
			'cidr'        => $parsed['cidr'],
			'vlanid'      => $vlanid > 0 ? $vlanid : null,
			'site'        => trim((string) ($data['site'] ?? '')),
			'description' => trim((string) ($data['description'] ?? '')),
			'status'      => in_array(($data['status'] ?? 'active'), ['active', 'planned', 'reserved', 'disabled'], true) ? $data['status'] : 'active'
		];

		if ($subnetid > 0) {
			$old = $this->subnet($subnetid);
			DBexecute('UPDATE ipam_subnets SET subnet='.$this->quote($fields['subnet']).', cidr='.(int) $fields['cidr'].
				', vlanid='.$this->nullableInt($fields['vlanid']).', site='.$this->quote($fields['site']).
				', description='.$this->quote($fields['description']).', status='.$this->quote($fields['status']).
				' WHERE subnetid='.(int) $subnetid);

			if (!$old || $old['subnet'] !== $fields['subnet'] || (int) $old['cidr'] !== (int) $fields['cidr']) {
				DBexecute('DELETE FROM ipam_ips WHERE subnetid='.(int) $subnetid);
				$this->generateIps($subnetid, $fields['subnet'], (int) $fields['cidr']);
			}

			return $subnetid;
		}

		DBexecute('INSERT INTO ipam_subnets (subnet,cidr,vlanid,site,description,status) VALUES ('.
			$this->quote($fields['subnet']).','.(int) $fields['cidr'].','.$this->nullableInt($fields['vlanid']).','.
			$this->quote($fields['site']).','.$this->quote($fields['description']).','.$this->quote($fields['status']).')');

		$row      = DBfetch($this->safeQuery('SELECT LAST_INSERT_ID() AS id'));
		$subnetid = (int) $row['id'];
		$this->generateIps($subnetid, $fields['subnet'], (int) $fields['cidr']);

		return $subnetid;
	}

	public function deleteSubnet(int $subnetid): void {
		DBexecute('DELETE FROM ipam_subnets WHERE subnetid='.(int) $subnetid);
	}

	public function saveIp(array $data): void {
		$ipid   = (int) ($data['ipid'] ?? 0);
		$status = in_array(($data['status'] ?? 'free'), ['free', 'used', 'reserved'], true) ? $data['status'] : 'free';
		DBexecute('UPDATE ipam_ips SET hostname='.$this->quote(trim((string) ($data['hostname'] ?? ''))).
			', mac='.$this->quote(trim((string) ($data['mac'] ?? ''))).
			', description='.$this->quote(trim((string) ($data['description'] ?? ''))).
			', owner='.$this->quote(trim((string) ($data['owner'] ?? ''))).
			', vlan='.$this->quote(trim((string) ($data['vlan'] ?? ''))).
			', status='.$this->quote($status).
			' WHERE ipid='.$ipid);
	}

	public function reserveIp(array $data): void {
		$ipid = (int) ($data['ipid'] ?? 0);
		DBexecute("UPDATE ipam_ips SET status='reserved' WHERE ipid=".$ipid);
		DBexecute('INSERT INTO ipam_reservations (ipid,reserved_by,device,purpose,notes) VALUES ('.
			$ipid.','.$this->quote(trim((string) ($data['reserved_by'] ?? ''))).','
			.$this->quote(trim((string) ($data['device'] ?? ''))).','
			.$this->quote(trim((string) ($data['purpose'] ?? ''))).','
			.$this->quote(trim((string) ($data['notes'] ?? ''))).')'
			.' ON DUPLICATE KEY UPDATE reserved_by=VALUES(reserved_by), device=VALUES(device), purpose=VALUES(purpose), notes=VALUES(notes)');
	}

	public function saveVlan(array $data): void {
		$vlanid = (int) ($data['vlanid'] ?? 0);
		$number = max(1, min(4094, (int) ($data['vlan_number'] ?? 1)));
		if ($vlanid > 0) {
			DBexecute('UPDATE ipam_vlans SET vlan_number='.$number.', name='.$this->quote(trim((string) ($data['name'] ?? ''))).
				', site='.$this->quote(trim((string) ($data['site'] ?? ''))).
				', description='.$this->quote(trim((string) ($data['description'] ?? ''))).
				' WHERE vlanid='.$vlanid);
			return;
		}

		DBexecute('INSERT INTO ipam_vlans (vlan_number,name,site,description) VALUES ('.
			$number.','.$this->quote(trim((string) ($data['name'] ?? ''))).','
			.$this->quote(trim((string) ($data['site'] ?? ''))).','
			.$this->quote(trim((string) ($data['description'] ?? ''))).')');
	}

	public function deleteVlan(int $vlanid): void {
		DBexecute('DELETE FROM ipam_vlans WHERE vlanid='.(int) $vlanid);
	}

	/**
	 * @param array $responding Associative array of ip_address => mac
	 *   (mac may be an empty string when the host didn't answer ARP,
	 *   e.g. it was found via ICMP/TCP probes only or is off-link).
	 */
	public function markScanResult(int $subnetid, array $responding): array {
		$ips      = $this->ips($subnetid, '', 70000);
		$used     = 0;
		$free     = 0;
		$reserved = 0;

		foreach ($ips as $ip) {
			if ($ip['status'] === 'reserved') {
				$reserved++;
				continue;
			}

			if (array_key_exists($ip['ip_address'], $responding)) {
				$used++;
				$mac = $responding[$ip['ip_address']];

				// Only overwrite an existing MAC if the scan actually found one
				// (a host answering ICMP/TCP but not ARP reports no MAC — don't
				// blank out a MAC captured on a previous scan or entered manually).
				if ($mac !== '') {
					DBexecute("UPDATE ipam_ips SET status='used', mac=".$this->quote($mac).
						', last_seen_at=NOW() WHERE ipid='.(int) $ip['ipid']);
				}
				else {
					DBexecute("UPDATE ipam_ips SET status='used', last_seen_at=NOW() WHERE ipid=".(int) $ip['ipid']);
				}
			} else {
				$free++;
				DBexecute("UPDATE ipam_ips SET status='free', last_seen_at=NULL WHERE ipid=".(int) $ip['ipid']." AND zabbix_hostid IS NULL");
			}
		}

		DBexecute('UPDATE ipam_subnets SET last_scan_at=NOW() WHERE subnetid='.(int) $subnetid);

		return ['used' => $used, 'free' => $free, 'reserved' => $reserved];
	}

	public function recordScan(int $subnetid, string $command, string $status, array $counts, string $message): void {
		DBexecute('INSERT INTO ipam_scan_history (subnetid,command,finished_at,responding_count,free_count,reserved_count,status,message) VALUES ('.
			(int) $subnetid.','.$this->quote($command).',NOW(),'.(int) ($counts['used'] ?? 0).','.
			(int) ($counts['free'] ?? 0).','.(int) ($counts['reserved'] ?? 0).','.$this->quote($status).','.$this->quote($message).')');
	}

	/**
	 * Queue a scan for the ipam-scanner sidecar container to pick up.
	 * The PHP web process never runs nmap itself in this workflow — it just
	 * writes a row here, and the sidecar (which has real LAN/ARP access)
	 * polls this table and does the actual scanning.
	 */
	public function queueScan(int $subnetid): void {
		if (!$this->subnet($subnetid)) {
			throw new \RuntimeException('Subnet was not found.');
		}

		$pending = DBfetch($this->safeQuery(
			"SELECT queueid FROM ipam_scan_queue WHERE subnetid=".(int) $subnetid.
			" AND status IN ('queued','running') LIMIT 1"
		));
		if ($pending) {
			throw new \RuntimeException('A scan for this subnet is already queued or running.');
		}

		DBexecute('INSERT INTO ipam_scan_queue (subnetid,status,requested_at) VALUES ('.
			(int) $subnetid.",'queued',NOW())");
	}

	/**
	 * Latest queue entry for a subnet, so the UI can show "queued" /
	 * "running" / "completed" / "failed" instead of nothing while the
	 * sidecar container is working.
	 */
	public function latestQueueStatus(int $subnetid): ?array {
		$row = DBfetch($this->safeQuery(
			'SELECT * FROM ipam_scan_queue WHERE subnetid='.(int) $subnetid.
			' ORDER BY requested_at DESC LIMIT 1'
		));

		return $row ?: null;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function safeSubnets(string $search, int $limit, string $sort): array {
		try {
			return $this->subnets($search, $limit, $sort);
		} catch (\Throwable $e) {
			return [];
		}
	}

	private function safeRecentScans(int $limit): array {
		try {
			return $this->recentScans($limit);
		} catch (\Throwable $e) {
			return [];
		}
	}

	private function generateIps(int $subnetid, string $subnet, int $cidr): void {
		foreach (Cidr::usableHosts($subnet, $cidr) as $ip) {
			DBexecute('INSERT IGNORE INTO ipam_ips (subnetid, ip_address, status) VALUES ('.(int) $subnetid.','.$this->quote($ip).",".$this->quote('free').')');
		}
	}

	private function quote(?string $value): string {
		return function_exists('zbx_dbstr') ? zbx_dbstr((string) $value) : "'".addslashes((string) $value)."'";
	}

	private function like(string $value): string {
		$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);
		return $this->quote('%'.$escaped.'%');
	}

	private function nullableInt(?int $value): string {
		return $value === null ? 'NULL' : (string) (int) $value;
	}
}
