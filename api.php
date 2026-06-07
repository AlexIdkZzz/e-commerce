<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u743672715_ecommerce');
define('DB_USER', 'u743672715_yael');   // ← tu usuario MySQL
define('DB_PASS', 'ezF.XKJC89tU&i4');      // ← tu contraseña MySQL
date_default_timezone_set('America/Mexico_City');
// ================================================================

// ── CORS — permite llamadas desde tu propio dominio ─────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Conexión a MySQL ────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET time_zone = '-06:00'");
        asegurar_esquema_pedidos($pdo);
    } catch (PDOException $e) {
        resp(500, ['error' => 'No se pudo conectar a la base de datos.']);
    }
    return $pdo;
}

function columna_existe(PDO $pdo, string $tabla, string $columna): bool {
    $st = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $st->execute([$tabla, $columna]);
    return (int)$st->fetchColumn() > 0;
}

function asegurar_esquema_pedidos(PDO $pdo): void {
    $columnas = [
        'pago_confirmado'    => "ALTER TABLE ordenes ADD COLUMN pago_confirmado TINYINT(1) NOT NULL DEFAULT 0 AFTER estado",
        'pago_confirmado_en' => "ALTER TABLE ordenes ADD COLUMN pago_confirmado_en DATETIME NULL AFTER pago_confirmado",
        'estado_pedido'      => "ALTER TABLE ordenes ADD COLUMN estado_pedido VARCHAR(30) NOT NULL DEFAULT 'pendiente' AFTER pago_confirmado_en",
        'seguimiento'        => "ALTER TABLE ordenes ADD COLUMN seguimiento TEXT NULL AFTER estado_pedido",
        'actualizado_en'     => "ALTER TABLE ordenes ADD COLUMN actualizado_en DATETIME NULL AFTER seguimiento",
    ];
    foreach ($columnas as $columna => $sql) {
        if (!columna_existe($pdo, 'ordenes', $columna)) $pdo->exec($sql);
    }
    $pdo->exec("
        UPDATE ordenes
        SET pago_confirmado = 1,
            pago_confirmado_en = COALESCE(pago_confirmado_en, creado_en),
            estado_pedido = IF(estado_pedido = 'pendiente', 'seguimiento', estado_pedido),
            seguimiento = COALESCE(seguimiento, 'Tu pedido se esta llevando a cabo. Referencia: sale de almacen y va en camino al centro de reparto.'),
            actualizado_en = COALESCE(actualizado_en, creado_en)
        WHERE estado = 'pagado' AND pago_confirmado = 0
    ");
}

// ── Helpers ─────────────────────────────────────────────────────
function resp(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function estado_pedido_valido(string $estado): bool {
    return in_array($estado, ['pendiente', 'pago_confirmado', 'aceptado', 'rechazado', 'seguimiento', 'entregado'], true);
}

function seguimiento_default(string $estado): string {
    $map = [
        'pendiente'        => 'Pedido recibido. Estamos esperando que se refleje el pago.',
        'pago_confirmado' => 'Pago reflejado. El administrador revisara y aceptara el pedido.',
        'aceptado'        => 'Pedido aceptado. Se esta preparando para envio.',
        'rechazado'       => 'Pedido rechazado. Contacta al administrador para mas informacion.',
        'seguimiento'     => 'Tu pedido se esta llevando a cabo. Referencia: sale de almacen y va en camino al centro de reparto.',
        'entregado'       => 'Pedido entregado.',
    ];
    return $map[$estado] ?? $map['pendiente'];
}

function cargar_ordenes(?int $usuarioId, bool $admin = false): array {
    $where = '';
    $params = [];
    if (!$admin || $usuarioId) {
        $where = 'WHERE o.usuario_id = ?';
        $params[] = $usuarioId;
    }

    $st = db()->prepare("
        SELECT o.*, u.nombre AS usuario, u.email
        FROM ordenes o
        JOIN usuarios u ON u.id = o.usuario_id
        $where
        ORDER BY o.creado_en DESC, o.id DESC
    ");
    $st->execute($params);
    $ordenes = $st->fetchAll();

    if (!$ordenes) return [];
    $ids = array_column($ordenes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stDet = db()->prepare("
        SELECT d.orden_id, p.nombre AS producto, d.cantidad, d.precio_unit, d.subtotal
        FROM orden_detalle d
        JOIN productos p ON p.id = d.producto_id
        WHERE d.orden_id IN ($placeholders)
        ORDER BY d.orden_id, d.id
    ");
    $stDet->execute($ids);
    $detalles = [];
    foreach ($stDet->fetchAll() as $d) {
        $detalles[$d['orden_id']][] = $d;
    }
    foreach ($ordenes as &$orden) {
        $orden['detalles'] = $detalles[$orden['id']] ?? [];
        $orden['pago_confirmado'] = (int)($orden['pago_confirmado'] ?? 0);
        $orden['estado_pedido'] = $orden['estado_pedido'] ?: 'pendiente';
        $orden['seguimiento'] = $orden['seguimiento'] ?: seguimiento_default($orden['estado_pedido']);
    }
    return $ordenes;
}

function token_usuario(): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) return null;
    $token = $m[1];
    $st = db()->prepare('SELECT usuario_id FROM sesiones WHERE token = ? AND expira_en > NOW()');
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) return null;
    $st2 = db()->prepare('SELECT id, nombre, email, rol, terms_aceptados, terms_fecha FROM usuarios WHERE id = ?');
    $st2->execute([$row['usuario_id']]);
    return $st2->fetch() ?: null;
}

function requiere_auth(): array {
    $u = token_usuario();
    if (!$u) resp(401, ['error' => 'No autorizado. Inicia sesión.']);
    return $u;
}

function requiere_admin(): array {
    $u = requiere_auth();
    if ($u['rol'] !== 'admin') resp(403, ['error' => 'Solo administradores.']);
    return $u;
}

function hash_pass(string $pass): string {
    return password_hash($pass, PASSWORD_BCRYPT);
}

function limpiar_pdf_texto(string $txt): string {
    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $txt);
    if ($convertido === false) $convertido = $txt;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $convertido);
}

function pdf_code128(string $data, float $x, float $y, float $height, float $module = 1.15): string {
    $patterns = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'
    ];

    $clean = preg_replace('/[^\x20-\x7E]/', ' ', $data);
    $codes = [104]; // Start Code B
    $checksum = 104;
    $pos = 1;
    foreach (str_split($clean) as $ch) {
        $code = ord($ch) - 32;
        $codes[] = $code;
        $checksum += $code * $pos;
        $pos++;
    }
    $codes[] = $checksum % 103;
    $codes[] = 106; // Stop

    $pdf = "q 0 0 0 rg\n";
    $cursor = $x;
    foreach ($codes as $code) {
        $pattern = $patterns[$code];
        $bar = true;
        for ($i = 0; $i < strlen($pattern); $i++) {
            $w = ((int)$pattern[$i]) * $module;
            if ($bar) {
                $pdf .= sprintf('%.2F %.2F %.2F %.2F re f' . "\n", $cursor, $y, $w, $height);
            }
            $cursor += $w;
            $bar = !$bar;
        }
    }
    return $pdf . "Q\n";
}

function enviar_ficha_pdf(array $orden, array $detalles): void {
    $lineas = [
        'MiTienda - Ficha de pago',
        'Orden #' . $orden['id'],
        'Cliente: ' . $orden['usuario'] . ' (' . $orden['email'] . ')',
        'Metodo: ' . strtoupper($orden['metodo_pago']),
        'Referencia: ' . $orden['referencia_pago'],
        'Subtotal: $' . number_format((float)$orden['subtotal'], 2) . ' MXN',
        'IVA: $' . number_format((float)$orden['iva'], 2) . ' MXN',
        'Envio: $' . number_format((float)$orden['costo_envio'], 2) . ' MXN',
        'Total a pagar: $' . number_format((float)$orden['total'], 2) . ' MXN',
        'Fecha: ' . $orden['creado_en'],
        '',
        'Productos:'
    ];

    foreach ($detalles as $d) {
        $lineas[] = '- ' . $d['producto'] . ' x' . $d['cantidad'] . ' = $' . number_format((float)$d['subtotal'], 2);
    }

    $lineas[] = '';
    $lineas[] = 'Codigo de barras para caja: ' . $orden['referencia_pago'];
    $lineas[] = '';
    $lineas[] = 'Simulacion academica. No valido para cobros reales.';

    $contenido = "BT\n/F1 18 Tf\n50 780 Td\n";
    foreach ($lineas as $i => $linea) {
        if ($i === 1) $contenido .= "/F1 12 Tf\n";
        $contenido .= '(' . limpiar_pdf_texto($linea) . ") Tj\n0 -20 Td\n";
    }
    $contenido .= "ET";
    $contenido .= "\n" . pdf_code128($orden['referencia_pago'], 70, 245, 72);
    $contenido .= "BT\n/F1 10 Tf\n70 225 Td\n(" . limpiar_pdf_texto($orden['referencia_pago']) . ") Tj\nET";

    $objetos = [];
    $objetos[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objetos[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objetos[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objetos[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objetos[] = "<< /Length " . strlen($contenido) . " >>\nstream\n" . $contenido . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objetos as $i => $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objetos); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="ficha-pago-orden-' . $orden['id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// ── Router ──────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Quita el prefijo si está en subdirectorio o directo en api.php
$uri = preg_replace('#^/api\.php#', '', $uri);
$uri = '/' . trim($uri, '/'); // Esto vuelve "/productos/" o "productos" en "/productos"

// ================================================================
//  RUTAS
// ================================================================

// ── POST /login ─────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/login') {
    $b = body();
    $email = trim($b['email'] ?? '');
    $pass  = $b['password'] ?? '';

    if (!$email || !$pass) resp(400, ['error' => 'Email y contraseña requeridos.']);

    $st = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    $ok = false;
    if ($user) {
        if (str_starts_with($user['password_hash'], '$2')) {
            $ok = password_verify($pass, $user['password_hash']);
        } else {
            $ok = hash_equals($user['password_hash'], hash('sha256', $pass));
            if ($ok) {
                $nuevo = hash_pass($pass);
                db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
                    ->execute([$nuevo, $user['id']]);
            }
        }
    }

    if (!$ok) resp(401, ['error' => 'Correo o contraseña incorrectos.']);

    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+60 days'));
    db()->prepare('INSERT INTO sesiones (usuario_id, token, expira_en) VALUES (?,?,?)')
        ->execute([$user['id'], $token, $expira]);

    resp(200, [
        'token'   => $token,
        'usuario' => [
            'id'              => $user['id'],
            'nombre'          => $user['nombre'],
            'email'           => $user['email'],
            'rol'             => $user['rol'],
            'terms_aceptados' => $user['terms_aceptados'],
            'terms_fecha'     => $user['terms_fecha'],
        ]
    ]);
}

// ── POST /registro ───────────────────────────────────────────────
elseif ($method === 'POST' && $uri === '/registro') {
    $b      = body();
    $nombre = trim($b['nombre'] ?? '');
    $email  = strtolower(trim($b['email'] ?? ''));
    $pass   = $b['password'] ?? '';

    if (!$nombre || !$email || !$pass) resp(400, ['error' => 'Nombre, email y contraseña son obligatorios.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) resp(400, ['error' => 'Email inválido.']);
    if (strlen($pass) < 6) resp(400, ['error' => 'La contraseña debe tener al menos 6 caracteres.']);

    $st = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) resp(409, ['error' => 'Este correo ya está registrado.']);

    $hash = hash_pass($pass);
    $ins  = db()->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?,?,?,?)');
    $ins->execute([$nombre, $email, $hash, 'cliente']);
    $id = (int) db()->lastInsertId();

    $token  = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+60 days'));
    db()->prepare('INSERT INTO sesiones (usuario_id, token, expira_en) VALUES (?,?,?)')
        ->execute([$id, $token, $expira]);

    resp(201, [
        'token'   => $token,
        'usuario' => [
            'id'              => $id,
            'nombre'          => $nombre,
            'email'           => $email,
            'rol'             => 'cliente',
            'terms_aceptados' => null,
            'terms_fecha'     => null,
        ]
    ]);
}

// ── POST /logout ─────────────────────────────────────────────────
elseif ($method === 'POST' && $uri === '/logout') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        db()->prepare('DELETE FROM sesiones WHERE token = ?')->execute([$m[1]]);
    }
    resp(200, ['ok' => true]);
}

// ── GET /productos ───────────────────────────────────────────────
elseif ($method === 'GET' && $uri === '/productos') {
    $todos = isset($_GET['todos']) && $_GET['todos'] == '1';
    if ($todos) requiere_admin();

    $sql = '
        SELECT p.id, p.nombre, c.nombre AS categoria, p.descripcion, p.precio, p.stock,
               p.emoji, p.etiqueta, p.activo, p.creado_en, p.actualizado_en
        FROM productos p
        JOIN categorias c ON c.id = p.categoria_id
    ';
    if (!$todos) $sql .= ' WHERE p.activo = 1';
    $sql .= ' ORDER BY p.id';

    $st = db()->query($sql);
    resp(200, ['productos' => $st->fetchAll()]);
}

elseif ($method === 'POST' && $uri === '/ordenes') {
    $u = requiere_auth();
    $b = body();

    $items = $b['items'] ?? [];
    $metodo = $b['metodo_pago'] ?? 'oxxo';

    if (!$items) resp(400, ['error' => 'Carrito vacío.']);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $subtotal = 0;
        $detalles = [];

        foreach ($items as $item) {
            $productoId = (int)$item['id'];
            $cantidad = (int)$item['cantidad'];

            $st = $pdo->prepare('SELECT id, nombre, precio, stock FROM productos WHERE id=? AND activo=1 FOR UPDATE');
            $st->execute([$productoId]);
            $p = $st->fetch();

            if (!$p) throw new Exception('Producto no disponible.');
            if ($p['stock'] < $cantidad) throw new Exception('Stock insuficiente para '.$p['nombre']);

            $precio = (float)$p['precio'];
            $linea = $precio * $cantidad;
            $subtotal += $linea;

            $detalles[] = [$productoId, $cantidad, $precio, $linea];

            $upd = $pdo->prepare('UPDATE productos SET stock = stock - ?, actualizado_en = NOW() WHERE id = ?');
            $upd->execute([$cantidad, $productoId]);
        }

        $iva = round($subtotal * 0.16, 2);
        $envio = 99;
        $total = $subtotal + $iva + $envio;
        $ref = 'MT-' . strtoupper(bin2hex(random_bytes(4)));

        $estado = $metodo === 'tarjeta' ? 'pagado' : 'pendiente';
        $pagoConfirmado = $metodo === 'tarjeta' ? 1 : 0;
        $estadoPedido = $pagoConfirmado ? 'seguimiento' : 'pendiente';
        $seguimiento = seguimiento_default($estadoPedido);

        $stOrden = $pdo->prepare('
            INSERT INTO ordenes 
            (usuario_id, metodo_pago, estado, pago_confirmado, pago_confirmado_en, estado_pedido, seguimiento, actualizado_en, subtotal, iva, costo_envio, total, referencia_pago)
            VALUES (?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?, ?, NOW(), ?, ?, ?, ?, ?)
        ');
        $stOrden->execute([$u['id'], $metodo, $estado, $pagoConfirmado, $pagoConfirmado, $estadoPedido, $seguimiento, $subtotal, $iva, $envio, $total, $ref]);

        $ordenId = (int)$pdo->lastInsertId();
        $ficha = 'api.php/ordenes/' . $ordenId . '/ficha-pago.pdf';
        $pdo->prepare('UPDATE ordenes SET ficha_pago_pdf = ? WHERE id = ?')->execute([$ficha, $ordenId]);

        foreach ($detalles as $d) {
            $pdo->prepare('
                INSERT INTO orden_detalle
                (orden_id, producto_id, cantidad, precio_unit, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$ordenId, $d[0], $d[1], $d[2], $d[3]]);
        }

        $pdo->commit();

        resp(201, [
            'ok' => true,
            'orden_id' => $ordenId,
            'referencia_pago' => $ref,
            'total' => $total,
            'ficha_pago_pdf' => $ficha
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        resp(400, ['error' => $e->getMessage()]);
    }
}

// ── POST /productos  (solo admin) ────────────────────────────────
elseif ($method === 'POST' && $uri === '/productos') {
    requiere_admin();
    $b = body();
    $nombre       = trim($b['nombre']      ?? '');
    $categoriaNom = trim($b['categoria']   ?? ''); // Viene el texto, ej: "Electrónica"
    $desc         = trim($b['descripcion'] ?? '');
    $precio       = (float)($b['precio']   ?? 0);
    $stock        = (int)  ($b['stock']    ?? 0);
    $emoji        = $b['emoji']    ?? '📦';
    $etiqueta     = $b['etiqueta'] ?? null;

    if (!$nombre || !$categoriaNom || !$desc || $precio < 0 || $stock < 0) {
        resp(400, ['error' => 'Faltan campos obligatorios.']);
    }

    // 1. Buscar el ID de la categoría basándonos en su nombre
    $stCat = db()->prepare('SELECT id FROM categorias WHERE nombre = ?');
    $stCat->execute([$categoriaNom]);
    $catRow = $stCat->fetch();
    
    // Si no existe la categoría, puedes asignarle una por defecto o lanzar error
    $categoria_id = $catRow ? (int)$catRow['id'] : 1; 

    // 2. Insertar usando la columna correcta: categoria_id
    $st = db()->prepare(
        'INSERT INTO productos (nombre, categoria_id, descripcion, precio, stock, emoji, etiqueta, activo) VALUES (?,?,?,?,?,?,?,1)'
    );
    $st->execute([$nombre, $categoria_id, $desc, $precio, $stock, $emoji, $etiqueta]);
    
    $id = (int) db()->lastInsertId();
    resp(201, ['id' => $id, 'mensaje' => "Producto \"$nombre\" creado."]);
}

//

elseif ($method === 'PUT' && preg_match('#^/productos/(\d+)$#', $uri, $m)) {
    requiere_admin();

    $id = (int)$m[1];
    $b = body();

    $nombre = trim($b['nombre'] ?? '');
    $categoriaNom = trim($b['categoria'] ?? '');
    $desc = trim($b['descripcion'] ?? '');
    $precio = (float)($b['precio'] ?? 0);
    $stock = (int)($b['stock'] ?? 0);
    $emoji = $b['emoji'] ?? '📦';
    $etiqueta = $b['etiqueta'] ?? null;
    $activo = isset($b['activo']) ? (int)$b['activo'] : 1;

    if (!$nombre || !$categoriaNom || !$desc || $precio < 0 || $stock < 0) {
        resp(400, ['error' => 'Datos inválidos.']);
    }

    $stCat = db()->prepare('SELECT id FROM categorias WHERE nombre = ?');
    $stCat->execute([$categoriaNom]);
    $cat = $stCat->fetch();

    if (!$cat) resp(400, ['error' => 'Categoría no existe.']);

    db()->prepare('
        UPDATE productos
        SET nombre=?, categoria_id=?, descripcion=?, precio=?, stock=?, emoji=?, etiqueta=?, activo=?, actualizado_en=NOW()
        WHERE id=?
    ')->execute([
        $nombre, $cat['id'], $desc, $precio, $stock, $emoji, $etiqueta, $activo, $id
    ]);

    resp(200, ['mensaje' => 'Producto actualizado.']);
}

// 
elseif ($method === 'POST' && $uri === '/ordenes') {
    $u = requiere_auth();
    $b = body();

    $items = $b['items'] ?? [];
    $metodo = $b['metodo_pago'] ?? 'oxxo';

    if (!$items) resp(400, ['error' => 'Carrito vacío.']);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $subtotal = 0;
        $detalles = [];

        foreach ($items as $item) {
            $productoId = (int)$item['id'];
            $cantidad = (int)$item['cantidad'];

            $st = $pdo->prepare('SELECT id, nombre, precio, stock FROM productos WHERE id=? AND activo=1 FOR UPDATE');
            $st->execute([$productoId]);
            $p = $st->fetch();

            if (!$p) throw new Exception('Producto no disponible.');
            if ($p['stock'] < $cantidad) throw new Exception('Stock insuficiente para '.$p['nombre']);

            $precio = (float)$p['precio'];
            $linea = $precio * $cantidad;
            $subtotal += $linea;

            $detalles[] = [$productoId, $cantidad, $precio, $linea];

            $upd = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
            $upd->execute([$cantidad, $productoId]);
        }

        $iva = round($subtotal * 0.16, 2);
        $envio = 99;
        $total = $subtotal + $iva + $envio;
        $ref = 'MT-' . strtoupper(bin2hex(random_bytes(4)));
        $estado = $metodo === 'tarjeta' ? 'pagado' : 'pendiente';
        $pagoConfirmado = $metodo === 'tarjeta' ? 1 : 0;
        $estadoPedido = $pagoConfirmado ? 'seguimiento' : 'pendiente';
        $seguimiento = seguimiento_default($estadoPedido);

        $stOrden = $pdo->prepare('
            INSERT INTO ordenes 
            (usuario_id, metodo_pago, estado, pago_confirmado, pago_confirmado_en, estado_pedido, seguimiento, actualizado_en, subtotal, iva, costo_envio, total, referencia_pago)
            VALUES (?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?, ?, NOW(), ?, ?, ?, ?, ?)
        ');
        $stOrden->execute([$u['id'], $metodo, $estado, $pagoConfirmado, $pagoConfirmado, $estadoPedido, $seguimiento, $subtotal, $iva, $envio, $total, $ref]);

        $ordenId = (int)$pdo->lastInsertId();

        foreach ($detalles as $d) {
            $pdo->prepare('
                INSERT INTO orden_detalle
                (orden_id, producto_id, cantidad, precio_unit, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$ordenId, $d[0], $d[1], $d[2], $d[3]]);
        }

        $pdo->commit();

        resp(201, [
            'ok' => true,
            'orden_id' => $ordenId,
            'referencia_pago' => $ref,
            'total' => $total
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        resp(400, ['error' => $e->getMessage()]);
    }
}

// ── GET /usuarios  (solo admin) ──────────────────────────────────
elseif ($method === 'DELETE' && preg_match('#^/productos/(\d+)$#', $uri, $m)) {
    requiere_admin();
    db()->prepare('UPDATE productos SET activo = 0, actualizado_en = NOW() WHERE id = ?')->execute([(int)$m[1]]);
    resp(200, ['mensaje' => 'Producto deshabilitado.']);
}

elseif ($method === 'GET' && preg_match('#^/ordenes/(\d+)/ficha-pago\.pdf$#', $uri, $m)) {
    $u = requiere_auth();
    $id = (int)$m[1];

    $st = db()->prepare('
        SELECT o.*, u.nombre AS usuario, u.email
        FROM ordenes o
        JOIN usuarios u ON u.id = o.usuario_id
        WHERE o.id = ?
    ');
    $st->execute([$id]);
    $orden = $st->fetch();
    if (!$orden) resp(404, ['error' => 'Orden no encontrada.']);
    if ((int)$orden['usuario_id'] !== (int)$u['id'] && $u['rol'] !== 'admin') {
        resp(403, ['error' => 'Sin permiso.']);
    }

    $stDet = db()->prepare('
        SELECT p.nombre AS producto, d.cantidad, d.precio_unit, d.subtotal
        FROM orden_detalle d
        JOIN productos p ON p.id = d.producto_id
        WHERE d.orden_id = ?
    ');
    $stDet->execute([$id]);
    enviar_ficha_pdf($orden, $stDet->fetchAll());
}

elseif ($method === 'GET' && $uri === '/ordenes') {
    $u = requiere_auth();
    $usuarioId = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : null;
    if ($u['rol'] !== 'admin') $usuarioId = (int)$u['id'];
    resp(200, ['ordenes' => cargar_ordenes($usuarioId, $u['rol'] === 'admin')]);
}

elseif ($method === 'PATCH' && preg_match('#^/ordenes/(\d+)/estado$#', $uri, $m)) {
    requiere_admin();
    $id = (int)$m[1];
    $b = body();
    $estado = trim($b['estado_pedido'] ?? '');
    if (!estado_pedido_valido($estado)) resp(400, ['error' => 'Estado de pedido invalido.']);
    $seguimiento = trim($b['seguimiento'] ?? '') ?: seguimiento_default($estado);

    db()->prepare('
        UPDATE ordenes
        SET estado_pedido = ?,
            estado = IF(? = "rechazado", "cancelado", estado),
            seguimiento = ?,
            actualizado_en = NOW()
        WHERE id = ?
    ')->execute([$estado, $estado, $seguimiento, $id]);

    resp(200, ['ok' => true, 'orden' => cargar_ordenes(null, true)[0] ?? null]);
}

elseif ($method === 'PATCH' && preg_match('#^/ordenes/(\d+)/pago$#', $uri, $m)) {
    requiere_admin();
    $id = (int)$m[1];
    $seguimiento = seguimiento_default('seguimiento');
    db()->prepare('
        UPDATE ordenes
        SET estado = "pagado",
            pago_confirmado = 1,
            pago_confirmado_en = NOW(),
            estado_pedido = "seguimiento",
            seguimiento = ?,
            actualizado_en = NOW()
        WHERE id = ?
    ')->execute([$seguimiento, $id]);

    resp(200, ['ok' => true, 'mensaje' => 'Pago confirmado. El pedido ya esta en proceso.']);
}

elseif ($method === 'GET' && $uri === '/usuarios') {
    requiere_admin();
    $st = db()->query('SELECT id, nombre, email, rol, terms_aceptados, terms_fecha, creado_en FROM usuarios ORDER BY id');
    $usuarios = $st->fetchAll();
    $ordenes = cargar_ordenes(null, true);
    $porUsuario = [];
    foreach ($ordenes as $orden) $porUsuario[$orden['usuario_id']][] = $orden;
    foreach ($usuarios as &$usuario) {
        $usuario['ordenes'] = $porUsuario[$usuario['id']] ?? [];
    }
    resp(200, ['usuarios' => $usuarios]);
}

// ── PATCH /usuarios/{id}/terminos ────────────────────────────────
elseif ($method === 'PATCH' && preg_match('#^/usuarios/(\d+)/terminos$#', $uri, $m)) {
    $u  = requiere_auth();
    $id = (int)$m[1];

    if ($u['id'] !== $id && $u['rol'] !== 'admin') {
        resp(403, ['error' => 'Sin permiso.']);
    }

    $b = body();
    $val = isset($b['terms_aceptados']) ? ($b['terms_aceptados'] ? 1 : 0) : null;

    db()->prepare('
        UPDATE usuarios 
        SET terms_aceptados = ?, terms_fecha = NOW()
        WHERE id = ?
    ')->execute([$val, $id]);

    resp(200, ['ok' => true, 'terms_fecha' => date('Y-m-d H:i:s')]);
}

// ── GET /admin/stats  (solo admin) ───────────────────────────────
elseif ($method === 'GET' && $uri === '/admin/stats') {
    requiere_admin();
    $stats = db()->query("
        SELECT
            (SELECT COUNT(*) FROM productos WHERE activo = 1)             AS total_productos,
            (SELECT COUNT(*) FROM usuarios)                                AS total_usuarios,
            (SELECT COALESCE(SUM(precio * stock),0) FROM productos WHERE activo=1) AS valor_inventario,
            (SELECT COUNT(*) FROM usuarios WHERE terms_aceptados = 1)     AS terms_aceptados,
            (SELECT COUNT(*) FROM ordenes)                                AS total_ordenes,
            (SELECT COUNT(*) FROM ordenes WHERE pago_confirmado = 1)      AS pagos_confirmados
    ")->fetch();
    resp(200, $stats);
}

// ── Si no entró a ninguna de las anteriores ──────────────────────
else {
    resp(404, ['error' => "Ruta no encontrada: $method $uri"]);
}