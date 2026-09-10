<?php
declare(strict_types = 1);

namespace Modules\IPAMPro\Includes\IpamPro;

final class Cidr {
	public static function parse(string $value): array {
		$value = trim($value);

		if (!preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/([0-9]|[1-2][0-9]|3[0-2])$/', $value, $matches)) {
			throw new \InvalidArgumentException('Subnet must use IPv4 CIDR notation, for example 192.168.1.0/24.');
		}

		$ip = $matches[1];
		$cidr = (int) $matches[2];

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			throw new \InvalidArgumentException('Subnet contains an invalid IPv4 address.');
		}

		$network = long2ip(ip2long($ip) & self::maskLong($cidr));

		return ['subnet' => $network, 'cidr' => $cidr, 'label' => $network.'/'.$cidr];
	}

	public static function usableHosts(string $subnet, int $cidr): array {
		if ($cidr < 1 || $cidr > 32 || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			throw new \InvalidArgumentException('Invalid IPv4 subnet.');
		}

		$network = ip2long($subnet) & self::maskLong($cidr);
		$broadcast = $network | (~self::maskLong($cidr) & 0xffffffff);

		if ($cidr >= 31) {
			$start = $network;
			$end = $broadcast;
		}
		else {
			$start = $network + 1;
			$end = $broadcast - 1;
		}

		if (($end - $start) > 65534) {
			throw new \InvalidArgumentException('IPAM Pro generates up to 65,535 addresses per subnet. Split larger networks before importing.');
		}

		$hosts = [];
		for ($cursor = $start; $cursor <= $end; $cursor++) {
			$hosts[] = long2ip($cursor);
		}

		return $hosts;
	}

	public static function capacity(string $subnet, int $cidr): int {
		return count(self::usableHosts($subnet, $cidr));
	}

	private static function maskLong(int $cidr): int {
		return $cidr === 0 ? 0 : ((0xffffffff << (32 - $cidr)) & 0xffffffff);
	}
}
