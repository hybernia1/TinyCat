param(
    [ValidateSet('status', 'start', 'stop')][string]$Action = 'status',
    [string]$Executable = '',
    [ValidateRange(1024, 65535)][int]$Port = 11211,
    [ValidateRange(16, 4096)][int]$MemoryMB = 64
)
$ErrorActionPreference = 'Stop'
$workspaceHelper = (Join-Path $PSScriptRoot 'workspace.php').Replace('\', '/')
$directory = "<?php require '$workspaceHelper'; echo TinyCat\Tools\Workspace::directory('tools/memcached');" | php
if ($LASTEXITCODE -ne 0 -or !$directory) { throw 'Cannot resolve development workspace through PHP.' }
$statePath = Join-Path $directory "$Port.json"
function Read-Version {
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $pending = $client.ConnectAsync('127.0.0.1', $Port)
        if (!$pending.Wait(500)) { return $null }
        $stream = $client.GetStream()
        $stream.ReadTimeout = 500
        $bytes = [Text.Encoding]::ASCII.GetBytes("version`r`n")
        $stream.Write($bytes, 0, $bytes.Length)
        $reader = [IO.StreamReader]::new($stream)
        $line = $reader.ReadLine()
        if ($line -match '^VERSION [0-9]') { return $line }
        return $null
    } catch { return $null } finally { $client.Dispose() }
}
if ($Action -eq 'status') {
    $version = Read-Version
    if ($version) { Write-Output "127.0.0.1:$Port $version" } else { Write-Output "127.0.0.1:$Port unavailable" }
    exit 0
}
if ($Action -eq 'stop') {
    if (!(Test-Path -LiteralPath $statePath)) { throw 'No process owned by this tool. Refusing to stop another server.' }
    $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $process = Get-Process -Id $state.processId -ErrorAction SilentlyContinue
    if ($process) {
        if ($process.Path -ne $state.executable -or $process.StartTime.ToUniversalTime().ToString('o') -ne $state.started) {
            throw 'Process identity changed. Refusing to stop it.'
        }
        Stop-Process -Id $process.Id
        $process.WaitForExit()
    }
    Remove-Item -LiteralPath $statePath
    Write-Output "Stopped owned Memcached on port $Port."
    exit 0
}
if (Read-Version) { Write-Output "Memcached already available on 127.0.0.1:$Port; no changes."; exit 0 }
if (!$Executable) {
    $command = Get-Command memcached.exe -ErrorAction SilentlyContinue
    if ($command) { $Executable = $command.Source }
}
if (!$Executable -or !(Test-Path -LiteralPath $Executable -PathType Leaf)) {
    throw 'Supply -Executable with the installed memcached.exe path. No software is downloaded automatically.'
}
$Executable = (Resolve-Path -LiteralPath $Executable).Path
$process = Start-Process -FilePath $Executable -ArgumentList @('-l', '127.0.0.1', '-p', $Port, '-U', '0', '-m', $MemoryMB) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $directory "$Port.stdout.log") -RedirectStandardError (Join-Path $directory "$Port.stderr.log")
$started = $process.StartTime.ToUniversalTime().ToString('o')
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    Start-Sleep -Milliseconds 100
    $process.Refresh()
    if ($process.HasExited) { throw "Memcached exited; inspect $directory/$Port.stderr.log" }
    $version = Read-Version
    if ($version) {
        @{ processId = $process.Id; executable = $Executable; started = $started; port = $Port } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding UTF8
        Write-Output "Started 127.0.0.1:$Port $version ($MemoryMB MiB, UDP disabled)."
        exit 0
    }
}
Stop-Process -Id $process.Id
throw 'Memcached did not become ready; owned process stopped.'
