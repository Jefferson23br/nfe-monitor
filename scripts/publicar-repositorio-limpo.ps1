# Recria o historico Git do zero (1 commit limpo) e faz force push.
# Use antes de tornar o repositorio PUBLICO no GitHub.
#
# Uso: .\scripts\publicar-repositorio-limpo.ps1
# Depois: GitHub Desktop → Push origin (force)

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $Root

$git = $null
foreach ($p in @(
    "C:\Program Files\Git\cmd\git.exe",
    "$env:LOCALAPPDATA\Programs\GitHubDesktop\app-*\resources\app\git\cmd\git.exe"
)) {
    if ($p -like "*`**") {
        $found = Get-ChildItem (Split-Path $p) -Filter git.exe -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($found) { $git = $found.FullName; break }
    } elseif (Test-Path $p) { $git = $p; break }
}
if (-not $git) {
    $gh = Get-ChildItem "$env:LOCALAPPDATA\GitHubDesktop" -Recurse -Filter git.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($gh) { $git = $gh.FullName }
}
if (-not $git) { Write-Error "git.exe nao encontrado." }

Write-Host "==> Verificando arquivos sensiveis rastreados..." -ForegroundColor Cyan
$tracked = & $git ls-files | Where-Object {
    ($_ -eq 'config.local.php' -or $_ -eq 'database.local.php' -or $_ -eq 'app.config.php' `
        -or $_ -match '^certs/.+\.(pfx|p12)$' -or $_ -match 'purge-secrets\.local\.txt$' `
        -or $_ -match 'deploy\.local\.ps1$')
}
if ($tracked) {
    Write-Error "Arquivos sensiveis ainda rastreados:`n$($tracked -join "`n")`nRemova com: git rm --cached <arquivo>"
}

Write-Host "==> Criando branch limpa (historico zerado)..." -ForegroundColor Cyan
& $git add -A
& $git checkout --orphan public-clean
& $git add -A
& $git commit -m @"
Public release: NFe Monitor - portfolio multi-empresa SEFAZ

Plataforma de monitoramento de NF-e com arquitetura multi-tenant,
consulta automatica DistDFe, painel web e seguranca de credenciais.
Historico anterior removido; sem segredos versionados.
"@

& $git branch -D main 2>$null
& $git branch -m main

Write-Host "==> Force push para origin..." -ForegroundColor Cyan
& $git push --force origin main

Write-Host ""
Write-Host "Concluido. Historico antigo removido do GitHub." -ForegroundColor Green
Write-Host "Proximo passo: GitHub.com → Settings → Change visibility → Public" -ForegroundColor Yellow
Write-Host "ROTACIONE senhas de banco, app_key e certificado .pfx por precaucao." -ForegroundColor Yellow
