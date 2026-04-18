<?php
require 'api/db.php';
$stmt = $pdo->query('SELECT * FROM Roles');
print_r($stmt->fetchAll());
