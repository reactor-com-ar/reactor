#requires -Version 5.1
<#
.SYNOPSIS
    Rebuild local Reactor stack (idempotent).

.DESCRIPTION
    - Verifica que Docker este disponible.
    - Detecta puertos libres para el host (app + mysql), evitando conflictos
      con otros contenedores ya corriendo.
    - Escribe .env con los puertos elegidos.
    - Recrea el stack desde cero (down -v + up -d --build).
    - Imprime la URL final donde se sirve la app.

    Pensado para ejecutarse desde VSCode > Terminal > Run Task > "Rebuild Local"
    o manualmente:  pwsh ./scripts/rebuild-local.ps1
#>

$ErrorActionPreference = 'Stop'

# --- Repo root ---------------------------------------------------------------
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

Write-Host ""
Write-Host "==> Reactor :: rebuild-local" -ForegroundColor Red
Write-Host "    repo: $RepoRoot"
Write-Host ""

# --- Pre-flight: docker --------------------------------------------------------
# (sin 2>$null: en PS 5.1 redirigir stderr de un comando nativo lo convierte
#  en NativeCommandError y con ErrorActionPreference=Stop aborta el script)
$null = & docker version --format '{{.Server.Version}}'
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Docker no esta corriendo o no es accesible. Inicia Docker Desktop e intenta de nuevo." -ForegroundColor Red
    exit 1
}

# --- Helper: puertos ya publicados por contenedores Docker --------------------
function Get-DockerUsedPorts {
    $ports = @()
    $rows = & docker ps --format '{{.Ports}}'
    foreach ($row in $rows) {
        # Ej: "0.0.0.0:8090->80/tcp, [::]:8090->80/tcp, 0.0.0.0:8080-8082->8080-8082/tcp"
        foreach ($chunk in ($row -split ',')) {
            if ($chunk -match ':(\d+)(?:-(\d+))?->') {
                $from = [int]$Matches[1]
                $to   = if ($Matches[2]) { [int]$Matches[2] } else { $from }
                for ($i = $from; $i -le $to; $i++) { $ports += $i }
            }
        }
    }
    return $ports | Sort-Object -Unique
}

# --- Helper: puertos en LISTEN segun el host (Windows) ------------------------
function Get-HostListeningPorts {
    try {
        return Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
               Select-Object -ExpandProperty LocalPort -Unique
    } catch { return @() }
}

# --- Helper: bind real en 0.0.0.0 (lo que hara Docker) ------------------------
function Test-CanBind {
    param([int] $Port)
    try {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Any, $Port)
        $listener.Start()
        $listener.Stop()
        return $true
    } catch { return $false }
}

# --- Helper: find free TCP port ------------------------------------------------
function Get-FreePort {
    param(
        [Parameter(Mandatory)] [int]   $Start,
        [int]   $Max     = 500,
        [int[]] $Reserved = @()
    )

    $dockerUsed = Get-DockerUsedPorts
    $hostUsed   = Get-HostListeningPorts
    $blocked    = @($dockerUsed + $hostUsed + $Reserved) | Sort-Object -Unique

    for ($p = $Start; $p -lt ($Start + $Max); $p++) {
        if ($blocked -contains $p) { continue }
        if (-not (Test-CanBind -Port $p)) { continue }
        return $p
    }

    throw "No se encontro un puerto libre en el rango $Start..$($Start + $Max - 1)"
}

# --- Pick ports ---------------------------------------------------------------
$AppPort = Get-FreePort -Start 8080
$DbPort  = Get-FreePort -Start 3307 -Reserved @($AppPort)

Write-Host "==> Puertos elegidos:" -ForegroundColor Red
Write-Host "    app   -> $AppPort"
Write-Host "    mysql -> $DbPort"
Write-Host ""

# --- Write .env (idempotent) --------------------------------------------------
$envPath = Join-Path $RepoRoot '.env'
$envBody = @"
# Generado por scripts/rebuild-local.ps1 - no editar a mano
REACTOR_APP_PORT=$AppPort
REACTOR_DB_PORT=$DbPort
"@
Set-Content -Path $envPath -Value $envBody -Encoding ascii

# --- Force-clean stale containers (idempotent) --------------------------------
# Solo borramos lo que existe, asi no escribimos a stderr (que PS 5.1
# convertiria en NativeCommandError y abortaria el script con Stop)
Write-Host "==> Limpiando contenedores previos..." -ForegroundColor Red

$existingNames = @(& docker ps -a --format '{{.Names}}')
foreach ($name in @('reactor', 'reactor-db')) {
    if ($existingNames -contains $name) {
        Write-Host "    removiendo $name"
        & docker rm -f $name | Out-Null
    }
}

# Down del proyecto compose si tiene recursos asociados (red/volumen huerfanos)
$composeIds = @(& docker compose -p reactor ps -aq)
if ($composeIds.Count -gt 0) {
    & docker compose -p reactor down -v --remove-orphans | Out-Null
} else {
    # Igual limpiamos red/volumen huerfanos si quedaron de un run anterior
    $net = @(& docker network ls --filter "name=^reactor_default$" --format '{{.Name}}')
    if ($net.Count -gt 0) { & docker network rm reactor_default | Out-Null }
}

# --- Build & up ---------------------------------------------------------------
Write-Host ""
Write-Host "==> docker compose up -d --build" -ForegroundColor Red
docker compose -p reactor up -d --build
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "ERROR: docker compose fallo." -ForegroundColor Red
    Write-Host "--- docker ps -a (reactor) ---" -ForegroundColor Yellow
    docker ps -a --filter "name=reactor" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    Write-Host ""
    Write-Host "--- docker logs reactor (ultimas 50 lineas) ---" -ForegroundColor Yellow
    docker logs --tail 50 reactor 2>&1
    Write-Host ""
    Write-Host "--- docker logs reactor-db (ultimas 50 lineas) ---" -ForegroundColor Yellow
    docker logs --tail 50 reactor-db 2>&1
    exit 1
}

# --- Wait for app to be reachable ---------------------------------------------
Write-Host ""
Write-Host "==> Esperando a que la app responda en http://localhost:$AppPort ..." -ForegroundColor Red
$deadline = (Get-Date).AddSeconds(60)
$ok = $false
while ((Get-Date) -lt $deadline) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost:$AppPort/" -UseBasicParsing -TimeoutSec 2
        if ($r.StatusCode -eq 200) { $ok = $true; break }
    } catch { Start-Sleep -Milliseconds 800 }
}

Write-Host ""
if ($ok) {
    Write-Host "==> Listo." -ForegroundColor Green
} else {
    Write-Host "==> Stack arriba, pero la app aun no responde. Revisa logs con: docker logs reactor" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "  Cloud   : http://localhost:$AppPort" -ForegroundColor Green
Write-Host "  MySQL   : localhost:$DbPort  (user: root / pass: root / db: reactor_dev)"
Write-Host ""
Write-Host "  Logs    : docker logs -f reactor"
Write-Host "  Down    : docker compose -p reactor down"
Write-Host ""
