# NFe Monitor

Monitor automatizado para consulta de documentos fiscais na SEFAZ (distribuicao DFe), com persistencia em PostgreSQL e registro de tentativas/processamentos.

## Visao Geral

Este projeto realiza consultas periodicas de NSU na SEFAZ para identificar novos documentos fiscais vinculados ao CNPJ configurado. Quando encontra notas, processa o retorno XML, extrai dados relevantes e salva no banco de dados.

Tambem registra tentativas, respostas e situacoes de bloqueio preventivo (como consumo indevido), ajudando a manter rastreabilidade operacional.

## Funcao do Sistema

- Consultar NSU de forma incremental na SEFAZ (DistDFe)
- Identificar retorno com documentos disponiveis
- Descompactar e interpretar XML (`docZip`)
- Salvar dados das notas no PostgreSQL
- Registrar logs de operacao no banco
- Evitar sobrecarga via controle preventivo de quarentena

## Stack de Tecnologias

- PHP (scripts CLI)
- Composer (gerenciamento de dependencias)
- [`nfephp-org/sped-nfe`](https://github.com/nfephp-org/sped-nfe) para comunicacao com SEFAZ
- PostgreSQL (armazenamento de dados e logs)
- Certificado digital A1 (`.pfx`) para autenticacao

## Estrutura Principal

- `monitor.php` - rotina principal de monitoramento e persistencia
- `config.php` - configuracoes fiscais e caminho do certificado
- `teste_sefaz.php` - teste de comunicacao com SEFAZ
- `debug_nfe.php` - inspecao rapida de retorno XML
- `checar_distancia.php` e `distancia_real.php` - estimativa de distancia entre NSU local e maxNSU
- `buscar_por_chave.php` - tentativa de consulta/manifestacao por chave
- `status_robot.json` - estado local de controle preventivo

## Requisitos

- PHP 8+ com extensoes:
  - `pdo_pgsql`
  - `openssl`
  - `zlib`
  - `dom`
- PostgreSQL 12+
- Certificado digital A1 valido
- Acesso de rede aos webservices da SEFAZ

## Instalacao

```bash
composer install
```

## Banco de Dados (base sugerida)

Crie o banco e as tabelas utilizadas pelos scripts:

```sql
CREATE DATABASE nfe_monitor;

-- Execute os comandos abaixo dentro do banco nfe_monitor

CREATE TABLE IF NOT EXISTS config_monitor (
    campo VARCHAR(50) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL
);

INSERT INTO config_monitor (campo, valor)
VALUES ('ultimo_nsu', '0')
ON CONFLICT (campo) DO NOTHING;

CREATE TABLE IF NOT EXISTS notas_fiscais (
    id BIGSERIAL PRIMARY KEY,
    chNFe VARCHAR(44) UNIQUE NOT NULL,
    cnpj_emitente VARCHAR(14),
    nome_emitente TEXT,
    valor_nota NUMERIC(15, 2),
    data_emissao TIMESTAMP,
    nsu VARCHAR(20),
    criado_em TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS registros_robot (
    id BIGSERIAL PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    descricao TEXT NOT NULL,
    nsu VARCHAR(20),
    criado_em TIMESTAMP DEFAULT NOW()
);
```

## Configuracao

Atualize `config.php` com os dados do emissor e certificado:

- `tpAmb` (1 producao / 2 homologacao)
- `cnpj`, `siglaUF`, `razaosocial`
- `pfx_path`
- `pfx_password`

Tambem ajuste credenciais PostgreSQL nos scripts (`PDO`), conforme seu ambiente.

## Execucao

Rodar monitor principal:

```bash
php monitor.php
```

Rodar testes utilitarios:

```bash
php teste_sefaz.php
php debug_nfe.php
php checar_distancia.php
```

## Boas Praticas de Seguranca

Atualmente ha dados sensiveis em scripts (senha do certificado e credenciais de banco). Recomendado:

- mover segredos para variaveis de ambiente
- criar um `config.example.php` sem informacoes sensiveis
- ignorar arquivos locais sensiveis no Git (`.gitignore`)

## Roadmap (Melhorias Recomendadas)

- [ ] Migrar configuracoes sensiveis para `.env`
- [ ] Adicionar `README` de deploy (Linux/Windows)
- [ ] Agendar execucao com cron/Task Scheduler
- [ ] Adicionar testes automatizados de parser XML
- [ ] Estruturar logs em arquivo e observabilidade

## Licenca

Defina a licenca do projeto (ex.: MIT) para distribuicao no GitHub.

