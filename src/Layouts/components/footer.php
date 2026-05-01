<footer class="bg-white dark:bg-gray-800 border-t dark:border-gray-700 mt-12">
    <div class="container mx-auto px-4 py-6">
        <div class="text-center text-sm text-gray-600 dark:text-gray-400">
            <p>&copy; <?= date('Y') ?> SistemaX - Multi-Empresa v1.0</p>
            <p class="mt-1">
                DB: <strong><?= htmlspecialchars(Session::getDbase() ?? 'N/A') ?></strong> |
                Usuario: <strong><?= htmlspecialchars($_SESSION['usuario'] ?? 'N/A') ?></strong>
            </p>
        </div>
    </div>
</footer>