<?php
// --- CONFIGURAÇÃO E SEGURANÇA ---
session_start();

// Gerar Token CSRF se não existir
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Configuração de Diretórios
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

// Limpeza Automática (Arquivos > 2h)
$files = glob($uploadDir . "*");
$now = time();
if ($files) {
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) >= 7200)) {
            unlink($file);
        }
    }
}

// Inicialização de Variáveis
$resultado_html = "";
$json_dados_js = "null"; 
$swal_fire = "null"; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validação CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $swal_fire = json_encode([
            'icon' => 'error',
            'title' => 'Acesso Negado',
            'text' => 'Token de segurança inválido. Recarregue a página.'
        ]);
    } 
    elseif (isset($_FILES['planilha'])) {
        
        // Validação de Arquivo (MIME Type)
        $arquivoValido = false;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['planilha']['tmp_name']);
            $mimes_permitidos = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 
                'application/vnd.ms-excel',
                'application/octet-stream'
            ];
            if (in_array($mime, $mimes_permitidos)) $arquivoValido = true;
        } else {
            $ext = strtolower(pathinfo($_FILES['planilha']['name'], PATHINFO_EXTENSION));
            if ($ext === 'xlsx') $arquivoValido = true;
        }

        if (!$arquivoValido) {
            $swal_fire = json_encode([
                'icon' => 'warning',
                'title' => 'Arquivo Inválido',
                'text' => 'Por favor, envie apenas arquivos Excel (.xlsx).'
            ]);
        } else {
            // Sanitização e Upload
            $nomeOriginal = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['planilha']['name']));
            $novoNome = uniqid() . "_BASE_" . $nomeOriginal;
            $caminhoCompleto = $uploadDir . $novoNome;
            
            $caminhoAudit = "";
            if (isset($_FILES['audit_file']) && $_FILES['audit_file']['error'] === UPLOAD_ERR_OK) {
                $nomeAudit = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['audit_file']['name']));
                $novoNomeAudit = uniqid() . "_AUDIT_" . $nomeAudit;
                $caminhoAudit = $uploadDir . $novoNomeAudit;
                move_uploaded_file($_FILES['audit_file']['tmp_name'], $caminhoAudit);
            }

            if (move_uploaded_file($_FILES['planilha']['tmp_name'], $caminhoCompleto)) {
                
                // Execução do Python
                $arg1 = escapeshellarg($caminhoCompleto);
                $arg2 = $caminhoAudit ? " " . escapeshellarg($caminhoAudit) : "";
                $comando = "python processamento.py $arg1$arg2";
                
                // Captura saída
                $output = shell_exec($comando . " 2>&1");
                $linhas = explode("\n", trim($output));
                $json_str = end($linhas);
                $dados = json_decode($json_str, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($dados['sucesso']) && $dados['sucesso']) {
                    
                    // --- CORREÇÃO DO ERRO UNDEFINED KEY ---
                    // Agora pegamos os dados do cenário padrão (Planilha)
                    $statsPadrao = $dados['cenario_planilha']['stats'];
                    
                    $csvPath = $dados['arquivo'];
                    $totalGeral = $statsPadrao['total']; // Corrigido aqui
                    $temAudit = $dados['tem_audit_file'];
                    
                    // Passa o JSON completo para o JS manipular a troca de cenários
                    $json_dados_js = json_encode($dados);

                    // Alerta Sucesso
                    $swal_fire = json_encode([
                        'icon' => 'success',
                        'title' => 'Análise Concluída',
                        'text' => number_format($totalGeral, 0, ',', '.') . " drops processados com sucesso.",
                        'timer' => 2000,
                        'showConfirmButton' => false
                    ]);

                    // Renderização Inicial (PHP) - Baseada no Cenário Planilha
                    function renderTablePHP($data, $limit=27) {
                        $h=""; arsort($data); $i=0;
                        foreach($data as $k=>$v){
                            if($i++ >= $limit) break;
                            $k = $k ?: "N/D";
                            $v = number_format($v, 0, ',', '.');
                            $h .= "<tr><td><span class='row-label'>$k</span></td><td class='text-right font-mono'>$v</td></tr>";
                        }
                        return $h;
                    }

                    $tblVendedor = renderTablePHP($statsPadrao['por_vendedor']);
                    $tblSold = renderTablePHP($statsPadrao['por_sold']);
                    
                    // Lógica FAD Inicial
                    $tblFad = "";
                    if ($temAudit) {
                        $auditList = $dados['cenario_planilha']['audit'];
                        usort($auditList, function($a, $b){ return abs($b['diff']) - abs($a['diff']); });
                        foreach($auditList as $item) {
                            $nome = $item['fad']?:"N/D";
                            $calc = number_format($item['calculado'],0,',','.');
                            $esp = number_format($item['esperado'],0,',','.');
                            $diff = $item['diff'];
                            
                            $badgeClass = $diff==0 ? 'badge-success' : ($diff>0 ? 'badge-warning' : 'badge-danger');
                            $badgeTxt = $diff==0 ? 'OK' : ($diff>0 ? 'Excedente' : 'Faltante');
                            $diffTxt = $diff>0 ? "+$diff" : $diff;

                            $tblFad .= "<tr>
                                <td><a href='#' onclick=\"openFadDetails('$nome');return false;\" class='link-drill'>$nome</a></td>
                                <td class='text-right'>$calc</td>
                                <td class='text-right text-muted'>$esp</td>
                                <td class='text-right font-bold " . ($diff!=0?'text-danger':'text-success') . "'>$diffTxt</td>
                                <td class='text-center'><span class='badge $badgeClass'>$badgeTxt</span></td>
                            </tr>";
                        }
                        $headFad = "<tr><th>FAD</th><th class='text-right'>Real</th><th class='text-right'>Meta</th><th class='text-right'>Dif.</th><th class='text-center'>Status</th></tr>";
                    } else {
                        $headFad = "<tr><th>FAD</th><th class='text-right'>Qtd</th></tr>";
                        $fadData = $statsPadrao['por_fad'];
                        arsort($fadData);
                        foreach($fadData as $k=>$v) {
                            $v = number_format($v,0,',','.');
                            $tblFad .= "<tr><td><a href='#' onclick=\"openFadDetails('$k');return false;\" class='link-drill'>$k</a></td><td class='text-right font-mono'>$v</td></tr>";
                        }
                    }

                    $resultado_html = "
                    <div class='fade-in'>
                        <div class='download-card'>
                            <div class='d-flex align-center gap-3'>
                                <div class='icon-box success'>
                                    <svg width='24' height='24' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M22 11.08V12a10 10 0 1 1-5.93-9.14'/><polyline points='22 4 12 14.01 9 11.01'/></svg>
                                </div>
                                <div>
                                    <h3>Processamento Finalizado</h3>
                                    <p class='text-muted text-sm'>Base: <strong>$nomeOriginal</strong> " . ($temAudit ? " • Auditoria Ativa" : "") . "</p>
                                </div>
                            </div>
                            <a href='$csvPath' class='btn btn-outline btn-sm' download>
                                <svg width='18' height='18' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>
                                Baixar CSV
                            </a>
                        </div>

                        <div class='control-bar'>
                            <div class='control-group'>
                                <label class='label-sm'>Cenário de Dados:</label>
                                <div class='switch-toggle'>
                                    <input type='radio' id='viewPlanilha' name='viewScenario' value='planilha' checked onchange=\"waitAndProcess(switchScenario)\">
                                    <label for='viewPlanilha'>Planilha</label>
                                    
                                    <input type='radio' id='viewBanco' name='viewScenario' value='banco' onchange=\"waitAndProcess(switchScenario)\">
                                    <label for='viewBanco'>Banco SQL</label>
                                </div>
                            </div>
                            
                            <div class='control-actions'>
                                <button class='btn btn-subtle btn-sm' onclick=\"waitAndProcess(openAuditModal)\">
                                    <svg width='16' height='16' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M9 11l3 3L22 4'/><path d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/></svg>
                                    Auditoria Cadastral
                                </button>
                                <div class='metric-pill'>
                                    <span class='label'>Total Drops</span>
                                    <span class='value' id='totalDisplay'>" . number_format($totalGeral, 0, ',', '.') . "</span>
                                </div>
                            </div>
                        </div>

                        <div class='dashboard-grid'>
                            <div class='card data-card'>
                                <div class='card-header'>
                                    <h4><span class='icon-dot blue'></span>Por Vendedor</h4>
                                    <button class='btn-icon' onclick=\"waitAndProcess(function(){openModal('Vendedor')})\" title='Expandir'><svg width='16' height='16' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><polyline points='15 3 21 3 21 9'/><polyline points='9 21 3 21 3 15'/><line x1='21' y1='3' x2='14' y2='10'/><line x1='3' y1='21' x2='10' y2='14'/></svg></button>
                                </div>
                                <div class='table-responsive scroll-y'>
                                    <table class='table-clean' id='tblVendedor'>
                                        <thead><tr><th>Nome</th><th class='text-right'>Qtd</th></tr></thead>
                                        <tbody>$tblVendedor</tbody>
                                    </table>
                                </div>
                            </div>

                            <div class='card data-card'>
                                <div class='card-header'>
                                    <h4><span class='icon-dot purple'></span>Por Cliente (Sold)</h4>
                                    <button class='btn-icon' onclick=\"waitAndProcess(function(){openModal('Sold')})\" title='Expandir'><svg width='16' height='16' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><polyline points='15 3 21 3 21 9'/><polyline points='9 21 3 21 3 15'/><line x1='21' y1='3' x2='14' y2='10'/><line x1='3' y1='21' x2='10' y2='14'/></svg></button>
                                </div>
                                <div class='table-responsive scroll-y'>
                                    <table class='table-clean' id='tblSold'>
                                        <thead><tr><th>Cliente</th><th class='text-right'>Qtd</th></tr></thead>
                                        <tbody>$tblSold</tbody>
                                    </table>
                                </div>
                            </div>

                            <div class='card data-card full-width-mobile' " . ($temAudit ? "style='grid-column: 1 / -1;'" : "") . ">
                                <div class='card-header'>
                                    <h4><span class='icon-dot orange'></span>Performance FAD " . ($temAudit ? "<span class='badge badge-audit'>Auditado</span>" : "") . "</h4>
                                    <button class='btn-icon' onclick=\"waitAndProcess(function(){openModal('FAD')})\" title='Expandir'><svg width='16' height='16' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><polyline points='15 3 21 3 21 9'/><polyline points='9 21 3 21 3 15'/><line x1='21' y1='3' x2='14' y2='10'/><line x1='3' y1='21' x2='10' y2='14'/></svg></button>
                                </div>
                                <div class='table-responsive no-scroll'>
                                    <table class='table-clean table-hover' id='tblFad'>
                                        <thead id='headFad'>$headFad</thead>
                                        <tbody>$tblFad</tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>";

                } else {
                    $erroMsg = isset($dados['erro']) ? $dados['erro'] : $output;
                    $swal_fire = json_encode(['icon' => 'error', 'title' => 'Falha no Processamento', 'html' => "<div class='text-left text-sm text-danger bg-red-50 p-3 rounded'>$erroMsg</div>"]);
                }
            } else {
                $swal_fire = json_encode(['icon' => 'error', 'title' => 'Erro de Upload', 'text' => 'Não foi possível salvar o arquivo no servidor.']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SalesDrop Analytics</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Estilos -->
    <link rel="stylesheet" href="style.css">
    
    <!-- Libs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    
    <!-- Loader Global -->
    <div id="loadingOverlay" class="loading-overlay hidden">
        <div class="spinner-container">
            <div class="spinner"></div>
            <p class="loading-text">Processando dados...</p>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="navbar glass">
        <div class="container-fluid">
            <div class="logo-area">
                <!-- Se tiver logo, descomente: <img src="assets/logo.png" alt="Logo" class="logo-img"> -->
                <span class="brand-name">SalesDrop<span class="text-primary">.Analytics</span></span>
            </div>
            <ul class="nav-links">
                <li><a href="index.php" class="active">Dashboard</a></li>
                <li><a href="config.php">Configurações</a></li>
            </ul>
        </div>
    </nav>

    <!-- Conteúdo Principal -->
    <main class="container fade-in">
        
        <?php if (empty($resultado_html)): ?>
        <!-- Tela de Upload (Empty State) -->
        <div class="upload-wrapper">
            <div class="card upload-card">
                <div class="card-header text-center">
                    <h1>Nova Análise</h1>
                    <p class="text-muted">Importe seus dados para gerar insights instantâneos.</p>
                </div>
                
                <form method="POST" enctype="multipart/form-data" id="uploadForm" onsubmit="showLoading()">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="dropzone-container">
                        <div class="dropzone">
                            <label for="planilha" class="dropzone-label">
                                <div class="icon-upload">📂</div>
                                <span class="title">1. Planilha Base (Obrigatório)</span>
                                <span class="desc">Arraste ou clique para selecionar .xlsx</span>
                            </label>
                            <input type="file" name="planilha" id="planilha" required accept=".xlsx" class="file-input">
                        </div>

                        <div class="dropzone secondary">
                            <label for="audit_file" class="dropzone-label">
                                <div class="icon-upload">📊</div>
                                <span class="title">2. Auditoria (Opcional)</span>
                                <span class="desc">Arquivo de fechamento do cliente</span>
                            </label>
                            <input type="file" name="audit_file" id="audit_file" accept=".xlsx" class="file-input">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 mt-4">
                        <span>Iniciar Processamento</span>
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
            <!-- Tela de Resultados -->
            <div class="results-wrapper">
                <div class="header-actions mb-4">
                    <a href="index.php" class="btn btn-subtle">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Nova Análise
                    </a>
                </div>
                <?php echo $resultado_html; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- === MODAIS === -->
    
    <!-- Modal Detalhes (Genérico) -->
    <div id="detailModal" class="modal-backdrop hidden">
        <div class="modal-panel animate-slide-up">
            <div class="modal-header">
                <h3 id="modalTitle">Detalhes</h3>
                <button class="btn-close" onclick="closeModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="chart-wrapper">
                    <canvas id="detailChart"></canvas>
                </div>
                <div class="search-bar">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="searchInput" onkeyup="filterTable('modalTable')" placeholder="Filtrar registros...">
                </div>
                <div class="table-wrapper-modal">
                    <table id="modalTable" class="table-clean">
                        <thead id="modalTableHead"></thead>
                        <tbody id="modalTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <div class="pagination" id="detailPagination">
                    <button class="btn-page" onclick="changeDetailPage(-1)">←</button>
                    <span id="detailPageIndicator" class="page-info">1 / 1</span>
                    <button class="btn-page" onclick="changeDetailPage(1)">→</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Drill-Down (FAD) -->
    <div id="fadDrillModal" class="modal-backdrop hidden">
        <div class="modal-panel large animate-slide-up">
            <div class="modal-header">
                <h3 id="drillTitle">Drill Down</h3>
                <button class="btn-close" onclick="closeDrillModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="toolbar">
                    <span id="drillCount" class="badge badge-neutral">0 registros</span>
                    <div class="actions">
                        <button onclick="exportDrill('txt')" class="btn btn-sm btn-secondary">Exportar TXT</button>
                        <button onclick="exportDrill('csv')" class="btn btn-sm btn-primary">Exportar Excel</button>
                    </div>
                </div>
                <div class="table-wrapper-modal">
                    <table id="drillTable" class="table-clean table-striped">
                        <thead></thead><tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <div class="pagination" id="drillPagination">
                    <button class="btn-page" id="btnPrev" onclick="changeDrillPage(-1)">←</button>
                    <span id="pageIndicator" class="page-info"></span>
                    <button class="btn-page" id="btnNext" onclick="changeDrillPage(1)">→</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Auditoria Cadastral -->
    <div id="auditModal" class="modal-backdrop hidden">
        <div class="modal-panel large animate-slide-up">
            <div class="modal-header">
                <h3 class="text-danger">Divergência Cadastral</h3>
                <button class="btn-close" onclick="closeAuditModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="toolbar">
                    <p class="text-sm text-muted">Clientes com classificação divergente (Planilha vs Banco)</p>
                    <div class="actions">
                        <button onclick="exportAudit('txt')" class="btn btn-sm btn-secondary">TXT</button>
                        <button onclick="exportAudit('csv')" class="btn btn-sm btn-primary">Excel</button>
                    </div>
                </div>
                <div class="table-wrapper-modal">
                    <table id="auditTable" class="table-clean">
                        <thead><tr><th>Cliente</th><th>Vendedor</th><th>Planilha</th><th>Banco</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Core -->
    <script>
        // Inicialização de Dados
        const rawData = <?php echo $json_dados_js ?: 'null'; ?>;
        const swalData = <?php echo $swal_fire ?: 'null'; ?>;

        // Feedback Inicial
        if (swalData) {
            Swal.fire({
                ...swalData,
                customClass: { popup: 'swal-modern-popup', confirmButton: 'btn btn-primary' },
                buttonsStyling: false
            });
        }

        // Variáveis de Estado
        let myChart = null;
        let currentDrillData = [], currentDetailData = [], currentFadName = "";
        let drillPage = 1, detailPage = 1, rowsPerPage = 100;
        let activeScenarioKey = 'cenario_planilha';

        document.addEventListener('DOMContentLoaded', () => { 
            if (rawData) switchScenario(); 
        });

        // --- UI UTILS ---
        function showLoading() { document.getElementById('loadingOverlay').classList.remove('hidden'); }
        function hideLoading() { document.getElementById('loadingOverlay').classList.add('hidden'); }
        
        function waitAndProcess(callback) {
            showLoading();
            setTimeout(() => {
                try { callback(); } catch(e) { console.error(e); }
                hideLoading();
            }, 50);
        }

        // Modais
        function closeModal() { document.getElementById('detailModal').classList.add('hidden'); }
        function closeDrillModal() { document.getElementById('fadDrillModal').classList.add('hidden'); }
        function closeAuditModal() { document.getElementById('auditModal').classList.add('hidden'); }
        
        // Fechar ao clicar fora
        window.onclick = function(e) {
            if(e.target.classList.contains('modal-backdrop')) e.target.classList.add('hidden');
        }

        // --- LÓGICA DE DADOS ---
        function switchScenario() {
            const isPlanilha = document.getElementById('viewPlanilha').checked;
            activeScenarioKey = isPlanilha ? 'cenario_planilha' : 'cenario_banco';
            const data = rawData[activeScenarioKey].stats;
            const auditData = rawData[activeScenarioKey].audit;
            const hasAudit = rawData.tem_audit_file;

            // Animação de troca de valor
            const totalEl = document.getElementById('totalDisplay');
            totalEl.style.opacity = 0;
            setTimeout(() => {
                totalEl.innerText = new Intl.NumberFormat('pt-BR').format(data.total);
                totalEl.style.opacity = 1;
            }, 150);

            renderMiniTable('tblVendedor', data.por_vendedor);
            renderMiniTable('tblSold', data.por_sold);
            renderFadTable(data.por_fad, auditData, hasAudit);
        }

        function renderMiniTable(tid, obj) {
            const tbody = document.querySelector('#'+tid+' tbody'); tbody.innerHTML="";
            Object.entries(obj).sort((a,b)=>b[1]-a[1]).slice(0,27).forEach(([k,v])=>{
                tbody.innerHTML+=`<tr><td><span class='row-title'>${k}</span></td><td class='text-right font-mono'>${new Intl.NumberFormat('pt-BR').format(v)}</td></tr>`;
            });
        }

        function renderFadTable(obj, audit, has) {
            const tb = document.querySelector('#tblFad tbody'), th=document.getElementById('headFad'); tb.innerHTML="";
            if(has && audit.length){
                th.innerHTML="<tr><th>FAD</th><th class='text-right'>Calc.</th><th class='text-right'>Meta</th><th class='text-right'>Dif.</th><th class='text-center'>Status</th></tr>";
                audit.sort((a,b)=>Math.abs(b.diff)-Math.abs(a.diff));
                audit.forEach(i=>{
                    let nm=i.fad||"N/D", dd=i.diff>0?`+${i.diff}`:i.diff, bc=i.diff==0?"badge-success":(i.diff>0?"badge-warning":"badge-danger"), bt=i.diff==0?"OK":(i.diff>0?"Sobrou":"Faltou");
                    let lnk=`<a href="#" onclick="waitAndProcess(function(){openFadDetails('${nm}')});return false;" class="link-drill">${nm}</a>`;
                    tb.innerHTML+=`<tr><td>${lnk}</td><td class='text-right'>${i.calculado}</td><td class='text-right text-muted'>${i.esperado}</td><td class='text-right font-bold ${i.diff!=0?'text-danger':''}'>${dd}</td><td class='text-center'><span class='badge ${bc}'>${bt}</span></td></tr>`;
                });
            } else {
                th.innerHTML="<tr><th>FAD</th><th class='text-right'>Qtd</th></tr>";
                Object.entries(obj).sort((a,b)=>b[1]-a[1]).forEach(([k,v])=>{
                    let lnk=`<a href="#" onclick="waitAndProcess(function(){openFadDetails('${k}')});return false;" class="link-drill">${k}</a>`;
                    tb.innerHTML+=`<tr><td>${lnk}</td><td class='text-right font-mono'>${new Intl.NumberFormat('pt-BR').format(v)}</td></tr>`;
                });
            }
        }

        // --- AUDITORIA ---
        function openAuditModal() {
            const m=document.getElementById('auditModal'), tb=document.querySelector('#auditTable tbody'), l=rawData.lista_divergencia_cadastral||[];
            tb.innerHTML=""; 
            if(!l.length) tb.innerHTML="<tr><td colspan='4' class='text-center p-4 text-muted'>Nenhuma divergência encontrada. Tudo certo! 🎉</td></tr>";
            else {
                l.sort((a,b)=>(a.Desc_FAD_Planilha>b.Desc_FAD_Planilha)?1:-1);
                l.forEach(i=>{tb.innerHTML+=`<tr class='diff-row'><td><span class='font-medium'>${i.Cliente}</span></td><td>${i.Vendedor}</td><td class='text-danger'>${i.Desc_FAD_Planilha}<br><small class='text-muted'>ID: ${i.FAD_ID_Planilha}</small></td><td class='text-primary'>${i.Desc_FAD_Banco}<br><small class='text-muted'>ID: ${i.FAD_ID_Banco}</small></td></tr>`});
            }
            m.classList.remove('hidden');
        }

        // --- DRILL DOWN ---
        function openFadDetails(fadName) {
            currentFadName = fadName;
            const isP = (activeScenarioKey === 'cenario_planilha');
            currentDrillData = rawData.detalhes.filter(row => {
                if (isP) return row.fad_plan === fadName && row.valid_plan === true;
                else return row.fad_banc === fadName && row.valid_banc === true;
            });
            document.getElementById('drillTitle').innerText = `${fadName} (${isP?'Planilha':'Banco'})`;
            document.getElementById('drillCount').innerText = `${currentDrillData.length} registros`;
            document.querySelector('#drillTable thead').innerHTML = "<tr><th>Data</th><th>Cliente</th><th>Vend.</th><th class='text-right'>Valor</th><th class='text-right'>Min.</th></tr>";
            drillPage = 1; renderDrillTable();
            document.getElementById('fadDrillModal').classList.remove('hidden');
        }

        function renderDrillTable() {
            const tb = document.querySelector('#drillTable tbody'); tb.innerHTML = "";
            const isP = (activeScenarioKey === 'cenario_planilha');
            const start = (drillPage - 1) * rowsPerPage;
            const data = currentDrillData.slice(start, start + rowsPerPage);
            
            data.forEach(r => {
                let vf = new Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'}).format(r.valor);
                let mv = isP ? r.min_plan : r.min_banc;
                let mf = new Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'}).format(mv);
                tb.innerHTML += `<tr><td>${r.data}</td><td>${r.cliente}</td><td>${r.vendedor}</td><td class='text-right text-success font-bold'>${vf}</td><td class='text-right text-muted'>${mf}</td></tr>`;
            });
            updatePag(currentDrillData.length, drillPage, 'drillPagination', 'pageIndicator');
        }
        
        function changeDrillPage(d) {
            const total = Math.ceil(currentDrillData.length / rowsPerPage);
            if((d===-1 && drillPage>1) || (d===1 && drillPage<total)) { drillPage+=d; renderDrillTable(); }
        }

        // --- DETALHES GERAIS ---
        function openModal(type) {
            const m = document.getElementById('detailModal'), h = document.getElementById('modalTitle'), th = document.getElementById('modalTableHead');
            const stats = rawData[activeScenarioKey].stats, audit = rawData[activeScenarioKey].audit;
            const has = rawData.tem_audit_file, isAudit = (type === 'FAD' && has);
            let ds = {};

            if (type === 'Vendedor') { h.innerText = "Vendedores"; ds = stats.por_vendedor; th.innerHTML = "<tr><th>Nome</th><th class='text-right'>Qtd</th></tr>"; }
            else if (type === 'Sold') { h.innerText = "Clientes"; ds = stats.por_sold; th.innerHTML = "<tr><th>Cliente</th><th class='text-right'>Qtd</th></tr>"; }
            else { h.innerText = "Performance FAD"; 
                if(isAudit) { ds = audit; th.innerHTML = "<tr><th>FAD</th><th class='text-right'>Real</th><th class='text-right'>Meta</th><th class='text-right'>Dif</th><th class='text-center'>Status</th></tr>"; }
                else { ds = stats.por_fad; th.innerHTML = "<tr><th>FAD</th><th class='text-right'>Qtd</th></tr>"; }
            }

            currentDetailData = [];
            if(isAudit && Array.isArray(ds)) currentDetailData = ds.map(i => ({t:'a', ...i}));
            else currentDetailData = Object.entries(ds).sort((a,b)=>b[1]-a[1]).map(([k,v])=>({t:'s', k, v}));

            detailPage = 1; renderDetailTable();
            renderChart(type==='FAD'&&isAudit ? [] : currentDetailData.slice(0,10).map(x=>[x.k, x.v]));
            m.classList.remove('hidden');
        }

        function renderDetailTable() {
            const tb = document.getElementById('modalTableBody'); tb.innerHTML = "";
            const start = (detailPage - 1) * rowsPerPage;
            const data = currentDetailData.slice(start, start + rowsPerPage);

            data.forEach(i => {
                if(i.t==='a'){
                    let bc = i.diff==0?"badge-success":(i.diff>0?"badge-warning":"badge-danger"), lnk=`<a href="#" onclick="waitAndProcess(function(){openFadDetails('${i.fad}')});return false;" class="link-drill">${i.fad}</a>`;
                    tb.innerHTML+=`<tr><td>${lnk}</td><td class='text-right'>${i.calculado}</td><td class='text-right'>${i.esperado}</td><td class='text-right'>${i.diff}</td><td class='text-center'><span class='badge ${bc}'>!</span></td></tr>`;
                } else {
                    let c = document.getElementById('modalTitle').innerText.includes('FAD') ? `<a href="#" onclick="waitAndProcess(function(){openFadDetails('${i.k}')});return false;" class="link-drill">${i.k}</a>` : i.k;
                    tb.innerHTML+=`<tr><td>${c}</td><td class='text-right'>${new Intl.NumberFormat('pt-BR').format(i.v)}</td></tr>`;
                }
            });
            updatePag(currentDetailData.length, detailPage, 'detailPagination', 'detailPageIndicator');
        }
        function changeDetailPage(d) {
            const total = Math.ceil(currentDetailData.length / rowsPerPage);
            if((d===-1 && detailPage>1) || (d===1 && detailPage<total)) { detailPage+=d; renderDetailTable(); }
        }

        // --- HELPERS ---
        function updatePag(totalItems, curr, divId, txtId) {
            const total = Math.ceil(totalItems / rowsPerPage);
            const div = document.getElementById(divId);
            div.style.display = total > 1 ? 'flex' : 'none';
            if(total > 1) document.getElementById(txtId).innerText = `${curr} / ${total}`;
        }

        function renderChart(arr) {
            const ctx = document.getElementById('detailChart').getContext('2d');
            if(myChart) myChart.destroy();
            myChart = new Chart(ctx, { type:'bar', data:{labels:arr.map(x=>x[0]), datasets:[{label:'Qtd', data:arr.map(x=>x[1]), backgroundColor:'#3b82f6', borderRadius:4}]}, options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, grid:{display:false}}, x:{grid:{display:false}}} } });
        }

        function filterTable(tid) {
            const v = document.getElementById('searchInput').value.toUpperCase(), rows = document.getElementById(tid).querySelectorAll('tbody tr');
            rows.forEach(r => r.style.display = r.innerText.toUpperCase().includes(v) ? '' : 'none');
        }

        // Funções de Exportação (Idênticas ao anterior, compactadas)
        function downloadFile(c,n,m){const b=new Blob([c],{type:m}),l=document.createElement("a");l.href=URL.createObjectURL(b);l.download=n;l.click();}
        function exportAudit(type){
            const l=rawData.lista_divergencia_cadastral||[];if(!l.length)return;let c="";
            if(type==='csv'){c="Cliente;Vendedor;Planilha;Banco\n";l.forEach(i=>{c+=`${i.Cliente};${i.Vendedor};${i.Desc_FAD_Planilha};${i.Desc_FAD_Banco}\n`});downloadFile(c,"Audit.csv","text/csv;charset=utf-8;")}
            else{c="AUDITORIA\n----\n";l.forEach(i=>{c+=`Cli:${i.Cliente}|Plan:${i.Desc_FAD_Planilha}|Banco:${i.Desc_FAD_Banco}\n`});downloadFile(c,"Audit.txt","text/plain")}
        }
        function exportDrill(fmt){
            if(!currentDrillData.length)return;let c="", isP=(activeScenarioKey==='cenario_planilha');
            if(fmt==='csv'){c="Data;Cliente;Vendedor;Valor;Min\n";currentDrillData.forEach(r=>{let m=isP?r.min_plan:r.min_banc;c+=`${r.data};${r.cliente};${r.vendedor};${String(r.valor).replace('.',',')};${String(m).replace('.',',')}\n`});downloadFile(c,"Drill.csv","text/csv;charset=utf-8;")}
            else{c="DRILL\n----\n";currentDrillData.forEach(r=>{let m=isP?r.min_plan:r.min_banc;c+=`${r.data}|${r.cliente}|${r.valor}|${m}\n`});downloadFile(c,"Drill.txt","text/plain")}
        }
    </script>
</body>
</html>