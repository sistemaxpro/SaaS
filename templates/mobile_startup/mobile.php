<?php
require_once __DIR__ . '/config/db_config.php';

$idEmpresa = (int)(Session::getIdEmpresa() ?? 0);
$conn = getEmpresaConnection($idEmpresa);
$dbName = $conn['dbName'];
$empresa = $conn['config'];
?>
<!DOCTYPE html>
<html lang="es" x-data="mobileApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobile App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-lg p-4">
        <div class="rounded-2xl bg-white p-4 shadow">
            <h1 class="text-xl font-semibold">Mobile App</h1>
            <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($empresa['empresa'] ?? 'Empresa', ENT_QUOTES, 'UTF-8') ?></p>
            <p class="mt-1 text-sm text-slate-500">DB: <?= htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </main>
    <script>
    function mobileApp() {
        return {
            init() {}
        };
    }
    </script>
</body>
</html>

