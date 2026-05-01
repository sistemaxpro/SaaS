<?php
/**
 * API de Favoritos - Menú Mobile
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin();

header('Content-Type: application/json');

try {
    $pdo = Database::getMasterConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $id_login = (int)($input['id_login'] ?? 0);
        $id_menu = (int)($input['id_menu'] ?? 0);
        $action = $input['action'] ?? 'add';
        
        if (!$id_login || !$id_menu) {
            throw new Exception('Datos incompletos');
        }
        
        // Verificar que el usuario solo modifique sus propios favoritos
        if ($id_login !== Session::getIdLogin()) {
            throw new Exception('No autorizado');
        }
        
        if ($action === 'add') {
            // Crear tabla si no existe
            $pdo->exec("CREATE TABLE IF NOT EXISTS sec_favoritos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_login INT NOT NULL,
                id_menu INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_fav (id_login, id_menu)
            )");
            
            $stmt = $pdo->prepare("INSERT IGNORE INTO sec_favoritos (id_login, id_menu) VALUES (:login, :menu)");
            $stmt->execute([':login' => $id_login, ':menu' => $id_menu]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM sec_favoritos WHERE id_login = :login AND id_menu = :menu");
            $stmt->execute([':login' => $id_login, ':menu' => $id_menu]);
        }
        
        echo json_encode(['success' => true]);
    } else {
        // GET: Obtener favoritos
        $id_login = Session::getIdLogin();
        
        $stmt = $pdo->prepare("SELECT id_menu FROM sec_favoritos WHERE id_login = :id");
        $stmt->execute([':id' => $id_login]);
        $favoritos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode(['success' => true, 'favoritos' => $favoritos]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
