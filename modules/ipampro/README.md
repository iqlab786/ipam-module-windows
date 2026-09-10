# IPAM – Zabbix Module

A full-featured IP Address Management module for Zabbix, integrated directly into the Inventory menu.

---

cd ~/zabbix-docker/zabbix-ipam-module
git pull


sudo docker cp ~/zabbix-docker/zabbix-ipam-module/. \
    zabbix-web:/usr/share/zabbix/modules/zabbix-ipam-module

sudo docker exec -u root zabbix-web chown -R 1997:1997 /usr/share/zabbix/modules/zabbix-ipam-module
## ⚡ Quick Start

### 1. Copy module files

```bash
cp -r zabbix-ipam-module /usr/share/zabbix/modules/
```

### 2. Create the database tables ← **do this first, or you will see errors**

```bash
mysql -u <zabbix_user> -p <zabbix_database> \
  < /usr/share/zabbix/modules/zabbix-ipam-module/sql/schema.sql
```

Find your DB credentials in `/etc/zabbix/zabbix_server.conf` — look for `DBUser`, `DBPassword`, and `DBName`.

### 3. Enable the module in Zabbix

1. Log in to Zabbix as a Super Admin
2. Go to **Administration → General → Modules**
3. Click **Scan directory**
4. Find **IPAM** and click **Enable**

### 4. Navigate to the module

Go to **Inventory → IPAM** in the left sidebar.

---

## 📡 Network Scan — Architecture (cross-platform: Linux + Windows)

The `zabbix-web` container **never runs nmap itself**. Reliable ARP-based host discovery (needed for both accurate host counts and MAC address capture) requires real Layer-2 network access, which a normal Docker container doesn't have — and on Docker Desktop for Windows/Mac, even privileged networking tricks like macvlan don't reliably provide it (a platform limitation of the hidden VM Docker Desktop runs containers in).

Instead, this module uses a **queue-based design** that works the same way regardless of platform:

1. Clicking **Scan** in the IPAM UI just inserts a row into the `ipam_scan_queue` table (via `Repository::queueScan()`) and returns immediately — no nmap call happens in PHP.
2. A separate **scanner process** — the same `scanner/scan_daemon.py` script on both platforms — polls that table every few seconds, wherever it's deployed:
   - **Linux**: runs as a Docker container with `network_mode: host`, which gives it real LAN/ARP access natively — see `scanner/SETUP_LINUX.md`.
   - **Windows**: runs as a native background process on the Docker host machine (installed as a Scheduled Task, not in a container) — see `windows-scanner/SETUP_STEPS_WINDOWS.md`.
3. When it finds a queued job, it runs the real ARP-capable nmap scan using real network access (full LAN visibility, real MAC capture), then writes IP status + MAC addresses straight into `ipam_ips`, plus a row into `ipam_scan_history`.
4. The IPAM UI shows the queue status (queued/running/completed/failed) on the subnet overview page and, once complete, the normal IP list with MAC addresses populated.

Pick the setup guide matching your Docker host OS — the module itself (this folder) is identical either way; only where the scanner runs differs.

### How to run a scan

1. Go to **Inventory → IPAM → Subnets**
2. Find the subnet you want to scan
3. Click **Scan** in the Actions column — this queues the job
4. The scanner process (Linux container or Windows service, wherever you deployed it) picks it up within a few seconds and runs:
   ```
   nmap -sn -PR -PE -PP -PS21,22,23,25,80,135,139,443,445,3389,8080 -PA80,443 -T4 -n <subnet>/<cidr>
   ```
   - `-PR` ARP ping — the only technique that returns a MAC address, and on a local subnet the most reliable discovery method (near 100% response rate)
   - `-PE`/`-PP` ICMP echo + timestamp
   - `-PS`/`-PA` TCP SYN/ACK probes on common ports, for hosts that block ICMP
5. Refresh the subnet's **Overview** tab to see the queue status update, then the IP list with MAC addresses populated

### Troubleshooting

If a scan stays "queued" forever, check that the scanner process is actually running — see the Troubleshooting section in `scanner/SETUP_LINUX.md` (Linux) or `windows-scanner/SETUP_STEPS_WINDOWS.md` (Windows).

---

## 🔴 Common Error: "Table doesn't exist"

```
Error in query [SELECT COUNT(*) AS total FROM ipam_subnets]
Table 'zabbix.ipam_subnets' doesn't exist
```

**Cause:** You enabled the module in Zabbix before running the SQL schema.

**Fix:** Run step 2 above, then reload the IPAM page.

---

## Features

- **Dashboard** — subnet count, IP utilisation, top subnets, recent scans
- **Subnets** — add/edit/delete subnets with automatic host-address generation
- **IP Addresses** — browse, filter, edit hostname/MAC/owner per IP
- **Network Overview** — per-subnet detail view
- **VLANs** — manage VLAN database linked to subnets
- **Reports** — CSV export of utilisation, free IPs, or reserved IPs
- **Zabbix Sync** — link IPAM IPs to Zabbix hosts automatically
- **Network Scan** — ping-based scan to mark IPs used/free

---

## Requirements

| Component | Minimum |
|-----------|---------|
| Zabbix    | 6.0 LTS |
| PHP       | 8.0     |
| MySQL/MariaDB | 5.7 / 10.3 |

---

## Permissions

- **All users** — read-only access (browse subnets, IPs, VLANs)  
- **Zabbix Admin / Super Admin** — full write access

---

## License

MIT
