<?php
// ==========================================================================
// PASSERELLE SÉCURISÉE POUR MÉDIAS PRIVÉS / EN ATTENTE (IMG & PDF) - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

$type   = $_GET['type'] ?? '';$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$chemin = str_replace('\\', '/', trim((string)($_GET['f'] ?? '')));

// 1. Validation stricte des paramètres et du chemin serveur autorisé
if (
    !in_array($type, ['article', 'membre_pub'], true) or
    $id <= 0 or$chemin === '' or
    strpos($chemin, '..') !== false or
    !preg_match('#^(IMG|DOCS)/\d{4}/(articles|membres)/[a-zA-Z0-9_\-\.]+/[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|webp|pdf)$#i', $chemin)
) {
    http_response_code(400);
    exit('Requête média invalide.');
}

// 2. Chargement de l'enregistrement en base de données
$table = ($type === 'article') ? 'articles' : 'membres_publications';
$stmt  =$pdo->prepare("SELECT image_principale, galerie_photos, documents_pdf, visibilite, statut, auteur_id FROM $table WHERE id = :id");
$stmt->execute([':id' =>$id]);
$enregistrement =$stmt->fetch();

if (!$enregistrement) {
    http_response_code(404);
    exit('Contenu introuvable.');
}

// 3. Vérification que le fichier demandé appartient bien à cet article ou cette publication
$fichiersAutorises = [];
if (!empty($enregistrement['image_principale'])) {
    $fichiersAutorises[] = str_replace('\\', '/', trim($enregistrement['image_principale']));
}
if (!empty($enregistrement['galerie_photos'])) {
    foreach (preg_split('/[\r\n,]+/', $enregistrement['galerie_photos']) as$p) {
        $p = str_replace('\\', '/', trim($p));
        if ($p !== '') $fichiersAutorises[] =$p;
    }
}
if (!empty($enregistrement['documents_pdf'])) {
    foreach (preg_split('/[\r\n,]+/', $enregistrement['documents_pdf']) as$p) {
        $p = str_replace('\\', '/', trim($p));
        if ($p !== '') $fichiersAutorises[] =$p;
    }
}

if (!in_array($chemin,$fichiersAutorises, true)) {
    http_response_code(403);
    exit('Accès refusé à ce fichier.');
}

// 4. Contrôle des droits d'accès selon la session PHP
$estConnecte      = isset($_SESSION['membre']);
$membreId         = (int)($_SESSION['membre']['id'] ?? 0);
$role             =$_SESSION['membre']['role'] ?? null;
$estAdminOuBureau = in_array($role, ['admin', 'bureau'], true);

$estEnAttente = (($enregistrement['statut'] ?? 'publie') === 'en_attente');
$estPrive     = (($enregistrement['visibilite'] ?? 'public') === 'prive');

// Si en attente de validation : réservé à Admin, Bureau et à l'auteur connecté
if ($estEnAttente) {
    $estAuteur = ($estConnecte and (int)$enregistrement['auteur_id'] ===$membreId);
    if (!$estAdminOuBureau and !$estAuteur) {
        http_response_code(403);
        exit('Accès refusé.');
    }
}

// Si privé : réservé uniquement aux membres connectés
if ($estPrive and !$estConnecte) {
    http_response_code(403);
    exit('Accès réservé aux membres connectés.');
}

if (!is_file($chemin)) {
    http_response_code(404);
    exit('Fichier introuvable sur le serveur.');
}

// 5. Détection du type MIME et envoi sécurisé du fichier
$ext       = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));$typesMime = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf'
];
$mime        =$typesMime[$ext] ?? 'application/octet-stream';$disposition = (isset($_GET['dl']) and$_GET['dl'] === '1') ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($chemin) . '"');
header('Content-Length: ' . filesize($chemin));

if ($estPrive or$estEnAttente) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
} else {
    header('Cache-Control: public, max-age=86400');
}

readfile($chemin);
exit;
