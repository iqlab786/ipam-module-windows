CREATE TABLE IF NOT EXISTS ipam_vlans (
  vlanid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vlan_number INT UNSIGNED NOT NULL,
  name VARCHAR(128) NOT NULL,
  site VARCHAR(128) DEFAULT '',
  description TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (vlanid),
  UNIQUE KEY ipam_vlans_vlan_site_uq (vlan_number, site),
  KEY ipam_vlans_site_idx (site)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS ipam_subnets (
  subnetid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subnet VARCHAR(45) NOT NULL,
  cidr TINYINT UNSIGNED NOT NULL,
  vlanid BIGINT UNSIGNED DEFAULT NULL,
  site VARCHAR(128) DEFAULT '',
  description TEXT,
  status ENUM('active','planned','reserved','disabled') NOT NULL DEFAULT 'active',
  last_scan_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (subnetid),
  UNIQUE KEY ipam_subnets_cidr_uq (subnet, cidr),
  KEY ipam_subnets_vlan_idx (vlanid),
  KEY ipam_subnets_site_idx (site),
  CONSTRAINT ipam_subnets_vlan_fk FOREIGN KEY (vlanid) REFERENCES ipam_vlans (vlanid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS ipam_ips (
  ipid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subnetid BIGINT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  hostname VARCHAR(255) DEFAULT '',
  mac VARCHAR(32) DEFAULT '',
  description TEXT,
  owner VARCHAR(128) DEFAULT '',
  vlan VARCHAR(64) DEFAULT '',
  status ENUM('free','used','reserved') NOT NULL DEFAULT 'free',
  zabbix_hostid BIGINT UNSIGNED DEFAULT NULL,
  zabbix_available TINYINT UNSIGNED DEFAULT NULL,
  zabbix_groups VARCHAR(512) DEFAULT '',
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ipid),
  UNIQUE KEY ipam_ips_subnet_ip_uq (subnetid, ip_address),
  KEY ipam_ips_ip_idx (ip_address),
  KEY ipam_ips_hostname_idx (hostname),
  KEY ipam_ips_mac_idx (mac),
  KEY ipam_ips_status_idx (status),
  CONSTRAINT ipam_ips_subnet_fk FOREIGN KEY (subnetid) REFERENCES ipam_subnets (subnetid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS ipam_reservations (
  reservationid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ipid BIGINT UNSIGNED NOT NULL,
  reserved_by VARCHAR(128) NOT NULL,
  device VARCHAR(255) DEFAULT '',
  purpose VARCHAR(255) DEFAULT '',
  notes TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (reservationid),
  UNIQUE KEY ipam_reservations_ip_uq (ipid),
  KEY ipam_reservations_reserved_by_idx (reserved_by),
  CONSTRAINT ipam_reservations_ip_fk FOREIGN KEY (ipid) REFERENCES ipam_ips (ipid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS ipam_scan_queue (
  queueid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subnetid BIGINT UNSIGNED NOT NULL,
  status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL DEFAULT NULL,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  message TEXT,
  PRIMARY KEY (queueid),
  KEY ipam_scan_queue_status_idx (status, requested_at),
  KEY ipam_scan_queue_subnet_idx (subnetid),
  CONSTRAINT ipam_scan_queue_subnet_fk FOREIGN KEY (subnetid) REFERENCES ipam_subnets (subnetid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS ipam_scan_history (
  scanid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subnetid BIGINT UNSIGNED NOT NULL,
  command VARCHAR(512) NOT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  responding_count INT UNSIGNED NOT NULL DEFAULT 0,
  free_count INT UNSIGNED NOT NULL DEFAULT 0,
  reserved_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  message TEXT,
  PRIMARY KEY (scanid),
  KEY ipam_scan_history_subnet_idx (subnetid, started_at),
  CONSTRAINT ipam_scan_history_subnet_fk FOREIGN KEY (subnetid) REFERENCES ipam_subnets (subnetid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
