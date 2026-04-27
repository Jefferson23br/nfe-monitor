<?php
// CONFIGURAÇÕES DE ACESSO
$host = 'localhost';
$db   = 'nfe_monitor';
$user = 'postgres';
$pass = '356985Dp@'; // <--- COLOQUE SUA SENHA DO POSTGRES

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Lógica para o botão "Atualizar Agora"
    $mensagem = "";
    if (isset($_POST['atualizar'])) {
        // Executa o monitor.php e captura a saída
        $saida = shell_exec("php /root/nfe-monitor/monitor.php 2>&1");
        $mensagem = "<div class='alert alert-info'><strong>Resultado do Robô:</strong><br><pre>$saida</pre></div>";
    }

    // Busca estatísticas
    $total_notas = $pdo->query("SELECT count(*) FROM notas_fiscais")->fetchColumn();
    $ultimo_nsu = $pdo->query("SELECT valor FROM config_monitor WHERE campo = 'ultimo_nsu'")->fetchColumn();
    
    // Busca as notas
    $stmt = $pdo->query("SELECT * FROM notas_fiscais ORDER BY data_emissao DESC LIMIT 50");
    $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao conectar: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Painel NFe Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1>Monitor de Notas Fiscais</h1>
            <p class="text-muted">Acompanhamento em tempo real (PostgreSQL)</p>
        </div>
        <div class="col-md-4 text-end">
            <form method="post">
                <button type="submit" name="atualizar" class="btn btn-primary btn-lg">🚀 Atualizar Agora</button>
            </form>
        </div>
    </div>

    <?php echo $mensagem; ?>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card text-white bg-dark">
                <div class="card-body text-center">
                    <h5 class="card-title">Último NSU Processado</h5>
                    <p class="card-text display-6"><?php echo $ultimo_nsu; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-white bg-success">
                <div class="card-body text-center">
                    <h5 class="card-title">Total de Notas no Banco</h5>
                    <p class="card-text display-6"><?php echo $total_notas; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-body">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Emissão</th>
                        <th>Emitente</th>
                        <th>Valor</th>
                        <th>Chave de Acesso</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($notas) > 0): ?>
                        <?php foreach ($notas as $nota): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($nota['data_emissao'])); ?></td>
                            <td><?php echo htmlspecialchars((string)$nota['nome_emitente'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>R$ <?php echo number_format($nota['valor_nota'], 2, ',', '.'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)$nota['chnfe'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="baixar_xml.php?chave=<?php echo urlencode((string)$nota['chnfe']); ?>"
                                >
                                    Baixar XML
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">Nenhuma nota encontrada ainda. <br> O robô está processando a fila de eventos da SEFAZ.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
<h3 class="mt-5">Atividade Recente do Robô</h3>
<div class="table-responsive">
    <table class="table table-sm table-hover border">
        <thead class="table-light">
            <tr>
                <th>Data/Hora</th>
                <th>Tipo</th>
                <th>Descrição</th>
                <th>NSU</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $logs = $pdo->query("SELECT * FROM registros_robot ORDER BY id DESC LIMIT 10")->fetchAll();
            foreach ($logs as $log) {
                $cor = ($log['tipo'] == 'NOTA ENCONTRADA') ? 'text-success font-weight-bold' : '';
                echo "<tr class='$cor'>";
                echo "<td>" . date('d/m H:i', strtotime($log['data_registro'])) . "</td>";
                echo "<td>{$log['tipo']}</td>";
                echo "<td>{$log['descricao']}</td>";
                echo "<td>{$log['nsu']}</td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
</div>
        </div>
    </div>
</div>
</body>
</html>
