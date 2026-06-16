# Checklist de segurança

Use antes de tornar o repositório **público** ou após qualquer exposição acidental.

## Repositório GitHub

- [ ] Arquivos sensíveis fora do Git (`.gitignore`: `config.local.php`, `database.local.php`, `app.config.php`, `deploy/deploy.local.ps1`, `certs/*.pfx`)
- [ ] Histórico sem senhas/certificados: [PUBLICAR-GITHUB.md](PUBLICAR-GITHUB.md) + `.\scripts\publicar-repositorio-limpo.ps1` (recomendado) ou `.\scripts\limpar-historico-git.ps1`
- [ ] SQLs de migração sem CNPJ/razão social reais (apenas exemplos fictícios)
- [ ] Busca no GitHub: não deve retornar senhas antigas

## Credenciais (rotacionar se já houve push)

- [ ] Senha do PostgreSQL (`DB_PASS` / `database.local.php`)
- [ ] Senha do certificado digital `.pfx`
- [ ] `app_key` / `NFE_APP_KEY` (mínimo 32 caracteres aleatórios)
- [ ] Acesso SSH da VPS

Gerar `app_key`:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

## Aplicação em produção

- [ ] `app.config.php`: `dev_mode` => **false**
- [ ] `NFE_DEV_MODE` não definido ou `0`
- [ ] `mail_from` configurado; Postfix/sendmail ativo na VPS (`mail()`)
- [ ] Recuperação de senha: usuário recebe link **por e-mail** (não na tela)
- [ ] Cadastro de empresa bloqueado até `app_key` forte estar configurada

## VPS

- [ ] `config.local.php` e certificados apenas no servidor, não no Git
- [ ] Permissões `certs/` restritas (ex.: `chmod 640`)
