# Remove dados sensiveis de TODO o historico Git (nao so do commit atual).
# Requer: pip install git-filter-repo
#
# Uso:
#   1. Copie scripts/purge-secrets.example.txt para purge-secrets.local.txt
#   2. .\scripts\limpar-historico-git.ps1
#   3. git push origin main --force

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $Root

if (-not (Get-Command python -ErrorAction SilentlyContinue)) {
    Write-Error "Python nao encontrado. Instale Python e: pip install git-filter-repo"
}

$secrets = @()
$purgeFile = Join-Path $PSScriptRoot "purge-secrets.local.txt"
if (Test-Path $purgeFile) {
    $secrets += Get-Content $purgeFile | Where-Object { $_ -and $_ -notmatch '^\s*#' }
}
if ($env:NFE_PURGE_SECRET) { $secrets += $env:NFE_PURGE_SECRET }
if ($env:NFE_PURGE_IP) { $secrets += $env:NFE_PURGE_IP }
$secrets = $secrets | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne "" } | Select-Object -Unique

if ($secrets.Count -eq 0) {
    Write-Error "Defina purge-secrets.local.txt ou NFE_PURGE_SECRET. Veja purge-secrets.example.txt"
}

$exprFile = Join-Path $env:TEMP "nfe-monitor-filter-expressions.txt"
$lines = foreach ($s in $secrets) { "literal:${s}==>***REMOVIDO***" }
[System.IO.File]::WriteAllText($exprFile, ($lines -join "`n") + "`n")

Write-Host "==> Limpando $($secrets.Count) padrao(s) do historico..." -ForegroundColor Cyan
$filterState = Join-Path $Root ".git\filter-repo"
if (Test-Path $filterState) { Remove-Item -Recurse -Force $filterState }

python -m git_filter_repo --force --replace-text $exprFile `
    --invert-paths `
    --path-glob 'certs/*.pfx' --path-glob 'certs/*.p12' `
    --path config.local.php --path database.local.php --path app.config.php `
    --path deploy/deploy.local.ps1 --path deploy.local.ps1 `
    --path log.txt --path status_robot.json --path-glob 'status_robot_empresa_*.json' `
    --path monitor.php.save --path-glob 'docs/historico-conversas/*'

python -m git_filter_repo --force --replace-text $exprFile
Remove-Item $exprFile -ErrorAction SilentlyContinue

Write-Host "Concluido. git push origin main --force" -ForegroundColor Green
Write-Host "ROTACIONE senhas e app_key que estiveram no historico."
