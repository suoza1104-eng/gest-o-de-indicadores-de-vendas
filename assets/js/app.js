(function () {
  var shell = document.querySelector('[data-app-shell]');
  var navItems = Array.prototype.slice.call(document.querySelectorAll('[data-section-target]'));
  var contentSections = Array.prototype.slice.call(document.querySelectorAll('[data-app-section]'));

  function setupNavigation() {
    if (!shell || !contentSections.length) return;

    function resizeChartsSoon() {
      if (typeof Chart === 'undefined' || !Chart.instances) return;
      setTimeout(function () {
        Object.keys(Chart.instances).forEach(function (key) {
          if (Chart.instances[key] && typeof Chart.instances[key].resize === 'function') {
            Chart.instances[key].resize();
          }
        });
      }, 60);
    }

    function showSection(target) {
      var selected = target || 'dashboard';
      contentSections.forEach(function (section) {
        section.classList.toggle('hidden', section.getAttribute('data-app-section') !== selected);
      });
      navItems.forEach(function (item) {
        item.classList.toggle('active', item.getAttribute('data-section-target') === selected);
      });
      resizeChartsSoon();
    }

    navItems.forEach(function (item) {
      item.addEventListener('click', function () {
        showSection(item.getAttribute('data-section-target'));
      });
    });

    var initialSection = 'dashboard';
    var params = new URLSearchParams(window.location.search || '');
    if (window.location.hash === '#cron-audit') {
      initialSection = 'audit';
    }
    params.forEach(function (_value, key) {
      if (key.indexOf('audit_') === 0) {
        initialSection = 'audit';
      }
    });
    showSection(initialSection);
  }

  setupNavigation();

  async function postForm(url, data) {
    var response = await fetch(url, { method: 'POST', body: data });
    var raw = await response.text();
    var json = null;
    try {
      json = raw ? JSON.parse(raw) : null;
    } catch (e) {
      throw new Error(raw || 'Resposta inválida do servidor.');
    }
    if (!response.ok || !json || !json.ok) {
      throw new Error((json && json.message) || 'Erro inesperado.');
    }
    return json;
  }

  function makeLineChart(id, labels, datasets, options) {
    var ctx = document.getElementById(id);
    if (!ctx || typeof Chart === 'undefined') return;
    new Chart(ctx, {
      type: 'line',
      data: { labels: labels, datasets: datasets },
      options: Object.assign({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true } }
      }, options || {})
    });
  }

  if (window.metaChartData) {
    makeLineChart('metaSpendChart', window.metaChartData.labels, [
      { label: 'Gasto', data: window.metaChartData.spend, tension: 0.25, yAxisID: 'yMoney' },
      { label: 'Leads', data: window.metaChartData.leads, tension: 0.25, yAxisID: 'yCount' }
    ], {
      scales: {
        yMoney: { type: 'linear', position: 'left', beginAtZero: true },
        yCount: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
      }
    });
    makeLineChart('metaCpmChart', window.metaChartData.labels, [
      { label: 'CPM', data: window.metaChartData.cpm, tension: 0.25, yAxisID: 'yMoney' },
      { label: 'Frequência', data: window.metaChartData.frequency, tension: 0.25, yAxisID: 'yFreq' }
    ], {
      scales: {
        yMoney: { type: 'linear', position: 'left', beginAtZero: true },
        yFreq: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
      }
    });
  }

  if (window.attrChartData) {
    makeLineChart('attrRevenueChart', window.attrChartData.labels, [
      { label: 'Gasto', data: window.attrChartData.spend, tension: 0.25, yAxisID: 'yMoney' },
      { label: 'Receita', data: window.attrChartData.revenue, tension: 0.25, yAxisID: 'yMoney' },
      { label: 'Vendas', data: window.attrChartData.sales, tension: 0.25, yAxisID: 'yCount' }
    ], {
      scales: {
        yMoney: { type: 'linear', position: 'left', beginAtZero: true },
        yCount: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
      }
    });
    makeLineChart('attrEfficiencyChart', window.attrChartData.labels, [
      { label: 'CAC', data: window.attrChartData.cac, tension: 0.25, yAxisID: 'yMoney' },
      { label: 'ROAS', data: window.attrChartData.roas, tension: 0.25, yAxisID: 'yRoas' },
      { label: 'CPM', data: window.attrChartData.cpm, tension: 0.25, yAxisID: 'yMoney' },
      { label: 'Frequência', data: window.attrChartData.frequency, tension: 0.25, yAxisID: 'yFreq' },
      { label: 'CPC', data: window.attrChartData.cpc, tension: 0.25, yAxisID: 'yMoney' }
    ], {
      scales: {
        yMoney: { type: 'linear', position: 'left', beginAtZero: true },
        yRoas: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } },
        yFreq: { type: 'linear', position: 'right', beginAtZero: true, display: false }
      }
    });
  }


  function moneyBr(value) {
    return 'R$ ' + Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function qtyBr(value) {
    return Number(value || 0).toLocaleString('pt-BR');
  }

  function destroyChart(id) {
    var canvas = document.getElementById(id);
    if (!canvas || typeof Chart === 'undefined') return null;
    var existing = Chart.getChart(canvas);
    if (existing) existing.destroy();
    return canvas.getContext('2d');
  }

  function extraBaseOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'top',
          labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, color: '#1a2332' }
        },
        tooltip: {
          backgroundColor: '#fff',
          titleColor: '#1a2332',
          bodyColor: '#555',
          borderColor: '#eee',
          borderWidth: 1,
          cornerRadius: 12,
          padding: 12,
          boxPadding: 4,
          titleFont: { weight: '600' }
        }
      }
    };
  }

  function renderExtraCharts(extra) {
    if (typeof Chart === 'undefined' || !extra) return;
    var c = { blue:'#5ba0f5', pink:'#f087a0', yellow:'#f5c542', orange:'#f5924a', green:'#4db89a', lightBlue:'#8ac4ff', lightPink:'#f5b0c0', teal:'#67d5c5', purple:'#a78bfa', gray:'#cbd5e1' };

    var attribCtx = destroyChart('salesAttributionPieChart');
    if (attribCtx) {
      new Chart(attribCtx, {
        type: 'pie',
        data: { labels: (extra.attribVsNon||{}).labels||[], datasets: [{ data: (extra.attribVsNon||{}).counts||[], backgroundColor: [c.blue, c.pink], borderWidth: 0 }] },
        options: Object.assign(extraBaseOptions(), { plugins: Object.assign(extraBaseOptions().plugins, { tooltip: Object.assign(extraBaseOptions().plugins.tooltip, { callbacks: { label: function(ctx){ var counts=(extra.attribVsNon||{}).counts||[]; var revenue=(extra.attribVsNon||{}).revenue||[]; var total=counts.reduce(function(sum,v){return sum+Number(v||0);},0); var value=Number(ctx.parsed||0); var pct=total>0?((value/total)*100).toFixed(1):'0.0'; return ctx.label + ': ' + qtyBr(value) + ' (' + pct + '%) | ' + moneyBr(revenue[ctx.dataIndex]||0); } } }) }) })
      });
    }

    var paymentCtx = destroyChart('salesPaymentBarChart');
    if (paymentCtx) {
      new Chart(paymentCtx, {
        type: 'bar',
        data: { labels: (extra.payment||{}).labels||[], datasets: [ { label:'Quantidade', data:(extra.payment||{}).counts||[], backgroundColor:c.lightBlue, borderRadius:6, maxBarThickness:42 }, { label:'Valor (R$)', data:(extra.payment||{}).revenue||[], backgroundColor:c.lightPink, borderRadius:6, maxBarThickness:42 } ] },
        options: Object.assign(extraBaseOptions(), { scales: { x: { grid: { display:false }, ticks: { color:'#64748b', font:{size:11} } }, y: { beginAtZero:true, grid:{ color:'#f0f0f0' }, ticks:{ color:'#64748b' } } }, plugins: Object.assign(extraBaseOptions().plugins, { tooltip: Object.assign(extraBaseOptions().plugins.tooltip, { callbacks: { label: function(ctx){ return ctx.dataset.label === 'Valor (R$)' ? ctx.dataset.label + ': ' + moneyBr(ctx.parsed.y) : ctx.dataset.label + ': ' + qtyBr(ctx.parsed.y); } } }) }) })
      });
    }

    var instCtx = destroyChart('salesInstallmentsBarChart');
    if (instCtx) {
      new Chart(instCtx, {
        type: 'bar',
        data: { labels: (extra.installments||{}).labels||[], datasets: [ { label:'Quantidade', data:(extra.installments||{}).counts||[], backgroundColor:c.lightBlue, borderRadius:6, maxBarThickness:56 }, { label:'Valor (R$)', data:(extra.installments||{}).revenue||[], backgroundColor:c.lightPink, borderRadius:6, maxBarThickness:56 } ] },
        options: Object.assign(extraBaseOptions(), { scales: { x: { grid: { display:false }, ticks:{ color:'#64748b' } }, y: { beginAtZero:true, grid:{ color:'#f0f0f0' }, ticks:{ color:'#64748b' } } }, plugins: Object.assign(extraBaseOptions().plugins, { tooltip: Object.assign(extraBaseOptions().plugins.tooltip, { callbacks: { label: function(ctx){ return ctx.dataset.label === 'Valor (R$)' ? ctx.dataset.label + ': ' + moneyBr(ctx.parsed.y) : ctx.dataset.label + ': ' + qtyBr(ctx.parsed.y); } } }) }) })
      });
    }

    var ncCtx = destroyChart('nonCompletedEventsPieChart');
    if (ncCtx) {
      var ncColors = [c.blue,c.pink,c.yellow,c.orange,c.green,c.teal,c.purple,c.gray];
      new Chart(ncCtx, {
        type: 'doughnut',
        data: { labels: (extra.nonCompleted||{}).labels||[], datasets: [{ data: (extra.nonCompleted||{}).counts||[], backgroundColor: ((extra.nonCompleted||{}).labels||[]).map(function(_,i){ return ncColors[i % ncColors.length]; }), borderWidth:0 }] },
        options: Object.assign(extraBaseOptions(), { cutout: '48%', plugins: Object.assign(extraBaseOptions().plugins, { tooltip: Object.assign(extraBaseOptions().plugins.tooltip, { callbacks: { label: function(ctx){ var counts=(extra.nonCompleted||{}).counts||[]; var revenue=(extra.nonCompleted||{}).revenue||[]; var total=counts.reduce(function(sum,v){return sum+Number(v||0);},0); var value=Number(ctx.parsed||0); var pct=total>0?((value/total)*100).toFixed(1):'0.0'; return ctx.label + ': ' + qtyBr(value) + ' (' + pct + '%) | ' + moneyBr(revenue[ctx.dataIndex]||0); } } }) }) })
      });
    }

    var delayCtx = destroyChart('leadToSaleDelayBarChart');
    if (delayCtx) {
      new Chart(delayCtx, {
        type: 'bar',
        data: { labels: (extra.delay||{}).labels||[], datasets: [{ label:'Quantidade de alunos', data:(extra.delay||{}).counts||[], backgroundColor:c.lightBlue, borderRadius:3, barPercentage:0.9, categoryPercentage:0.92 }] },
        options: Object.assign(extraBaseOptions(), { scales: { x: { grid: { display:false }, ticks: { color:'#64748b', maxRotation:55, minRotation:55, autoSkip:true, maxTicksLimit:31 } }, y: { beginAtZero:true, grid:{ color:'#f0f0f0' }, ticks:{ color:'#64748b' } } }, plugins: Object.assign(extraBaseOptions().plugins, { tooltip: Object.assign(extraBaseOptions().plugins.tooltip, { callbacks: { title: function(ctx){ return 'Dias: ' + ctx[0].label; }, label: function(ctx){ return 'Quantidade: ' + qtyBr(ctx.parsed.y); } } }) }) })
      });
    }
  }

  function bootExtraCharts(){ if (window.salesExtraCharts) { try { renderExtraCharts(window.salesExtraCharts); } catch(e){ console.error('Erro ao renderizar gráficos extras', e); } } }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bootExtraCharts); } else { bootExtraCharts(); }

  document.querySelectorAll('.toggle-row').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-target');
      var open = btn.getAttribute('data-open') !== '0';
      document.querySelectorAll('.' + target).forEach(function (row) {
        row.style.display = open ? 'none' : '';
        if (open) {
          row.querySelectorAll('.toggle-row').forEach(function (childBtn) {
            childBtn.setAttribute('data-open', '0');
            childBtn.textContent = '▸';
          });
          row.className.split(' ').forEach(function (cls) {
            if (cls && cls !== target && row.classList.contains('child-row')) {
              document.querySelectorAll('.' + cls).forEach(function (nested) {
                if (nested !== row && nested.classList.contains('child-row')) nested.style.display = 'none';
              });
            }
          });
        }
      });
      btn.setAttribute('data-open', open ? '0' : '1');
      btn.textContent = open ? '▸' : '▾';
    });
  });


  document.querySelectorAll('[data-toggle-collapsible]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sel = btn.getAttribute('data-toggle-collapsible');
      var el = document.querySelector(sel);
      if (!el) return;
      var extras = el.querySelectorAll('.sync-extra');
      var expanding = btn.textContent.indexOf('Expandir') !== -1;
      extras.forEach(function (row) { row.classList.toggle('hidden', !expanding); });
      btn.textContent = expanding ? 'Recolher' : 'Expandir';
    });
  });

  function fillSelect(select, items, placeholder) {
    if (!select) return;
    select.innerHTML = '';
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder || 'Selecione';
    select.appendChild(opt);
    (items || []).forEach(function (item) {
      var o = document.createElement('option');
      o.value = item;
      o.textContent = item;
      select.appendChild(o);
    });
    select.disabled = !(items && items.length);
  }

  document.querySelectorAll('[data-manual-row]').forEach(function (row) {
    var campaign = row.querySelector('[data-manual-campaign]');
    var adset = row.querySelector('[data-manual-adset]');
    var ad = row.querySelector('[data-manual-ad]');
    var btn = row.querySelector('[data-save-manual]');
    var feedbackEl = row.querySelector('[data-manual-feedback]');
    var hier = window.metaHierarchyData || {};
    function setFeedback(msg, ok) {
      if (!feedbackEl) return;
      feedbackEl.textContent = msg || '';
      feedbackEl.style.color = ok ? '#15803d' : '#b91c1c';
    }
    if (campaign) {
      campaign.addEventListener('change', function () {
        if (campaign.value === '__NO_ATTRIB__') {
          fillSelect(adset, [], 'Não se aplica');
          fillSelect(ad, [], 'Não se aplica');
          adset.disabled = true;
          ad.disabled = true;
          return;
        }
        var adsets = Object.keys(hier[campaign.value] || {});
        fillSelect(adset, adsets, 'Selecione');
        fillSelect(ad, [], 'Selecione');
      });
    }
    if (adset) {
      adset.addEventListener('change', function () {
        if (campaign.value === '__NO_ATTRIB__') {
          fillSelect(ad, [], 'Não se aplica');
          ad.disabled = true;
          return;
        }
        var ads = ((hier[campaign.value] || {})[adset.value] || []);
        fillSelect(ad, ads, 'Selecione');
      });
    }
    if (btn) {
      btn.addEventListener('click', async function () {
        try {
          if (!campaign.value) {
            setFeedback('Selecione ao menos a campanha.', false);
            return;
          }
          btn.disabled = true;
          setFeedback('Salvando...', true);
          var fd = new FormData();
          var noAttrib = campaign.value === '__NO_ATTRIB__';
          fd.append('transaction_code', row.getAttribute('data-transaction-code') || '');
          fd.append('model', (window.manualAttributionConfig || {}).model || 'last_touch');
          fd.append('campaign_group', campaign.value);
          fd.append('campaign_name', noAttrib ? '' : adset.value);
          fd.append('ad_name', noAttrib ? '' : ad.value);
          fd.append('no_attribution', noAttrib ? '1' : '0');
          fd.append('source_user_id', row.getAttribute('data-source-user-id') || '');
          fd.append('lead_utm_source', row.getAttribute('data-lead-utm-source') || '');
          fd.append('lead_utm_medium', row.getAttribute('data-lead-utm-medium') || '');
          fd.append('lead_utm_campaign', row.getAttribute('data-lead-utm-campaign') || '');
          fd.append('lead_utm_term', row.getAttribute('data-lead-utm-term') || '');
          fd.append('lead_utm_content', row.getAttribute('data-lead-utm-content') || '');
          var json = await postForm((window.manualAttributionConfig || {}).saveUrl || '../api/save_manual_attribution.php', fd);
          setFeedback(json.message || 'Salvo.', true);
          row.remove();
        } catch (e) {
          setFeedback(e.message || 'Erro ao salvar.', false);
        } finally {
          btn.disabled = false;
        }
      });
    }
  });

})();
