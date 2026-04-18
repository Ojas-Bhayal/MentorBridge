<?php
require 'api/db.php';
try {
    $stmt = $pdo->query('SHOW TABLES'); 
    print_r($stmt->fetchAll());
} catch (\Exception $e) {
    echo $e->getMessage();
}
