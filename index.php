<?php
// index.php - Dashboard SalesDrop Analytics
$resultado_html = "";
$json_dados_js = "null"; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['planilha'])) {
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $nomeOriginal = basename($_FILES['planilha']['name']);
    $novoNome = uniqid() . "_BASE_" . $nomeOriginal;
    $caminhoCompleto = $uploadDir . $novoNome;
    
    $caminhoAudit = "";
    if (isset($_FILES['audit_file']) && $_FILES['audit_file']['error'] === UPLOAD_ERR_OK) {
        $nomeAudit = basename($_FILES['audit_file']['name']);
        $novoNomeAudit = uniqid() . "_AUDIT_" . $nomeAudit;
        $caminhoAudit = $uploadDir . $novoNomeAudit;
        move_uploaded_file($_FILES['audit_file']['tmp_name'], $caminhoAudit);
    }

    if (move_uploaded_file($_FILES['planilha']['tmp_name'], $caminhoCompleto)) {
        $arg1 = escapeshellarg($caminhoCompleto);
        $arg2 = $caminhoAudit ? " " . escapeshellarg($caminhoAudit) : "";
        $comando = "python processamento.py $arg1$arg2";
        $output = shell_exec($comando . " 2>&1");
        $linhas = explode("\n", trim($output));
        $json_str = end($linhas);
        $dados = json_decode($json_str, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($dados['sucesso']) && $dados['sucesso']) {
            $csvPath = $dados['arquivo'];
            $json_dados_js = json_encode($dados);
            $temAudit = $dados['tem_audit_file'];

            $resultado_html = "
            <div class='download-banner'>
                <div class='download-info'>
                    <h3>Processamento Concluído!</h3>
                    <small>Base: $nomeOriginal " . ($temAudit ? "| Auditoria Ativa" : "") . "</small>
                </div>
                <a href='$csvPath' class='btn btn-outline' download>
                    <svg width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>
                    Baixar Relatório CSV
                </a>
            </div>

            <div class='dashboard-controls'>
                <div class='scenario-toggle'>
                    <label>Fonte de Dados:</label>
                    <div class='toggle-group'>
                        <input type='radio' id='viewPlanilha' name='viewScenario' value='planilha' checked onchange=\"waitAndProcess(switchScenario)\">
                        <label for='viewPlanilha'>Planilha</label>
                        <input type='radio' id='viewBanco' name='viewScenario' value='banco' onchange=\"waitAndProcess(switchScenario)\">
                        <label for='viewBanco'>Banco</label>
                    </div>
                </div>
                
                <div style='display:flex; gap:15px; align-items:center;'>
                    <button class='btn btn-secondary' onclick=\"waitAndProcess(openAuditModal)\" style='margin:0; padding:8px 16px;'>
                        <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' style='margin-right:5px;'><path d='M2 12h20'/><path d='M2 12l5-5'/><path d='M2 12l5 5'/><path d='M22 12l-5-5'/><path d='M22 12l-5 5'/></svg>
                        Auditoria de Cadastro (FAD)
                    </button>
                    <span class='total-badge' id='totalDisplay'>Calculando...</span>
                </div>
            </div>
            
            <div class='dashboard-grid'>
                <div class='dash-card'>
                    <div class='dash-header'>
                        <div class='dash-title'>Por Vendedor</div>
                        <div class='dash-actions'><button onclick=\"waitAndProcess(function(){ openModal('Vendedor') })\">Ver detalhes</button></div>
                    </div>
                    <div class='table-container' style='max-height: 550px; overflow-y: auto;'>
                        <table id='tblVendedor'><thead><tr><th>Nome</th><th style='text-align:right'>Qtd</th></tr></thead><tbody></tbody></table>
                    </div>
                </div>
                <div class='dash-card'>
                    <div class='dash-header'>
                        <div class='dash-title'>Por Cliente (Sold)</div>
                        <div class='dash-actions'><button onclick=\"waitAndProcess(function(){ openModal('Sold') })\">Ver detalhes</button></div>
                    </div>
                    <div class='table-container' style='max-height: 550px; overflow-y: auto;'>
                        <table id='tblSold'><thead><tr><th>Cliente</th><th style='text-align:right'>Qtd</th></tr></thead><tbody></tbody></table>
                    </div>
                </div>
                <div class='dash-card' " . ($temAudit ? "style='grid-column: 1 / -1;'" : "") . ">
                    <div class='dash-header'>
                        <div class='dash-title'>Por FAD " . ($temAudit ? "<span class='badge badge-neutral' style='margin-left:8px'>Auditado</span>" : "") . "</div>
                        <div class='dash-actions'><button onclick=\"waitAndProcess(function(){ openModal('FAD') })\">Ver detalhes</button></div>
                    </div>
                    <div class='table-container' style='max-height: none;'>
                        <table id='tblFad'><thead id='headFad'></thead><tbody></tbody></table>
                    </div>
                </div>
            </div>";
        } else {
            $erroMsg = isset($dados['erro']) ? $dados['erro'] : $output;
            $resultado_html = "<div class='card error-box'><strong>Erro:</strong><pre>$erroMsg</pre></div>";
        }
    } else {
        $resultado_html = "<div class='card error-box'>Erro ao salvar arquivo.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SalesDrop Analytics</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 20px; }
        .scenario-toggle { display: flex; align-items: center; gap: 15px; background: #fff; padding: 8px 15px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .toggle-group { display: flex; background: #f1f5f9; padding: 4px; border-radius: 6px; }
        .toggle-group input { display: none; }
        .toggle-group label { padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 500; color: var(--text-light); transition: all 0.2s; }
        .toggle-group input:checked + label { background: #fff; color: var(--accent-color); box-shadow: 0 1px 3px rgba(0,0,0,0.1); font-weight: 600; }
        
        .modal-content { display: flex; flex-direction: column; max-height: 90vh; }
        .modal-header { flex-shrink: 0; background-color: #fff; z-index: 10; }
        .modal-body { flex-grow: 1; overflow: hidden; display: flex; flex-direction: column; padding: 25px 30px; }
        .modal-table-area { flex-grow: 1; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; scrollbar-width: thin; }
        .modal-footer { flex-shrink: 0; padding: 15px 30px; border-top: 1px solid #eee; background-color: #f9fafb; border-radius: 0 0 12px 12px; display: flex; justify-content: center; }
        .pagination { display: flex; align-items: center; gap: 15px; margin: 0; padding: 0; border: none; }
        .pagination button { padding: 6px 14px; cursor: pointer; }
        .pagination span { font-size: 0.9rem; color: var(--text-light); }
        .diff-row { background-color: #fff9f9; }
        .highlight-diff { color: var(--danger-text); font-weight: 600; }
        .sub-text { font-size: 0.8em; color: var(--text-light); font-weight: normal; }
    </style>
</head>
<body>
    <div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div><div class="loading-text">Processando...</div></div>

    <!-- Modal Resumo Geral -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h2 id="modalTitle">Detalhes</h2><span class="close-modal" onclick="closeModal()">&times;</span></div>
            <div class="modal-body">
                <div class="modal-charts-area"><canvas id="detailChart"></canvas></div>
                <input type="text" id="searchInput" class="form-control" onkeyup="filterTable('modalTable')" placeholder="Filtrar dados..." style="margin-bottom:15px;">
                <div class="modal-table-area" style="max-height:400px;">
                    <table id="modalTable"><thead id="modalTableHead"></thead><tbody id="modalTableBody"></tbody></table>
                </div>
            </div>
            <div class="modal-footer"><div class="pagination" id="detailPagination"><button onclick="changeDetailPage(-1)">Anterior</button><span id="detailPageIndicator"></span><button onclick="changeDetailPage(1)">Próxima</button></div></div>
        </div>
    </div>

    <!-- Modal Drill-down -->
    <div id="fadDrillModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header"><h2 id="drillTitle">Drill Down</h2><span class="close-modal" onclick="closeDrillModal()">&times;</span></div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; margin-bottom:15px;">
                    <div><span id="drillCount" class="badge badge-neutral"></span></div>
                    <div style="display:flex; gap:10px;">
                        <button onclick="exportDrill('txt')" class="btn btn-secondary" style="margin:0; padding:6px 12px;">TXT</button>
                        <button onclick="exportDrill('csv')" class="btn btn-primary" style="margin:0; padding:6px 12px;">Excel</button>
                    </div>
                </div>
                <div class="modal-table-area" style="max-height:none;"><table id="drillTable"><thead></thead><tbody></tbody></table></div>
            </div>
            <div class="modal-footer"><div class="pagination" id="drillPagination"><button id="btnPrev" onclick="changeDrillPage(-1)">Anterior</button><span id="pageIndicator"></span><button id="btnNext" onclick="changeDrillPage(1)">Próxima</button></div></div>
        </div>
    </div>

    <!-- Modal Auditoria de Cadastro -->
    <div id="auditModal" class="modal">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="modal-header">
                <h2 style="color:var(--danger-text)">Divergência de Cadastro</h2>
                <span class="close-modal" onclick="closeAuditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
                    <p style="margin:0">Clientes com FAD divergente (Planilha vs Banco)</p>
                    <div style="display:flex; gap:10px;">
                        <button onclick="exportAudit('txt')" class="btn btn-secondary" style="margin:0; padding:6px 12px;">TXT</button>
                        <button onclick="exportAudit('csv')" class="btn btn-primary" style="margin:0; padding:6px 12px;">Excel</button>
                    </div>
                </div>
                <div class="modal-table-area" style="max-height:500px;">
                    <table id="auditTable">
                        <thead><tr><th>Cliente</th><th>Vendedor</th><th>FAD Planilha</th><th>FAD Banco</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <nav class="navbar">
        <div class="logo-container"><strong>SalesDrop</strong> Analytics</div>
        <ul class="nav-links"><li><a href="index.php" class="active">Dashboard</a></li><li><a href="config.php">Config</a></li></ul>
    </nav>
    
    <div class="container">
        <?php if (empty($resultado_html)): ?>
        <div class="card">
            <h1>Nova Análise</h1>
            <form method="POST" enctype="multipart/form-data" onsubmit="showLoading()">
                <div class="upload-group">
                    <div class="upload-area"><label>1. Planilha Base</label><input type="file" name="planilha" required accept=".xlsx"></div>
                    <div class="upload-area"><label>2. Auditoria (Opcional)</label><input type="file" name="audit_file" accept=".xlsx"></div>
                </div>
                <button type="submit" class="btn btn-primary">Processar</button>
            </form>
        </div>
        <?php else: ?>
            <a href="index.php" class="btn btn-secondary">Nova Análise</a>
        <?php endif; ?>
        <?php echo $resultado_html; ?>
    </div>

    <script>
        const rawData = <?php echo $json_dados_js; ?>;
        let myChart = null;
        let currentDrillData = [], currentDetailData = []; 
        let currentFadName = "";
        let drillPage = 1, detailPage = 1;
        const rowsPerPage = 100;
        let activeScenarioKey = 'cenario_planilha'; 

        document.addEventListener('DOMContentLoaded', () => { if (rawData) switchScenario(); });

        function waitAndProcess(callback) {
            showLoading();
            setTimeout(function() { callback(); document.getElementById('loadingOverlay').style.display = 'none'; }, 50);
        }

        function switchScenario() {
            const isPlanilha = document.getElementById('viewPlanilha').checked;
            activeScenarioKey = isPlanilha ? 'cenario_planilha' : 'cenario_banco';
            const data = rawData[activeScenarioKey].stats;
            const auditData = rawData[activeScenarioKey].audit;
            const hasAudit = rawData.tem_audit_file;

            document.getElementById('totalDisplay').innerText = "Total: " + new Intl.NumberFormat('pt-BR').format(data.total) + " drops";
            renderMiniTable('tblVendedor', data.por_vendedor);
            renderMiniTable('tblSold', data.por_sold);
            renderFadTable(data.por_fad, auditData, hasAudit);
        }

        function renderMiniTable(tableId, dataObj) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            tbody.innerHTML = "";
            Object.entries(dataObj).sort((a, b) => b[1] - a[1]).slice(0, 27).forEach(([k, v]) => {
                tbody.innerHTML += `<tr><td>${k}</td><td style='text-align:right; font-weight:600;'>${new Intl.NumberFormat('pt-BR').format(v)}</td></tr>`;
            });
        }

        function renderFadTable(fadObj, auditList, hasAudit) {
            const table = document.getElementById('tblFad');
            const thead = document.getElementById('headFad');
            const tbody = table.querySelector('tbody');
            tbody.innerHTML = "";

            if (hasAudit && auditList.length > 0) {
                thead.innerHTML = "<tr><th>FAD</th><th style='text-align:right'>Calc.</th><th style='text-align:right'>Meta</th><th style='text-align:right'>Dif.</th><th style='text-align:center'>Status</th></tr>";
                auditList.sort((a, b) => Math.abs(b.diff) - Math.abs(a.diff));
                auditList.forEach(item => {
                    let nome = item.fad || "N/D", diffDisplay = item.diff > 0 ? `+${item.diff}` : item.diff;
                    let badgeClass = item.diff === 0 ? "badge-success" : (item.diff > 0 ? "badge-warning" : "badge-danger");
                    let badgeText = item.diff === 0 ? "OK" : (item.diff > 0 ? "Sobrou" : "Faltou");
                    let link = `<a href="#" onclick="waitAndProcess(function(){ openFadDetails('${nome}') }); return false;" style="color:var(--accent-color); text-decoration:none; font-weight:600;">${nome}</a>`;
                    tbody.innerHTML += `<tr><td>${link}</td><td style='text-align:right'>${item.calculado}</td><td style='text-align:right'>${item.esperado}</td><td style='text-align:right; font-weight:bold;'>${diffDisplay}</td><td style='text-align:center'><span class='badge ${badgeClass}'>${badgeText}</span></td></tr>`;
                });
            } else {
                thead.innerHTML = "<tr><th>FAD</th><th style='text-align:right'>Qtd</th></tr>";
                Object.entries(fadObj).sort((a, b) => b[1] - a[1]).forEach(([k, v]) => {
                    let link = `<a href="#" onclick="waitAndProcess(function(){ openFadDetails('${k}') }); return false;" style="color:var(--accent-color); text-decoration:none; font-weight:600;">${k}</a>`;
                    tbody.innerHTML += `<tr><td>${link}</td><td style='text-align:right; font-weight:600;'>${v}</td></tr>`;
                });
            }
        }

        function openAuditModal() {
            const modal = document.getElementById('auditModal');
            const tbody = document.querySelector('#auditTable tbody');
            const list = rawData.lista_divergencia_cadastral || [];
            tbody.innerHTML = "";
            if (list.length === 0) tbody.innerHTML = "<tr><td colspan='4' style='text-align:center; padding:20px;'>Nenhuma divergência encontrada.</td></tr>";
            else {
                list.sort((a, b) => (a.Desc_FAD_Planilha > b.Desc_FAD_Planilha) ? 1 : -1);
                list.forEach(item => {
                    tbody.innerHTML += `<tr class='diff-row'><td>${item.Cliente}</td><td>${item.Vendedor}</td><td class='highlight-diff'>${item.Desc_FAD_Planilha} <br><span class='sub-text'>(ID: ${item.FAD_ID_Planilha})</span></td><td class='highlight-diff' style='color:#0f172a'>${item.Desc_FAD_Banco} <br><span class='sub-text'>(ID: ${item.FAD_ID_Banco})</span></td></tr>`;
                });
            }
            modal.style.display = 'block';
        }
        function closeAuditModal() { document.getElementById('auditModal').style.display = 'none'; }

        function exportAudit(type) {
            const list = rawData.lista_divergencia_cadastral || [];
            if(!list.length) return;
            let content = "";
            if(type === 'csv') {
                content = "Cliente;Vendedor;FAD_ID_Planilha;FAD_Desc_Planilha;FAD_ID_Banco;FAD_Desc_Banco\n";
                list.forEach(item => { content += `${item.Cliente};${item.Vendedor};${item.FAD_ID_Planilha};${item.Desc_FAD_Planilha};${item.FAD_ID_Banco};${item.Desc_FAD_Banco}\n`; });
                downloadFile(content, "Auditoria_Cadastro.csv", "text/csv;charset=utf-8;");
            } else {
                content = "RELATÓRIO DE DIVERGÊNCIA CADASTRAL (Planilha vs Banco)\n----------------------------------------------------\n";
                list.forEach(item => { content += `Cliente: ${item.Cliente} | Vend: ${item.Vendedor}\n   Planilha: [${item.FAD_ID_Planilha}] ${item.Desc_FAD_Planilha}\n   Banco:    [${item.FAD_ID_Banco}] ${item.Desc_FAD_Banco}\n----------------------------------------------------\n`; });
                downloadFile(content, "Auditoria_Cadastro.txt", "text/plain");
            }
        }

        function openModal(type) {
            const modal = document.getElementById('detailModal');
            const title = document.getElementById('modalTitle');
            const thead = document.getElementById('modalTableHead');
            
            const stats = rawData[activeScenarioKey].stats;
            const audit = rawData[activeScenarioKey].audit;
            const hasAudit = rawData.tem_audit_file;
            let dataSet = {}, isAuditMode = (type === 'FAD' && hasAudit);

            if (type === 'Vendedor') { title.innerText = "Detalhes: Vendedor"; dataSet = stats.por_vendedor; thead.innerHTML = "<tr><th>Nome</th><th style='text-align:right'>Qtd</th></tr>"; }
            else if (type === 'Sold') { title.innerText = "Detalhes: Cliente"; dataSet = stats.por_sold; thead.innerHTML = "<tr><th>Cliente</th><th style='text-align:right'>Qtd</th></tr>"; }
            else if (type === 'FAD') {
                title.innerText = "Detalhes: FAD";
                if (isAuditMode) { dataSet = audit; thead.innerHTML = "<tr><th>FAD</th><th style='text-align:right'>Calc.</th><th style='text-align:right'>Meta</th><th style='text-align:right'>Dif.</th><th style='text-align:center'>Status</th></tr>"; }
                else { dataSet = stats.por_fad; thead.innerHTML = "<tr><th>FAD</th><th style='text-align:right'>Qtd</th></tr>"; }
            }

            currentDetailData = [];
            if (isAuditMode && Array.isArray(dataSet)) currentDetailData = dataSet.map(i => ({ type: 'audit', ...i }));
            else currentDetailData = Object.entries(dataSet).sort((a, b) => b[1] - a[1]).map(([k, v]) => ({ key: k, val: v, type: 'simple' }));

            detailPage = 1;
            renderDetailTable();
            renderChart(type === 'FAD' && isAuditMode ? [] : currentDetailData.slice(0, 10).map(x => [x.key, x.val])); 
            modal.style.display = 'block';
            document.getElementById('searchInput').value = ''; 
        }

        function renderDetailTable() {
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = "";
            const totalPages = Math.ceil(currentDetailData.length / rowsPerPage);
            const start = (detailPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageItems = currentDetailData.slice(start, end);

            pageItems.forEach(item => {
                if (item.type === 'audit') {
                    let nome = item.fad, badgeClass = item.diff === 0 ? "badge-success" : (item.diff > 0 ? "badge-warning" : "badge-danger");
                    let link = `<a href="#" onclick="waitAndProcess(function(){ openFadDetails('${nome}') }); return false;" style="color:var(--accent-color); font-weight:600; text-decoration:none">${nome}</a>`;
                    tbody.innerHTML += `<tr><td>${link}</td><td style='text-align:right'>${item.calculado}</td><td style='text-align:right'>${item.esperado}</td><td style='text-align:right'>${item.diff}</td><td style='text-align:center'><span class='badge ${badgeClass}'>!</span></td></tr>`;
                } else {
                    let k = item.key, v = new Intl.NumberFormat('pt-BR').format(item.val);
                    let cell = document.getElementById('modalTitle').innerText.includes('FAD') ? `<a href="#" onclick="waitAndProcess(function(){ openFadDetails('${k}') }); return false;" style="color:var(--accent-color); font-weight:600; text-decoration:none">${k}</a>` : k;
                    tbody.innerHTML += `<tr><td>${cell}</td><td style='text-align:right'>${v}</td></tr>`;
                }
            });

            const div = document.getElementById('detailPagination');
            div.style.display = totalPages > 1 ? 'flex' : 'none';
            if(totalPages > 1) {
                div.querySelector('span').innerText = `Página ${detailPage} de ${totalPages}`;
                div.querySelectorAll('button')[0].disabled = detailPage === 1;
                div.querySelectorAll('button')[1].disabled = detailPage === totalPages;
            }
            document.querySelector('#detailModal .modal-table-area').scrollTop = 0;
        }
        function changeDetailPage(d) { detailPage += d; renderDetailTable(); }

        function openFadDetails(fadName) {
            currentFadName = fadName;
            const isPlanilha = (activeScenarioKey === 'cenario_planilha');
            currentDrillData = rawData.detalhes.filter(row => {
                if (isPlanilha) return row.fad_plan === fadName && row.valid_plan === true;
                else return row.fad_banc === fadName && row.valid_banc === true;
            });
            document.getElementById('drillTitle').innerText = "Drill Down: " + fadName + (isPlanilha ? " (Planilha)" : " (Banco)");
            document.getElementById('drillCount').innerText = currentDrillData.length + " regs";
            document.querySelector('#drillTable thead').innerHTML = "<tr><th>Data</th><th>Cliente</th><th>Vendedor</th><th style='text-align:right'>Valor</th><th style='text-align:right'>Mínimo</th></tr>";
            drillPage = 1;
            renderDrillTable();
            document.getElementById('fadDrillModal').style.display = 'block';
        }

        function renderDrillTable() {
            const tbody = document.querySelector('#drillTable tbody');
            tbody.innerHTML = "";
            const isPlanilha = (activeScenarioKey === 'cenario_planilha');
            const start = (drillPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageData = currentDrillData.slice(start, end);

            pageData.forEach(row => {
                let valFmt = new Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'}).format(row.valor);
                let minVal = isPlanilha ? row.min_plan : row.min_banc;
                let minFmt = new Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'}).format(minVal);
                tbody.innerHTML += `<tr><td>${row.data}</td><td>${row.cliente}</td><td>${row.vendedor}</td><td style='text-align:right; font-weight:600;'>${valFmt}</td><td style='text-align:right; color:#666;'>${minFmt}</td></tr>`;
            });
            
            const total = Math.ceil(currentDrillData.length / rowsPerPage);
            const div = document.getElementById('drillPagination');
            div.style.display = total > 1 ? 'flex' : 'none';
            if(total > 1) {
                document.getElementById('pageIndicator').innerText = `Página ${drillPage} de ${total}`;
                div.querySelectorAll('button')[0].disabled = drillPage === 1;
                div.querySelectorAll('button')[1].disabled = drillPage === total;
            }
            document.querySelector('#fadDrillModal .modal-table-area').scrollTop = 0;
        }
        function changeDrillPage(d) { drillPage += d; renderDrillTable(); }

        function exportDrill(fmt) {
            if(!currentDrillData.length) return;
            let content = "";
            const isPlanilha = (activeScenarioKey === 'cenario_planilha');
            if(fmt==='csv') {
                content = "Data;Cliente;Vendedor;Valor;Minimo\n";
                currentDrillData.forEach(r => { let min = isPlanilha ? r.min_plan : r.min_banc; content += `${r.data};${r.cliente};${r.vendedor};${String(r.valor).replace('.',',')};${String(min).replace('.',',')}\n`; });
                downloadFile(content, `Drill_${currentFadName}.csv`, 'text/csv');
            } else {
                content = `RELATÓRIO DE DROPS (${isPlanilha?'Planilha':'Banco'}) - FAD: ${currentFadName}\nData: ${new Date().toLocaleDateString()}\n--------------------\n`;
                currentDrillData.forEach(r => { let min = isPlanilha ? r.min_plan : r.min_banc; content += `${r.data} | Cli: ${r.cliente} | Vend: ${r.vendedor} | Val: R$ ${r.valor} (Min: ${min})\n`; });
                downloadFile(content, `Drill_${currentFadName}.txt`, 'text/plain');
            }
        }
        function downloadFile(content, name, mime) {
            const blob = new Blob([content], {type: mime});
            const link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            link.download = name;
            link.click();
        }
        function showLoading() { document.getElementById('loadingOverlay').style.display = 'flex'; }
        function closeModal() { document.getElementById('detailModal').style.display = 'none'; }
        function closeDrillModal() { document.getElementById('fadDrillModal').style.display = 'none'; }
        window.onclick = function(e) { 
            if(e.target.id==='detailModal') closeModal(); 
            if(e.target.id==='fadDrillModal') closeDrillModal();
            if(e.target.id==='auditModal') closeAuditModal();
        }
        function renderChart(dataArr) {
            const ctx = document.getElementById('detailChart').getContext('2d');
            if(myChart) myChart.destroy();
            myChart = new Chart(ctx, { type: 'bar', data: { labels: dataArr.map(x=>x[0]), datasets: [{label:'Qtd', data: dataArr.map(x=>x[1]), backgroundColor:'#2563eb'}] }, options: { responsive:true, maintainAspectRatio:false, scales:{y:{beginAtZero:true}} } });
        }
        function filterTable(tid) {
            const val = document.getElementById('searchInput').value.toUpperCase();
            if(!val) { renderDetailTable(); return; }
            const filtered = currentDetailData.filter(item => {
                if(item.type === 'audit') return (item.fad||'').toUpperCase().includes(val);
                return (item.key||'').toUpperCase().includes(val);
            });
            const tbody = document.getElementById('modalTableBody');
            tbody.innerHTML = "";
            filtered.slice(0, 100).forEach(item => { 
                let k = item.key || item.fad;
                let v = item.val || item.calculado;
                tbody.innerHTML += `<tr><td>${k}</td><td style='text-align:right'>${v}</td></tr>`;
            });
        }
    </script>
</body>
</html>