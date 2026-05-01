<?php
require_once __DIR__ . '/_module.php';

$conn = appStartupCurrentDb();
$pdo = $conn['pdo'];
$dbName = $conn['dbName'];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App Nueva</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-5xl p-6">
        <h1 class="text-2xl font-semibold">App Nueva</h1>
        <p class="mt-2 text-sm text-slate-600">Base de datos: <?= htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') ?></p>
    </main>
</body>
</html>

