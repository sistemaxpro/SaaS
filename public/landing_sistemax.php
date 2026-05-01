<?php
function l_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function l_shot_url(string $baseName): string
{
    $baseDir = __DIR__ . '/assets/img/landing/';
    $baseUrl = '/public/assets/img/landing/';
    $exts = ['png', 'jpg', 'jpeg', 'webp', 'avif'];
    foreach ($exts as $ext) {
        $disk = $baseDir . $baseName . '.' . $ext;
        if (is_file($disk)) {
            return $baseUrl . rawurlencode($baseName . '.' . $ext);
        }
    }
    return $baseUrl . rawurlencode($baseName . '.jpg');
}

function l_price_gs($value): string
{
    $num = (float)$value;
    if ($num <= 0) return 'Gs 0';
    return 'Gs ' . number_format($num, 0, ',', '.');
}

$modsReady = [];
$modsDev = [];
try {
    require_once __DIR__ . '/../config/bootstrap.php';
    if (class_exists('Database')) {
        $db = Database::getMasterConnection();
        $stmt = $db->query("
            SELECT
                COALESCE(nombre, '') AS nombre,
                COALESCE(codigo, '') AS codigo,
                COALESCE(descripcion, '') AS descripcion,
                COALESCE(modulo, '') AS modulo,
                COALESCE(negocio, '') AS negocio,
                COALESCE(precio_mensual, 0) AS precio_mensual,
                COALESCE(en_desarrollo, 0) AS en_desarrollo,
                COALESCE(activo, 1) AS activo
            FROM saas_apps_catalogo
            ORDER BY COALESCE(orden, 999), nombre ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = [
                'nombre' => trim((string)($row['nombre'] ?? '')),
                'codigo' => trim((string)($row['codigo'] ?? '')),
                'descripcion' => trim((string)($row['descripcion'] ?? '')),
                'modulo' => trim((string)($row['modulo'] ?? '')),
                'negocio' => trim((string)($row['negocio'] ?? '')),
                'precio_mensual' => (float)($row['precio_mensual'] ?? 0),
                'activo' => (int)($row['activo'] ?? 1) === 1,
            ];
            if ((int)($row['en_desarrollo'] ?? 0) === 1) {
                $modsDev[] = $item;
            } else {
                $modsReady[] = $item;
            }
        }
    }
} catch (Throwable $e) {
    // En landing pública no bloquear render por errores de DB.
}

$currentLocale = class_exists('SmxI18n') ? SmxI18n::getLocale() : 'es';
$localeOptions = class_exists('SmxI18n') ? SmxI18n::getLocaleOptions() : [
    ['code' => 'es', 'label' => 'Español', 'native' => 'ES'],
    ['code' => 'en', 'label' => 'English', 'native' => 'EN'],
    ['code' => 'pt', 'label' => 'Português', 'native' => 'PT'],
];
?><!doctype html>
<html lang="<?= l_esc($currentLocale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>sistemax.pro | ERP On Demand</title>
  <meta name="description" content="sistemax.pro: activa solo los modulos que necesitas y paga solo por lo que usas.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #f5f8ff;
      --surface: #ffffff;
      --ink: #0f172a;
      --muted: #42526b;
      --line: #d8e2f5;
      --brand: #0d66ff;
      --brand-2: #073ea5;
      --accent: #00a870;
      --hero-a: #e8f0ff;
      --hero-b: #f7fbff;
      --radius-xl: 26px;
      --radius-lg: 16px;
      --shadow: 0 18px 42px rgba(9, 38, 94, .12);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: "Manrope", sans-serif;
      background: radial-gradient(1000px 500px at 75% -10%, #d8e8ff 0%, transparent 60%), var(--bg);
      color: var(--ink);
    }

    h1, h2, h3, h4 { font-family: "Sora", sans-serif; margin: 0; }
    p { margin: 0; }

    .wrap {
      width: 100%;
      max-width: none;
      padding-inline: clamp(14px, 2vw, 30px);
      margin-inline: auto;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 20;
      backdrop-filter: blur(10px);
      background: rgba(245, 248, 255, 0.86);
      border-bottom: 1px solid var(--line);
    }

    .topbar-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 0;
      gap: 16px;
    }

    .brand {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: var(--ink);
      font-weight: 800;
      letter-spacing: .2px;
    }

    .brand-dot {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: linear-gradient(145deg, #1677ff, #073ea5);
      box-shadow: inset 0 0 0 2px rgba(255,255,255,.4);
    }

    .menu {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .locale-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid #c9dbff;
      background: #e9f1ff;
      color: var(--brand-2);
      border-radius: 999px;
      padding: 10px 14px;
      font-family: "Sora", sans-serif;
      font-size: 13px;
      font-weight: 700;
      position: relative;
    }

    .locale-select {
      appearance: none;
      border: 0;
      background: transparent;
      color: inherit;
      font: inherit;
      padding-right: 18px;
      cursor: pointer;
      outline: none;
    }

    .locale-chevron {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 10px;
      pointer-events: none;
    }

    .btn {
      border: 1px solid transparent;
      border-radius: 999px;
      padding: 10px 18px;
      font-family: "Sora", sans-serif;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      transition: .2s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
    }

    .btn-ghost {
      color: var(--brand-2);
      background: #e9f1ff;
      border-color: #c9dbff;
    }

    .btn-ghost:hover { transform: translateY(-1px); }

    .btn-primary {
      color: #fff;
      background: linear-gradient(145deg, var(--brand), var(--brand-2));
      box-shadow: 0 10px 22px rgba(13,102,255,.28);
    }

    .btn-primary:hover { transform: translateY(-1px); }

    .hero {
      padding: 72px 0 40px;
    }

    .hero-box {
      display: grid;
      grid-template-columns: 30% 70%;
      gap: 26px;
      background: linear-gradient(145deg, var(--hero-b), var(--hero-a));
      border: 1px solid #cfe0ff;
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow);
      overflow: hidden;
      position: relative;
      isolation: isolate;
      min-height: 62vh;
    }

    .hero-box::after {
      content: "";
      position: absolute;
      inset: auto -80px -110px auto;
      width: 340px;
      height: 340px;
      border-radius: 50%;
      background: radial-gradient(circle at center, rgba(13, 102, 255, .16), transparent 70%);
      z-index: -1;
    }

    .hero-copy {
      padding: 42px;
    }

    .eyebrow {
      font-size: 12px;
      font-family: "Sora", sans-serif;
      font-weight: 800;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--brand-2);
      margin-bottom: 14px;
    }

    .hero h1 {
      font-size: clamp(32px, 4.2vw, 58px);
      line-height: 1.05;
      letter-spacing: -.02em;
      margin-bottom: 14px;
    }

    .hero p {
      font-size: clamp(16px, 1.5vw, 19px);
      line-height: 1.55;
      color: var(--muted);
      max-width: 58ch;
      margin-bottom: 22px;
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 16px;
    }

    .hero-meta {
      display: grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap: 10px;
      max-width: 680px;
    }

    .meta {
      border: 1px solid #cad8ef;
      border-radius: 14px;
      background: #fff;
      padding: 12px;
    }

    .meta b {
      display: block;
      font-family: "Sora", sans-serif;
      font-size: 18px;
      margin-bottom: 4px;
    }

    .meta span {
      font-size: 12px;
      color: var(--muted);
    }

    .hero-visual {
      padding: 16px;
      display: grid;
      place-items: stretch;
      min-height: 620px;
      background: linear-gradient(170deg, #dbe9ff, #eff6ff 60%);
      border-left: 1px solid #cfe0ff;
    }

    .hero-showcase {
      position: relative;
      width: 100%;
      height: 100%;
      min-height: clamp(430px, 52vh, 680px);
      border-radius: 20px;
      border: 1px solid #bcd2f8;
      background: linear-gradient(180deg, #f7fbff, #e9f2ff);
      box-shadow: 0 22px 48px rgba(16, 56, 125, .18);
      overflow: hidden;
      isolation: isolate;
    }

    .hero-showcase::before {
      content: "";
      position: absolute;
      inset: -25% -10% auto;
      height: 60%;
      z-index: 0;
      background: radial-gradient(ellipse at center, rgba(104, 146, 255, .2) 0%, rgba(104, 146, 255, 0) 70%);
      animation: glowDrift 8s ease-in-out infinite;
      pointer-events: none;
    }

    .hero-slides {
      position: absolute;
      inset: 0;
      z-index: 1;
    }

    .hero-slide {
      position: absolute;
      inset: 0;
      opacity: 0;
      transition: opacity 1.6s ease;
      pointer-events: none;
    }

    .hero-slide img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: cover;
      transform: translate3d(0, 0, 0) scale(1.12);
      filter: blur(13px) saturate(.92) brightness(.92);
      transition: filter 3.8s ease, transform 5.8s ease;
    }

    .hero-slide.is-active {
      opacity: 1;
      z-index: 2;
    }

    .hero-slide.is-active img {
      filter: blur(0) saturate(1.04) brightness(1);
    }

    .hero-slide.fx-slide-left img { transform: translate3d(8%, 0, 0) scale(1.16); }
    .hero-slide.fx-slide-up img { transform: translate3d(0, 8%, 0) scale(1.16); }
    .hero-slide.fx-zoom img { transform: translate3d(0, 0, 0) scale(1.2); }

    .hero-slide.is-active.fx-slide-left img { transform: translate3d(0, 0, 0) scale(1.02); }
    .hero-slide.is-active.fx-slide-up img { transform: translate3d(0, 0, 0) scale(1.02); }
    .hero-slide.is-active.fx-zoom img { transform: translate3d(0, 0, 0) scale(1.01); }

    .hero-slide-caption {
      position: absolute;
      left: 16px;
      bottom: 16px;
      z-index: 3;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(220, 235, 255, .55);
      background: rgba(10, 25, 54, .62);
      color: #edf4ff;
      font-size: 12px;
      font-family: "Sora", sans-serif;
      font-weight: 700;
      letter-spacing: .03em;
      backdrop-filter: blur(4px);
    }

    @keyframes glowDrift {
      0%, 100% {
        transform: translate3d(0, 0, 0) scale(1);
      }
      50% {
        transform: translate3d(0, 12px, 0) scale(1.08);
      }
    }

    .panel-stack {
      width: min(460px, 100%);
      display: grid;
      gap: 12px;
      transform: perspective(900px) rotateY(-10deg) rotateX(5deg);
      transform-origin: center;
    }

    .pane {
      border: 1px solid #c0d3f6;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 10px 20px rgba(17, 65, 156, .12);
      padding: 14px;
    }

    .pane h4 {
      font-size: 15px;
      margin-bottom: 10px;
    }

    .pane-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap: 8px;
    }

    .mini {
      border: 1px solid #d8e5ff;
      border-radius: 10px;
      padding: 8px;
      font-size: 11px;
      color: #3f4f68;
      background: #f9fbff;
      text-align: center;
      font-weight: 700;
    }

    .flow, .gallery, .plans, .cta {
      padding: 42px 0;
    }

    .head {
      display: flex;
      align-items: end;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 20px;
    }

    .head h2 {
      font-size: clamp(24px, 3.1vw, 42px);
      letter-spacing: -.02em;
    }

    .head p {
      color: var(--muted);
      max-width: 58ch;
      line-height: 1.5;
    }

    .steps {
      display: grid;
      grid-template-columns: repeat(5, minmax(0,1fr));
      gap: 12px;
    }

    .step {
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      background: var(--surface);
      padding: 18px;
      box-shadow: 0 8px 20px rgba(29, 55, 94, .08);
    }

    .step .n {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-family: "Sora", sans-serif;
      font-size: 13px;
      font-weight: 800;
      color: #fff;
      background: linear-gradient(145deg, #0d66ff, #0a4dbf);
      margin-bottom: 10px;
    }

    .step h3 { font-size: 18px; margin-bottom: 8px; }
    .step p { color: var(--muted); font-size: 14px; line-height: 1.5; }

    .pricing {
      display: grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: 14px;
    }

    .tier {
      position: relative;
      border: 1px solid var(--line);
      border-radius: 22px;
      background: linear-gradient(180deg, #fff, #f7fbff);
      padding: 20px;
      box-shadow: 0 14px 32px rgba(8, 40, 98, .08);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .tier.featured {
      background: linear-gradient(180deg, #0c4ec2, #083487);
      border-color: rgba(8, 52, 135, .4);
      color: #fff;
      transform: translateY(-4px);
    }

    .tier.featured .money,
    .tier.featured h3,
    .tier.featured ul,
    .tier.featured small { color: #fff; }

    .tier h3 { font-size: 20px; margin-bottom: 6px; }
    .tier .money {
      font-family: "Sora", sans-serif;
      font-weight: 800;
      color: #0a4dbf;
      font-size: 32px;
      margin-bottom: 8px;
    }

    .tier small { color: var(--muted); display: block; margin-bottom: 14px; line-height: 1.5; }

    .tier ul {
      margin: 0;
      padding-left: 0;
      list-style: none;
      color: #243349;
      display: grid;
      gap: 10px;
      font-size: 14px;
    }

    .tier li {
      position: relative;
      padding-left: 22px;
      line-height: 1.45;
    }

    .tier li::before {
      content: "•";
      position: absolute;
      left: 6px;
      top: 0;
      color: #0a4dbf;
      font-weight: 800;
    }

    .tier.featured li::before { color: #93c5fd; }

    .tier-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      margin-bottom: 14px;
      background: #e0f2fe;
      color: #075985;
    }

    .tier.featured .tier-badge {
      background: rgba(255,255,255,.14);
      color: #dbeafe;
    }

    .tier-action {
      margin-top: 18px;
      width: 100%;
      justify-content: center;
    }

    .plans-compare {
      margin-top: 22px;
      border: 1px solid #cfdbef;
      border-radius: 24px;
      background: linear-gradient(180deg, #ffffff, #f7fbff);
      box-shadow: 0 16px 34px rgba(8, 40, 98, .08);
      overflow: hidden;
    }

    .plans-compare-head {
      padding: 18px 20px 10px;
      border-bottom: 1px solid #dbe5f5;
    }

    .plans-compare-head h3 {
      font-size: 20px;
      margin-bottom: 6px;
    }

    .plans-compare-head p {
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
    }

    .plans-table-wrap {
      overflow-x: auto;
    }

    .plans-table {
      width: 100%;
      min-width: 980px;
      border-collapse: collapse;
      font-size: 14px;
    }

    .plans-table thead th {
      background: #eef4ff;
      color: #0b2b63;
      font-family: "Sora", sans-serif;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .08em;
      padding: 16px 14px;
      border-bottom: 1px solid #dbe5f5;
      text-align: left;
    }

    .plans-table tbody tr:nth-child(odd) {
      background: rgba(239, 245, 255, .55);
    }

    .plans-table td {
      padding: 14px;
      border-bottom: 1px solid #e2eaf7;
      color: #203047;
      vertical-align: middle;
    }

    .plans-table tbody tr:last-child td {
      border-bottom: 0;
    }

    .plans-table td:first-child,
    .plans-table th:first-child {
      width: 220px;
      font-weight: 800;
      color: #0f172a;
      background: rgba(255,255,255,.86);
    }

    .plans-table .is-featured {
      background: linear-gradient(180deg, rgba(13, 102, 255, .1), rgba(7, 62, 165, .12));
      color: #0a3d9d;
      font-weight: 800;
    }

    .shots {
      display: grid;
      grid-template-columns: repeat(12, minmax(0,1fr));
      gap: 12px;
    }

    .shot {
      border: 1px solid #ccdaf2;
      border-radius: 14px;
      overflow: hidden;
      background: #fff;
      min-height: 220px;
      position: relative;
      box-shadow: 0 10px 22px rgba(8, 40, 98, .1);
    }

    .shot .thumb {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      background: linear-gradient(135deg, #d7e6ff, #e9f1ff 55%, #fdfefe);
    }

    .shot .tag {
      position: absolute;
      left: 10px;
      top: 10px;
      font-size: 11px;
      font-family: "Sora", sans-serif;
      font-weight: 700;
      color: #fff;
      background: rgba(7, 62, 165, .92);
      border: 1px solid rgba(255,255,255,.28);
      border-radius: 999px;
      padding: 5px 10px;
    }

    .shot .legend {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      padding: 10px 12px;
      font-size: 13px;
      color: #fff;
      font-weight: 700;
      background: linear-gradient(180deg, transparent, rgba(8, 28, 70, .86));
    }

    .span-6 { grid-column: span 6; }
    .span-4 { grid-column: span 4; }

    .cta-card {
      border: 1px solid #bed3fa;
      border-radius: var(--radius-xl);
      background: linear-gradient(145deg, #0d66ff, #0a4dbf);
      color: #fff;
      padding: clamp(24px, 4vw, 44px);
      box-shadow: 0 22px 44px rgba(13, 102, 255, .34);
      display: grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 20px;
      align-items: center;
    }

    .cta-card h2 {
      font-size: clamp(26px, 3.8vw, 48px);
      margin-bottom: 10px;
      letter-spacing: -.02em;
    }

    .cta-card p { opacity: .95; line-height: 1.55; }

    .cta-actions {
      display: flex;
      justify-content: end;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn-light {
      background: #fff;
      color: #0a4dbf;
    }

    .btn-outline {
      border-color: rgba(255,255,255,.5);
      color: #fff;
      background: rgba(255,255,255,.1);
    }

    footer {
      padding: 24px 0 44px;
      color: #53617c;
      font-size: 13px;
      text-align: center;
    }

    .mod-modal {
      position: fixed;
      inset: 0;
      background: rgba(9, 25, 59, .48);
      backdrop-filter: blur(6px);
      z-index: 80;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
    }

    .mod-modal.is-open { display: flex; }

    .mod-sheet {
      width: min(1120px, 100%);
      max-height: 88vh;
      overflow: auto;
      border-radius: 22px;
      border: 1px solid #bcd1f6;
      background: linear-gradient(180deg, #f7fbff, #eef5ff);
      box-shadow: 0 28px 56px rgba(7, 44, 120, .3);
    }

    .mod-head {
      position: sticky;
      top: 0;
      z-index: 2;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      padding: 16px 18px;
      border-bottom: 1px solid #c9daf8;
      background: rgba(237, 245, 255, .95);
      backdrop-filter: blur(6px);
    }

    .mod-head h3 { font-size: 22px; letter-spacing: -.01em; }
    .mod-head p { color: var(--muted); font-size: 14px; margin-top: 2px; }

    .mod-close {
      border: 1px solid #b8ccf4;
      background: #fff;
      color: #29406d;
      border-radius: 10px;
      width: 36px;
      height: 36px;
      font-size: 19px;
      line-height: 1;
      cursor: pointer;
    }

    .mod-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      padding: 16px;
    }

    .mod-tools {
      grid-column: 1 / -1;
      border: 1px solid #c8d9f7;
      border-radius: 14px;
      background: #fff;
      padding: 10px;
      display: grid;
      grid-template-columns: 1.3fr .7fr .7fr;
      gap: 10px;
      align-items: center;
    }

    .mod-tools input,
    .mod-tools select {
      width: 100%;
      border: 1px solid #cbdcf8;
      border-radius: 10px;
      padding: 9px 11px;
      font: inherit;
      background: #f7fbff;
      color: #1b2a43;
      outline: none;
    }

    .mod-tools input:focus,
    .mod-tools select:focus {
      border-color: #73a0f5;
      box-shadow: 0 0 0 3px rgba(17, 97, 238, .16);
      background: #fff;
    }

    .mod-col {
      border: 1px solid #c8d9f7;
      border-radius: 16px;
      background: #fff;
      overflow: hidden;
    }

    .mod-col-head {
      padding: 12px 14px;
      border-bottom: 1px solid #d7e4fb;
      background: #f6faff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .mod-col-head b { font-family: "Sora", sans-serif; font-size: 14px; }
    .mod-col-head span {
      font-size: 12px;
      border-radius: 999px;
      background: #e3eeff;
      border: 1px solid #c6dafc;
      padding: 4px 9px;
      font-weight: 700;
      color: #1d4b9f;
    }

    .mod-list {
      display: grid;
      gap: 10px;
      padding: 12px;
    }

    .mod-item {
      border: 1px solid #d8e4f8;
      border-radius: 12px;
      padding: 10px;
      background: #fbfdff;
    }

    .mod-item-top {
      display: flex;
      align-items: start;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 6px;
    }

    .mod-item h4 { font-size: 14px; margin: 0; }
    .mod-item code {
      font-size: 11px;
      color: #4f5f7b;
      background: #f1f6ff;
      border: 1px solid #d7e4fc;
      border-radius: 7px;
      padding: 2px 6px;
    }

    .mod-item p {
      font-size: 12px;
      color: #51617d;
      line-height: 1.45;
      margin-bottom: 7px;
    }

    .mod-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .chip {
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid #cfe0fb;
      background: #eef5ff;
      color: #1f4c9f;
      font-weight: 700;
    }

    .chip.price {
      color: #106147;
      border-color: #bde9da;
      background: #eafaf4;
    }

    .chip.state-off {
      color: #8a4b00;
      border-color: #ffd6a8;
      background: #fff4e5;
    }

    [data-reveal] {
      opacity: 0;
      transform: translateY(16px);
      transition: opacity .45s ease, transform .45s ease;
    }

    [data-reveal].is-in {
      opacity: 1;
      transform: translateY(0);
    }

    @media (max-width: 1024px) {
      .hero-box, .cta-card { grid-template-columns: 1fr; }
      .hero-box { min-height: auto; }
      .hero-visual { min-height: 400px; }
      .steps { grid-template-columns: repeat(2, minmax(0,1fr)); }
      .pricing { grid-template-columns: 1fr; }
      .plans-compare { border-radius: 18px; }
      .cta-actions { justify-content: start; }
    }

    @media (max-width: 760px) {
      .topbar-inner { flex-wrap: wrap; }
      .menu { width: 100%; }
      .menu .btn { flex: 1; justify-content: center; }
      .hero-copy { padding: 24px; }
      .hero-meta { grid-template-columns: 1fr; }
      .steps { grid-template-columns: 1fr; }
      .shots { grid-template-columns: 1fr; }
      .span-6, .span-4 { grid-column: auto; }
      .panel-stack { transform: none; }
      .hero-showcase {
        height: clamp(320px, 46vh, 500px);
      }
      .hero-showcase::before { display: none; }
      .hero-slide-caption {
        left: 10px;
        right: 10px;
        bottom: 10px;
        text-align: center;
      }
      .mod-tools { grid-template-columns: 1fr; }
      .mod-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body data-page-locale="<?= l_esc($currentLocale) ?>">
  <header class="topbar">
    <div class="wrap topbar-inner">
      <a class="brand" href="#inicio">
        <span class="brand-dot"></span>
        <span>sistemax.pro</span>
      </a>
      <nav class="menu">
        <label class="locale-pill" aria-label="Idioma">
          <select id="landingLocaleSwitcher" class="locale-select">
            <?php foreach ($localeOptions as $option): ?>
              <option value="<?= l_esc((string)$option['code']) ?>" <?= ((string)$option['code'] === $currentLocale) ? 'selected' : '' ?>>
                <?= l_esc((string)$option['native']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="locale-chevron">▼</span>
        </label>
        <a class="btn btn-ghost" href="#como-funciona" data-l10n="nav.how_it_works">Como funciona</a>
        <a class="btn btn-ghost" href="#modulos" data-l10n="nav.modules">Modulos</a>
        <a class="btn btn-ghost" href="/public/video_demo_sistemax_stock.html" target="_blank" rel="noopener" data-l10n="common.watch_demo">Ver video demo</a>
        <button id="openModulesModal" type="button" class="btn btn-ghost" data-l10n="nav.all_modules">Ver todos los modulos</button>
        <a class="btn btn-ghost" href="https://cliente.sistemax.pro" data-l10n="common.login_system">Ingresar al sistema</a>
        <a class="btn btn-primary" href="/public/suscribete.php?registro=1&v=20260327183227" data-l10n="common.try_now">Probar ahora</a>
      </nav>
    </div>
  </header>

  <div id="modulesModal" class="mod-modal" aria-hidden="true">
    <div class="mod-sheet" role="dialog" aria-modal="true" aria-label="Catalogo de modulos">
      <div class="mod-head">
        <div>
          <h3 data-l10n="modal.catalog_title">Catalogo completo de modulos</h3>
          <p data-l10n="modal.catalog_subtitle">Listado actualizado de apps ya creadas y apps en desarrollo.</p>
        </div>
        <button id="closeModulesModal" class="mod-close" type="button" aria-label="Cerrar">×</button>
      </div>
      <div class="mod-grid">
        <div class="mod-tools">
          <input id="modulesSearch" type="text" placeholder="Buscar modulo por nombre, codigo o descripcion..." data-l10n-placeholder="modal.search_placeholder">
          <select id="modulesFilterReady">
            <option value="" data-l10n="modal.created_all">Creados: todos los modulos</option>
            <?php
            $modsReadyTypes = [];
            foreach ($modsReady as $m) {
                $k = trim((string)($m['modulo'] ?? ''));
                if ($k !== '') $modsReadyTypes[$k] = true;
            }
            ksort($modsReadyTypes, SORT_NATURAL | SORT_FLAG_CASE);
            foreach (array_keys($modsReadyTypes) as $opt):
            ?>
              <option value="<?= l_esc($opt) ?>"><?= l_esc($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="modulesFilterDev">
            <option value="" data-l10n="modal.dev_all">Desarrollo: todos los modulos</option>
            <?php
            $modsDevTypes = [];
            foreach ($modsDev as $m) {
                $k = trim((string)($m['modulo'] ?? ''));
                if ($k !== '') $modsDevTypes[$k] = true;
            }
            ksort($modsDevTypes, SORT_NATURAL | SORT_FLAG_CASE);
            foreach (array_keys($modsDevTypes) as $opt):
            ?>
              <option value="<?= l_esc($opt) ?>"><?= l_esc($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <section class="mod-col">
          <div class="mod-col-head">
            <b data-l10n="modal.modules_ready">Modulos ya creados</b>
            <span><?= count($modsReady) ?></span>
          </div>
          <div class="mod-list">
            <?php if (empty($modsReady)): ?>
              <div class="mod-item"><p data-l10n="modal.no_data">Sin datos disponibles.</p></div>
            <?php else: ?>
              <?php foreach ($modsReady as $mod): ?>
                <article
                  class="mod-item"
                  data-item-type="ready"
                  data-modulo="<?= l_esc(mb_strtolower((string)($mod['modulo'] ?? ''), 'UTF-8')) ?>"
                  data-search="<?= l_esc(mb_strtolower(trim(($mod['nombre'] ?? '') . ' ' . ($mod['codigo'] ?? '') . ' ' . ($mod['descripcion'] ?? '')), 'UTF-8')) ?>"
                >
                  <div class="mod-item-top">
                    <h4><?= l_esc($mod['nombre'] !== '' ? $mod['nombre'] : 'Sin nombre') ?></h4>
                    <code><?= l_esc($mod['codigo'] !== '' ? $mod['codigo'] : 'sin_codigo') ?></code>
                  </div>
                  <p><?= l_esc($mod['descripcion'] !== '' ? $mod['descripcion'] : 'Modulo sin descripcion cargada.') ?></p>
                  <div class="mod-meta">
                    <?php if ($mod['modulo'] !== ''): ?><span class="chip"><?= l_esc($mod['modulo']) ?></span><?php endif; ?>
                    <?php if ($mod['negocio'] !== ''): ?><span class="chip"><?= l_esc($mod['negocio']) ?></span><?php endif; ?>
                    <span class="chip price"><?= l_esc(l_price_gs($mod['precio_mensual'])) ?>/mes</span>
                    <?php if (!$mod['activo']): ?><span class="chip state-off">Inactivo</span><?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
        <section class="mod-col">
          <div class="mod-col-head">
            <b data-l10n="modal.modules_dev">Modulos en desarrollo</b>
            <span><?= count($modsDev) ?></span>
          </div>
          <div class="mod-list">
            <?php if (empty($modsDev)): ?>
              <div class="mod-item"><p data-l10n="modal.no_dev">No hay modulos marcados en desarrollo.</p></div>
            <?php else: ?>
              <?php foreach ($modsDev as $mod): ?>
                <article
                  class="mod-item"
                  data-item-type="dev"
                  data-modulo="<?= l_esc(mb_strtolower((string)($mod['modulo'] ?? ''), 'UTF-8')) ?>"
                  data-search="<?= l_esc(mb_strtolower(trim(($mod['nombre'] ?? '') . ' ' . ($mod['codigo'] ?? '') . ' ' . ($mod['descripcion'] ?? '')), 'UTF-8')) ?>"
                >
                  <div class="mod-item-top">
                    <h4><?= l_esc($mod['nombre'] !== '' ? $mod['nombre'] : 'Sin nombre') ?></h4>
                    <code><?= l_esc($mod['codigo'] !== '' ? $mod['codigo'] : 'sin_codigo') ?></code>
                  </div>
                  <p><?= l_esc($mod['descripcion'] !== '' ? $mod['descripcion'] : 'Modulo en construccion.') ?></p>
                  <div class="mod-meta">
                    <?php if ($mod['modulo'] !== ''): ?><span class="chip"><?= l_esc($mod['modulo']) ?></span><?php endif; ?>
                    <?php if ($mod['negocio'] !== ''): ?><span class="chip"><?= l_esc($mod['negocio']) ?></span><?php endif; ?>
                    <span class="chip price"><?= l_esc(l_price_gs($mod['precio_mensual'])) ?>/mes</span>
                    <span class="chip state-off">En desarrollo</span>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>

  <section id="inicio" class="hero">
    <div class="wrap hero-box" data-reveal>
      <div class="hero-copy">
        <div class="eyebrow" data-l10n="hero.eyebrow">ERP Cloud On Demand</div>
        <h1 data-l10n="hero.title">Tu sistema crece por modulos, no por paquetes cerrados.</h1>
        <p data-l10n="hero.description">
          sistemax.pro te permite activar POS, Compras, Ventas, SIFEN, Usuarios y mas segun tu operacion.
          Contratas solo lo que necesitas hoy y escalas cuando tu negocio lo pide.
        </p>
        <div class="hero-actions">
          <a class="btn btn-primary" href="/public/suscribete.php?registro=1&v=20260327183227" data-l10n="hero.request_demo">Solicitar demo</a>
          <a class="btn btn-ghost" href="/public/video_demo_sistemax_stock.html" target="_blank" rel="noopener" data-l10n="common.watch_demo">Ver video demo</a>
          <a class="btn btn-ghost" href="https://cliente.sistemax.pro" data-l10n="common.login_system">Ingresar al sistema</a>
        </div>
        <div class="hero-meta">
          <article class="meta">
            <b data-l10n="hero.meta_1_title">Activacion inmediata</b>
            <span data-l10n="hero.meta_1_text">Modulos listos en minutos</span>
          </article>
          <article class="meta">
            <b data-l10n="hero.meta_2_title">Pago por uso</b>
            <span data-l10n="hero.meta_2_text">Plan mensual por app activa</span>
          </article>
          <article class="meta">
            <b data-l10n="hero.meta_3_title">Asistente IA</b>
            <span data-l10n="hero.meta_3_text">Soporte y operaciones guiadas</span>
          </article>
          <article class="meta">
            <b data-l10n="hero.meta_4_title">Listo para instalar</b>
            <span data-l10n="hero.meta_4_text">Instaladores para celular, tablet y PC (app de escritorio)</span>
          </article>
        </div>
      </div>
      <div class="hero-visual">
        <div class="hero-showcase">
          <div class="hero-slides" id="heroSlides">
            <div class="hero-slide is-active">
              <img src="<?= l_esc(l_shot_url('menu-modulos')) ?>" alt="Menu de modulos SistemaX">
              <div class="hero-slide-caption">Menu de modulos</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('pos')) ?>" alt="POS SistemaX">
              <div class="hero-slide-caption">POS</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('compras')) ?>" alt="Compras SistemaX">
              <div class="hero-slide-caption">Compras</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('cobro')) ?>" alt="Cobros SistemaX">
              <div class="hero-slide-caption">Cobros</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('sifen')) ?>" alt="SIFEN SistemaX">
              <div class="hero-slide-caption">SIFEN</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('balanza')) ?>" alt="Balanza SistemaX">
              <div class="hero-slide-caption">Balanza</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('usuarios')) ?>" alt="Usuarios SistemaX">
              <div class="hero-slide-caption">Usuarios</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('panel')) ?>" alt="Panel SistemaX">
              <div class="hero-slide-caption">Panel</div>
            </div>
            <div class="hero-slide">
              <img src="<?= l_esc(l_shot_url('suscripcion')) ?>" alt="Suscripcion SistemaX">
              <div class="hero-slide-caption">Suscripcion</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="plans">
    <div class="wrap">
      <div class="head" data-reveal>
        <div>
          <h2 data-l10n="plans.title">Planes pensados para crecer con Factura Electronica</h2>
          <p data-l10n="plans.text">Empezas con Gratis FE para emitir sin costo y escalas por capacidad, usuarios, sucursales y control.</p>
        </div>
      </div>
      <div class="pricing">
        <article class="tier" data-reveal>
          <div class="tier-badge" data-l10n="plans.free.badge">Factura Electronica gratis</div>
          <h3 data-l10n="plans.free.title">Gratis FE</h3>
          <div class="money" data-l10n="plans.free.price">Gs 0</div>
          <small data-l10n="plans.free.text">Ideal para empezar y emitir tus primeros comprobantes electronicos sin costo mensual.</small>
          <ul>
            <li data-l10n="plans.free.item_1">1 usuario, 1 sucursal y 1 caja</li>
            <li data-l10n="plans.free.item_2">Hasta 30 comprobantes por mes</li>
            <li data-l10n="plans.free.item_3">Hasta 100 productos y 100 clientes</li>
            <li data-l10n="plans.free.item_4">Stock basico y Powered by SistemaX</li>
          </ul>
          <a class="btn btn-primary tier-action" href="/public/suscribete.php?registro=1&plan=gratis_fe" data-l10n="plans.cta">Suscribirse</a>
        </article>
        <article class="tier" data-reveal>
          <div class="tier-badge" data-l10n="plans.start.badge">Plan de arranque</div>
          <h3 data-l10n="plans.start.title">Emprendedor FE</h3>
          <div class="money" data-l10n="plans.start.price">Gs 99.000</div>
          <small data-l10n="plans.start.text">Para negocios que ya venden todos los dias y necesitan mas capacidad operativa.</small>
          <ul>
            <li data-l10n="plans.start.item_1">2 usuarios y 2 cajas</li>
            <li data-l10n="plans.start.item_2">Hasta 300 comprobantes por mes</li>
            <li data-l10n="plans.start.item_3">Hasta 2.000 productos</li>
            <li data-l10n="plans.start.item_4">Presupuestos, pedidos, email y WhatsApp</li>
          </ul>
          <a class="btn btn-primary tier-action" href="/public/suscribete.php?registro=1&plan=emprendedor_fe" data-l10n="plans.cta">Suscribirse</a>
        </article>
        <article class="tier featured" data-reveal>
          <div class="tier-badge" data-l10n="plans.pro.badge">Mas recomendado</div>
          <h3 data-l10n="plans.pro.title">Pro FE</h3>
          <div class="money" data-l10n="plans.pro.price">Gs 199.000</div>
          <small data-l10n="plans.pro.text">Para equipos que necesitan mas control, mas usuarios y gestion profesional.</small>
          <ul>
            <li data-l10n="plans.pro.item_1">5 usuarios y hasta 3 sucursales</li>
            <li data-l10n="plans.pro.item_2">Hasta 3.000 comprobantes por mes</li>
            <li data-l10n="plans.pro.item_3">Productos ilimitados</li>
            <li data-l10n="plans.pro.item_4">Cuentas corrientes, reportes y permisos</li>
          </ul>
          <a class="btn btn-light tier-action" href="/public/suscribete.php?registro=1&plan=pro_fe" data-l10n="plans.cta">Suscribirse</a>
        </article>
        <article class="tier" data-reveal>
          <div class="tier-badge" data-l10n="plans.enterprise.badge">Modelo actual completo</div>
          <h3 data-l10n="plans.enterprise.title">Empresa FE</h3>
          <div class="money" data-l10n="plans.enterprise.price">A medida</div>
          <small data-l10n="plans.enterprise.text">Es el modelo actual completo de SistemaX, con toda la potencia operativa disponible.</small>
          <ul>
            <li data-l10n="plans.enterprise.item_1">Usuarios y sucursales segun necesidad</li>
            <li data-l10n="plans.enterprise.item_2">Alto volumen o sin limite practico</li>
            <li data-l10n="plans.enterprise.item_3">Integraciones y automatizaciones</li>
            <li data-l10n="plans.enterprise.item_4">Soporte prioritario y personalizacion</li>
          </ul>
          <a class="btn btn-primary tier-action" href="/public/suscribete.php?registro=1&plan=empresa_fe" data-l10n="plans.cta">Suscribirse</a>
        </article>
      </div>
      <div class="plans-compare" data-reveal>
        <div class="plans-compare-head">
          <h3>Comparativa completa de planes</h3>
          <p>Elegí el nivel de capacidad que necesita tu negocio hoy y escalá sin cambiar de sistema.</p>
        </div>
        <div class="plans-table-wrap">
          <table class="plans-table">
            <thead>
              <tr>
                <th>Plan</th>
                <th>Gratis FE</th>
                <th>Emprendedor FE</th>
                <th class="is-featured">Pro FE</th>
                <th>Empresa FE</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>Precio mensual</td><td>Gs. 0</td><td>Gs. 99.000</td><td class="is-featured">Gs. 199.000</td><td>A medida</td></tr>
              <tr><td>Factura electrónica</td><td>Sí</td><td>Sí</td><td class="is-featured">Sí</td><td>Sí</td></tr>
              <tr><td>Usuarios</td><td>1</td><td>2</td><td class="is-featured">5</td><td>Según necesidad</td></tr>
              <tr><td>Sucursales</td><td>1</td><td>1</td><td class="is-featured">3</td><td>Según necesidad</td></tr>
              <tr><td>Cajas</td><td>1</td><td>2</td><td class="is-featured">Múltiples</td><td>Múltiples</td></tr>
              <tr><td>Comprobantes por mes</td><td>30</td><td>300</td><td class="is-featured">3.000</td><td>Alto volumen</td></tr>
              <tr><td>Productos</td><td>100</td><td>2.000</td><td class="is-featured">Ilimitados</td><td>Ilimitados</td></tr>
              <tr><td>Clientes</td><td>100</td><td>Ilimitados</td><td class="is-featured">Ilimitados</td><td>Ilimitados</td></tr>
              <tr><td>Proveedores</td><td>20</td><td>Ilimitados</td><td class="is-featured">Ilimitados</td><td>Ilimitados</td></tr>
              <tr><td>Stock</td><td>Básico</td><td>Completo</td><td class="is-featured">Completo</td><td>Completo</td></tr>
              <tr><td>Presupuestos</td><td>No</td><td>Sí</td><td class="is-featured">Sí</td><td>Sí</td></tr>
              <tr><td>Pedidos</td><td>No</td><td>Sí</td><td class="is-featured">Sí</td><td>Sí</td></tr>
              <tr><td>Cuentas corrientes</td><td>No</td><td>Básico</td><td class="is-featured">Avanzado</td><td>Avanzado</td></tr>
              <tr><td>Reportes</td><td>Básicos</td><td>Básicos</td><td class="is-featured">Avanzados</td><td>Avanzados</td></tr>
              <tr><td>Impresión directa</td><td>No</td><td>Sí</td><td class="is-featured">Sí</td><td>Sí</td></tr>
              <tr><td>WhatsApp / Email</td><td>No</td><td>Sí</td><td class="is-featured">Sí</td><td>Sí</td></tr>
              <tr><td>Permisos por usuario</td><td>No</td><td>No</td><td class="is-featured">Sí</td><td>Sí</td></tr>
              <tr><td>API / Integraciones</td><td>No</td><td>No</td><td class="is-featured">Opcional</td><td>Sí</td></tr>
              <tr><td>Soporte</td><td>Limitado</td><td>Normal</td><td class="is-featured">Prioritario</td><td>Prioritario</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="cta">
    <div class="wrap">
      <div class="cta-card" data-reveal>
        <div>
          <h2 data-l10n="cta.title">Activa SistemaX por etapas, sin friccion.</h2>
          <p data-l10n="cta.text">Inicia con los modulos clave y escala cuando tu operacion lo exija. Plataforma lista para retail, servicios y empresas con facturacion electronica.</p>
        </div>
        <div class="cta-actions">
          <a class="btn btn-light" href="/public/suscribete.php?registro=1&v=20260327183227" data-l10n="cta.start_now">Empezar ahora</a>
          <a class="btn btn-outline" href="/public/video_demo_sistemax_stock.html" target="_blank" rel="noopener" data-l10n="common.watch_demo">Ver video demo</a>
          <a class="btn btn-outline" href="https://cliente.sistemax.pro" data-l10n="cta.have_account">Ya tengo cuenta</a>
        </div>
      </div>
    </div>
  </section>

  <section id="como-funciona" class="flow">
    <div class="wrap">
      <div class="head" data-reveal>
        <div>
          <h2 data-l10n="flow.title">Como funciona el modelo on demand</h2>
          <p data-l10n="flow.text">Flujo simple: configuramos tu base, activas apps por necesidad y administras costos por modulo desde Mi Suscripcion.</p>
        </div>
      </div>
      <div class="steps">
        <article class="step" data-reveal>
          <div class="n">1</div>
          <h3 data-l10n="flow.step_1_title">Alta de empresa</h3>
          <p data-l10n="flow.step_1_text">Se crea tu entorno, datos fiscales y usuario administrador con acceso inmediato.</p>
        </article>
        <article class="step" data-reveal>
          <div class="n">2</div>
          <h3 data-l10n="flow.step_2_title">Eliges modulos</h3>
          <p data-l10n="flow.step_2_text">Activas POS, Compras, Ventas, SIFEN, Balanza o los que tu operacion necesita.</p>
        </article>
        <article class="step" data-reveal>
          <div class="n">3</div>
          <h3 data-l10n="flow.step_3_title">Operas en tiempo real</h3>
          <p data-l10n="flow.step_3_text">Todo en una sola plataforma con paneles, controles de usuarios y flujo de caja.</p>
        </article>
        <article class="step" data-reveal>
          <div class="n">4</div>
          <h3 data-l10n="flow.step_4_title">Ajustas cuando quieras</h3>
          <p data-l10n="flow.step_4_text">Desde Mi Suscripcion agregas o cancelas apps. Pagas solo por lo activo.</p>
        </article>
        <article class="step" data-reveal>
          <div class="n">5</div>
          <h3 data-l10n="flow.step_5_title">Instalas donde trabajas</h3>
          <p data-l10n="flow.step_5_text">Incluye instaladores para Android/tablet y funciones para instalar en PC como aplicativo.</p>
        </article>
      </div>
    </div>
  </section>

  <section id="modulos" class="gallery">
    <div class="wrap">
      <div class="head" data-reveal>
        <div>
          <h2 data-l10n="gallery.title">Capturas reales de modulos</h2>
          <p data-l10n="gallery.text">Galeria lista para montar tus prints. Solo reemplaza las imagenes en /public/assets/img/landing/ con los mismos nombres.</p>
        </div>
      </div>
      <div class="shots">
        <article class="shot span-6" data-reveal>
          <span class="tag">Vista General</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('menu-modulos')) ?>" alt="Menu de modulos" loading="lazy">
          <div class="legend">Home de apps activas con asistente IA</div>
        </article>
        <article class="shot span-6" data-reveal>
          <span class="tag">POS</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('pos')) ?>" alt="Modulo POS" loading="lazy">
          <div class="legend">Punto de venta con carrito y estado de caja</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">Compras</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('compras')) ?>" alt="Modulo compras" loading="lazy">
          <div class="legend">Registro de facturas con OCR y carga asistida</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">Cobros</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('cobro')) ?>" alt="Modulo cobros" loading="lazy">
          <div class="legend">Gestion de cobros y seguimiento de cuentas.</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">SIFEN</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('sifen')) ?>" alt="Modulo SIFEN" loading="lazy">
          <div class="legend">Configuracion fiscal y validacion de contribuyente</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">Balanza</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('balanza')) ?>" alt="Modulo balanza" loading="lazy">
          <div class="legend">Parametrizacion de modelos y pruebas de lectura</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">Usuarios</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('usuarios')) ?>" alt="Modulo usuarios" loading="lazy">
          <div class="legend">Roles, accesos y control de cuentas</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">Panel</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('panel')) ?>" alt="Panel de analitica" loading="lazy">
          <div class="legend">Analitica comercial, metodos de pago y top productos</div>
        </article>
        <article class="shot span-4" data-reveal>
          <span class="tag">Suscripcion</span>
          <img class="thumb" src="<?= l_esc(l_shot_url('suscripcion')) ?>" alt="Modulo de suscripcion" loading="lazy">
          <div class="legend">Gestion mensual de apps activas y costos on demand</div>
        </article>
      </div>
    </div>
  </section>

  <footer data-l10n="footer.caption">
    sistemax.pro · ERP On Demand · Paraguay
  </footer>

  <script>
    (function () {
      const landingTranslations = {
        es: {
          'nav.how_it_works': 'Como funciona',
          'nav.modules': 'Modulos',
          'nav.all_modules': 'Ver todos los modulos',
          'common.watch_demo': 'Ver video demo',
          'common.login_system': 'Ingresar al sistema',
          'common.try_now': 'Probar ahora',
          'modal.catalog_title': 'Catalogo completo de modulos',
          'modal.catalog_subtitle': 'Listado actualizado de apps ya creadas y apps en desarrollo.',
          'modal.search_placeholder': 'Buscar modulo por nombre, codigo o descripcion...',
          'modal.created_all': 'Creados: todos los modulos',
          'modal.dev_all': 'Desarrollo: todos los modulos',
          'modal.modules_ready': 'Modulos ya creados',
          'modal.modules_dev': 'Modulos en desarrollo',
          'modal.no_data': 'Sin datos disponibles.',
          'modal.no_dev': 'No hay modulos marcados en desarrollo.',
          'hero.eyebrow': 'ERP Cloud On Demand',
          'hero.title': 'Tu sistema crece por modulos, no por paquetes cerrados.',
          'hero.description': 'sistemax.pro te permite activar POS, Compras, Ventas, SIFEN, Usuarios y mas segun tu operacion. Contratas solo lo que necesitas hoy y escalas cuando tu negocio lo pide.',
          'hero.request_demo': 'Solicitar demo',
          'hero.meta_1_title': 'Activacion inmediata',
          'hero.meta_1_text': 'Modulos listos en minutos',
          'hero.meta_2_title': 'Pago por uso',
          'hero.meta_2_text': 'Plan mensual por app activa',
          'hero.meta_3_title': 'Asistente IA',
          'hero.meta_3_text': 'Soporte y operaciones guiadas',
          'hero.meta_4_title': 'Listo para instalar',
          'hero.meta_4_text': 'Instaladores para celular, tablet y PC (app de escritorio)',
          'cta.title': 'Activa SistemaX por etapas, sin friccion.',
          'cta.text': 'Inicia con los modulos clave y escala cuando tu operacion lo exija. Plataforma lista para retail, servicios y empresas con facturacion electronica.',
          'cta.start_now': 'Empezar ahora',
          'cta.have_account': 'Ya tengo cuenta',
          'flow.title': 'Como funciona el modelo on demand',
          'flow.text': 'Flujo simple: configuramos tu base, activas apps por necesidad y administras costos por modulo desde Mi Suscripcion.',
          'flow.step_1_title': 'Alta de empresa',
          'flow.step_1_text': 'Se crea tu entorno, datos fiscales y usuario administrador con acceso inmediato.',
          'flow.step_2_title': 'Eliges modulos',
          'flow.step_2_text': 'Activas POS, Compras, Ventas, SIFEN, Balanza o los que tu operacion necesita.',
          'flow.step_3_title': 'Operas en tiempo real',
          'flow.step_3_text': 'Todo en una sola plataforma con paneles, controles de usuarios y flujo de caja.',
          'flow.step_4_title': 'Ajustas cuando quieras',
          'flow.step_4_text': 'Desde Mi Suscripcion agregas o cancelas apps. Pagas solo por lo activo.',
          'flow.step_5_title': 'Instalas donde trabajas',
          'flow.step_5_text': 'Incluye instaladores para Android/tablet y funciones para instalar en PC como aplicativo.',
          'plans.title': 'Planes pensados para crecer con Factura Electronica',
          'plans.text': 'Empezas con Gratis FE para emitir sin costo y escalas por capacidad, usuarios, sucursales y control.',
          'plans.free.badge': 'Factura Electronica gratis',
          'plans.free.title': 'Gratis FE',
          'plans.free.price': 'Gs 0',
          'plans.free.text': 'Ideal para empezar y emitir tus primeros comprobantes electronicos sin costo mensual.',
          'plans.free.item_1': '1 usuario, 1 sucursal y 1 caja',
          'plans.free.item_2': 'Hasta 30 comprobantes por mes',
          'plans.free.item_3': 'Hasta 100 productos y 100 clientes',
          'plans.free.item_4': 'Stock basico y Powered by SistemaX',
          'plans.start.badge': 'Plan de arranque',
          'plans.start.title': 'Emprendedor FE',
          'plans.start.price': 'Gs 99.000',
          'plans.start.text': 'Para negocios que ya venden todos los dias y necesitan mas capacidad operativa.',
          'plans.start.item_1': '2 usuarios y 2 cajas',
          'plans.start.item_2': 'Hasta 300 comprobantes por mes',
          'plans.start.item_3': 'Hasta 2.000 productos',
          'plans.start.item_4': 'Presupuestos, pedidos, email y WhatsApp',
          'plans.pro.badge': 'Mas recomendado',
          'plans.pro.title': 'Pro FE',
          'plans.pro.price': 'Gs 199.000',
          'plans.pro.text': 'Para equipos que necesitan mas control, mas usuarios y gestion profesional.',
          'plans.pro.item_1': '5 usuarios y hasta 3 sucursales',
          'plans.pro.item_2': 'Hasta 3.000 comprobantes por mes',
          'plans.pro.item_3': 'Productos ilimitados',
          'plans.pro.item_4': 'Cuentas corrientes, reportes y permisos',
          'plans.enterprise.badge': 'Modelo actual completo',
          'plans.enterprise.title': 'Empresa FE',
          'plans.enterprise.price': 'A medida',
          'plans.enterprise.text': 'Es el modelo actual completo de SistemaX, con toda la potencia operativa disponible.',
          'plans.enterprise.item_1': 'Usuarios y sucursales segun necesidad',
          'plans.enterprise.item_2': 'Alto volumen o sin limite practico',
          'plans.enterprise.item_3': 'Integraciones y automatizaciones',
          'plans.enterprise.item_4': 'Soporte prioritario y personalizacion',
          'plans.cta': 'Suscribirse',
          'gallery.title': 'Capturas reales de modulos',
          'gallery.text': 'Galeria lista para montar tus prints. Solo reemplaza las imagenes en /public/assets/img/landing/ con los mismos nombres.',
          'footer.caption': 'sistemax.pro · ERP On Demand · Paraguay'
        },
        en: {
          'nav.how_it_works': 'How it works',
          'nav.modules': 'Modules',
          'nav.all_modules': 'View all modules',
          'common.watch_demo': 'Watch demo video',
          'common.login_system': 'Log in',
          'common.try_now': 'Try now',
          'modal.catalog_title': 'Full module catalog',
          'modal.catalog_subtitle': 'Updated list of apps already built and apps still in development.',
          'modal.search_placeholder': 'Search module by name, code or description...',
          'modal.created_all': 'Built: all modules',
          'modal.dev_all': 'Development: all modules',
          'modal.modules_ready': 'Modules already built',
          'modal.modules_dev': 'Modules in development',
          'modal.no_data': 'No data available.',
          'modal.no_dev': 'There are no modules marked as in development.',
          'hero.eyebrow': 'ERP Cloud On Demand',
          'hero.title': 'Your system grows through modules, not closed packages.',
          'hero.description': 'sistemax.pro lets you enable POS, Purchases, Sales, SIFEN, Users and more according to your operation. You pay only for what you need today and scale when your business requires it.',
          'hero.request_demo': 'Request demo',
          'hero.meta_1_title': 'Instant activation',
          'hero.meta_1_text': 'Modules ready in minutes',
          'hero.meta_2_title': 'Pay per use',
          'hero.meta_2_text': 'Monthly plan per active app',
          'hero.meta_3_title': 'AI assistant',
          'hero.meta_3_text': 'Guided support and operations',
          'hero.meta_4_title': 'Ready to install',
          'hero.meta_4_text': 'Installers for mobile, tablet and PC (desktop app)',
          'cta.title': 'Enable SistemaX in stages, with no friction.',
          'cta.text': 'Start with the key modules and scale when your operation demands it. Platform ready for retail, services and electronic invoicing businesses.',
          'cta.start_now': 'Start now',
          'cta.have_account': 'I already have an account',
          'flow.title': 'How the on-demand model works',
          'flow.text': 'Simple flow: we configure your database, you activate apps as needed and manage costs per module from My Subscription.',
          'flow.step_1_title': 'Company setup',
          'flow.step_1_text': 'Your environment, tax data and administrator user are created with immediate access.',
          'flow.step_2_title': 'Choose modules',
          'flow.step_2_text': 'Enable POS, Purchases, Sales, SIFEN, Scale or whatever your operation needs.',
          'flow.step_3_title': 'Operate in real time',
          'flow.step_3_text': 'Everything in one platform with dashboards, user controls and cash flow.',
          'flow.step_4_title': 'Adjust anytime',
          'flow.step_4_text': 'From My Subscription you add or cancel apps. You only pay for what is active.',
          'flow.step_5_title': 'Install where you work',
          'flow.step_5_text': 'Includes installers for Android/tablet and functions to install on PC as an app.',
          'plans.title': 'Plans built to grow with Electronic Invoicing',
          'plans.text': 'Start with Free FE to issue at no monthly cost and scale by capacity, users, branches and control.',
          'plans.free.badge': 'Free electronic invoicing',
          'plans.free.title': 'Free FE',
          'plans.free.price': 'Gs 0',
          'plans.free.text': 'Ideal to start and issue your first electronic documents with no monthly fee.',
          'plans.free.item_1': '1 user, 1 branch and 1 register',
          'plans.free.item_2': 'Up to 30 documents per month',
          'plans.free.item_3': 'Up to 100 products and 100 customers',
          'plans.free.item_4': 'Basic stock and Powered by SistemaX',
          'plans.start.badge': 'Starter plan',
          'plans.start.title': 'Entrepreneur FE',
          'plans.start.price': 'Gs 99,000',
          'plans.start.text': 'For businesses already selling every day and needing more operational capacity.',
          'plans.start.item_1': '2 users and 2 registers',
          'plans.start.item_2': 'Up to 300 documents per month',
          'plans.start.item_3': 'Up to 2,000 products',
          'plans.start.item_4': 'Quotes, purchase orders, email and WhatsApp',
          'plans.pro.badge': 'Most recommended',
          'plans.pro.title': 'Pro FE',
          'plans.pro.price': 'Gs 199,000',
          'plans.pro.text': 'For teams that need more control, more users and professional management.',
          'plans.pro.item_1': '5 users and up to 3 branches',
          'plans.pro.item_2': 'Up to 3,000 documents per month',
          'plans.pro.item_3': 'Unlimited products',
          'plans.pro.item_4': 'Accounts receivable, reports and permissions',
          'plans.enterprise.badge': 'Current full model',
          'plans.enterprise.title': 'Enterprise FE',
          'plans.enterprise.price': 'Custom',
          'plans.enterprise.text': 'This is the current full SistemaX model with all available operational power.',
          'plans.enterprise.item_1': 'Users and branches as needed',
          'plans.enterprise.item_2': 'High volume or no practical limit',
          'plans.enterprise.item_3': 'Integrations and automations',
          'plans.enterprise.item_4': 'Priority support and customization',
          'plans.cta': 'Subscribe',
          'gallery.title': 'Real module screenshots',
          'gallery.text': 'Gallery ready for your screenshots. Just replace the images in /public/assets/img/landing/ using the same file names.',
          'footer.caption': 'sistemax.pro · ERP On Demand · Paraguay'
        },
        pt: {
          'nav.how_it_works': 'Como funciona',
          'nav.modules': 'Módulos',
          'nav.all_modules': 'Ver todos os módulos',
          'common.watch_demo': 'Ver vídeo demo',
          'common.login_system': 'Entrar no sistema',
          'common.try_now': 'Testar agora',
          'modal.catalog_title': 'Catálogo completo de módulos',
          'modal.catalog_subtitle': 'Lista atualizada de apps já criados e apps em desenvolvimento.',
          'modal.search_placeholder': 'Buscar módulo por nome, código ou descrição...',
          'modal.created_all': 'Criados: todos os módulos',
          'modal.dev_all': 'Desenvolvimento: todos os módulos',
          'modal.modules_ready': 'Módulos já criados',
          'modal.modules_dev': 'Módulos em desenvolvimento',
          'modal.no_data': 'Sem dados disponíveis.',
          'modal.no_dev': 'Não há módulos marcados em desenvolvimento.',
          'hero.eyebrow': 'ERP Cloud On Demand',
          'hero.title': 'Seu sistema cresce por módulos, não por pacotes fechados.',
          'hero.description': 'sistemax.pro permite ativar POS, Compras, Vendas, SIFEN, Usuários e mais conforme sua operação. Você contrata apenas o que precisa hoje e escala quando seu negócio exigir.',
          'hero.request_demo': 'Solicitar demonstração',
          'hero.meta_1_title': 'Ativação imediata',
          'hero.meta_1_text': 'Módulos prontos em minutos',
          'hero.meta_2_title': 'Pague pelo uso',
          'hero.meta_2_text': 'Plano mensal por app ativo',
          'hero.meta_3_title': 'Assistente IA',
          'hero.meta_3_text': 'Suporte e operações guiadas',
          'hero.meta_4_title': 'Pronto para instalar',
          'hero.meta_4_text': 'Instaladores para celular, tablet e PC (app desktop)',
          'cta.title': 'Ative o SistemaX por etapas, sem fricção.',
          'cta.text': 'Comece com os módulos-chave e escale quando sua operação exigir. Plataforma pronta para varejo, serviços e empresas com faturamento eletrônico.',
          'cta.start_now': 'Começar agora',
          'cta.have_account': 'Já tenho conta',
          'flow.title': 'Como funciona o modelo on demand',
          'flow.text': 'Fluxo simples: configuramos sua base, você ativa apps conforme a necessidade e administra custos por módulo em Minha Assinatura.',
          'flow.step_1_title': 'Cadastro da empresa',
          'flow.step_1_text': 'Seu ambiente, dados fiscais e usuário administrador são criados com acesso imediato.',
          'flow.step_2_title': 'Escolha os módulos',
          'flow.step_2_text': 'Ative POS, Compras, Vendas, SIFEN, Balança ou os módulos que sua operação precisa.',
          'flow.step_3_title': 'Opere em tempo real',
          'flow.step_3_text': 'Tudo em uma única plataforma com painéis, controles de usuários e fluxo de caixa.',
          'flow.step_4_title': 'Ajuste quando quiser',
          'flow.step_4_text': 'Em Minha Assinatura você adiciona ou cancela apps. Paga apenas pelo que estiver ativo.',
          'flow.step_5_title': 'Instale onde trabalha',
          'flow.step_5_text': 'Inclui instaladores para Android/tablet e funções para instalar no PC como aplicativo.',
          'plans.title': 'Planos pensados para crescer com Fatura Eletrônica',
          'plans.text': 'Você começa com Gratis FE para emitir sem custo e escala por capacidade, usuários, filiais e controle.',
          'plans.free.badge': 'Fatura eletrônica grátis',
          'plans.free.title': 'Gratis FE',
          'plans.free.price': 'Gs 0',
          'plans.free.text': 'Ideal para começar e emitir seus primeiros comprovantes eletrônicos sem mensalidade.',
          'plans.free.item_1': '1 usuário, 1 filial e 1 caixa',
          'plans.free.item_2': 'Até 30 comprovantes por mês',
          'plans.free.item_3': 'Até 100 produtos e 100 clientes',
          'plans.free.item_4': 'Estoque básico e Powered by SistemaX',
          'plans.start.badge': 'Plano inicial',
          'plans.start.title': 'Empreendedor FE',
          'plans.start.price': 'Gs 99.000',
          'plans.start.text': 'Para negócios que já vendem todos os dias e precisam de mais capacidade operacional.',
          'plans.start.item_1': '2 usuários e 2 caixas',
          'plans.start.item_2': 'Até 300 comprovantes por mês',
          'plans.start.item_3': 'Até 2.000 produtos',
          'plans.start.item_4': 'Orçamentos, pedidos, email e WhatsApp',
          'plans.pro.badge': 'Mais recomendado',
          'plans.pro.title': 'Pro FE',
          'plans.pro.price': 'Gs 199.000',
          'plans.pro.text': 'Para equipes que precisam de mais controle, mais usuários e gestão profissional.',
          'plans.pro.item_1': '5 usuários e até 3 filiais',
          'plans.pro.item_2': 'Até 3.000 comprovantes por mês',
          'plans.pro.item_3': 'Produtos ilimitados',
          'plans.pro.item_4': 'Contas correntes, relatórios e permissões',
          'plans.enterprise.badge': 'Modelo atual completo',
          'plans.enterprise.title': 'Empresa FE',
          'plans.enterprise.price': 'Sob medida',
          'plans.enterprise.text': 'É o modelo atual completo do SistemaX, com toda a potência operacional disponível.',
          'plans.enterprise.item_1': 'Usuários e filiais conforme a necessidade',
          'plans.enterprise.item_2': 'Alto volume ou sem limite prático',
          'plans.enterprise.item_3': 'Integrações e automações',
          'plans.enterprise.item_4': 'Suporte prioritário e personalização',
          'plans.cta': 'Assinar',
          'gallery.title': 'Capturas reais dos módulos',
          'gallery.text': 'Galeria pronta para suas capturas. Basta substituir as imagens em /public/assets/img/landing/ mantendo os mesmos nomes.',
          'footer.caption': 'sistemax.pro · ERP On Demand · Paraguai'
        }
      };

      const applyLandingLocale = (locale) => {
        const dict = landingTranslations[locale] || landingTranslations.es;
        document.querySelectorAll("[data-l10n]").forEach((el) => {
          const key = el.getAttribute("data-l10n");
          if (key && Object.prototype.hasOwnProperty.call(dict, key)) {
            el.textContent = dict[key];
          }
        });
        document.querySelectorAll("[data-l10n-placeholder]").forEach((el) => {
          const key = el.getAttribute("data-l10n-placeholder");
          if (key && Object.prototype.hasOwnProperty.call(dict, key)) {
            el.setAttribute("placeholder", dict[key]);
          }
        });
      };

      const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) entry.target.classList.add("is-in");
        });
      }, { threshold: .12 });
      document.querySelectorAll("[data-reveal]").forEach((el) => io.observe(el));

      const modulesModal = document.getElementById("modulesModal");
      const openModulesModal = document.getElementById("openModulesModal");
      const closeModulesModal = document.getElementById("closeModulesModal");
      const closeModal = () => {
        if (!modulesModal) return;
        modulesModal.classList.remove("is-open");
        modulesModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
      };
      const openModal = () => {
        if (!modulesModal) return;
        modulesModal.classList.add("is-open");
        modulesModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
      };
      if (openModulesModal) openModulesModal.addEventListener("click", openModal);
      if (closeModulesModal) closeModulesModal.addEventListener("click", closeModal);
      if (modulesModal) {
        modulesModal.addEventListener("click", (ev) => {
          if (ev.target === modulesModal) closeModal();
        });
      }
      document.addEventListener("keydown", (ev) => {
        if (ev.key === "Escape") closeModal();
      });

      const modulesSearch = document.getElementById("modulesSearch");
      const modulesFilterReady = document.getElementById("modulesFilterReady");
      const modulesFilterDev = document.getElementById("modulesFilterDev");
      const localeSwitcher = document.getElementById("landingLocaleSwitcher");
      const moduleCards = Array.from(document.querySelectorAll(".mod-item[data-item-type]"));
      const applyModuleFilters = () => {
        const q = String(modulesSearch?.value || "").trim().toLowerCase();
        const readyFilter = String(modulesFilterReady?.value || "").trim().toLowerCase();
        const devFilter = String(modulesFilterDev?.value || "").trim().toLowerCase();
        moduleCards.forEach((card) => {
          const type = card.getAttribute("data-item-type") || "";
          const modulo = String(card.getAttribute("data-modulo") || "").toLowerCase();
          const search = String(card.getAttribute("data-search") || "").toLowerCase();
          let ok = true;
          if (q && !search.includes(q)) ok = false;
          if (type === "ready" && readyFilter && modulo !== readyFilter) ok = false;
          if (type === "dev" && devFilter && modulo !== devFilter) ok = false;
          card.style.display = ok ? "" : "none";
        });
      };
      if (modulesSearch) modulesSearch.addEventListener("input", applyModuleFilters);
      if (modulesFilterReady) modulesFilterReady.addEventListener("change", applyModuleFilters);
      if (modulesFilterDev) modulesFilterDev.addEventListener("change", applyModuleFilters);

      const initialLocale = document.body?.dataset?.pageLocale || (window.SmxI18n ? window.SmxI18n.getLocale() : "es");
      applyLandingLocale(initialLocale);
      if (localeSwitcher) {
        localeSwitcher.value = initialLocale;
        localeSwitcher.addEventListener("change", async (ev) => {
          const nextLocale = String(ev.target.value || "es");
          if (window.SmxI18n) {
            await window.SmxI18n.setLocale(nextLocale);
          }
          applyLandingLocale(nextLocale);
        });
      }

      const heroSlidesHost = document.getElementById("heroSlides");
      if (heroSlidesHost) {
        const slides = Array.from(heroSlidesHost.querySelectorAll(".hero-slide"));
        if (slides.length > 1) {
          const fxCycle = ["fx-zoom", "fx-slide-left", "fx-slide-up"];
          slides.forEach((slide, idx) => {
            slide.classList.remove("fx-zoom", "fx-slide-left", "fx-slide-up");
            slide.classList.add(fxCycle[idx % fxCycle.length]);
          });
          let slideIndex = 0;
          window.setInterval(() => {
            slides[slideIndex].classList.remove("is-active");
            slideIndex = (slideIndex + 1) % slides.length;
            slides[slideIndex].classList.add("is-active");
          }, 5600);
        }
      }

      // Fallback visual si aun no montaste capturas reales
      document.querySelectorAll(".hero-slide img, img.thumb").forEach((img) => {
        img.addEventListener("error", () => {
          img.style.objectFit = "contain";
          img.style.padding = "18px";
          img.style.background = "linear-gradient(145deg,#d7e6ff,#edf4ff)";
          img.alt = "Captura pendiente";
        }, { once: true });
      });
    })();
  </script>
</body>
</html>
