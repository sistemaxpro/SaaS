<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/bootstrap.php';
$pdo = Database::getMasterConnection();

// Test 1: COUNT all
$stmt1 = $pdo->prepare("SELECT COUNT(*) as cnt FROM saas_apps_catalogo");
$stmt1->execute();
$count = $stmt1->fetchColumn();

// Test 2: SELECT all distinct negocios
$stmt2 = $pdo->prepare("SELECT DISTINCT negocio FROM saas_apps_catalogo ORDER BY negocio");
$stmt2->execute();
$negocios = $stmt2->fetchAll(PDO::FETCH_COLUMN);

// Test 3: SELECT with exact string
$stmt3 = $pdo->prepare("SELECT COUNT(*) as cnt FROM saas_apps_catalogo WHERE negocio = 'Estación de Servicio'");
$stmt3->execute();
$estacionCount = $stmt3->fetchColumn();

// Test 4: SELECT with LIKE
$stmt4 = $pdo->prepare("SELECT COUNT(*) as cnt FROM saas_apps_catalogo WHERE negocio LIKE '%Estaci%'");
$stmt4->execute();
$estacionLike = $stmt4->fetchColumn();

// Test 5: Raw SELECT *
$stmt5 = $pdo->prepare("SELECT * FROM saas_apps_catalogo LIMIT 5");
$stmt5->execute();
$sample = $stmt5->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'total_apps' => $count,
    'negocios' => $negocios,
    'estacion_exact_count' => $estacionCount,
    'estacion_like_count' => $estacionLike,
    'sample_data' => $sample
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
