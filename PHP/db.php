<?php
// ==========================================================================
// CONNEXION BASE DE DONNÉES (PDO) & INITIALISATION - LES KAMIKAZES AGATHOIS
// ==========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Identifiants MySQL (à remplacer par ceux de ta nouvelle base)
$host     = 'mysql-kamikazesagathois.alwaysdata.net';
$dbname   = 'kamikazesagathois_db';   // Nom exact de ta base MySQL
$username = 'kamikazesagathois_romain';       // Utilisateur MySQL
$password = '@Romainj770123';  // Mot de passe MySQL

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données. Vérifiez PHP/db.php.");
}

// Chargement des fonctions partagées
require_once __DIR__ . '/fonctions.php';
