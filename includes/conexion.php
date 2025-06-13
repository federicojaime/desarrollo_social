<?php
/**
 * Conexión a Base de Datos - Sistema de Desarrollo Social
 * Versión 2.0
 */

// Configuración de la base de datos
$host = "srv1597.hstgr.io";
$dbname = "u565673608_desarrollo_soc";
$username = "u565673608_desarrollo_soc";
$password = "xU]xX1z;";

try {
    // Conexión PDO con opciones mejoradas
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4", 
        $username, 
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
    
    // Configurar zona horaria de MySQL
    $pdo->exec("SET time_zone = '-03:00'");
    
} catch (PDOException $e) {
    error_log("Error de conexión a la base de datos: " . $e->getMessage());
    die("Error de conexión a la base de datos. Por favor, contacte al administrador del sistema.");
}

// Verificar y crear tabla de usuarios si no existe
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'usuarios'");
    if ($stmt->rowCount() == 0) {
        // Crear tabla usuarios
        $pdo->exec("
            CREATE TABLE usuarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario VARCHAR(50) UNIQUE NOT NULL,
                nombre VARCHAR(100) NOT NULL DEFAULT '',
                apellido VARCHAR(100) NOT NULL DEFAULT '',
                email VARCHAR(255) DEFAULT NULL,
                contraseña VARCHAR(255) NOT NULL,
                rol ENUM('admin', 'supervisor', 'empleado', 'consulta') DEFAULT 'empleado',
                activo TINYINT(1) DEFAULT 1,
                intentos_fallidos INT DEFAULT 0,
                ultimo_acceso TIMESTAMP NULL,
                fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Crear usuarios por defecto
        $usuarios_default = [
            ['admin', 'Administrador', 'Sistema', password_hash('password', PASSWORD_DEFAULT), 'admin'],
            ['supervisor', 'Juan Carlos', 'Supervisor', password_hash('password', PASSWORD_DEFAULT), 'supervisor'],
            ['empleado', 'María Elena', 'Empleada', password_hash('password', PASSWORD_DEFAULT), 'empleado']
        ];
        
        foreach ($usuarios_default as $user_data) {
            $pdo->exec("
                INSERT INTO usuarios (usuario, nombre, apellido, contraseña, rol) 
                VALUES ('{$user_data[0]}', '{$user_data[1]}', '{$user_data[2]}', '{$user_data[3]}', '{$user_data[4]}')
            ");
        }
        
        error_log("Tabla usuarios creada con usuarios por defecto");
    } else {
        // Verificar si existen los usuarios por defecto, si no, crearlos
        $stmt_check = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE usuario IN ('admin', 'supervisor', 'empleado')");
        $existing_users = $stmt_check->fetchColumn();
        
        if ($existing_users < 3) {
            // Actualizar contraseñas existentes o crear usuarios faltantes
            $usuarios_default = [
                ['admin', 'Administrador', 'Sistema', 'admin'],
                ['supervisor', 'Juan Carlos', 'Supervisor', 'supervisor'],
                ['empleado', 'María Elena', 'Empleada', 'empleado']
            ];
            
            foreach ($usuarios_default as $user_data) {
                $password_hash = password_hash('password', PASSWORD_DEFAULT);
                
                // Intentar actualizar primero
                $stmt_update = $pdo->prepare("UPDATE usuarios SET contraseña = ? WHERE usuario = ?");
                $stmt_update->execute([$password_hash, $user_data[0]]);
                
                // Si no se actualizó ninguna fila, insertar
                if ($stmt_update->rowCount() == 0) {
                    try {
                        $stmt_insert = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, apellido, contraseña, rol) VALUES (?, ?, ?, ?, ?)");
                        $stmt_insert->execute([$user_data[0], $user_data[1], $user_data[2], $password_hash, $user_data[3]]);
                    } catch (Exception $e) {
                        // Usuario ya existe, continuar
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error verificando/creando tabla usuarios: " . $e->getMessage());
}

// Verificar otras tablas esenciales
try {
    // Tabla familias
    $stmt = $pdo->query("SHOW TABLES LIKE 'familias'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE familias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre_jefe VARCHAR(150) NOT NULL,
                apellido_jefe VARCHAR(150) NOT NULL DEFAULT '',
                dni_jefe VARCHAR(20) UNIQUE NOT NULL,
                telefono VARCHAR(20) DEFAULT NULL,
                direccion TEXT DEFAULT NULL,
                barrio VARCHAR(100) DEFAULT NULL,
                cantidad_integrantes INT DEFAULT 1,
                estado ENUM('activa', 'inactiva') DEFAULT 'activa',
                fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Tabla ayudas
    $stmt = $pdo->query("SHOW TABLES LIKE 'ayudas'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE ayudas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre_ayuda VARCHAR(100) NOT NULL,
                descripcion TEXT DEFAULT NULL,
                activo TINYINT(1) DEFAULT 1,
                fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Tabla asignaciones
    $stmt = $pdo->query("SHOW TABLES LIKE 'asignaciones'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE asignaciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                familia_id INT DEFAULT NULL,
                id_persona INT DEFAULT NULL,
                id_ayuda INT DEFAULT NULL,
                beneficiario_id INT DEFAULT NULL,
                tipo_ayuda VARCHAR(255) DEFAULT NULL,
                cantidad DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                motivo TEXT DEFAULT NULL,
                observaciones TEXT DEFAULT NULL,
                fecha_asignacion DATE NOT NULL,
                estado ENUM('pendiente','autorizada','entregada','cancelada') DEFAULT 'pendiente',
                prioridad ENUM('baja','media','alta','urgente') DEFAULT 'media',
                numero_expediente VARCHAR(50) DEFAULT NULL,
                usuario_asignador INT NOT NULL,
                usuario_autorizador INT DEFAULT NULL,
                usuario_entregador INT DEFAULT NULL,
                fecha_autorizacion DATETIME DEFAULT NULL,
                fecha_entrega_real DATETIME DEFAULT NULL,
                fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX(familia_id),
                INDEX(id_ayuda),
                INDEX(usuario_asignador)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Tabla personas
    $stmt = $pdo->query("SHOW TABLES LIKE 'personas'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE personas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                apellido VARCHAR(100) DEFAULT '',
                cedula VARCHAR(20) DEFAULT NULL,
                direccion VARCHAR(255) DEFAULT NULL,
                telefono VARCHAR(20) DEFAULT NULL,
                id_familia INT DEFAULT NULL,
                fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY cedula_unique (cedula),
                INDEX(id_familia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Tabla beneficiarios
    $stmt = $pdo->query("SHOW TABLES LIKE 'beneficiarios'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE beneficiarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                apellido VARCHAR(100) DEFAULT '',
                dni VARCHAR(20) NOT NULL,
                fecha_nacimiento DATE DEFAULT NULL,
                telefono VARCHAR(20) DEFAULT NULL,
                direccion TEXT DEFAULT NULL,
                email VARCHAR(100) DEFAULT NULL,
                estado ENUM('activo','inactivo') DEFAULT 'activo',
                fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY dni_beneficiario_unique (dni)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

} catch (Exception $e) {
    error_log("Error creando tablas del sistema: " . $e->getMessage());
}

// Funciones auxiliares del sistema

/**
 * Registrar actividad en el log
 */
function registrarLog($pdo, $tabla, $registro_id, $accion, $descripcion, $usuario_id) {
    try {
        // Verificar si existe la tabla de logs
        $stmt = $pdo->query("SHOW TABLES LIKE 'log_actividades'");
        if ($stmt->rowCount() == 0) {
            // Crear tabla de logs
            $pdo->exec("
                CREATE TABLE log_actividades (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tabla_afectada VARCHAR(50) NOT NULL,
                    registro_id INT NOT NULL,
                    accion ENUM('crear','actualizar','eliminar','autorizar','entregar','login') NOT NULL,
                    descripcion TEXT DEFAULT NULL,
                    usuario_id INT NOT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    fecha_actividad DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX(usuario_id),
                    INDEX(fecha_actividad)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
        $stmt = $pdo->prepare("
            INSERT INTO log_actividades (tabla_afectada, registro_id, accion, descripcion, usuario_id, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tabla, $registro_id, $accion, $descripcion, $usuario_id, $ip]);
    } catch (Exception $e) {
        error_log("Error al registrar log: " . $e->getMessage());
    }
}

/**
 * Obtener configuración del sistema
 */
function obtenerConfiguracion($pdo, $clave, $default = null) {
    try {
        // Verificar si existe la tabla de configuraciones
        $stmt = $pdo->query("SHOW TABLES LIKE 'configuraciones'");
        if ($stmt->rowCount() == 0) {
            // Crear tabla de configuraciones
            $pdo->exec("
                CREATE TABLE configuraciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    clave VARCHAR(100) NOT NULL,
                    valor TEXT DEFAULT NULL,
                    descripcion TEXT DEFAULT NULL,
                    tipo ENUM('texto','numero','booleano','json') DEFAULT 'texto',
                    categoria VARCHAR(50) DEFAULT 'general',
                    fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY clave_config_unique (clave)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Insertar configuraciones por defecto
            $pdo->exec("
                INSERT INTO configuraciones (clave, valor, descripcion, categoria) VALUES
                ('nombre_organizacion', 'Municipalidad de San Fernando', 'Nombre de la organización', 'general'),
                ('version_sistema', '2.0', 'Versión del sistema', 'sistema')
            ");
        }
        
        $stmt = $pdo->prepare("SELECT valor FROM configuraciones WHERE clave = ?");
        $stmt->execute([$clave]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Formatear fecha para mostrar
 */
function formatearFecha($fecha) {
    if (!$fecha || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date('d/m/Y', strtotime($fecha));
}

/**
 * Formatear fecha y hora
 */
function formatearFechaHora($fecha) {
    if (!$fecha || $fecha === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date('d/m/Y H:i', strtotime($fecha));
}

/**
 * Generar número de expediente único
 */
function generarNumeroExpediente($pdo) {
    try {
        $año = date('Y');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) + 1 as siguiente 
            FROM asignaciones 
            WHERE YEAR(fecha_asignacion) = ?
        ");
        $stmt->execute([$año]);
        $siguiente = $stmt->fetchColumn();
        
        return sprintf("EXP-%d-%04d", $año, $siguiente);
    } catch (Exception $e) {
        return 'EXP-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

/**
 * Validar y sanitizar entrada
 */
function limpiarEntrada($data) {
    if (is_array($data)) {
        return array_map('limpiarEntrada', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Obtener color del estado
 */
function obtenerColorEstado($estado) {
    $colores = [
        'pendiente' => 'warning',
        'autorizada' => 'info',
        'entregada' => 'success',
        'cancelada' => 'danger'
    ];
    return $colores[$estado] ?? 'secondary';
}

/**
 * Obtener color de prioridad
 */
function obtenerColorPrioridad($prioridad) {
    $colores = [
        'baja' => 'secondary',
        'media' => 'primary',
        'alta' => 'warning',
        'urgente' => 'danger'
    ];
    return $colores[$prioridad] ?? 'secondary';
}

/**
 * Capitalizar primera letra de cada palabra
 */
function capitalizarTexto($texto) {
    return mb_convert_case($texto, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Verificar permisos de usuario
 */
function verificarPermiso($rol_requerido, $rol_usuario) {
    if ($rol_requerido === 'admin' && $rol_usuario !== 'admin') {
        return false;
    }
    return true;
}

/**
 * Generar token CSRF
 */
function generarTokenCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validar token CSRF
 */
function validarTokenCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Arrays para selects del formulario
 */
function obtenerEstadosAsignacion() {
    return [
        'pendiente' => 'Pendiente',
        'autorizada' => 'Autorizada',
        'entregada' => 'Entregada',
        'cancelada' => 'Cancelada'
    ];
}

function obtenerPrioridades() {
    return [
        'baja' => 'Baja',
        'media' => 'Media',
        'alta' => 'Alta',
        'urgente' => 'Urgente'
    ];
}

function obtenerSituacionesLaborales() {
    return [
        'empleado' => 'Empleado',
        'desempleado' => 'Desempleado',
        'jubilado' => 'Jubilado',
        'pensionado' => 'Pensionado',
        'trabajador_informal' => 'Trabajador Informal',
        'otros' => 'Otros'
    ];
}

function obtenerTiposVivienda() {
    return [
        'propia' => 'Propia',
        'alquilada' => 'Alquilada',
        'prestada' => 'Prestada',
        'precaria' => 'Precaria',
        'otros' => 'Otros'
    ];
}

// Configurar zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Función para verificar si una tabla existe
function tablaExiste($pdo, $tabla) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$tabla'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Configurar IP del usuario para logs
if (isset($_SESSION['user_id'])) {
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        $pdo->exec("SET @user_ip = '$user_ip'");
        $pdo->exec("SET @user_id = {$_SESSION['user_id']}");
    } catch (Exception $e) {
        // No es crítico si falla
    }
}
?>