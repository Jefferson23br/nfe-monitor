# Envia site para a VPS — configure NFE_DEPLOY_SSH em deploy/deploy.local.ps1
$ErrorActionPreference = "Stop"
$localDeploy = Join-Path $PSScriptRoot "..\deploy\deploy.local.ps1"
if (Test-Path $localDeploy) { . $localDeploy }
$Vps = $env:NFE_DEPLOY_SSH
if (-not $Vps) { $Vps = "root@SEU_SERVIDOR" }
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

Write-Host "Origem: $Root -> ${Vps}:/root/nfe-monitor/"

ssh $Vps "mkdir -p /root/nfe-monitor/Frontend/nfe /root/nfe-monitor/includes /root/nfe-monitor/deploy /root/nfe-monitor/scripts /var/lib/nfe-monitor/certs /var/lib/nfe-monitor/status"

scp -r "$Root\Frontend\nfe\*" "${Vps}:/root/nfe-monitor/Frontend/nfe/"
scp "$Root\includes\bootstrap.php" "$Root\includes\auth.php" "$Root\includes\database.php" `
    "$Root\includes\web.php" "$Root\includes\empresa.php" "$Root\includes\monitor_helpers.php" `
    "${Vps}:/root/nfe-monitor/includes/"
scp "$Root\monitor.php" "$Root\consultar_distancia.php" "$Root\config.php" "${Vps}:/root/nfe-monitor/"
scp "$Root\deploy\nginx-nfe-monitor.example.conf" "$Root\deploy\openssl-legacy.cnf" "${Vps}:/root/nfe-monitor/deploy/"
scp "$Root\scripts\restaurar-site-vps.sh" "$Root\scripts\fix-permissoes-vps.sh" `
    "$Root\scripts\deploy-empresa-vps.sh" "$Root\scripts\habilitar-openssl-legacy-vps.sh" `
    "${Vps}:/root/nfe-monitor/scripts/"

Write-Host "Nao envia app.config.php nem config.local.php (segredos ficam so na VPS)."
Write-Host "Na VPS: bash /root/nfe-monitor/scripts/restaurar-site-vps.sh"
