<?php
/**
 * Página 404 - App en Mantenimiento / No Encontrado
 * SistemaX v1
 */
http_response_code(404);

// Detectar si es una solicitud de API
$isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
if ($isApi) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Recurso no encontrado', 'code' => 404]);
    exit;
}

// Detectar tema del sistema
$prefersDark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark';
?>
<!DOCTYPE html>
<html lang="es" class="<?= $prefersDark ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App en Mantenimiento - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        // Detectar tema del sistema
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            overflow: hidden;
        }
        
        .dark body {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #1e1b4b 100%);
        }
        
        /* Animated background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .bg-animation span {
            position: absolute;
            display: block;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.1);
            animation: move 25s linear infinite;
            bottom: -150px;
            border-radius: 50%;
        }
        
        .bg-animation span:nth-child(1) { left: 25%; width: 80px; height: 80px; animation-delay: 0s; }
        .bg-animation span:nth-child(2) { left: 10%; width: 20px; height: 20px; animation-delay: 2s; animation-duration: 12s; }
        .bg-animation span:nth-child(3) { left: 70%; width: 20px; height: 20px; animation-delay: 4s; }
        .bg-animation span:nth-child(4) { left: 40%; width: 60px; height: 60px; animation-delay: 0s; animation-duration: 18s; }
        .bg-animation span:nth-child(5) { left: 65%; width: 20px; height: 20px; animation-delay: 0s; }
        .bg-animation span:nth-child(6) { left: 75%; width: 110px; height: 110px; animation-delay: 3s; }
        .bg-animation span:nth-child(7) { left: 35%; width: 150px; height: 150px; animation-delay: 7s; }
        .bg-animation span:nth-child(8) { left: 50%; width: 25px; height: 25px; animation-delay: 15s; animation-duration: 45s; }
        .bg-animation span:nth-child(9) { left: 20%; width: 15px; height: 15px; animation-delay: 2s; animation-duration: 35s; }
        .bg-animation span:nth-child(10) { left: 85%; width: 150px; height: 150px; animation-delay: 0s; animation-duration: 11s; }
        
        @keyframes move {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
                border-radius: 50%;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }
        
        /* Glass card */
        .glass-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            padding: 3rem;
            text-align: center;
            max-width: 480px;
            width: 90%;
            position: relative;
            z-index: 10;
        }
        
        .dark .glass-card {
            background: rgba(30, 27, 75, 0.6);
            border-color: rgba(139, 92, 246, 0.3);
        }
        
        /* Icon container */
        .icon-container {
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
            background: linear-gradient(145deg, rgba(99, 155, 255, 0.85) 0%, rgba(59, 130, 246, 0.75) 50%, rgba(37, 99, 235, 0.85) 100%);
            border-radius: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 
                0 10px 40px -5px rgba(37, 99, 235, 0.4),
                0 20px 50px -10px rgba(30, 64, 175, 0.25),
                inset 0 2px 10px rgba(255, 255, 255, 0.35);
            animation: pulse 2s ease-in-out infinite;
        }
        
        .icon-container svg {
            width: 60px;
            height: 60px;
            color: white;
            stroke-width: 1.5;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .subtitle {
            font-size: 1.125rem;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 2rem;
        }
        
        .message {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        
        /* Button */
        .btn-glass {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 14px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .btn-glass:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .btn-glass svg {
            width: 20px;
            height: 20px;
        }
        
        /* Error code */
        .error-code {
            position: absolute;
            bottom: -60px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 10rem;
            font-weight: 900;
            color: rgba(255, 255, 255, 0.05);
            pointer-events: none;
            user-select: none;
        }
        
        /* Logo */
        .logo {
            position: fixed;
            top: 24px;
            left: 24px;
            z-index: 20;
        }
        
        .logo img {
            height: 40px;
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }
    </style>
</head>
<body class="dark:bg-slate-900">
    <!-- Background animation -->
    <div class="bg-animation">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
    </div>
    
    <!-- Logo -->
    <div class="logo">
        <img src="assets/images/logo-sistemax-v1.svg" alt="SistemaX">
    </div>
    
    <!-- Main card -->
    <div class="glass-card">
        <div class="icon-container">
            <!-- Heroicon: wrench-screwdriver -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" />
            </svg>
        </div>
        
        <h1>App en Mantenimiento</h1>
        <p class="subtitle">Estamos mejorando tu experiencia</p>
        
        <p class="message">
            La página que buscas está temporalmente fuera de servicio o no existe. 
            Nuestro equipo está trabajando para resolver esto lo antes posible.
        </p>
        
        <a href="menu/menu.php" class="btn-glass">
            <!-- Heroicon: home -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
            </svg>
            Volver al Inicio
        </a>
        
        <div class="error-code">404</div>
    </div>
    
    <script>
        // Auto-redirect after 30 seconds
        setTimeout(() => {
            window.location.href = 'menu/menu.php';
        }, 30000);
    </script>
</body>
</html>
