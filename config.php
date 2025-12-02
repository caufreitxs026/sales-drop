<?php
session_start();

// 1. SEGURANÇA: Geração de Token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$arquivo_json = 'config_fads.json';
// Carrega ou cria array vazio se falhar
$fads = file_exists($arquivo_json) ? json_decode(file_get_contents($arquivo_json), true) : [];
if (!$fads) $fads = [];

$swal_fire = "null";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 2. SEGURANÇA: Validação do Token CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $swal_fire = json_encode([
            'icon' => 'error',
            'title' => 'Erro de Segurança',
            'text' => 'Token de sessão inválido. Recarregue a página.'
        ]);
    } else {
        // Adicionar Nova Regra
        if (isset($_POST['acao']) && $_POST['acao'] === 'adicionar') {
            $novo_cod = trim($_POST['novo_cod']);
            $nova_desc = trim($_POST['nova_desc']);
            $novo_min = floatval($_POST['novo_min']);

            if (!empty($novo_cod) && !empty($nova_desc)) {
                // 3. SEGURANÇA: Sanitização Rigorosa na Criação
                // Remove qualquer caractere que não seja letra, número, _ ou - do código
                $novo_cod = preg_replace("/[^a-zA-Z0-9_-]/", "", $novo_cod);
                // Remove tags HTML da descrição para evitar XSS armazenado
                $nova_desc = strip_tags($nova_desc);
                
                if (isset($fads[$novo_cod])) {
                    $swal_fire = json_encode([
                        'icon' => 'error',
                        'title' => 'Código Existente',
                        'text' => "O CODFAD $novo_cod já está cadastrado!"
                    ]);
                } else {
                    $fads[$novo_cod] = ['descricao' => $nova_desc, 'minimo' => $novo_min];
                    ksort($fads);
                    file_put_contents($arquivo_json, json_encode($fads, JSON_PRETTY_PRINT));
                    $swal_fire = json_encode([
                        'icon' => 'success',
                        'title' => 'Regra Adicionada',
                        'text' => 'Nova regra salva com sucesso!',
                        'timer' => 1500,
                        'showConfirmButton' => false
                    ]);
                }
            }
        }
        // Excluir Regra
        else if (isset($_POST['acao']) && $_POST['acao'] === 'excluir') {
            $cod = $_POST['cod_remover'];
            if (isset($fads[$cod])) {
                unset($fads[$cod]);
                file_put_contents($arquivo_json, json_encode($fads, JSON_PRETTY_PRINT));
                $swal_fire = json_encode([
                    'icon' => 'success',
                    'title' => 'Regra Removida',
                    'text' => 'O item foi excluído da lista.',
                    'timer' => 1500,
                    'showConfirmButton' => false
                ]);
            }
        }
        // Salvar Tabela Completa
        else if (isset($_POST['fads'])) {
            $novos = $_POST['fads'];
            foreach ($novos as $k => $v) {
                // Segurança: Garante que só altera chaves existentes
                if (isset($fads[$k])) {
                    $fads[$k]['minimo'] = floatval($v['minimo']);
                    // Sanitização ao salvar edição
                    $fads[$k]['descricao'] = strip_tags($v['descricao']); 
                }
            }
            file_put_contents($arquivo_json, json_encode($fads, JSON_PRETTY_PRINT));
            $swal_fire = json_encode([
                'icon' => 'success',
                'title' => 'Alterações Salvas',
                'text' => 'A tabela foi atualizada com sucesso.',
                'timer' => 1500,
                'showConfirmButton' => false
            ]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Configurações - Mirasol</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function confirmarExclusao(cod) {
            Swal.fire({
                title: 'Excluir Regra?',
                text: "Você está removendo o FAD " + cod + ". Essa ação não pode ser desfeita.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('form-excluir-' + cod).submit();
                }
            })
            return false;
        }
    </script>
</head>
<body>
    <nav class="navbar glass">
        <div class="container-fluid">
            <div class="logo-area">
                <span class="brand-name">SalesDrop<span class="text-primary">.Analytics</span></span>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="config.php" class="active">Configurações</a></li>
            </ul>
        </div>
    </nav>

    <div class="container fade-in">
        
        <!-- Formulário de Adição -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Nova Regra de Validação</h4>
            </div>
            <div style="padding: 24px;">
                <form method="POST">
                    <!-- 3. SEGURANÇA: Token CSRF no Form -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="acao" value="adicionar">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Código (CODFAD)</label>
                            <input type="text" name="novo_cod" class="form-control" placeholder="Ex: 99" required>
                        </div>
                        <div class="form-group" style="flex: 2;">
                            <label class="form-label">Descrição do Canal</label>
                            <input type="text" name="nova_desc" class="form-control" placeholder="Ex: Canal Especial" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Valor Mínimo (R$)</label>
                            <input type="number" step="0.01" name="novo_min" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="form-group" style="flex: 0;">
                            <button type="submit" class="btn btn-primary" style="height: 44px; margin-top: 24px;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right: 4px;"><path d="M12 5v14M5 12h14"/></svg>
                                Adicionar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabela de Edição -->
        <div class="card">
            <div class="card-header">
                <h4>Regras Ativas</h4>
                <button type="submit" form="form-tabela" class="btn btn-primary btn-sm">Salvar Alterações</button>
            </div>
            
            <form id="form-tabela" method="POST">
                <!-- 3. SEGURANÇA: Token CSRF no Form Tabela -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="table-responsive" style="max-height: 600px;">
                    <table class="table-clean table-hover">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Cód.</th>
                                <th>Descrição</th>
                                <th style="width: 20%; text-align: right;">Mínimo (R$)</th>
                                <th style="width: 80px; text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fads as $cod => $dados): ?>
                            <tr>
                                <!-- 4. SEGURANÇA: htmlspecialchars para prevenir XSS na exibição -->
                                <td class="font-bold text-primary"><?php echo htmlspecialchars($cod); ?></td>
                                <td>
                                    <input type="hidden" name="fads[<?php echo htmlspecialchars($cod); ?>][descricao]" value="<?php echo htmlspecialchars($dados['descricao']); ?>">
                                    <?php echo htmlspecialchars($dados['descricao']); ?>
                                </td>
                                <td style="text-align: right;">
                                    <input type="number" step="0.01" name="fads[<?php echo htmlspecialchars($cod); ?>][minimo]" value="<?php echo htmlspecialchars($dados['minimo']); ?>" class="form-control" style="text-align: right; padding: 6px 10px; width: 120px; display: inline-block;">
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="confirmarExclusao('<?php echo htmlspecialchars($cod); ?>')" class="btn-icon" title="Excluir">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <?php foreach ($fads as $cod => $dados): ?>
            <form id="form-excluir-<?php echo htmlspecialchars($cod); ?>" method="POST" style="display:none">
                <!-- 3. SEGURANÇA: Token CSRF no Form Excluir -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="cod_remover" value="<?php echo htmlspecialchars($cod); ?>">
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Inicializa SweetAlert se houver mensagem do PHP
        const swalData = <?php echo $swal_fire; ?>;
        if (swalData) {
            Swal.fire({
                ...swalData,
                customClass: {
                    confirmButton: 'btn btn-primary',
                    cancelButton: 'btn btn-secondary'
                },
                buttonsStyling: false
            });
        }
    </script>
</body>
</html>