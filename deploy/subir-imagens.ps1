# Envia imagens e CSS do login para a VPS.
# Configure em deploy/deploy.local.ps1: $env:NFE_DEPLOY_SSH e $env:NFE_PUBLIC_URL
# Uso: .\deploy\subir-imagens.ps1

$ErrorActionPreference = "Stop"
$localDeploy = Join-Path $PSScriptRoot "deploy.local.ps1"
if (Test-Path $localDeploy) { . $localDeploy }

$Vps = if ($env:NFE_DEPLOY_SSH) { $env:NFE_DEPLOY_SSH } else { "root@SEU_SERVIDOR" }
$PublicUrl = if ($env:NFE_PUBLIC_URL) { $env:NFE_PUBLIC_URL } else { "https://nfe.seudominio.com.br" }
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$Remoto = "/root/nfe-monitor"

Write-Host "Destino: ${Vps}:${Remoto}/Frontend/nfe/assets/"

ssh $Vps "mkdir -p ${Remoto}/Frontend/nfe/assets/img ${Remoto}/Frontend/nfe/assets/css ${Remoto}/includes"

scp "$Root\images\logo-removebg-preview.png" "${Vps}:${Remoto}/Frontend/nfe/assets/img/logo-removebg.png"
scp "$Root\images\logo-removebg-preview.png" "${Vps}:${Remoto}/Frontend/nfe/assets/img/logo.png"
scp "$Root\Frontend\nfe\assets\img\emblema.png" "${Vps}:${Remoto}/Frontend/nfe/assets/img/" 2>$null
scp "$Root\Frontend\nfe\assets\css\auth.css" "${Vps}:${Remoto}/Frontend/nfe/assets/css/"
scp "$Root\includes\auth.php" "${Vps}:${Remoto}/includes/"

Write-Host ""
Write-Host "Teste: ${PublicUrl}/login.php (Ctrl+F5)"
