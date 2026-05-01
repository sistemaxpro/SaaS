<?php

/**
 * Diagnóstico de sesión y credenciales
 */
session_start();

echo "=== DIAGNÓSTICO DE SESIÓN ===\n\n";

echo "Credenciales en sesión:\n";
echo "  - server: " . ($_SESSION['server'] ?? 'NO DEFINIDO') . "\n";
echo "  - user: " . ($_SESSION['user'] ?? 'NO DEFINIDO') . "\n";
echo "  - password: " . (isset($_SESSION['password']) ? '***' . substr($_SESSION['password'], -4) : 'NO DEFINIDO') . "\n";
echo "  - dbu: " . ($_SESSION['dbu'] ?? 'NO DEFINIDO') . "\n\n";

echo "Sesión de usuario:\n";
echo "  - id_login: " . ($_SESSION['id_login'] ?? 'NO DEFINIDO') . "\n";
echo "  - id_empresa: " . ($_SESSION['id_empresa'] ?? 'NO DEFINIDO') . "\n";
echo "  - usuario: " . ($_SESSION['usuario'] ?? 'NO DEFINIDO') . "\n\n";

echo "Todas las variables de sesión:\n";
print_r($_SESSION);
