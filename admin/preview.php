<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview - Meta Ads Manager</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="app-body">
<div class="app-shell" data-app-shell>
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand">
            <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="Alternar menu">
                <span></span><span></span><span></span>
            </button>
            <div class="brand-text">
                <strong>Meta Ads</strong>
                <small>Performance real</small>
            </div>
        </div>
        <nav class="sidebar-nav" aria-label="Menu principal">
            <button type="button" class="nav-item active" data-section-target="dashboard"><span class="nav-icon">01</span><span class="nav-label">Indicadores</span></button>
            <button type="button" class="nav-item" data-section-target="campaigns"><span class="nav-icon">02</span><span class="nav-label">Campanhas</span></button>
            <button type="button" class="nav-item" data-section-target="settings"><span class="nav-icon">03</span><span class="nav-label">Configurações</span></button>
        </nav>
        <div class="sidebar-footer"><span class="status-dot"></span><span class="nav-label">Preview local</span></div>
    </aside>
    <main class="app-main">
        <div class="container">
            <header class="page-header">
                <div>
                    <h1>Meta Ads Manager</h1>
                    <p>Preview visual com dados demonstrativos.</p>
                </div>
                <div class="header-actions">
                    <a class="small-btn ghost-link" href="../login.php">Login</a>
                </div>
            </header>

            <section class="panel">
                <h2>Configurar integração</h2>
                <form class="grid-form">
                    <div><label>Nome</label><input value="Meta Principal"></div>
                    <div><label>Ad Account ID</label><input value="act_123456789"></div>
                    <div><label>Status</label><select><option>Ativa</option></select></div>
                    <div class="full"><label>Access Token</label><textarea rows="4">••••••••••••••••••••</textarea></div>
                    <div class="actions full">
                        <button type="button">Salvar integração</button>
                        <button type="button">Testar conexão</button>
                        <button type="button">Sincronizar Meta</button>
                    </div>
                </form>
            </section>

            <section class="cards cards-6">
                <article class="card"><span>Gasto hoje</span><strong>R$ 1.284,32</strong></article>
                <article class="card"><span>Impressões</span><strong>84.920</strong></article>
                <article class="card"><span>Cliques</span><strong>2.314</strong></article>
                <article class="card"><span>Leads</span><strong>418</strong></article>
                <article class="card"><span>CPM</span><strong>R$ 15,12</strong></article>
                <article class="card"><span>Frequência</span><strong>1,84</strong></article>
            </section>

            <section class="panel">
                <div class="section-head"><h2>Indicadores e gráficos</h2></div>
                <div class="chart-grid">
                    <div class="chart-card"><canvas id="metaSpendChart"></canvas></div>
                    <div class="chart-card"><canvas id="attrRevenueChart"></canvas></div>
                </div>
            </section>

            <section class="cards cards-5">
                <article class="card"><span>Gasto período</span><strong>R$ 18.420,00</strong></article>
                <article class="card"><span>Vendas</span><strong>136</strong></article>
                <article class="card"><span>Receita</span><strong>R$ 92.880,00</strong></article>
                <article class="card"><span>CAC</span><strong>R$ 135,44</strong></article>
                <article class="card"><span>ROAS</span><strong>5,04</strong></article>
            </section>

            <section class="panel">
                <div class="section-head"><h2>Análise de campanhas</h2></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Campanha</th><th>Gasto</th><th>Leads</th><th>Vendas</th><th>Receita</th><th>CPL</th><th>CAC</th><th>ROAS</th></tr></thead>
                        <tbody>
                            <tr><td>Captação Mentoria</td><td>R$ 8.240,00</td><td>220</td><td>74</td><td>R$ 51.800,00</td><td>R$ 37,45</td><td>R$ 111,35</td><td>6,28</td></tr>
                            <tr><td>Remarketing Aula</td><td>R$ 4.180,00</td><td>96</td><td>42</td><td>R$ 28.560,00</td><td>R$ 43,54</td><td>R$ 99,52</td><td>6,83</td></tr>
                            <tr><td>Topo de Funil</td><td>R$ 6.000,00</td><td>180</td><td>20</td><td>R$ 12.520,00</td><td>R$ 33,33</td><td>R$ 300,00</td><td>2,09</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
<script>
window.metaChartData = {
    labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'],
    spend: [1200, 1320, 1180, 1580, 1740, 1490, 1680],
    leads: [42, 51, 46, 63, 70, 55, 68]
};
window.attrChartData = {
    labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'],
    spend: [1200, 1320, 1180, 1580, 1740, 1490, 1680],
    revenue: [6200, 7100, 5800, 9400, 10300, 8900, 11200],
    sales: [8, 9, 7, 13, 15, 12, 16],
    cac: [150, 146, 168, 121, 116, 124, 105],
    roas: [5.1, 5.3, 4.9, 5.9, 5.9, 5.9, 6.6],
    cpm: [14, 15, 13, 16, 15, 14, 16],
    frequency: [1.7, 1.8, 1.6, 1.9, 1.8, 1.7, 2.0],
    cpc: [1.2, 1.1, 1.3, 1.0, 1.0, 1.1, 0.9]
};
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>
