# Publicar o repositório no GitHub (sem vazar segredos)

Use este guia **antes** de tornar o repositório **público**.

## 1. Arquivos que nunca devem ir para o Git

| Arquivo | Conteúdo sensível |
|---------|-------------------|
| `config.local.php` | CNPJ, senha do `.pfx` |
| `database.local.php` | Senha PostgreSQL, `app_key` |
| `app.config.php` | URLs, WhatsApp, ajustes do servidor |
| `deploy/deploy.local.ps1` | IP/usuário SSH da VPS |
| `certs/*.pfx` | Certificado digital |
| `.env` | Variáveis de ambiente |
| `scripts/purge-secrets.local.txt` | Lista de segredos para limpeza |

Todos estão no `.gitignore`.

## 2. Auditoria rápida no código atual

No PowerShell, na pasta do projeto:

```powershell
# IPs, dominios e padroes comuns (exclui vendor)
Select-String -Path .\* -Pattern "72\.60|auctus|nfmonitor\.com|password\s*=>|app_key\s*=>" -Recurse `
  -Exclude vendor | Where-Object { $_.Path -notmatch '\\vendor\\' }
```

Não deve retornar senhas reais, IPs de produção ou domínios privados nos arquivos versionados.

## 3. Limpar o histórico (obrigatório se já houve commit com segredos)

### Opção A — Histórico zerado (recomendado para tornar público)

```powershell
cd C:\Users\SEU_USUARIO\Documents\GitHub\nfe-monitor
.\scripts\publicar-repositorio-limpo.ps1
```

Cria **1 commit limpo** e faz force push. Todo o histórico antigo é apagado do GitHub.

### Opção B — Substituir textos no histórico (manter commits)

```powershell
copy scripts\purge-secrets.example.txt scripts\purge-secrets.local.txt
# Edite purge-secrets.local.txt — uma linha por senha, IP, domínio ou CNPJ real
pip install git-filter-repo
.\scripts\limpar-historico-git.ps1
git push origin main --force
```

**Inclua na lista** qualquer valor que já tenha ido para o Git: IP da VPS, domínio real, senhas, `app_key`, CNPJ, links WhatsApp, etc.

## 4. GitHub Desktop (passo a passo)

1. Faça commit local de todas as correções de segurança desta auditoria.
2. Execute `.\scripts\publicar-repositorio-limpo.ps1` no PowerShell (ou a Opção B).
3. Abra o **GitHub Desktop** → repositório `nfe-monitor`.
4. Menu **Repository → Push origin**.
5. Se aparecer aviso de **force push**, confirme **somente** após rodar o script de limpeza.
6. No GitHub.com: **Settings → Danger Zone → Change visibility → Public**.

## 5. Rotacionar credenciais

Mesmo após limpar o histórico, **troque por precaução**:

- Senha do PostgreSQL
- `app_key` em `database.local.php`
- Senha do certificado `.pfx`
- Senhas de usuários do painel
- Chaves SSH da VPS

## 6. Verificação final

```powershell
git grep -i "senha" -- ":!vendor" ":!*.example.*"
git log --all -S "SEU_IP_OU_DOMINIO_ANTIGO" --oneline
```

No GitHub: busca `repo:Jefferson23br/nfe-monitor` + termos sensíveis — não deve retornar resultados.

## 7. Checklist

Veja também [SEGURANCA.md](SEGURANCA.md).
