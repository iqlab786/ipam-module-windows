# IPAM Module for Zabbix — Windows Edition

Adds IP Address Management to Zabbix: subnet inventory, VLAN tracking,
IP reservations, CSV export, and automatic network scanning that
discovers live hosts and captures MAC addresses.

This edition is for a Zabbix stack running in **Docker Desktop on
Windows**. Docker Desktop runs every container inside a hidden Linux VM,
so containers never get real access to your LAN — MAC-address capture
(ARP scanning) silently fails no matter how a container is configured.
To get around this, the scanner here runs as a **native Windows
background service** instead of a container, using Nmap for Windows and
its Npcap driver for real network access. Only the Zabbix stack itself
stays in Docker.

> Running Zabbix on **native Linux** instead? Use the
> `ipam-module-linux` package instead — there, the scanner can just run
> as another Docker container.

---

## What's in this folder

```
ipam-module-windows/
├── modules/ipampro/                  The Zabbix frontend module (PHP)
│   └── sql/schema.sql                Database tables this module needs
├── scanner/
│   └── scan_daemon.py                The scanning script — runs natively on Windows
├── install_windows_service.ps1       Installs the scanner as an auto-starting service
└── uninstall_windows_service.ps1     Removes it
```

---

## How it works (30-second version)

Clicking **Scan** in Zabbix doesn't scan anything directly — it just
adds a request row to a database table. A separate program,
`scan_daemon.py`, runs constantly in the background on your Windows
machine (kept alive by a Windows Scheduled Task) and checks that table
every 5 seconds. When it finds a request, it runs `nmap` against your
real network, and writes the results (live hosts + MAC addresses) back
into the database. Zabbix just displays whatever's currently there.

---

## Prerequisites

- Docker Desktop running your Zabbix stack (`docker-compose.yml`), with
  services named `mysql-server`, `zabbix-server`, and `zabbix-web`
- Administrator access on the Windows machine
- **Nmap for Windows** — installed with one specific option set
  correctly (Part 2 below)
- **Python 3** for Windows, with `pymysql` and `cryptography` installed
  for that exact interpreter (Part 3 below)

---

## Part 1 — Install the module into Zabbix

Run these from the same folder as your `docker-compose.yml`.

### 1. Copy the module in

```powershell
Copy-Item -Recurse "C:\path\to\ipam-module-windows\modules" ".\modules"
```

### 2. Mount the module into `zabbix-web`

Add this line to your `zabbix-web` service's `volumes:` in
`docker-compose.yml`:

```yaml
  zabbix-web:
    build:
      context: .
      dockerfile: Dockerfile
    ...
    volumes:
      - /etc/localtime:/etc/localtime:ro
      - /etc/timezone:/etc/timezone:ro
      - ./zabbix-custom-ui/favicon.ico:/usr/share/zabbix/favicon.ico
      - ./zabbix-custom-ui/brand.conf.php:/usr/share/zabbix/local/conf/brand.conf.php:ro
      - ./zabbix-custom-ui/rebranding/identiqa.png:/usr/share/zabbix/rebranding/identiqa.png:ro
      - ./modules:/usr/share/zabbix/modules          # <-- ADD THIS LINE
```

Apply it:
```powershell
docker-compose up -d zabbix-web
```

### 3. Apply the module's database schema

```powershell
docker cp modules\ipampro\sql\schema.sql mysql-server:/tmp/schema.sql
docker exec -it mysql-server bash
```
then, inside the container's own shell (this sidesteps PowerShell's
quoting rules entirely — always do MySQL work this way on Windows):
```bash
mysql -u zabbix -p'iqlab@2025' zabbix < /tmp/schema.sql
mysql -u zabbix -p'iqlab@2025' zabbix -e "SHOW TABLES LIKE 'ipam_scan_queue';"
exit
```
Confirm you see one row back: `ipam_scan_queue`.

### 4. Enable the module inside Zabbix

Open Zabbix in your browser — this stack serves the UI through
`license-proxy` on port **8080**, e.g. `http://localhost:8080` — log
in, then:

1. Go to **Administration → General → Modules**
2. Click **Scan directory**
3. Find **IPAM** in the list and set its status to **Enabled**

---

## Part 2 — Install Nmap for Windows

Download: https://nmap.org/download.html#windows

During setup, on the **Npcap options screen**, this checkbox matters a
lot and is easy to miss:

> [ ] **"Restrict Npcap driver's access to Administrators only"** — leave
> this **UNCHECKED**.

If this is checked, ARP scanning (and therefore MAC address capture)
will silently fail — even for `SYSTEM`, which Npcap does **not**
consider equivalent to "Administrator." This single checkbox is the
most common cause of "scan completes but finds 0 MAC addresses."

If you're not sure which option you picked previously, just re-run the
installer — reinstalling over an existing install is safe.

Find nmap's install path (you'll need it in Part 4):
```powershell
(Get-Command nmap).Source
```
Typically `C:\Program Files (x86)\Nmap\nmap.exe`.

---

## Part 3 — Install Python and required packages

If you don't have Python: https://www.python.org/downloads/windows/

Find the exact interpreter you have — this matters more than you'd
expect, because Windows often has several Pythons (a Microsoft Store
alias, a per-user install, a system-wide install) and `pip install`
only affects the one you run it with:
```powershell
python -c "import sys; print(sys.executable)"
```
Copy that exact path. If it contains `WindowsApps`, that's the
Microsoft Store alias — it does **not** work from a Scheduled Task.
Install Python from python.org instead.

Install the required packages against that exact interpreter:
```powershell
& "PASTE_THE_PATH_FROM_ABOVE" -m pip install pymysql cryptography
```
Confirm:
```powershell
& "PASTE_THE_PATH_FROM_ABOVE" -m pip show pymysql
```

---

## Part 4 — Install the scanner as an auto-starting service

Open PowerShell **as Administrator**.

Allow the scripts to run (this session only, no permanent change):
```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
```

Clear the "downloaded from the internet" block flag:
```powershell
cd C:\path\to\ipam-module-windows
Unblock-File .\install_windows_service.ps1
Unblock-File .\uninstall_windows_service.ps1
```

Expose MySQL's port so the native Windows scanner can reach it —
add this to your `mysql-server` service in `docker-compose.yml`:
```yaml
  mysql-server:
    image: mysql:8.0
    container_name: mysql-server
    ...
    ports:
      - "127.0.0.1:3306:3306"        # <-- ADD THIS LINE
    ...
```
```powershell
docker-compose up -d mysql-server
```

Now run the installer, passing the paths from Parts 2 and 3:
```powershell
.\install_windows_service.ps1 -PythonPath "C:\path\from\part3\python.exe" -NmapPath "C:\Program Files (x86)\Nmap\nmap.exe" -RunAsSystem
```

`-RunAsSystem` is recommended — it starts the scanner at boot, before
anyone logs in, and avoids permission quirks that can silently break
ARP scanning under a normal user's Scheduled Task session.

The database connection defaults already match this stack
(`DB_NAME=zabbix`, `DB_USER=zabbix`, `DB_PASSWORD=iqlab@2025`,
`DB_HOST=127.0.0.1`, `DB_PORT=3306`) — no need to pass them unless
yours differ.

Expected output ends with:
```
Task status:
TaskName               State
--------               -----
IPAM-Windows-Scanner   Running
```
Confirm it says `Running`, not `Ready` (see Troubleshooting if not).

---

## Test it

```powershell
Get-Content .\scanner.log -Wait -Tail 20
```
In the Zabbix UI, go to the IPAM section and click **Scan** on a
subnet. Within ~5–10 seconds you should see:
```
[scan] subnet=1 target=192.168.1.0/24
[scan] subnet=1 completed: nmap discovery scan completed - 28 used, 226 free, 27 with MAC captured.
```
Refresh the subnet's IP list in the UI — it should now show updated
used/free status and MAC addresses.

---

## Uninstalling the scanner

```powershell
cd C:\path\to\ipam-module-windows
.\uninstall_windows_service.ps1
```
(run as Administrator)

To remove the module itself, delete the `./modules:/usr/share/zabbix/modules`
line from `docker-compose.yml` and run `docker-compose up -d zabbix-web`,
or just disable **IPAM** under Administration → General → Modules to
keep the data but hide it from the UI.

---

## Troubleshooting

**"is not digitally signed. You cannot run this script"**
```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
Unblock-File .\install_windows_service.ps1
```

**Task state is `Ready` instead of `Running`** — the process started
and immediately exited. Check the log:
```powershell
Get-Content .\scanner.log -Tail 30
```
- `The system cannot execute the specified program` → you passed the
  Microsoft Store Python alias. Redo Part 3 to get the real path.
- `ModuleNotFoundError: No module named 'pymysql'` → packages were
  installed against a different Python than the task is using. Redo
  Part 3's install command with the exact `-PythonPath` you passed in.
- `nmap executable not found at 'nmap'` → `-NmapPath` was missing or
  wrong. Redo Part 2's path check and reinstall.

**Scan completes but always `0 with MAC captured`** — the Npcap
checkbox from Part 2. Reinstall Npcap with it unchecked, then confirm
from a plain, non-elevated PowerShell window:
```powershell
nmap -sn -PR -n 192.168.1.0/24
```
You should see `MAC Address:` lines without needing elevation.

**Scan results randomly flip between correct and broken (1 host, 0
MAC) between scans** — two scanner processes are running at once and
racing for jobs:
```powershell
Get-CimInstance Win32_Process -Filter "Name='python.exe'" | Select ProcessId, CommandLine
```
You should see exactly **one** row containing `scan_daemon.py`. Kill
extras with `Stop-Process -Id <ProcessId> -Force`, then reinstall.

**Can't connect to `127.0.0.1:3306`**
```powershell
docker ps --filter name=mysql-server
```
Should show `127.0.0.1:3306->3306/tcp` in the PORTS column.

**Scan stays "queued" forever**
```powershell
Get-ScheduledTask -TaskName "IPAM-Windows-Scanner" | Select TaskName, State
```
Should say `Running`. If not, see the "Task state is `Ready`" section.

---

## Notes

- Nothing scanned is ever deleted on a rescan — an IP that stops
  responding is just marked `free`, not removed. See the module's own
  `modules/ipampro/README.md` for feature-level details (reservations,
  CSV export, VLANs).
- The default DB password (`iqlab@2025`) matches this stack's
  `docker-compose.yml` — change it in both places if you change it.
