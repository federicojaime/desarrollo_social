<?php
session_start();
require 'includes/conexion.php'; // Asegúrate de que este archivo conecta a la base de datos
// require 'vendor/autoload.php'; // Habilitar si usas librerías como PhpOffice para un XLSX real

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Obtener el rol del usuario logueado desde la sesión
$user_role = $_SESSION['rol'] ?? 'empleado'; // 'empleado' por defecto si no está definido

$mensaje = '';
$error = '';

// Recuperar mensajes de sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']); // Limpiar el mensaje después de mostrarlo
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']); // Limpiar el error después de mostrarlo
}

// Manejo de Agregar/Editar Familia
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['agregar_familia']) || isset($_POST['editar_familia'])) {
        $id = $_POST['familia_id'] ?? null;
        $nombre_jefe = trim($_POST['nombre_jefe']);
        $dni_jefe = trim($_POST['dni_jefe']); // Nuevo campo DNI
        $direccion = trim($_POST['direccion']); // ¡Error corregido aquí!
        $telefono = trim($_POST['telefono']);
        $integrantes = (int)$_POST['integrantes'];

        if (empty($nombre_jefe) || empty($dni_jefe)) { // DNI ahora es obligatorio
            $_SESSION['error'] = "El nombre del jefe de familia y el DNI son obligatorios.";
        } elseif (!ctype_digit($dni_jefe)) { // Validar que el DNI contenga solo dígitos
            $_SESSION['error'] = "El DNI del jefe de familia debe contener solo números.";
        } else {
            try {
                // Verificar si el DNI ya existe (excepto para la familia que se está editando)
                $stmt_check_dni = $pdo->prepare("SELECT COUNT(*) FROM familias WHERE dni_jefe = ? AND id != ?");
                $stmt_check_dni->execute([$dni_jefe, $id ?? 0]);
                if ($stmt_check_dni->fetchColumn() > 0) {
                    $_SESSION['error'] = "Ya existe una familia con el DNI del jefe ingresado.";
                } else {
                    if ($id) {
                        // Editar familia
                        $stmt = $pdo->prepare("UPDATE familias SET nombre_jefe = ?, dni_jefe = ?, direccion = ?, telefono = ?, integrantes = ? WHERE id = ?");
                        $stmt->execute([$nombre_jefe, $dni_jefe, $direccion, $telefono, $integrantes, $id]);
                        $_SESSION['mensaje'] = "Familia actualizada correctamente.";
                    } else {
                        // Agregar nueva familia
                        $stmt = $pdo->prepare("INSERT INTO familias (nombre_jefe, dni_jefe, direccion, telefono, integrantes) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$nombre_jefe, $dni_jefe, $direccion, $telefono, $integrantes]);
                        $_SESSION['mensaje'] = "Familia agregada correctamente.";
                    }
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
            }
        }
        header("Location: familias.php"); // Redirigir para evitar reenvío de formulario
        exit();
    } elseif (isset($_POST['eliminar_familia'])) {
        $id = $_POST['familia_id'];
        try {
            // Verificar si hay personas asociadas a esta familia
            $stmt_check_personas = $pdo->prepare("SELECT COUNT(*) FROM personas WHERE id_familia = ?");
            $stmt_check_personas->execute([$id]);
            if ($stmt_check_personas->fetchColumn() > 0) {
                $_SESSION['error'] = "No se puede eliminar la familia porque tiene personas asociadas. Primero desvincula las personas o elimínalas.";
            } else {
                // Eliminar familia
                $stmt = $pdo->prepare("DELETE FROM familias WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['mensaje'] = "Familia eliminada correctamente.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error de base de datos: " . $e->getMessage();
        }
        header("Location: familias.php");
        exit();
    } elseif (isset($_POST['importar_familias_xls'])) {
        // Lógica de importación de XLS para Familias
        if (isset($_FILES['xlsFileFamilias']) && $_FILES['xlsFileFamilias']['error'] == UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['xlsFileFamilias']['tmp_name'];
            $fileName = $_FILES['xlsFileFamilias']['name'];
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);

            if ($fileExtension !== 'xls' && $fileExtension !== 'xlsx') {
                $_SESSION['error'] = "Por favor, sube un archivo Excel válido (.xls o .xlsx).";
                header("Location: familias.php");
                exit();
            }

            // Aquí iría la lógica real de procesamiento del archivo XLS
            // Esto es un placeholder. Necesitarías una librería como PhpOffice/PhpSpreadsheet para esto.
            // Puedes ver un ejemplo conceptual comentado en el dashboard.php si lo necesitas.

            $_SESSION['mensaje'] = "Importación de Familias desde XLS procesada. (Funcionalidad completa requiere librería PhpOffice/PhpSpreadsheet)";
        } else {
            $_SESSION['error'] = "Error al subir el archivo XLS.";
        }
        header("Location: familias.php");
        exit();
    } elseif (isset($_POST['exportar_familias_xls'])) {
        // Lógica de exportación a XLS para Familias
        // Esto es un placeholder. Necesitarías una librería como PhpOffice/PhpSpreadsheet.

        // Ejemplo conceptual con PhpOffice/PhpSpreadsheet:
        /*
        try {
            require 'vendor/autoload.php'; // Asegúrate de tener la librería instalada via Composer
            use PhpOffice\PhpSpreadsheet\Spreadsheet;
            use PhpOffice\PhpSpreadsheet\Writer\Xls;

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Encabezados
            $sheet->setCellValue('A1', 'Nombre Jefe');
            $sheet->setCellValue('B1', 'DNI Jefe');
            $sheet->setCellValue('C1', 'Dirección');
            $sheet->setCellValue('D1', 'Teléfono');
            $sheet->setCellValue('E1', 'Integrantes');
            $sheet->setCellValue('F1', 'Fecha Registro');

            // Datos
            $rowIndex = 2;
            $stmt = $pdo->query("SELECT * FROM familias ORDER BY nombre_jefe ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sheet->setCellValue('A' . $rowIndex, $row['nombre_jefe']);
                $sheet->setCellValue('B' . $rowIndex, $row['dni_jefe']);
                $sheet->setCellValue('C' . $rowIndex, $row['direccion']);
                $sheet->setCellValue('D' . $rowIndex, $row['telefono']);
                $sheet->setCellValue('E' . $rowIndex, $row['integrantes']);
                $sheet->setCellValue('F' . $rowIndex, date('d/m/Y', strtotime($row['fecha_registro'])));
                $rowIndex++;
            }

            $writer = new Xls($spreadsheet);
            $fileName = 'familias_' . date('Ymd_His') . '.xls';

            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="' . $fileName . '"');
            header('Cache-Control: max-age=0');

            $writer->save('php://output');
            exit;

        } catch (Exception $e) {
            $_SESSION['error'] = "Error al exportar a XLS: " . $e->getMessage();
            header("Location: familias.php");
            exit();
        }
        */
        $_SESSION['mensaje'] = "Exportación de Familias a XLS procesada. (Funcionalidad completa requiere librería PhpOffice/PhpSpreadsheet)";
        header("Location: familias.php");
        exit();
    }
}

// Obtener todas las familias para mostrar en la tabla
try {
    $stmt = $pdo->query("SELECT * FROM familias ORDER BY nombre_jefe ASC");
    $familias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error al cargar familias: " . $e->getMessage();
    $familias = []; // Asegúrate de que $familias sea un array vacío en caso de error
}

// Lógica para pre-llenar el formulario en modo edición
$familia_a_editar = null;
if (isset($_GET['action']) && $_GET['action'] === 'editar' && isset($_GET['id'])) {
    $id_editar = $_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM familias WHERE id = ?");
        $stmt->execute([$id_editar]);
        $familia_a_editar = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$familia_a_editar) {
            $error = "Familia no encontrada para edición.";
        }
    } catch (PDOException $e) {
        $error = "Error al cargar familia para edición: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Familias - Desarrollo Social</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        :root {
            --primary-color: #0056b3; /* Un azul más oscuro para el sidebar y elementos principales */
            --secondary-color: #007bff; /* Azul vibrante, usado para el hover general */
            --background-color: #f4f7f6;
            --card-background: #ffffff;
            --text-color: #333;
            --border-color: #ddd;
            --shadow-color: rgba(0, 0, 0, 0.1);

            /* Colores específicos de la imagen para el menú activo */
            --sidebar-active-bg: #E0EFFF; /* Azul claro para el fondo activo del sidebar */
            --sidebar-active-text: #0056b3; /* Azul oscuro para el texto activo */
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
            display: flex;
            flex-direction: column; /* Para empujar el logout al final */
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            flex-grow: 1; /* Permite que la lista de enlaces ocupe el espacio disponible */
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
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Estilos para el elemento de menú activo */
        .sidebar ul li a.active {
            background-color: var(--sidebar-active-bg); /* Fondo azul claro como en la imagen */
            color: var(--sidebar-active-text); /* Texto azul oscuro */
            font-weight: bold;
        }

        .sidebar ul li a:not(.active):hover { /* Solo aplica hover si no está activo */
            background-color: rgba(255, 255, 255, 0.2); /* Un blanco transparente para hover en inactivos */
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

        /* Botón de cerrar sesión en el sidebar */
        .sidebar .btn-outline {
            background-color: transparent;
            border: 1px solid white;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
            margin-top: auto; /* Empuja el botón al final del sidebar */
            display: block; /* Para que ocupe todo el ancho disponible */
        }

        .sidebar .btn-outline:hover {
            background-color: white;
            color: var(--primary-color);
        }

        .form-container, .table-container {
            background-color: var(--card-background);
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px var(--shadow-color);
            margin-bottom: 30px;
        }

        .form-container h2, .table-container h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 1rem; /* Espaciado estándar de Bootstrap */
        }

        .form-group label {
            font-weight: bold;
            margin-bottom: .5rem; /* Espaciado estándar de Bootstrap */
            display: block;
        }

        .form-control {
            border-radius: .25rem; /* Bordes ligeramente redondeados como en Bootstrap */
            border: 1px solid var(--border-color);
            padding: .375rem .75rem; /* Padding estándar de Bootstrap */
            width: 100%;
        }

        .btn { /* Estilo base para todos los botones */
            padding: .375rem .75rem; /* Padding estándar de Bootstrap */
            border-radius: .25rem; /* Bordes ligeramente redondeados como en Bootstrap */
            cursor: pointer;
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
            display: inline-flex; /* Para alinear íconos y texto */
            align-items: center; /* Centrar verticalmente el contenido */
            justify-content: center; /* Centrar horizontalmente el contenido */
            gap: 5px; /* Espacio entre icono y texto */
            font-size: 1rem; /* Tamaño de fuente base */
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #004494;
            border-color: #004494;
        }

        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
        }

        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
        }
        .btn-success:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }

        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #333; /* Color oscuro para contraste con el amarillo */
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #e0a800;
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
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

        .table-responsive {
            margin-top: 20px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead th {
            background-color: var(--primary-color);
            color: white;
            padding: 10px;
            text-align: left;
        }

        .table tbody td {
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr:hover {
            background-color: #f0f0f0;
        }

        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-container .form-control {
            flex-grow: 1;
            margin-bottom: 0;
        }

        .no-results, .no-data-message {
            text-align: center;
            color: #666;
            padding: 20px;
            border: 1px dashed var(--border-color);
            border-radius: 5px;
            margin-top: 20px;
        }

        /* Estilos unificados para botones de importar/exportar */
        .btn-file-action {
            display: inline-flex;
            background-color: #007bff; /* Azul vibrante */
            color: white;
            padding: 8px 15px; /* Ajustado para ser un poco más pequeño que los botones de acción principales */
            border-radius: .25rem; /* Pequeño redondeo */
            cursor: pointer;
            transition: background-color 0.3s ease;
            text-decoration: none;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-size: 0.9rem; /* Un poco más pequeño para estos botones */
            border: 1px solid #007bff; /* Borde del mismo color */
            min-width: 130px; /* Ancho mínimo para igualar si el texto varía */
            box-sizing: border-box; /* Asegura que padding y border se incluyan en el width/height */
            height: 38px; /* Altura explícita, ajusta si es necesario para que coincida con otros botones */
        }

        .btn-file-action:hover {
            background-color: #0056b3; /* Azul oscuro al pasar el ratón */
            border-color: #0056b3;
        }

        /* Oculta el input de tipo file original */
        .file-input {
            display: none;
        }

        /* Contenedor de botones de import/export para asegurar alineación */
        .file-action-buttons {
            display: flex;
            gap: 10px; /* Espacio entre los botones */
            align-items: center; /* Asegura que estén a la misma altura */
            flex-wrap: wrap; /* Permite que los botones salten de línea en pantallas pequeñas */
        }

    </style>
</head>
<body>
    <div class="wrapper">
        <div class="sidebar">
            <h2>Gestión Social</h2>
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Inicio</a></li>
                <li><a href="personas.php"><i class="fas fa-users"></i> Personas</a></li>
                <li><a href="familias.php" class="active"><i class="fas fa-people-arrows"></i> Familias</a></li>
                <li><a href="ayudas.php"><i class="fas fa-hands-helping"></i> Ayudas</a></li>
                <li><a href="asignaciones.php"><i class="fas fa-file-invoice"></i> Asignaciones</a></li>
                <?php if ($user_role === 'admin'): ?>
                    <li><a href="dashboard.php?section=usuarios"><i class="fas fa-cogs"></i> Configuración</a></li>
                <?php endif; ?>
            </ul>
            <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Gestión de Familias</h1>
                <div class="user-info">
                    Bienvenido, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuario'); ?>
                    <br>
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

            <div class="form-container">
                <h2><?php echo ($familia_a_editar ? 'Editar Familia' : 'Agregar Nueva Familia'); ?></h2>
                <form action="familias.php" method="POST">
                    <?php if ($familia_a_editar): ?>
                        <input type="hidden" name="familia_id" value="<?php echo htmlspecialchars($familia_a_editar['id']); ?>">
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="nombre_jefe">Nombre del Jefe de Familia:</label>
                            <input type="text" class="form-control" id="nombre_jefe" name="nombre_jefe" required value="<?php echo htmlspecialchars($familia_a_editar['nombre_jefe'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="dni_jefe">DNI del Jefe de Familia:</label>
                            <input type="text" class="form-control" id="dni_jefe" name="dni_jefe" required value="<?php echo htmlspecialchars($familia_a_editar['dni_jefe'] ?? ''); ?>" pattern="\d*" title="Solo se permiten números">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="direccion">Dirección:</label>
                            <input type="text" class="form-control" id="direccion" name="direccion" value="<?php echo htmlspecialchars($familia_a_editar['direccion'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="telefono">Teléfono:</label>
                            <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($familia_a_editar['telefono'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="integrantes">Número de Integrantes:</label>
                        <input type="number" class="form-control" id="integrantes" name="integrantes" required min="1" value="<?php echo htmlspecialchars($familia_a_editar['integrantes'] ?? 1); ?>">
                    </div>
                    <button type="submit" name="<?php echo ($familia_a_editar ? 'editar_familia' : 'agregar_familia'); ?>" class="btn btn-primary">
                        <?php echo ($familia_a_editar ? '<i class="fas fa-sync-alt"></i> Actualizar Familia' : '<i class="fas fa-plus"></i> Agregar Familia'); ?>
                    </button>
                    <?php if ($familia_a_editar): ?>
                        <a href="familias.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar Edición</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2>Listado de Familias</h2>
                    <div class="file-action-buttons"> <form action="familias.php" method="POST" enctype="multipart/form-data" class="d-flex align-items-center">
                            <button type="button" id="importXlsTriggerBtnFamilias" class="btn-file-action">
                                <i class="fas fa-upload"></i> Importar XLS
                            </button>
                            <input type="file" name="xlsFileFamilias" id="xlsFileFamilias" class="file-input" accept=".xls, .xlsx">
                            <button type="submit" name="importar_familias_xls" id="submitImportFamilias" style="display: none;"></button>
                        </form>
                        <form action="familias.php" method="POST" class="d-flex align-items-center">
                            <button type="submit" name="exportar_familias_xls" class="btn-file-action">
                                <i class="fas fa-download"></i> Exportar XLS
                            </button>
                        </form>
                    </div>
                </div>

                <div class="search-container">
                    <input type="text" id="searchInput" class="form-control" placeholder="Buscar por nombre o DNI de jefe...">
                    <button id="clearSearchBtn" class="btn btn-secondary" style="display: none;"><i class="fas fa-times"></i></button>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="familiasTable">
                        <thead>
                            <tr>
                                <th>Nombre Jefe</th>
                                <th>DNI Jefe</th>
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Integrantes</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($familias)): ?>
                                <?php foreach ($familias as $familia): ?>
                                    <tr data-nombre="<?php echo htmlspecialchars(strtolower($familia['nombre_jefe'])); ?>" data-dni="<?php echo htmlspecialchars(strtolower($familia['dni_jefe'])); ?>">
                                        <td><?php echo htmlspecialchars($familia['nombre_jefe']); ?></td>
                                        <td><?php echo htmlspecialchars($familia['dni_jefe']); ?></td>
                                        <td><?php echo htmlspecialchars($familia['direccion']); ?></td>
                                        <td><?php echo htmlspecialchars($familia['telefono']); ?></td>
                                        <td><?php echo htmlspecialchars($familia['integrantes']); ?></td>
                                        <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($familia['fecha_registro']))); ?></td>
                                        <td>
                                            <a href="familias.php?action=editar&id=<?php echo htmlspecialchars($familia['id']); ?>" class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form action="familias.php" method="POST" style="display:inline-block;" onsubmit="return confirm('¿Estás seguro de eliminar esta familia? Se eliminarán también las personas asociadas.');">
                                                <input type="hidden" name="familia_id" value="<?php echo htmlspecialchars($familia['id']); ?>">
                                                <button type="submit" name="eliminar_familia" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr id="noDataMessage">
                                    <td colspan="7" class="text-center">No hay familias registradas aún.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="noResults" class="no-results" style="display: none;">No se encontraron resultados para su búsqueda.</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            capitalizarInput('nombre_jefe');

            // --- Lógica de búsqueda ---
            const searchInput = document.getElementById('searchInput');
            const clearSearchBtn = document.getElementById('clearSearchBtn');
            const familiasTableBody = document.querySelector('#familiasTable tbody');
            const noResultsDiv = document.getElementById('noResults');
            const noDataMessage = document.getElementById('noDataMessage'); // El mensaje "No hay familias registradas aún"

            function realizarBusqueda() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                let visibleRows = 0;

                // Ocultar el mensaje "No hay familias registradas aún" si hay filas o se está buscando
                if (noDataMessage) {
                    noDataMessage.style.display = 'none';
                }

                Array.from(familiasTableBody.rows).forEach(row => {
                    // Excluir la fila 'noDataMessage' de la búsqueda
                    if (row.id === 'noDataMessage') {
                        row.style.display = 'none'; // Asegurarse de que esté oculta durante la búsqueda
                        return;
                    }

                    const nombreJefe = row.dataset.nombre;
                    const dniJefe = row.dataset.dni;

                    if (nombreJefe.includes(searchTerm) || dniJefe.includes(searchTerm)) {
                        row.style.display = ''; // Mostrar fila
                        visibleRows++;
                    } else {
                        row.style.display = 'none'; // Ocultar fila
                    }
                });

                // Mostrar/ocultar mensaje de "No se encontraron resultados"
                if (visibleRows === 0 && searchTerm !== '') {
                    noResultsDiv.style.display = 'block';
                } else {
                    noResultsDiv.style.display = 'none';
                }

                // Si no hay búsqueda activa y no hay resultados iniciales (tabla vacía), mostrar el mensaje "No hay familias registradas"
                if (searchTerm === '' && visibleRows === 0 && noDataMessage) {
                     noDataMessage.style.display = 'table-row'; // Mostrar la fila de no datos si la tabla está vacía y no hay búsqueda
                }
            }

            function limpiarBusqueda() {
                searchInput.value = '';
                clearSearchBtn.style.display = 'none';
                realizarBusqueda();
            }

            searchInput.addEventListener('input', function() {
                if (this.value.trim() !== '') {
                    clearSearchBtn.style.display = 'inline-block';
                } else {
                    clearSearchBtn.style.display = 'none';
                }
                realizarBusqueda();
            });

            clearSearchBtn.addEventListener('click', limpiarBusqueda);

            // Asegurarse de que el botón de limpiar esté visible si ya hay texto en el input al cargar la página
            if (searchInput.value.trim() !== '') {
                clearSearchBtn.style.display = 'inline-block';
            }

            // Ejecutar la búsqueda inicial para manejar el estado de la tabla (vacía o con datos)
            realizarBusqueda();

            // --- Manejo del archivo XLS para Familias ---
            const importXlsTriggerBtnFamilias = document.getElementById('importXlsTriggerBtnFamilias'); // Nuevo botón visible
            const xlsFileFamiliasInput = document.getElementById('xlsFileFamilias');
            const submitImportFamilias = document.getElementById('submitImportFamilias');

            if (importXlsTriggerBtnFamilias && xlsFileFamiliasInput && submitImportFamilias) {
                // Cuando se hace clic en el botón visible, activa el input de archivo oculto
                importXlsTriggerBtnFamilias.addEventListener('click', function() {
                    xlsFileFamiliasInput.click();
                });

                // Cuando se selecciona un archivo en el input (incluso si fue activado por el botón)
                xlsFileFamiliasInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const fileName = this.files[0].name;
                        if (confirm(`¿Estás seguro de que deseas importar el archivo \"${fileName}\"?\n\nEl archivo XLS (compatible con Excel) debe tener las columnas (en orden): Nombre_Jefe, DNI_Jefe, Direccion, Telefono, Integrantes.`)) {
                            submitImportFamilias.click(); // Dispara el clic en el botón oculto para enviar el formulario
                        } else {
                            this.value = ''; // Limpiar la selección del archivo si el usuario cancela
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>