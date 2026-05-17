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
# Cloud preferido en 8086 (convencion del proyecto). Si esta ocupado, se busca
# el siguiente libre hacia arriba.
$AppPort = Get-FreePort -Start 8086
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
# El orden importa:
#   1) `docker compose down -v --remove-orphans` SIEMPRE primero. Borra
#      contenedores, red y -- critico -- el volumen `reactor-db-data` del
#      proyecto. Si saltearamos esto el volumen sobrevive y MySQL no vuelve
#      a correr schema.sql en el siguiente up (initdb solo se ejecuta en
#      DB virgen), dejando el schema desactualizado.
#   2) `docker rm -f` como fallback por si quedaron contenedores con esos
#      nombres pero sin label de compose (ej. de una version vieja del script).
# Nota: no redirigimos stderr (2>) para no convertirlo en NativeCommandError
# con $ErrorActionPreference=Stop. La salida de stderr va a la consola y ya.
Write-Host "==> Limpiando contenedores previos..." -ForegroundColor Red

& docker compose -p reactor down -v --remove-orphans | Out-Null
# LASTEXITCODE puede ser != 0 si no habia nada que borrar; no es un error real.

$existingNames = @(& docker ps -a --format '{{.Names}}')
foreach ($name in @('reactor', 'reactor-db')) {
    if ($existingNames -contains $name) {
        Write-Host "    removiendo $name (huerfano sin label compose)"
        & docker rm -f $name | Out-Null
    }
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

# --- Aplicar migraciones idempotentes -----------------------------------------
# `docker-entrypoint-initdb.d` SOLO procesa archivos en su raiz y SOLO la
# primera vez (DB virgen), asi que las migraciones de cloud/sql/migrations/
# nunca se ejecutarian solas. Las corremos siempre, en orden alfabetico.
# Todas estan escritas como idempotentes (chequean information_schema antes
# de ALTER), asi que reaplicarlas es no-op.
$migrationsDir = Join-Path $RepoRoot 'cloud/sql/migrations'
if (Test-Path $migrationsDir) {
    Write-Host ""
    Write-Host "==> Aplicando migraciones de cloud/sql/migrations/ ..." -ForegroundColor Red
    $migrations = @(Get-ChildItem -Path $migrationsDir -Filter '*.sql' | Sort-Object Name)
    foreach ($m in $migrations) {
        Write-Host "    $($m.Name)"
        & docker cp $m.FullName "reactor-db:/tmp/migration.sql" | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-Host "ERROR copiando $($m.Name) al contenedor reactor-db" -ForegroundColor Red
            exit 1
        }
        & docker exec reactor-db sh -c 'mysql -uroot -proot reactor_dev < /tmp/migration.sql'
        if ($LASTEXITCODE -ne 0) {
            Write-Host "ERROR aplicando $($m.Name)" -ForegroundColor Red
            exit 1
        }
    }
    & docker exec reactor-db rm -f /tmp/migration.sql | Out-Null
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
