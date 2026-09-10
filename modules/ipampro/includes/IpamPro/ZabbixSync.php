<?php
declare(strict_types = 1);

namespace Modules\IPAMPro\Includes\IpamPro;

final class ZabbixSync {
	public function sync(): int {
		if (!class_exists('API')) {
			return 0;
		}

		$hosts = \API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'selectInterfaces' => ['ip', 'dns', 'available'],
			'selectGroups' => ['name']
		]);

		$count = 0;
		foreach ($hosts as $host) {
			$groups = [];
			foreach ($host['groups'] ?? [] as $group) {
				$groups[] = $group['name'];
			}

			foreach ($host['interfaces'] ?? [] as $interface) {
				$ip = $interface['ip'] ?? '';
				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
					continue;
				}

				DBexecute('UPDATE ipam_ips SET status=\'used\', hostname='.zbx_dbstr($host['name'] ?: $host['host']).
					', zabbix_hostid='.(int) $host['hostid'].
					', zabbix_available='.(int) ($interface['available'] ?? 0).
					', zabbix_groups='.zbx_dbstr(implode(', ', $groups)).
					', last_seen_at=NOW()
					WHERE ip_address='.zbx_dbstr($ip));
				$count++;
			}
		}

		return $count;
	}
}
