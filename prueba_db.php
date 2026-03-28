<?php
// Datos de conexión
$serverName = "DESKTOP-T26OK4S, 1433";
$database = "DB_MedCore_Global";
$username = "sa";
$password = "Oracle92";

try {
    // Intentamos conectar usando PDO con la cadena de conexión estándar
    // Agregamos TrustServerCertificate=true porque es lo que usa tu Visual Studio
    $dsn = "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=true";
    $conn = new PDO($dsn, $username, $password);
    
    // Configuramos para que PDO nos muestre errores detallados
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- ¡CONEXIÓN EXITOSA DESDE PHP PURO! ---\n";

    // Intentamos una consulta a la tabla Roles
    $query = $conn->query("SELECT * FROM Roles");
    $resultados = $query->fetchAll(PDO::FETCH_ASSOC);

    echo "Datos encontrados en la tabla Roles:\n";
    print_r($resultados);

} catch (PDOException $e) {
    echo "--- ERROR DE CONEXIÓN ---\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Código: " . $e->getCode() . "\n";
}