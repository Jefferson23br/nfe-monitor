# .\deploy\subir-frontend.ps1 — configure NFE_DEPLOY_SSH em deploy/deploy.local.ps1
$localDeploy = Join-Path $PSScriptRoot "deploy.local.ps1"
if (Test-Path $localDeploy) { . $localDeploy }
$Vps = if ($env:NFE_DEPLOY_SSH) { $env:NFE_DEPLOY_SSH } else { "root@SEU_SERVIDOR" }
$PublicUrl = if ($env:NFE_PUBLIC_URL) { $env:NFE_PUBLIC_URL } else { "https://nfe.seudominio.com.br" }
$raiz = Resolve-Path (Join-Path $PSScriptRoot "..")
Write-Host "Enviando frontend para $Vps ..."
scp -r (Join-Path $raiz "Frontend\nfe\*") "${Vps}:/root/nfe-monitor/Frontend/nfe/"
scp -r (Join-Path $raiz "includes") "${Vps}:/root/nfe-monitor/"
Write-Host "Teste: ${PublicUrl}/login.php"
