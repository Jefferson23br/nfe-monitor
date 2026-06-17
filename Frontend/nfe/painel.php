<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/bootstrap.php';

auth_require_empresa();

$user = auth_user();
$empresaId = (int) $user['empresa_id'];
$mensagem = '';

function painel_format_cnpj(string $cnpj): string
{
    $c = preg_replace('/\D/', '', $cnpj);
    if (strlen($c) !== 14) {
        return $cnpj;
    }

    return substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/'
        . substr($c, 8, 4) . '-' . substr($c, 12, 2);
}

function painel_log_badge_class(string $tipo): string
{
    return match (strtoupper($tipo)) {
        'CONSULTA' => 'dash-badge--consulta',
        'SYNC' => 'dash-badge--sync',
        'AJUSTE' => 'dash-badge--ajuste',
        default => 'dash-badge--default',
    };
}

function painel_iniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome));
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        if ($p !== '') {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }

    return $ini !== '' ? $ini : '?';
}

function painel_format_ultima_consulta(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return 'Aguardando primeira consulta';
    }
    try {
        return (new DateTimeImmutable((string) $raw))->format('d/m/Y H:i');
    } catch (Exception) {
        return (string) $raw;
    }
}

function painel_quota_badge_class(string $estado): string
{
    return match ($estado) {
        'vencida', 'esgotado' => 'dash-quota--danger',
        'valida', 'ok' => 'dash-quota--ok',
        default => 'dash-quota--neutral',
    };
}

function painel_aviso_consulta_bloqueada(array $bloqueio, array $licencaInfo, array $quotaLogs): string
{
    return match ($bloqueio['codigo'] ?? '') {
        'licenca_vencida' => 'Licença expirada em ' . $licencaInfo['texto']
            . '. Renovação necessária para retomar consultas.',
        'logs_esgotados' => 'Cota de consultas esgotada'
            . ($quotaLogs['limite'] !== null
                ? ' (' . number_format($quotaLogs['usado'], 0, ',', '.') . '/'
                    . number_format($quotaLogs['limite'], 0, ',', '.') . ').'
                : '.'),
        'inativa' => 'Empresa inativa. Consultas suspensas.',
        default => 'Consultas temporariamente indisponíveis.',
    };
}

function painel_parse_data_filtro(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return null;
    }

    return $raw;
}

/** @return array{0: string, 1: array<string, mixed>} */
function painel_montar_filtro_notas(int $empresaId, ?string $dataInicio, ?string $dataFim, string $fornecedor): array
{
    $where = ['empresa_id = :e'];
    $params = ['e' => $empresaId];

    if ($dataInicio !== null) {
        $where[] = 'data_emissao >= :data_inicio';
        $params['data_inicio'] = $dataInicio . ' 00:00:00';
    }
    if ($dataFim !== null) {
        $where[] = 'data_emissao <= :data_fim';
        $params['data_fim'] = $dataFim . ' 23:59:59';
    }
    if ($fornecedor !== '') {
        $where[] = 'nome_emitente ILIKE :fornecedor ESCAPE \'\\\'';
        $params['fornecedor'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fornecedor) . '%';
    }

    return [implode(' AND ', $where), $params];
}

try {
    empresa_garantir_schema();
    $pdo = db();

    $empresaRow = monitor_empresa_por_id($empresaId);
    $totalLogs = empresa_contar_logs($empresaId);
    $licencaInfo = empresa_format_validade_licenca($empresaRow);
    $quotaLogs = empresa_quota_logs($empresaRow, $totalLogs);
    $consultaPermitida = empresa_pode_consultar($empresaRow, $totalLogs);
    $limiteManualSefaz = monitor_limite_sefaz_consultas($empresaId);

    if (isset($_POST['atualizar'])) {
        if (!$consultaPermitida['ok']) {
            $mensagem = '<div class="dash-alert dash-alert-danger dash-alert--compact" role="alert">'
                . htmlspecialchars(painel_aviso_consulta_bloqueada($consultaPermitida, $licencaInfo, $quotaLogs))
                . '</div>';
        } elseif (!$limiteManualSefaz['ok']) {
            $mensagem = '<div class="dash-alert dash-alert-danger dash-alert--compact" role="alert">'
                . htmlspecialchars($limiteManualSefaz['mensagem'] ?? 'Consulta manual temporariamente indisponível.')
                . '</div>';
        } else {
            set_time_limit(180);
            try {
                $saida = monitor_executar($empresaId, true);
                $classe = str_starts_with($saida, 'ERRO:') || str_starts_with($saida, '⏳')
                    ? 'dash-alert-warning' : 'dash-alert-info';
                $mensagem = '<div class="dash-alert ' . $classe . '"><strong>Resultado da consulta</strong><pre>'
                    . htmlspecialchars($saida) . '</pre></div>';
                $totalLogs = empresa_contar_logs($empresaId);
                $quotaLogs = empresa_quota_logs($empresaRow, $totalLogs);
                $consultaPermitida = empresa_pode_consultar($empresaRow, $totalLogs);
                $limiteManualSefaz = monitor_limite_sefaz_consultas($empresaId);
            } catch (Throwable $e) {
                $mensagem = '<div class="dash-alert dash-alert-danger"><strong>Falha na consulta</strong> '
                    . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }

    if (isset($_POST['atualizar_certificado'])) {
        $resultCert = empresa_atualizar_certificado(
            $empresaId,
            $_POST['senha_cert'] ?? '',
            $_FILES['certificado'] ?? []
        );
        if ($resultCert['ok']) {
            $mensagem = '<div class="dash-alert dash-alert-success"><strong>Certificado instalado com sucesso.</strong> '
                . 'A consulta na SEFAZ já pode ser utilizada.</div>';
        } else {
            $mensagem = '<div class="dash-alert dash-alert-danger"><strong>Certificado</strong> — '
                . htmlspecialchars($resultCert['erro'] ?? 'Erro ao salvar.') . '</div>';
        }
    }

    $certStatus = empresa_certificado_status($empresaRow);

    $stmt = $pdo->prepare('SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = :c');

    $stmt->execute(['e' => $empresaId, 'c' => 'ultimo_nsu']);
    $ultimoNsu = $stmt->fetchColumn() ?: '0';
    $proximoNsu = monitor_nsu_pad((int) $ultimoNsu + 1);

    $stmt->execute(['e' => $empresaId, 'c' => 'ultima_consulta_at']);
    $ultimaConsultaAt = $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT count(*) FROM notas_fiscais WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    $totalNotas = (int) $stmt->fetchColumn();

    $filtroDataInicioInput = trim((string) ($_GET['data_inicio'] ?? ''));
    $filtroDataFimInput = trim((string) ($_GET['data_fim'] ?? ''));
    $filtroFornecedorInput = trim((string) ($_GET['fornecedor'] ?? ''));
    $filtroDataInicio = painel_parse_data_filtro($filtroDataInicioInput !== '' ? $filtroDataInicioInput : null);
    $filtroDataFim = painel_parse_data_filtro($filtroDataFimInput !== '' ? $filtroDataFimInput : null);
    $filtroAtivo = $filtroDataInicio !== null
        || $filtroDataFim !== null
        || $filtroFornecedorInput !== '';

    [$whereNotas, $paramsNotas] = painel_montar_filtro_notas(
        $empresaId,
        $filtroDataInicio,
        $filtroDataFim,
        $filtroFornecedorInput
    );

    $stmt = $pdo->prepare("SELECT count(*) FROM notas_fiscais WHERE {$whereNotas}");
    $stmt->execute($paramsNotas);
    $totalNotasFiltradas = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT * FROM notas_fiscais WHERE {$whereNotas} ORDER BY data_emissao DESC NULLS LAST LIMIT 50"
    );
    $stmt->execute($paramsNotas);
    $notas = $stmt->fetchAll();

    $logs = monitor_logs_listar($empresaId, 100);
    $totalLogs = empresa_contar_logs($empresaId);
    $quotaLogs = empresa_quota_logs($empresaRow, $totalLogs);
    $consultaPermitida = empresa_pode_consultar($empresaRow, $totalLogs);
    $limiteManualSefaz = monitor_limite_sefaz_consultas($empresaId);
} catch (PDOException $e) {
    die('Erro ao conectar: ' . htmlspecialchars($e->getMessage()));
} catch (Throwable $e) {
    die('Erro no painel: ' . htmlspecialchars($e->getMessage()));
}

$cnpjFmt = painel_format_cnpj((string) $user['empresa_cnpj']);
$iniciais = painel_iniciais((string) $user['nome']);
$ultimaConsultaFmt = painel_format_ultima_consulta($ultimaConsultaAt !== false ? (string) $ultimaConsultaAt : null);
$podeConsultar = (bool) ($consultaPermitida['ok'] ?? false);
$podeConsultarManual = $podeConsultar && (bool) ($limiteManualSefaz['ok'] ?? false);
$avisoConsultaManual = $limiteManualSefaz['mensagem']
    ?? 'Consulta manual temporariamente indisponível.';
$avisoConsultaBotao = !$podeConsultar
    ? $avisoConsulta
    : (!$podeConsultarManual ? $avisoConsultaManual : '');
$licencaBadge = painel_quota_badge_class($licencaInfo['estado']);
$logsBadge = painel_quota_badge_class($quotaLogs['estado']);
$logsKpiSub = $quotaLogs['limite'] === null
    ? 'Limite indeterminado · histórico de consultas NSU'
    : 'Limite total de ' . number_format($quotaLogs['limite'], 0, ',', '.') . ' consultas NSU';
$avisoConsulta = painel_aviso_consulta_bloqueada($consultaPermitida, $licencaInfo, $quotaLogs);
$dashboardCssPath = __DIR__ . '/assets/css/dashboard.css';
$dashboardCssVer = url_path('assets/css/dashboard.css');
if (is_file($dashboardCssPath)) {
    $dashboardCssVer .= '?v=' . (string) filemtime($dashboardCssPath);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel · <?= htmlspecialchars($user['empresa_nome']) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(url_path('assets/img/emblema.png')) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($dashboardCssVer) ?>">
</head>
<body class="dash-page">

<header class="dash-header">
    <div class="dash-header-inner">
        <div class="dash-brand">
            <img class="dash-logo" src="<?= htmlspecialchars(url_path('assets/img/emblema.png')) ?>" width="40" height="40" alt="NF Monitor">
            <div class="dash-brand-text">
                <h1><?= htmlspecialchars($user['empresa_nome']) ?></h1>
                <p>CNPJ <?= htmlspecialchars($cnpjFmt) ?> · Empresa #<?= (int) $empresaId ?></p>
            </div>
        </div>
        <div class="dash-header-meta" aria-label="Validade da licença e limite de logs">
            <div class="dash-quota <?= $licencaBadge ?>">
                <span class="dash-quota-label">Validade da licença</span>
                <strong><?= htmlspecialchars($licencaInfo['texto']) ?></strong>
            </div>
            <div class="dash-quota <?= $logsBadge ?>">
                <span class="dash-quota-label">Logs NSU</span>
                <strong><?= htmlspecialchars($quotaLogs['texto']) ?></strong>
                <?php if ($quotaLogs['limite'] !== null): ?>
                <span class="dash-quota-hint">de <?= number_format($quotaLogs['limite'], 0, ',', '.') ?></span>
                <?php else: ?>
                <span class="dash-quota-hint">indeterminado</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="dash-user">
            <div class="dash-user-info">
                <strong><?= htmlspecialchars($user['nome']) ?></strong>
                <span><?= htmlspecialchars($user['email']) ?></span>
            </div>
            <div class="dash-avatar" title="<?= htmlspecialchars($user['nome']) ?>"><?= htmlspecialchars($iniciais) ?></div>
            <a href="<?= htmlspecialchars(auth_suporte_url()) ?>" target="_blank" rel="noopener noreferrer"
               class="dash-btn-suporte">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                Suporte
            </a>
            <a href="<?= htmlspecialchars(auth_logout_url()) ?>" class="dash-btn-logout">Sair</a>
        </div>
    </div>
</header>

<main class="dash-main">

    <div class="dash-toolbar">
        <div class="dash-toolbar-meta">
            <span class="dash-pill">
                <span class="dash-pill-dot" aria-hidden="true"></span>
                <?= $podeConsultar ? 'Robô ativo · consNSU contínuo a cada 3h (até 18/h)' : 'Consultas suspensas' ?>
            </span>
            <span class="dash-pill">Última consulta: <?= htmlspecialchars($ultimaConsultaFmt) ?></span>
            <span class="dash-pill" title="Limite SEFAZ desta empresa (certificado próprio)">
                Consultas/hora: <?= (int) $limiteManualSefaz['contagem'] ?>/<?= (int) $limiteManualSefaz['limite'] ?>
            </span>
        </div>
        <div class="dash-actions">
            <form method="post" class="m-0">
                <button type="submit" name="atualizar" class="dash-btn dash-btn-primary"
                        <?= $podeConsultarManual ? '' : 'disabled title="' . htmlspecialchars($avisoConsultaBotao) . '"' ?>>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Consultar NSU agora
                </button>
            </form>
            <a href="<?= htmlspecialchars(danfe_visualizador_url()) ?>" target="_blank" rel="noopener noreferrer"
               class="dash-btn dash-btn-outline">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Visualizador DANFE
            </a>
        </div>
    </div>

    <?= $mensagem ?>

    <?php if (!$podeConsultar): ?>
    <div class="dash-notice" role="status">
        <?= htmlspecialchars($avisoConsulta) ?>
    </div>
    <?php elseif (!$podeConsultarManual): ?>
    <div class="dash-notice" role="status">
        <?= htmlspecialchars($avisoConsultaManual) ?>
    </div>
    <?php endif; ?>

    <section class="dash-kpi-grid" aria-label="Indicadores">
        <article class="dash-kpi">
            <div class="dash-kpi-icon dash-kpi-icon--nsu" aria-hidden="true">#</div>
            <div class="dash-kpi-body">
                <div class="dash-kpi-label">Último NSU gravado</div>
                <div class="dash-kpi-value mono"><?= htmlspecialchars((string) $ultimoNsu) ?></div>
                <div class="dash-kpi-sub">Posição na fila SEFAZ</div>
            </div>
        </article>
        <article class="dash-kpi">
            <div class="dash-kpi-icon dash-kpi-icon--next" aria-hidden="true">→</div>
            <div class="dash-kpi-body">
                <div class="dash-kpi-label">Próxima consulta</div>
                <div class="dash-kpi-value mono"><?= htmlspecialchars($proximoNsu) ?></div>
                <div class="dash-kpi-sub">NSU que será consultado</div>
            </div>
        </article>
        <article class="dash-kpi">
            <div class="dash-kpi-icon dash-kpi-icon--notes" aria-hidden="true">📄</div>
            <div class="dash-kpi-body">
                <div class="dash-kpi-label">Notas capturadas</div>
                <div class="dash-kpi-value"><?= number_format($totalNotas, 0, ',', '.') ?></div>
                <div class="dash-kpi-sub">Total no banco de dados</div>
            </div>
        </article>
        <article class="dash-kpi">
            <div class="dash-kpi-icon dash-kpi-icon--time" aria-hidden="true">⏱</div>
            <div class="dash-kpi-body">
                <div class="dash-kpi-label">Registros de log</div>
                <div class="dash-kpi-value"><?= htmlspecialchars($quotaLogs['texto']) ?></div>
                <div class="dash-kpi-sub"><?= htmlspecialchars($logsKpiSub) ?></div>
            </div>
        </article>
    </section>

    <section class="dash-panel">
        <div class="dash-panel-head">
            <h2>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Certificado digital A1
            </h2>
            <?php if ($certStatus['ok']): ?>
                <span class="dash-badge dash-badge--consulta">Ativo</span>
            <?php else: ?>
                <span class="dash-badge" style="background:var(--dash-danger-soft);color:var(--dash-danger)">Pendente</span>
            <?php endif; ?>
        </div>
        <div class="dash-panel-body">
            <div class="dash-cert">
                <div class="dash-cert-status">
                    <span class="dash-cert-indicator <?= $certStatus['ok'] ? 'dash-cert-indicator--ok' : 'dash-cert-indicator--err' ?>" aria-hidden="true"></span>
                    <div>
                        <?php if ($certStatus['ok']): ?>
                            <p class="dash-cert-msg ok"><?= htmlspecialchars($certStatus['mensagem']) ?></p>
                        <?php else: ?>
                            <p class="dash-cert-msg err">
                                <strong>Certificado ausente ou inacessível.</strong>
                                <?= htmlspecialchars($certStatus['mensagem']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$certStatus['ok']): ?>
                <form method="post" enctype="multipart/form-data" class="dash-cert-form">
                    <div class="dash-field">
                        <label for="certificado">Arquivo .pfx / .p12</label>
                        <input type="file" id="certificado" name="certificado" accept=".pfx,.p12" required>
                    </div>
                    <div class="dash-field">
                        <label for="senha_cert">Senha do certificado</label>
                        <input type="password" id="senha_cert" name="senha_cert" required autocomplete="off">
                    </div>
                    <button type="submit" name="atualizar_certificado" class="dash-btn dash-btn-danger">
                        Instalar certificado
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="dash-panel">
        <div class="dash-panel-head">
            <h2>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Busca de notas fiscais
            </h2>
            <?php if ($filtroAtivo): ?>
                <span class="dash-badge dash-badge--consulta"><?= number_format($totalNotasFiltradas, 0, ',', '.') ?> encontrada(s)</span>
            <?php else: ?>
                <span class="subtitle">Filtre por período de emissão e fornecedor</span>
            <?php endif; ?>
        </div>
        <div class="dash-panel-body">
            <form method="get" class="dash-busca-grid" action="">
                <div class="dash-field">
                    <label for="data_inicio">Emissão de</label>
                    <input type="date" id="data_inicio" name="data_inicio"
                           value="<?= htmlspecialchars($filtroDataInicioInput) ?>">
                </div>
                <span class="dash-busca-ate" aria-hidden="true">até</span>
                <div class="dash-field">
                    <label for="data_fim">Emissão até</label>
                    <input type="date" id="data_fim" name="data_fim"
                           value="<?= htmlspecialchars($filtroDataFimInput) ?>">
                </div>
                <div class="dash-field dash-busca-fornecedor">
                    <label for="fornecedor">Fornecedor</label>
                    <input type="text" id="fornecedor" name="fornecedor"
                           value="<?= htmlspecialchars($filtroFornecedorInput) ?>"
                           placeholder="Razão social do emitente">
                </div>
                <div class="dash-busca-actions">
                    <button type="submit" class="dash-btn dash-btn-primary">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Buscar
                    </button>
                    <?php if ($filtroAtivo): ?>
                    <a href="<?= htmlspecialchars(url_path('painel.php')) ?>" class="dash-btn dash-btn-outline">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="dash-panel">
        <div class="dash-panel-head">
            <h2>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Notas fiscais
            </h2>
            <span class="subtitle">
                <?php if ($filtroAtivo): ?>
                    <?= count($notas) ?> exibidas · <?= number_format($totalNotasFiltradas, 0, ',', '.') ?> encontrada(s) · <?= number_format($totalNotas, 0, ',', '.') ?> no total
                <?php else: ?>
                    <?= count($notas) ?> exibidas · <?= number_format($totalNotas, 0, ',', '.') ?> no total
                <?php endif; ?>
            </span>
        </div>
        <div class="dash-panel-body dash-panel-body--flush">
            <?php if ($notas): ?>
            <div class="dash-table-wrap">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Emissão</th>
                            <th>Emitente</th>
                            <th>Valor</th>
                            <th>Chave de acesso</th>
                            <th>Download</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($notas as $nota):
                        $chave = (string) ($nota['chnfe'] ?? $nota['chNFe'] ?? '');
                        $urlXml = $chave !== '' ? url_path('baixar_xml.php?chave=' . urlencode($chave)) : '';
                        $chaveCurta = strlen($chave) > 12
                            ? substr($chave, 0, 8) . '…' . substr($chave, -4)
                            : $chave;
                        ?>
                        <tr>
                            <td><?= $nota['data_emissao'] ? date('d/m/Y H:i', strtotime($nota['data_emissao'])) : '—' ?></td>
                            <td class="col-emitente" title="<?= htmlspecialchars((string) $nota['nome_emitente']) ?>">
                                <?= htmlspecialchars((string) $nota['nome_emitente']) ?>
                            </td>
                            <td class="col-valor">R$ <?= number_format((float) $nota['valor_nota'], 2, ',', '.') ?></td>
                            <td>
                                <?php if ($chave !== ''): ?>
                                <span class="dash-chave" data-copy-chave="<?= htmlspecialchars($chave) ?>"
                                      title="Clique para copiar a chave completa"><?= htmlspecialchars($chaveCurta) ?></span>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($urlXml !== ''): ?>
                                <a href="<?= htmlspecialchars($urlXml) ?>" class="dash-btn-xml" title="Baixar XML completo">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    XML
                                </a>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="dash-empty">
                <div class="dash-empty-icon" aria-hidden="true">📭</div>
                <?php if ($filtroAtivo): ?>
                <p>Nenhuma nota encontrada com os filtros informados.<br>
                    <a href="<?= htmlspecialchars(url_path('painel.php')) ?>">Limpar filtros</a> ou ajuste data/fornecedor.</p>
                <?php else: ?>
                <p>Nenhuma nota capturada ainda.<br>Use <strong>Consultar NSU agora</strong> ou aguarde o robô automático.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="dash-panel">
        <div class="dash-panel-head">
            <h2>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Histórico de consultas NSU
            </h2>
            <span class="subtitle"><?= count($logs) ?> de <?= number_format($totalLogs, 0, ',', '.') ?> registros</span>
        </div>
        <div class="dash-panel-body dash-panel-body--flush">
            <?php if ($logs): ?>
            <div class="dash-table-wrap">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tipo</th>
                            <th>Descrição</th>
                            <th>NSU ref.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log):
                        $tipo = (string) ($log['tipo'] ?? '');
                        ?>
                        <tr>
                            <td style="white-space:nowrap"><?= htmlspecialchars(monitor_log_data_hora($log)) ?></td>
                            <td>
                                <span class="dash-badge <?= painel_log_badge_class($tipo) ?>">
                                    <?= htmlspecialchars($tipo) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string) $log['descricao']) ?></td>
                            <td class="mono" style="font-family:'JetBrains Mono',monospace;font-size:0.75rem;color:var(--dash-muted)">
                                <?= htmlspecialchars((string) ($log['nsu'] ?? '')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="dash-empty">
                <div class="dash-empty-icon" aria-hidden="true">📋</div>
                <p>Nenhum registro de consulta ainda.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<script src="<?= htmlspecialchars(url_path('assets/js/dashboard.js')) ?>" defer></script>
</body>
</html>
