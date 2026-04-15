<?php
require_once 'config/database.php';

try {
    $db = getDB();
    echo "<h1>Casa Monarca 🦋</h1>";
    echo "<p style='color:green'>✓ Conexión a base de datos exitosa</p>";
    
    $tablas = $db->query("SHOW TABLES")->fetchAll();
    echo "<p>Tablas encontradas:</p><ul>";
    foreach ($tablas as $tabla) {
        echo "<li>" . array_values($tabla)[0] . "</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}