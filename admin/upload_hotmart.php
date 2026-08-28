<?php
session_start();
if (empty($_SESSION['meta_admin_logged'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
$message = $_SESSION['hotmart_csv_flash'] ?? null;
unset($_SESSION['hotmart_csv_flash']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importar CSV Hotmart - Meta Ads Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/app-pro.css">
</head>
<body class="bg-[#090d16] text-slate-100 min-h-screen p-6 md:p-12 relative overflow-x-hidden">
    <!-- Glow Backdrops -->
    <div class="absolute top-10 left-1/3 w-[500px] h-[500px] bg-emerald-600/10 rounded-full blur-[140px] pointer-events-none"></div>

    <div class="max-w-4xl mx-auto relative z-10 space-y-8">
        <!-- Top Bar -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-white/10">
            <div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
                        <i data-lucide="file-spread-sheet" class="w-5 h-5"></i>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-extrabold text-white">Importar CSV da Hotmart</h1>
                </div>
                <p class="text-slate-400 text-sm mt-1">Saneamento e conciliação em lote das vendas da Hotmart</p>
            </div>
            <div>
                <a href="index.php" class="btn-pro btn-secondary">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span>Voltar ao Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Alert Flash Message -->
        <?php if ($message): ?>
            <div class="p-4 rounded-xl border flex items-center gap-3 <?= !empty($message['ok']) ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400' : 'bg-rose-500/10 border-rose-500/30 text-rose-400' ?>">
                <i data-lucide="<?= !empty($message['ok']) ? 'check-circle' : 'alert-circle' ?>" class="w-6 h-6 flex-shrink-0"></i>
                <div class="text-sm font-medium"><?= htmlspecialchars((string)($message['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <!-- Main Upload Card -->
        <div class="glass-card">
            <form method="post" action="../api/import_hotmart_csv.php" enctype="multipart/form-data" class="space-y-6">
                <div>
                    <label class="block text-sm font-semibold text-slate-200 mb-3">Selecione o arquivo CSV oficial exportado da Hotmart</label>
                    
                    <div class="border-2 border-dashed border-white/15 hover:border-emerald-500/50 rounded-2xl p-8 text-center bg-slate-900/50 transition-all cursor-pointer group" id="drop-zone">
                        <div class="w-14 h-14 mx-auto rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                            <i data-lucide="upload-cloud" class="w-7 h-7"></i>
                        </div>
                        <p class="text-white font-medium text-base mb-1">Arraste seu arquivo CSV aqui ou clique para selecionar</p>
                        <p class="text-slate-500 text-xs mb-4">Formatos suportados: .csv (máx. 20MB)</p>

                        <input id="csv" type="file" name="csv_file" accept=".csv,text/csv" required class="hidden">
                        <div id="file-info" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 border border-white/10 text-emerald-400 text-sm hidden">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                            <span id="file-name" class="font-mono"></span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                    <button type="submit" class="btn-pro btn-emerald">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        <span>Importar e Recalcular Atribuição</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Info Cards Grid -->
        <div class="grid md:grid-cols-2 gap-4">
            <div class="glass-card">
                <div class="flex items-center gap-3 mb-3 text-indigo-400">
                    <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                    <h3 class="font-bold text-white">Conciliação Automática</h3>
                </div>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Se a transação (HP) já existir, os dados serão atualizados. Se for nova, a venda é inserida automaticamente relacionando com os leads da área de membros.
                </p>
            </div>

            <div class="glass-card">
                <div class="flex items-center gap-3 mb-3 text-violet-400">
                    <i data-lucide="zap" class="w-5 h-5"></i>
                    <h3 class="font-bold text-white">Recálculo Instantâneo</h3>
                </div>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Após o upload, o motor de atribuição roda imediatamente para ligar UTMs pendentes e recalcular CAC, CPL e ROAS real.
                </p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('csv');
        const fileInfo = document.getElementById('file-info');
        const fileName = document.getElementById('file-name');

        if (dropZone && fileInput) {
            dropZone.addEventListener('click', () => fileInput.click());

            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('border-emerald-500', 'bg-emerald-500/5');
            });

            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('border-emerald-500', 'bg-emerald-500/5');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('border-emerald-500', 'bg-emerald-500/5');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    updateFileInfo();
                }
            });

            fileInput.addEventListener('change', updateFileInfo);

            function updateFileInfo() {
                if (fileInput.files.length) {
                    fileName.textContent = fileInput.files[0].name;
                    fileInfo.classList.remove('hidden');
                    fileInfo.classList.add('inline-flex');
                }
            }
        }
    </script>
</body>
</html>
