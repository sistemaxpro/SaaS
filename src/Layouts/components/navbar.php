<nav class="bg-white dark:bg-slate-900 shadow-md dark:shadow-slate-950/50 border-b border-transparent dark:border-slate-800" x-data="{ mobileMenuOpen: false }">
    <div class="container mx-auto px-4">
        <div class="flex justify-between items-center h-16">
            <!-- Logo -->
            <div class="flex items-center">
                <a href="/" class="text-2xl font-bold text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
                    <i class="fas fa-cube mr-2"></i>SistemaX
                </a>
                <span class="ml-2 text-xs bg-green-500 dark:bg-green-600 text-white px-2 py-1 rounded shadow-sm">v1</span>
            </div>

            <!-- Desktop Menu -->
            <div class="hidden md:flex items-center space-x-6">
                <?php
                $empresaActual = MultiTenant::getEmpresaActual();
                if ($empresaActual):
                ?>
                    <span class="text-sm text-gray-600 dark:text-slate-400">
                        Empresa: <strong class="text-gray-900 dark:text-slate-200"><?= htmlspecialchars($empresaActual['empresa']) ?></strong>
                    </span>
                <?php endif; ?>

                <div x-data="{ dropdownOpen: false }" class="relative">
                    <button @click="dropdownOpen = !dropdownOpen" class="flex items-center space-x-2 border-2 border-blue-600 dark:border-blue-500 text-blue-600 dark:text-blue-400 font-semibold px-4 py-2 rounded-lg hover:bg-blue-600 hover:text-white dark:hover:bg-blue-500 dark:hover:text-white transition-all duration-200">
                        <i class="fas fa-user-circle text-xl"></i>
                        <span><?= htmlspecialchars($_SESSION['usuario'] ?? 'Usuario') ?></span>
                        <i class="fas fa-chevron-down text-xs"></i>
                    </button>

                    <div x-show="dropdownOpen" @click.away="dropdownOpen = false"
                        x-transition
                        class="absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800 rounded-lg shadow-xl py-2 z-50 border border-gray-200 dark:border-slate-700">
                        <a href="/api/v1/auth.php?action=logout" class="block px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                            <i class="fas fa-sign-out-alt mr-2"></i>Cerrar Sesión
                        </a>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu Button -->
            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </div>

        <!-- Mobile Menu -->
        <div x-show="mobileMenuOpen" x-transition class="md:hidden py-4 border-t dark:border-gray-700">
            <a href="/api/v1/auth.php?action=logout" class="block py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                <i class="fas fa-sign-out-alt mr-2"></i>Cerrar Sesión
            </a>
        </div>
    </div>
</nav>