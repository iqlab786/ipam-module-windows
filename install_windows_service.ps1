<#
.SYNOPSIS
    Installs the IPAM Windows scanner as an auto-starting Scheduled Task.

.DESCRIPTION
    Creates run_scanner.bat (embeds your DB connection env vars) next to this
    script, then registers a Windows Scheduled Task that runs it.

    By default the task runs under YOUR current Windows account, triggered
    "at log on" - this guarantees it uses the exact same PATH, Python
    install, and permissions you already tested manually (python --version,
    nmap --version), avoiding a common issue where the SYSTEM account can't
    see a per-user Python install.

    Pass -RunAsSystem to instead run it as SYSTEM at boot (starts before
    anyone logs in, but requires Python/nmap to be installed "for all
    users" so SYSTEM can actually see them).

.NOTES
    Run this script from an elevated ("Run as Administrator") PowerShell
    window. Re-running it is safe, it replaces the existing task/bat file.
#>

param(
    [string]$PythonPath = (Get-Command python -ErrorAction SilentlyContinue).Source,
    [string]$DbHost = "127.0.0.1",
    [string]$DbPort = "3306",
    [string]$DbName = "zabbix",
    [string]$DbUser = "zabbix",
    [string]$DbPassword = "iqlab@2025",
    [string]$NmapPath = "nmap",
    [switch]$RunAsSystem
)

$ErrorActionPreference = "Stop"

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "This script must be run as Administrator. Right-click PowerShell, choose Run as administrator, then re-run this script." -ForegroundColor Red
    exit 1
}

if (-not $PythonPath) {
    Write-Host "Could not find python on PATH. Install Python first, or pass -PythonPath C:\path\to\python.exe" -ForegroundColor Red
    exit 1
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$DaemonScript = Join-Path (Split-Path -Parent $ScriptDir) "scanner\scan_daemon.py"
$BatPath = Join-Path $ScriptDir "run_scanner.bat"
$LogPath = Join-Path $ScriptDir "scanner.log"

if (-not (Test-Path $DaemonScript)) {
    Write-Host "Could not find scan_daemon.py at: $DaemonScript" -ForegroundColor Red
    Write-Host "Expected it in the scanner folder next to windows-scanner (one level up)." -ForegroundColor Red
    exit 1
}

$batContent = @"
@echo off
set DB_HOST=$DbHost
set DB_PORT=$DbPort
set DB_NAME=$DbName
set DB_USER=$DbUser
set DB_PASSWORD=$DbPassword
set NMAP_PATH=$NmapPath
"$PythonPath" "$DaemonScript" >> "$LogPath" 2>&1
"@
Set-Content -Path $BatPath -Value $batContent -Encoding ASCII

Write-Host "Wrote wrapper script: $BatPath"
Write-Host "Using Python: $PythonPath"

$TaskName = "IPAM-Windows-Scanner"

$Action = New-ScheduledTaskAction -Execute $BatPath -WorkingDirectory $ScriptDir
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Days 0)

if ($RunAsSystem) {
    Write-Host "Configuring task to run as SYSTEM at startup."
    $Trigger = New-ScheduledTaskTrigger -AtStartup
    $Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
} else {
    $CurrentUser = "$env:USERDOMAIN\$env:USERNAME"
    Write-Host "Configuring task to run as $CurrentUser at log on."
    $Trigger = New-ScheduledTaskTrigger -AtLogOn -User $CurrentUser
    $Principal = New-ScheduledTaskPrincipal -UserId $CurrentUser -LogonType Interactive -RunLevel Highest
}

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "Existing task found, stopping and replacing it: $TaskName"
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

# Belt-and-suspenders: Stop-ScheduledTask doesn't always kill the underlying
# process promptly, so explicitly kill any leftover scan_daemon.py process
# too, to guarantee no orphaned instance keeps polling the queue table
# alongside the one we're about to start.
Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -like "*scan_daemon.py*" } |
    ForEach-Object {
        Write-Host "Stopping orphaned scanner process (PID $($_.ProcessId))"
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings -Description "Polls ipam_scan_queue and runs ARP-capable nmap scans for the Zabbix IPAM module." | Out-Null

Write-Host ""
Write-Host "Installed. Starting it now..." -ForegroundColor Green
Start-ScheduledTask -TaskName $TaskName

Start-Sleep -Seconds 3
Write-Host ""
Write-Host "Task status:" -ForegroundColor Cyan
Get-ScheduledTask -TaskName $TaskName | Select-Object TaskName, State

Write-Host ""
Write-Host "Log file: $LogPath" -ForegroundColor Cyan
Write-Host "Tail it with: Get-Content '$LogPath' -Wait -Tail 20"
Write-Host ""
if ($RunAsSystem) {
    Write-Host "It will now start automatically every time this machine boots, even before anyone logs in."
} else {
    Write-Host "It will now start automatically every time you log into Windows."
}
