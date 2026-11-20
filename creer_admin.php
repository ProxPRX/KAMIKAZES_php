<?php
require_once 'PHP/db.php';

$stmt = $pdo->prepare("
    INSERT INTO membres (nom, prenom, email, num_licence, identifiant, mot_de_passe, role, date_expiration)
    VALUES (:nom, :prenom, :email, :licence, :identifiant, :mdp, 'admin', NULL)
");

$stmt->execute([
    ':nom'         => 'KAMIKAZES',
    ':prenom'      => 'Admin',
    ':email'       => 'kamikazes34300@gmail.com',
    ':licence'     => 'APHD-00-00-000',
    ':identifiant' => 'admin',
    ':mdp'         => password_hash('admin', PASSWORD_DEFAULT),
]);

echo "✅ Compte admin créé (identifiant : admin / mdp : admin). Supprime ce fichier creer_admin.php maintenant !";
