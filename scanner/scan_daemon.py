#!/usr/bin/env python3
"""
IPAM Pro scanner daemon — cross-platform (Linux container OR native Windows).

Polls the `ipam_scan_queue` table (written by the Zabbix IPAM module's
"Scan" button) for queued subnets, runs an nmap ARP + ICMP/TCP discovery
scan against them, and writes host status + MAC addresses back into the
same `zabbix` database the Zabbix module reads from.

This ONE script runs in two different deployment modes, controlled purely
by environment variables — no code differences between platforms:

  Linux (Docker container, recommended):
    Run this inside a container with `network_mode: host` and
    `cap_add: [NET_RAW, NET_ADMIN]` — see scanner/Dockerfile and
    scanner/docker-compose.snippet.linux.yml. Host networking on native
    Linux gives the container real LAN/ARP access directly, no macvlan
    needed. Set DB_HOST=mysql-server (reachable because host networking
    means "localhost" from the container == the Docker host's network).
    Actually with network_mode: host, use DB_HOST=127.0.0.1 if MySQL is
    also using host networking, or the Docker bridge IP / mysql-server's
    LAN IP otherwise — see the Linux setup doc for the exact value.

  Windows (native process, via Scheduled Task):
    Run this directly on the Windows host with Python installed locally
    (Docker Desktop on Windows can't give containers real LAN/ARP access).
    Set DB_HOST=127.0.0.1 with MySQL's port published to the host in
    docker-compose.yml. See scanner/install_windows_service.ps1.

Required env vars (both modes):
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD

Optional:
    POLL_INTERVAL_SECONDS (default 5)
    NMAP_TIMEOUT_SECONDS  (default 180)
    NMAP_PATH             (default "nmap" — override with a full path,
                            e.g. on Windows if nmap.exe isn't on PATH)
"""

import os
import re
import subprocess
import time
import traceback

import pymysql
import pymysql.cursors

DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT", "3306"))
DB_NAME = os.environ.get("DB_NAME", "zabbix")
DB_USER = os.environ.get("DB_USER", "zabbix")
DB_PASSWORD = os.environ.get("DB_PASSWORD", "")
POLL_INTERVAL_SECONDS = int(os.environ.get("POLL_INTERVAL_SECONDS", "5"))
NMAP_TIMEOUT_SECONDS = int(os.environ.get("NMAP_TIMEOUT_SECONDS", "180"))
NMAP_PATH = os.environ.get("NMAP_PATH", "nmap")

HOST_BLOCK_RE = re.compile(
    r"^Nmap scan report for (?:\S+ \()?([0-9]{1,3}(?:\.[0-9]{1,3}){3})\)?$",
    re.MULTILINE,
)
MAC_RE = re.compile(r"^MAC Address:\s*([0-9A-Fa-f:]{17})", re.MULTILINE)


def db_connect():
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )


def parse_hosts(text: str) -> dict:
    """Return {ip: mac_or_empty_string} parsed from nmap -sn output."""
    hosts = {}
    blocks = re.split(r"(?=^Nmap scan report for )", text, flags=re.MULTILINE)

    for block in blocks:
        ip_match = HOST_BLOCK_RE.search(block)
        if not ip_match:
            continue
        ip = ip_match.group(1)

        mac_match = MAC_RE.search(block)
        mac = mac_match.group(1).upper() if mac_match else ""

        if ip not in hosts or hosts[ip] == "":
            hosts[ip] = mac

    return hosts


def run_nmap(subnet: str, cidr: int) -> tuple[str, int]:
    target = f"{subnet}/{cidr}"
    # -PR (ARP) is the only technique that returns a MAC address, and on a
    # local subnet it's by far the most reliable discovery method. It only
    # works when this process has real L2 access to the target LAN — true
    # for a Linux container with network_mode: host, or a native Windows
    # process — which is the entire reason this runs outside the normal
    # zabbix-web container.
    cmd = [
        NMAP_PATH, "-sn",
        "-PR",
        "-PE", "-PP", "-PS21,22,23,25,80,135,139,443,445,3389,8080",
        "-PA80,443",
        "-T4", "-n",
        target,
    ]
    try:
        proc = subprocess.run(
            cmd, capture_output=True, text=True, timeout=NMAP_TIMEOUT_SECONDS
        )
        return proc.stdout + proc.stderr, proc.returncode
    except subprocess.TimeoutExpired:
        return f"nmap timed out after {NMAP_TIMEOUT_SECONDS}s", 1
    except FileNotFoundError:
        return (
            f"nmap executable not found at '{NMAP_PATH}'. Install nmap and/or "
            "add it to PATH, or set the NMAP_PATH env var to its full path.",
            1,
        )


def process_job(conn, job: dict):
    subnetid = job["subnetid"]
    queueid = job["queueid"]

    with conn.cursor() as cur:
        cur.execute(
            "UPDATE ipam_scan_queue SET status='running', started_at=NOW() WHERE queueid=%s",
            (queueid,),
        )
        cur.execute(
            "SELECT subnet, cidr FROM ipam_subnets WHERE subnetid=%s", (subnetid,)
        )
        subnet_row = cur.fetchone()

    if not subnet_row:
        _finish_job(conn, queueid, "failed", "Subnet no longer exists.")
        return

    command_str = f"nmap -sn -PR -PE -PP -PS... -PA80,443 -T4 -n {subnet_row['subnet']}/{subnet_row['cidr']}"
    print(f"[scan] subnet={subnetid} target={subnet_row['subnet']}/{subnet_row['cidr']}", flush=True)

    output_text, exit_code = run_nmap(subnet_row["subnet"], subnet_row["cidr"])
    responding = parse_hosts(output_text)
    has_results = len(responding) > 0
    status = "completed" if (exit_code == 0 or has_results) else "failed"

    used = free = reserved = 0

    with conn.cursor() as cur:
        cur.execute(
            "SELECT ipid, ip_address, status FROM ipam_ips WHERE subnetid=%s",
            (subnetid,),
        )
        ip_rows = cur.fetchall()

        for row in ip_rows:
            if row["status"] == "reserved":
                reserved += 1
                continue

            ip_address = row["ip_address"]
            if ip_address in responding:
                used += 1
                mac = responding[ip_address]
                if mac:
                    cur.execute(
                        "UPDATE ipam_ips SET status='used', mac=%s, last_seen_at=NOW() WHERE ipid=%s",
                        (mac, row["ipid"]),
                    )
                else:
                    cur.execute(
                        "UPDATE ipam_ips SET status='used', last_seen_at=NOW() WHERE ipid=%s",
                        (row["ipid"],),
                    )
            else:
                free += 1
                cur.execute(
                    "UPDATE ipam_ips SET status='free', last_seen_at=NULL "
                    "WHERE ipid=%s AND zabbix_hostid IS NULL",
                    (row["ipid"],),
                )

        cur.execute(
            "UPDATE ipam_subnets SET last_scan_at=NOW() WHERE subnetid=%s",
            (subnetid,),
        )

        mac_count = sum(1 for m in responding.values() if m)
        message = (
            f"nmap discovery scan completed — {used} used, {free} free, "
            f"{mac_count} with MAC captured."
            if status == "completed"
            else f"nmap error: {output_text.strip()[:500]}"
        )

        cur.execute(
            "INSERT INTO ipam_scan_history "
            "(subnetid, command, finished_at, responding_count, free_count, reserved_count, status, message) "
            "VALUES (%s, %s, NOW(), %s, %s, %s, %s, %s)",
            (subnetid, command_str, used, free, reserved, status, message),
        )

    _finish_job(conn, queueid, status, message)
    print(f"[scan] subnet={subnetid} {status}: {message}", flush=True)


def _finish_job(conn, queueid: int, status: str, message: str):
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE ipam_scan_queue SET status=%s, finished_at=NOW(), message=%s WHERE queueid=%s",
            (status, message, queueid),
        )


def main_loop():
    print(
        f"[scan-daemon] starting, polling every {POLL_INTERVAL_SECONDS}s "
        f"against {DB_HOST}:{DB_PORT}/{DB_NAME} (nmap: {NMAP_PATH})",
        flush=True,
    )

    while True:
        try:
            conn = db_connect()
            try:
                while True:
                    with conn.cursor() as cur:
                        cur.execute(
                            "SELECT queueid, subnetid FROM ipam_scan_queue "
                            "WHERE status='queued' ORDER BY requested_at ASC LIMIT 1"
                        )
                        job = cur.fetchone()

                    if job:
                        try:
                            process_job(conn, job)
                        except Exception:
                            traceback.print_exc()
                            try:
                                _finish_job(conn, job["queueid"], "failed", "Internal scanner error, check daemon logs.")
                            except Exception:
                                pass
                    else:
                        time.sleep(POLL_INTERVAL_SECONDS)
            finally:
                conn.close()
        except Exception:
            print("[scan-daemon] DB connection error, retrying in 10s", flush=True)
            traceback.print_exc()
            time.sleep(10)


if __name__ == "__main__":
    main_loop()
