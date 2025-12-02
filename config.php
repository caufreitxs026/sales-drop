<?php
$arquivo_json = 'config_fads.json';
$fads = json_decode(file_get_contents($arquivo_json), true);
$mensagem = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] === 'adicionar') {
        $novo_cod = trim($_POST['novo_cod']);
        $nova_desc = trim($_POST['nova_desc']);
        $novo_min = floatval($_POST['novo_min']);

        if (!empty($novo_cod) && !empty($nova_desc)) {
            if (isset($fads[$novo_cod])) {
                $mensagem = "<div class='card' style='border-left:4px solid #ef4444; color:#991b1b; background:#fef2f2; padding:15px;'>Erro: O CODFAD <strong>$novo_cod</strong> já existe!</div>";
            } else {
                $fads[$novo_cod] = ['descricao' => $nova_desc, 'minimo' => $novo_min];
                ksort($fads);
                file_put_contents($arquivo_json, json_encode($fads, JSON_PRETTY_PRINT));
                $mensagem = "<div class='card' style='border-left:4px solid #166534; color:#166534; background:#dcfce7; padding:15px;'>Regra adicionada com sucesso!</div>";
            }
        }
    }
    else if (isset($_POST['acao']) && $_POST['acao'] === 'excluir') {
        $cod_remover = $_POST['cod_remover'];
        if (isset($fads[$cod_remover])) {
            unset($fads[$cod_remover]);
            file_put_contents($arquivo_json, json_encode($fads, JSON_PRETTY_PRINT));
            $mensagem = "<div class='card' style='border-left:4px solid #166534; color:#166534; background:#dcfce7; padding:15px;'>Regra removida!</div>";
        }
    }
    else if (isset($_POST['fads'])) {
        $novos_dados = $_POST['fads'];
        foreach ($novos_dados as $key => $val) {
            if (isset($fads[$key])) {
                $fads[$key]['minimo'] = floatval($val['minimo']);
                $fads[$key]['descricao'] = $val['descricao']; 
            }
        }
        file_put_contents($arquivo_json, json_encode($fads, JSON_PRETTY_PRINT));
        $mensagem = "<div class='card' style='border-left:4px solid #166534; color:#166534; background:#dcfce7; padding:15px;'>Tabela atualizada com sucesso!</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Mirasol</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function confirmarExclusao(cod) {
            return confirm("Tem certeza que deseja excluir a regra do CODFAD " + cod + "?");
        }
    </script>
</head>
<body>

    <nav class="navbar">
        <div class="logo-container"><strong>SalesDrop</strong> Analytics</div>
        <ul class="nav-links">
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="config.php" class="active">Configurações</a></li>
        </ul>
    </nav>

    <div class="container">
        
        <?php echo $mensagem; ?>

        <!-- Nova Regra -->
        <div class="card">
            <h1>Cadastrar Nova Regra</h1>
            <p>Adicione um novo parâmetro de validação para o sistema.</p>
            <form method="POST" style="margin-top:20px;">
                <input type="hidden" name="acao" value="adicionar">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="novo_cod">CODFAD (Cód.)</label>
                        <input type="text" name="novo_cod" id="novo_cod" class="form-control" placeholder="Ex: 99" required>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label" for="nova_desc">Descrição</label>
                        <input type="text" name="nova_desc" id="nova_desc" class="form-control" placeholder="Nome do Canal" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="novo_min">Valor Mínimo (R$)</label>
                        <input type="number" step="0.01" name="novo_min" id="novo_min" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="form-group" style="flex: 0;">
                        <button type="submit" class="btn btn-primary" style="margin-top:0; height:42px;">Adicionar</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tabela Existente -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h2 style="margin:0;">Regras Ativas</h2>
                <button type="submit" form="form-tabela" class="btn btn-primary" style="margin:0; width:auto;">Salvar Alterações</button>
            </div>
            
            <form id="form-tabela" method="POST">
                <div class="table-container" style="max-height: 600px;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 10%;">CODFAD</th>
                                <th>Descrição</th>
                                <th style="width: 20%;">Mínimo (R$)</th>
                                <th style="width: 50px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fads as $cod => $dados): ?>
                            <tr>
                                <td style="font-weight:700; color:var(--text-main);"><?php echo $cod; ?></td>
                                <td>
                                    <input type="hidden" name="fads[<?php echo $cod; ?>][descricao]" value="<?php echo $dados['descricao']; ?>">
                                    <?php echo $dados['descricao']; ?>
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="fads[<?php echo $cod; ?>][minimo]" value="<?php echo $dados['minimo']; ?>" class="form-control" style="padding:6px;">
                                </td>
                                <td style="text-align:center;">
                                    <button type="submit" form="form-excluir-<?php echo $cod; ?>" class="btn-outline" style="border:none; color:#cbd5e1; padding:5px;" title="Excluir">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <?php foreach ($fads as $cod => $dados): ?>
            <form id="form-excluir-<?php echo $cod; ?>" method="POST" onsubmit="return confirmarExclusao('<?php echo $cod; ?>');" style="display:none;">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="cod_remover" value="<?php echo $cod; ?>">
            </form>
            <?php endforeach; ?>

        </div>

    </div>
</body>
</html>