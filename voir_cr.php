<?php
// ==========================================================================
// PASSERELLE SÉCURISÉE DE LECTURE DES COMPTES RENDUS PDF - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// Sécurité : accès strictement réservé aux membres connectés
if (!isset($_SESSION['membre'])) {
    http_response_code(403);
    exit('Accès refusé.');
}

$crId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($crId <= 0) {
    http_response_code(400);
    exit('Document invalide.');
}

$stmt = $pdo->prepare("SELECT titre, fichier_pdf FROM compte_rendus WHERE id = :id");
$stmt->execute([':id' => $crId]);
$cr = $stmt->fetch();

if (!$cr or empty($cr['fichier_pdf'])) {
    http_response_code(404);
    exit('Compte rendu introuvable.');
}

$cheminFichier = $cr['fichier_pdf'];

// Vérification de sécurité du chemin sur le serveur
if (strpos($cheminFichier, '..') !== false or !preg_match('#^DOCS/\d{4}/compte-rendus/[a-zA-Z0-9_\-\.]+\.pdf$#', $cheminFichier) or !is_file($cheminFichier)) {
    http_response_code(404);
    exit('Fichier introuvable sur le serveur.');
}

// Envoi du flux PDF sans mise en cache publique
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($cheminFichier) . '"');
header('Content-Length: ' . filesize($cheminFichier));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

readfile($cheminFichier);
exit;
