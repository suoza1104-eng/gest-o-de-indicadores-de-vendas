document.addEventListener('DOMContentLoaded', function () {
    // 1. Initialize Lucide Icons if loaded
    if (window.lucide) {
        window.lucide.createIcons();
    }

    // 2. Toast System
    window.showToast = function (message, type = 'info') {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let iconName = 'info';
        if (type === 'success') iconName = 'check-circle';
        if (type === 'error') iconName = 'alert-circle';

        toast.innerHTML = `
            <i data-lucide="${iconName}"></i>
            <span>${message}</span>
        `;
        container.appendChild(toast);

        if (window.lucide) {
            window.lucide.createIcons({ props: {}, nameAttr: 'data-lucide' });
        }

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    };

    // 3. Sidebar Toggle
    const sidebarToggle = document.querySelectorAll('[data-sidebar-toggle]');
    const sidebar = document.querySelector('.app-sidebar');
    if (sidebarToggle.length && sidebar) {
        sidebarToggle.forEach(btn => {
            btn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
            });
        });
        if (localStorage.getItem('sidebar_collapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
    }

    // 4. Multi-select Dropdown
    document.querySelectorAll('[data-multi-select]').forEach(container => {
        const trigger = container.querySelector('[data-multi-select-trigger]');
        const menu = container.querySelector('[data-multi-select-menu]');
        if (!trigger || !menu) return;

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.classList.toggle('hidden');
        });

        document.addEventListener('click', () => menu.classList.add('hidden'));
        menu.addEventListener('click', (e) => e.stopPropagation());

        const selectAll = menu.querySelector('[data-select-all-campaigns]');
        const itemBoxes = menu.querySelectorAll('input[type="checkbox"]:not([data-select-all-campaigns])');

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                itemBoxes.forEach(cb => cb.checked = selectAll.checked);
                updateTriggerText();
            });
        }

        itemBoxes.forEach(cb => {
            cb.addEventListener('change', () => {
                if (selectAll && !cb.checked) selectAll.checked = false;
                updateTriggerText();
            });
        });

        function updateTriggerText() {
            const checkedCount = Array.from(itemBoxes).filter(c => c.checked).length;
            if (checkedCount === 0 || (selectAll && selectAll.checked)) {
                trigger.textContent = 'Todas as campanhas';
            } else {
                trigger.textContent = `${checkedCount} selecionada(s)`;
            }
        }
    });

    // Helper for Button Loading Spinner
    function setButtonLoading(btn, isLoading, text = '') {
        if (!btn) return;
        if (isLoading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner"></span> <span>${text || 'Aguarde...'}</span>`;
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
            if (window.lucide) window.lucide.createIcons();
        }
    }

    // Helper fetch wrapper
    async function apiFetch(url, options = {}) {
        try {
            const res = await fetch(url, options);
            const data = await res.json();
            return data;
        } catch (err) {
            console.error('API Error:', err);
            return { ok: false, message: 'Erro de conexão ou resposta inválida.' };
        }
    }

    // 5. AJAX Integration Form & Sync Buttons
    const formIntegration = document.getElementById('integration-form');
    if (formIntegration) {
        formIntegration.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = formIntegration.querySelector('button[type="submit"]');
            setButtonLoading(submitBtn, true, 'Salvando...');
            const formData = new FormData(formIntegration);
            const data = await apiFetch('../api/save_integration.php', { method: 'POST', body: formData });
            setButtonLoading(submitBtn, false);
            if (data.ok) {
                showToast(data.message || 'Integracão salva com sucesso!', 'success');
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(data.message || 'Erro ao salvar integração.', 'error');
            }
        });
    }

    const btnTest = document.getElementById('btn-test');
    if (btnTest) {
        btnTest.addEventListener('click', async () => {
            setButtonLoading(btnTest, true, 'Testando...');
            const data = await apiFetch('../api/test_connection.php');
            setButtonLoading(btnTest, false);
            if (data.ok) {
                showToast(data.message || 'Conexão com a Meta efetuada com sucesso!', 'success');
            } else {
                showToast(data.message || 'Erro no teste de conexão Meta.', 'error');
            }
        });
    }

    const btnSync = document.getElementById('btn-sync');
    if (btnSync) {
        btnSync.addEventListener('click', async () => {
            setButtonLoading(btnSync, true, 'Sincronizando Meta...');
            const data = await apiFetch('../api/run_sync.php');
            setButtonLoading(btnSync, false);
            if (data.ok) {
                showToast(data.message || 'Sincronização Meta (3 dias) concluída!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'Erro ao sincronizar dados da Meta.', 'error');
            }
        });
    }

    const btnAttrSync = document.getElementById('btn-attr-sync');
    if (btnAttrSync) {
        btnAttrSync.addEventListener('click', async () => {
            setButtonLoading(btnAttrSync, true, 'Atribuindo...');
            const data = await apiFetch('../api/run_attribution_sync.php');
            setButtonLoading(btnAttrSync, false);
            if (data.ok) {
                showToast(data.message || 'Atribuição (3 dias) recalculada com sucesso!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'Erro ao reprocessar atribuição.', 'error');
            }
        });
    }

    const btnSyncAll = document.getElementById('btn-sync-all');
    if (btnSyncAll) {
        btnSyncAll.addEventListener('click', async () => {
            setButtonLoading(btnSyncAll, true, 'Sincronizando tudo...');
            const res1 = await apiFetch('../api/run_sync.php');
            const res2 = await apiFetch('../api/run_attribution_sync.php');
            setButtonLoading(btnSyncAll, false);
            if (res1.ok && res2.ok) {
                showToast('Sincronização completa de Meta + Atribuição finalizada!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Aviso: Ocorreram falhas durante a sincronização completa.', 'error');
            }
        });
    }

    const btnSyncHistory = document.getElementById('btn-sync-history');
    if (btnSyncHistory) {
        btnSyncHistory.addEventListener('click', async () => {
            const daysInput = document.getElementById('meta-history-days');
            const days = daysInput ? daysInput.value : 30;
            if (!confirm(`Deseja importar o histórico dos últimos ${days} dias da Meta?`)) return;
            setButtonLoading(btnSyncHistory, true, 'Buscando histórico...');
            const data = await apiFetch(`../api/run_sync.php?days=${days}`);
            setButtonLoading(btnSyncHistory, false);
            if (data.ok) {
                showToast(`Histórico Meta de ${days} dias importado!`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'Erro na carga histórica Meta.', 'error');
            }
        });
    }

    const btnAttrHistory = document.getElementById('btn-attr-history');
    if (btnAttrHistory) {
        btnAttrHistory.addEventListener('click', async () => {
            const daysInput = document.getElementById('attr-history-days');
            const days = daysInput ? daysInput.value : 90;
            if (!confirm(`Deseja reprocessar a atribuição dos últimos ${days} dias?`)) return;
            setButtonLoading(btnAttrHistory, true, 'Processando...');
            const data = await apiFetch(`../api/run_attribution_sync.php?days=${days}`);
            setButtonLoading(btnAttrHistory, false);
            if (data.ok) {
                showToast(`Carga histórica de atribuição (${days}d) finalizada!`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'Erro ao processar histórico de atribuição.', 'error');
            }
        });
    }

    // 6. Manual Attribution Modal Handling
    const modal = document.getElementById('modal-manual-attribution');
    const modalCloseBtn = document.getElementById('modal-close-btn');
    const manualForm = document.getElementById('manual-attribution-form');

    if (modal) {
        document.querySelectorAll('[data-open-modal-manual]').forEach(btn => {
            btn.addEventListener('click', () => {
                const saleId = btn.dataset.saleId || '';
                const transId = btn.dataset.transId || '';
                const email = btn.dataset.email || '';
                const phone = btn.dataset.phone || '';
                
                if (manualForm) {
                    manualForm.querySelector('[name="sale_id"]').value = saleId;
                    const infoDisplay = document.getElementById('modal-sale-info');
                    if (infoDisplay) infoDisplay.textContent = `Venda: ${transId} | Lead: ${email || phone || 'N/D'}`;
                }
                modal.classList.add('active');
            });
        });

        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', () => modal.classList.remove('active'));
        }

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('active');
        });
    }

    if (manualForm) {
        manualForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = manualForm.querySelector('button[type="submit"]');
            setButtonLoading(submitBtn, true, 'Salvando...');
            const formData = new FormData(manualForm);
            const data = await apiFetch('../api/save_manual_attribution.php', { method: 'POST', body: formData });
            setButtonLoading(submitBtn, false);
            if (data.ok) {
                showToast('Atribuição manual salva com sucesso!', 'success');
                if (modal) modal.classList.remove('active');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(data.message || 'Erro ao salvar atribuição manual.', 'error');
            }
        });
    }

    // 7. Theme Toggle (dark/light)
    const themeToggleBtns = document.querySelectorAll('[data-theme-toggle]');
    if (themeToggleBtns.length) {
        const updateThemeIcon = () => {
            const isLight = document.documentElement.getAttribute('data-theme') === 'light';
            themeToggleBtns.forEach((btn) => {
                const icon = btn.querySelector('[data-lucide]');
                if (icon) icon.setAttribute('data-lucide', isLight ? 'moon' : 'sun');
            });
            if (window.lucide) window.lucide.createIcons();
        };
        updateThemeIcon();
        themeToggleBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const isLight = document.documentElement.getAttribute('data-theme') === 'light';
                if (isLight) {
                    document.documentElement.removeAttribute('data-theme');
                    try { localStorage.setItem('meta_admin_theme', 'dark'); } catch (e) {}
                } else {
                    document.documentElement.setAttribute('data-theme', 'light');
                    try { localStorage.setItem('meta_admin_theme', 'light'); } catch (e) {}
                }
                updateThemeIcon();
            });
        });
    }

    // 8. Multi-integration management (Nova BM / Editar / Pausar / Excluir / Sincronizar)
    const integrationForm = document.getElementById('integration-form');
    if (integrationForm) {
        const integrationDefaults = {
            id: '', name: 'Meta Principal', ad_account_id: '', app_id: '', app_secret: '',
            access_token: '', sync_interval_minutes: 30, status: 'active', timezone: 'America/Sao_Paulo',
            currency_code: 'BRL', currency_spread_percent: 0, manual_exchange_rate: ''
        };

        const integrationModal = document.getElementById('integration-modal');
        const integrationModalTitle = document.getElementById('integration-modal-title');
        const integrationModalClose = document.getElementById('integration-modal-close');

        function fillIntegrationForm(data) {
            Object.keys(integrationDefaults).forEach((key) => {
                const field = integrationForm.querySelector('[name="' + key + '"]');
                if (!field) return;
                const value = data && data[key] !== undefined && data[key] !== null ? data[key] : integrationDefaults[key];
                field.value = value;
            });
        }

        function openIntegrationModal(title) {
            if (integrationModalTitle) integrationModalTitle.textContent = title;
            if (integrationModal) integrationModal.classList.add('active');
        }

        if (integrationModalClose) {
            integrationModalClose.addEventListener('click', () => integrationModal.classList.remove('active'));
        }
        if (integrationModal) {
            integrationModal.addEventListener('click', (e) => {
                if (e.target === integrationModal) integrationModal.classList.remove('active');
            });
        }

        document.querySelectorAll('[data-new-integration]').forEach((btn) => {
            btn.addEventListener('click', () => {
                fillIntegrationForm(null);
                openIntegrationModal('Nova Integração Meta Ads');
            });
        });

        document.querySelectorAll('[data-edit-integration]').forEach((btn) => {
            btn.addEventListener('click', () => {
                try {
                    const data = JSON.parse(btn.getAttribute('data-edit-integration'));
                    fillIntegrationForm(data);
                    openIntegrationModal('Editar Integração: ' + (data.name || ''));
                } catch (e) {
                    showToast('Não foi possível carregar os dados dessa integração.', 'error');
                }
            });
        });

        document.querySelectorAll('[data-toggle-integration]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    const data = JSON.parse(btn.getAttribute('data-toggle-integration'));
                    const nextStatus = (data.status || 'active') === 'active' ? 'inactive' : 'active';
                    setButtonLoading(btn, true, nextStatus === 'inactive' ? 'Pausando...' : 'Ativando...');
                    const fd = new FormData();
                    Object.keys(integrationDefaults).forEach((key) => {
                        fd.append(key, data[key] !== undefined && data[key] !== null ? data[key] : integrationDefaults[key]);
                    });
                    fd.set('status', nextStatus);
                    const res = await apiFetch('../api/save_integration.php', { method: 'POST', body: fd });
                    setButtonLoading(btn, false);
                    if (res.ok) {
                        showToast(nextStatus === 'inactive' ? 'Integração pausada.' : 'Integração ativada.', 'success');
                        setTimeout(() => location.reload(), 900);
                    } else {
                        showToast(res.message || 'Erro ao atualizar status.', 'error');
                    }
                } catch (e) {
                    setButtonLoading(btn, false);
                    showToast('Erro ao atualizar status da integração.', 'error');
                }
            });
        });

        document.querySelectorAll('[data-delete-integration]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const id = btn.getAttribute('data-delete-integration');
                const name = btn.getAttribute('data-integration-name') || 'esta integração';
                if (!confirm('Excluir "' + name + '"? Isso apaga também todo o histórico de métricas e sincronizações dessa BM. Essa ação não pode ser desfeita.')) return;
                setButtonLoading(btn, true, 'Excluindo...');
                const fd = new FormData();
                fd.append('id', id);
                const res = await apiFetch('../api/delete_integration.php', { method: 'POST', body: fd });
                setButtonLoading(btn, false);
                if (res.ok) {
                    showToast('Integração excluída.', 'success');
                    setTimeout(() => location.reload(), 900);
                } else {
                    showToast(res.message || 'Erro ao excluir integração.', 'error');
                }
            });
        });

        document.querySelectorAll('[data-sync-integration]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const integrationId = btn.getAttribute('data-sync-integration');
                setButtonLoading(btn, true, 'Sincronizando...');
                const fdMeta = new FormData();
                fdMeta.append('integration_id', integrationId);
                fdMeta.append('scope', 'all');
                fdMeta.append('mode', 'daily');
                const res1 = await apiFetch('../api/run_sync.php', { method: 'POST', body: fdMeta });
                const fdAttr = new FormData();
                fdAttr.append('integration_id', integrationId);
                fdAttr.append('mode', 'daily');
                const res2 = await apiFetch('../api/run_attribution_sync.php', { method: 'POST', body: fdAttr });
                setButtonLoading(btn, false);
                if (res1.ok && res2.ok) {
                    showToast('Sincronização concluída para esta BM!', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast((res1.message || res2.message) || 'Falha ao sincronizar esta BM.', 'error');
                }
            });
        });

        const syncAllBtn = document.querySelector('[data-sync-all-integrations]');
        if (syncAllBtn) {
            syncAllBtn.addEventListener('click', async () => {
                const ids = Array.from(document.querySelectorAll('[data-sync-integration]')).map((b) => b.getAttribute('data-sync-integration'));
                if (!ids.length) {
                    showToast('Nenhuma BM cadastrada para sincronizar.', 'error');
                    return;
                }
                setButtonLoading(syncAllBtn, true, 'Sincronizando todas...');
                let hadError = false;
                for (const integrationId of ids) {
                    const fdMeta = new FormData();
                    fdMeta.append('integration_id', integrationId);
                    fdMeta.append('scope', 'all');
                    fdMeta.append('mode', 'daily');
                    const res1 = await apiFetch('../api/run_sync.php', { method: 'POST', body: fdMeta });
                    const fdAttr = new FormData();
                    fdAttr.append('integration_id', integrationId);
                    fdAttr.append('mode', 'daily');
                    const res2 = await apiFetch('../api/run_attribution_sync.php', { method: 'POST', body: fdAttr });
                    if (!res1.ok || !res2.ok) hadError = true;
                }
                setButtonLoading(syncAllBtn, false);
                if (hadError) {
                    showToast('Sincronização finalizada com falhas em uma ou mais BMs.', 'error');
                } else {
                    showToast('Todas as BMs foram sincronizadas com sucesso!', 'success');
                }
                setTimeout(() => location.reload(), 1200);
            });
        }
    }
});

// Chart.js Theme Helper
window.createChartGradient = function (ctx, colorStart, colorEnd) {
    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, colorStart);
    gradient.addColorStop(1, colorEnd);
    return gradient;
};

