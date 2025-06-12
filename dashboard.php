<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Incluir el archivo de conexión a la base de datos
// Asegúrate de que 'includes/conexion.php' exista y contenga la conexión $pdo
require 'includes/conexion.php'; 

$mensaje = "";
$error = "";
$mostrar_formulario_usuarios = false; // Variable para controlar la visibilidad del formulario de usuarios

// Obtener el rol del usuario logueado desde la sesión
// IMPORTANTE: Asegúrate de que la clave de sesión 'rol' sea la correcta que usas al iniciar sesión
// Y que el valor para los administradores en la DB sea 'admin'
$user_role = $_SESSION['rol'] ?? 'empleado'; // 'empleado' por defecto si no está definido

// Lógica para mostrar el formulario de agregar usuarios si se accede desde "Configuración"
// Se compara con 'admin' en lugar de 'administrador'
if (isset($_GET['section']) && $_GET['section'] === 'usuarios' && $user_role === 'admin') {
    $mostrar_formulario_usuarios = true;
}

try {
    // --- Obtener conteos para las tarjetas de resumen ---
    $personas_count = $pdo->query("SELECT COUNT(*) FROM personas")->fetchColumn();
    $familias_count = $pdo->query("SELECT COUNT(*) FROM familias")->fetchColumn();
    $ayudas_count = $pdo->query("SELECT COUNT(*) FROM ayudas")->fetchColumn();
    $asignaciones_count = $pdo->query("SELECT COUNT(*) FROM asignaciones")->fetchColumn();

    // --- Obtener las últimas personas registradas recientemente ---
    $stmt_recent_personas = $pdo->prepare("
        SELECT
            nombre,
            apellido,
            cedula AS cedula,
            fecha_registro
        FROM personas
        ORDER BY fecha_registro DESC
        LIMIT 3
    ");
    $stmt_recent_personas->execute();
    $recent_personas = $stmt_recent_personas->fetchAll(PDO::FETCH_ASSOC);

    // --- Lógica para agregar un nuevo usuario (solo para admins) ---
    // Se compara con 'admin' en lugar de 'administrador'
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'admin' && isset($_POST['agregar_usuario'])) {
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $email = trim($_POST['email']);
        $usuario = trim($_POST['usuario']);
        $contraseña = $_POST['contraseña']; // La contraseña se hashea antes de guardar
        $tipo_usuario = $_POST['tipo_usuario'];

        // Validación básica de campos
        if (empty($nombre) || empty($apellido) || empty($email) || empty($usuario) || empty($contraseña) || empty($tipo_usuario)) {
            $error = "Todos los campos son obligatorios.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "El formato del email no es válido.";
        } else {
            // Verificar si el usuario o email ya existen para evitar duplicados
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ? OR email = ?");
            $stmt_check->execute([$usuario, $email]);
            if ($stmt_check->fetchColumn() > 0) {
                $error = "El nombre de usuario o el email ya están registrados.";
            } else {
                // Hashear la contraseña antes de guardarla en la base de datos por seguridad
                $hashed_password = password_hash($contraseña, PASSWORD_DEFAULT);

                // Insertar el nuevo usuario en la tabla 'usuarios'
                // Asegúrate de que tu tabla 'usuarios' tenga las columnas: id, nombre, apellido, email, usuario, contraseña, rol
                $stmt_insert = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, email, usuario, contraseña, rol) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt_insert->execute([$nombre, $apellido, $email, $usuario, $hashed_password, $tipo_usuario])) {
                    $mensaje = "Usuario registrado exitosamente.";
                    // Limpiar los campos del formulario después del éxito (opcional)
                    $_POST = []; // Esto limpia todos los POST, se podría hacer más específico
                    // Redirigir para evitar re-envío del formulario al recargar
                    header("Location: dashboard.php?section=usuarios&msg=" . urlencode($mensaje));
                    exit();
                } else {
                    $error = "Error al registrar el usuario. Intente nuevamente.";
                }
            }
        }
        // Si hay un error al agregar usuario, asegurarse de que el formulario siga visible
        $mostrar_formulario_usuarios = true;
    }

    // Recuperar mensajes de la URL después de una redirección (para agregar usuario)
    if (isset($_GET['msg'])) {
        $mensaje = htmlspecialchars($_GET['msg']);
    }
    if (isset($_GET['err'])) {
        $error = htmlspecialchars($_GET['err']);
    }

} catch (PDOException $e) {
    // Manejo de errores de conexión o consulta a la base de datos
    $error = "Error de base de datos: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control - Desarrollo Social</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        :root {
            --primary-color: #0056b3; /* Un azul más oscuro */
            --secondary-color: #007bff; /* Azul vibrante */
            --background-color: #f4f7f6;
            --card-background: #ffffff;
            --text-color: #333;
            --border-color: #ddd;
            --shadow-color: rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: var(--primary-color);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px var(--shadow-color);
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
        }

        .sidebar ul li {
            margin-bottom: 15px;
        }

        .sidebar ul li a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .sidebar ul li a:hover,
        .sidebar ul li a.active {
            background-color: var(--secondary-color);
        }

        .main-content {
            flex-grow: 1;
            padding: 30px;
        }

        .header {
            background-color: var(--card-background);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px var(--shadow-color);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            margin: 0;
            color: var(--primary-color);
        }

        .header .user-info {
            font-weight: bold;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .card-item {
            background-color: var(--card-background);
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px var(--shadow-color);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .card-item:hover {
            transform: translateY(-5px);
        }

        .card-item .icon {
            font-size: 40px;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .card-item h3 {
            font-size: 28px;
            margin: 5px 0;
            color: var(--primary-color);
        }

        .card-item p {
            font-size: 16px;
            color: #666;
        }

        .recent-activity {
            background-color: var(--card-background);
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px var(--shadow-color);
        }

        .recent-activity h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }

        .recent-activity ul {
            list-style: none;
            padding: 0;
        }

        .recent-activity ul li {
            padding: 10px 0;
            border-bottom: 1px dashed var(--border-color);
            color: #555;
        }

        .recent-activity ul li:last-child {
            border-bottom: none;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .form-container {
            background-color: var(--card-background);
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px var(--shadow-color);
            margin-top: 30px;
        }

        .form-container h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            text-align: center;
        }

        .form-control { /* Estilo base para inputs y selects */
            border-radius: 5px;
            border: 1px solid var(--border-color);
            padding: 10px;
            margin-bottom: 15px; /* Se ajusta con mb-3 de Bootstrap */
            width: 100%;
        }

        .form-label { /* Estilo para etiquetas */
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }

        .form-select { /* Estilo específico para selects */
            border-radius: 5px;
            border: 1px solid var(--border-color);
            padding: 10px;
            width: 100%;
            background-color: white; /* Para asegurar fondo blanco */
        }


        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .btn-success:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

    </style>
</head>
<body>
    <div class="wrapper">
        <div class="sidebar">
            <h2>Gestión Social</h2>
            <ul>
                <li><a href="dashboard.php" class="<?php echo (!isset($_GET['section']) || $_GET['section'] === '') ? 'active' : ''; ?>"><i class="fas fa-home"></i> Inicio</a></li>
                <li><a href="personas.php"><i class="fas fa-users"></i> Personas</a></li>
                <li><a href="familias.php"><i class="fas fa-people-arrows"></i> Familias</a></li>
                <li><a href="ayudas.php"><i class="fas fa-hands-helping"></i> Ayudas</a></li>
                <li><a href="asignaciones.php"><i class="fas fa-file-invoice"></i> Asignaciones</a></li>
                <?php if ($user_role === 'admin'): // Muestra "Configuración" solo si el rol es 'admin' ?>
                    <li><a href="dashboard.php?section=usuarios" class="<?php echo (isset($_GET['section']) && $_GET['section'] === 'usuarios') ? 'active' : ''; ?>"><i class="fas fa-cogs"></i> Configuración</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Bienvenido, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuario'); ?>!</h1>
                <div class="user-info">
                    Rol: <span style="text-transform: capitalize;"><?php echo htmlspecialchars($user_role); ?></span>
                </div>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!$mostrar_formulario_usuarios): // Mostrar el dashboard principal si no se está en la sección de usuarios ?>
                <div class="dashboard-cards">
                    <div class="card-item">
                        <div class="icon"><i class="fas fa-users"></i></div>
                        <h3><?php echo $personas_count; ?></h3>
                        <p>Personas Registradas</p>
                    </div>
                    <div class="card-item">
                        <div class="icon"><i class="fas fa-people-arrows"></i></div>
                        <h3><?php echo $familias_count; ?></h3>
                        <p>Familias Registradas</p>
                    </div>
                    <div class="card-item">
                        <div class="icon"><i class="fas fa-hands-helping"></i></div>
                        <h3><?php echo $ayudas_count; ?></h3>
                        <p>Tipos de Ayuda</p>
                    </div>
                    <div class="card-item">
                        <div class="icon"><i class="fas fa-file-invoice"></i></div>
                        <h3><?php echo $asignaciones_count; ?></h3>
                        <p>Ayudas Asignadas</p>
                    </div>
                </div>

                <div class="recent-activity">
                    <h2>Últimas Personas Registradas</h2>
                    <?php if (!empty($recent_personas)): ?>
                        <ul>
                            <?php foreach ($recent_personas as $persona): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($persona['nombre'] . ' ' . $persona['apellido']); ?></strong> (cédula: <?php echo htmlspecialchars($persona['cedula']); ?>)
                                    - Registrado el: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($persona['fecha_registro']))); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No hay personas registradas recientemente.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($mostrar_formulario_usuarios && $user_role === 'admin'): // Mostrar el formulario de registro de usuarios solo si se ha hecho clic en "Configuración" y es 'admin' ?>
                <div class="form-container">
                    <h2 class="mb-4 text-center">Registrar Nuevo Usuario</h2>
                    <form action="dashboard.php?section=usuarios" method="POST">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="nombre_usuario" class="form-label">Nombre:</label>
                                    <input type="text" class="form-control" id="nombre_usuario" name="nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="apellido_usuario" class="form-label">Apellido:</label>
                                    <input type="text" class="form-control" id="apellido_usuario" name="apellido" required value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-group">
                                <label for="email" class="form-label">Email:</label>
                                <input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-group">
                                <label for="usuario" class="form-label">Usuario:</label>
                                <input type="text" class="form-control" id="usuario" name="usuario" required value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-group">
                                <label for="contraseña" class="form-label">Contraseña:</label>
                                <input type="password" class="form-control" id="contraseña" name="contraseña" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-group">
                                <label for="tipo_usuario" class="form-label">Tipo de Usuario:</label>
                                <select class="form-select" id="tipo_usuario" name="tipo_usuario" required>
                                    <option value="empleado" <?php echo (isset($_POST['tipo_usuario']) && $_POST['tipo_usuario'] == 'empleado') ? 'selected' : ''; ?>>Empleado</option>
                                    <option value="admin" <?php echo (isset($_POST['tipo_usuario']) && $_POST['tipo_usuario'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" name="agregar_usuario" class="btn btn-success">Registrar Usuario</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="text-center" style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                <a href="logout.php" class="btn btn-outline">Cerrar Sesión</a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // Función para capitalizar la primera letra de cada palabra
        function capitalizarInput(inputElementId) {
            const input = document.getElementById(inputElementId);
            if (input) {
                input.addEventListener('input', function() {
                    const words = this.value.split(' ');
                    const capitalizedWords = words.map(word => {
                        if (word.length > 0) {
                            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
                        }
                        return word;
                    });
                    this.value = capitalizedWords.join(' ');
                });
            }
        }

        // Aplicar capitalización a los campos de nombre y apellido del formulario de USUARIO
        capitalizarInput('nombre_usuario');
        capitalizarInput('apellido_usuario');
    </script>
</body>
</html>