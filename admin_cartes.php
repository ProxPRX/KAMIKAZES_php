<?php
// ==========================================================================
// GÉNÉRATEUR DE CARTES D'ADHÉRENTS (ADMIN : PLANCHE / MEMBRE : CARTE PERSO) - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// Sécurité : l'utilisateur doit obligatoirement être connecté
$estConnecte = isset($_SESSION['membre']);
if (!$estConnecte) {
    header('Location: connexion.php');
    exit;
}

$membreId    = (int)($_SESSION['membre']['id'] ?? 0);
$role        = $_SESSION['membre']['role'] ?? null;
$estAdmin    = ($role === 'admin');
$modeMaCarte = isset($_GET['ma_carte']);

if (!$modeMaCarte and !$estAdmin) {
    header('Location: admin_cartes.php?ma_carte=1');
    exit;
}

$saisonDefaut = ((int)date('n') >= 9) ? ((int)date('Y') + 1) : (int)date('Y');
$saison       = trim($_GET['saison'] ?? $_POST['saison'] ?? (string)$saisonDefaut);
if (!preg_match('/^\d{4}$/', $saison)) {
    $saison = (string)$saisonDefaut;
}

$erreur    = '';
$adherents = [];
$titrePdf  = "Cartes Adhérents Les Kamikazes Agathois - Saison $saison";

// --- 1. CHARGEMENT DES DONNÉES DEPUIS LA BASE DE DONNÉES ---
if ($modeMaCarte) {
    $stmt = $pdo->prepare("
        SELECT * FROM membres 
        WHERE id = :id 
          AND photo_url IS NOT NULL 
          AND photo_url != ''
    ");
    $stmt->execute([':id' => $membreId]);
    $monCompte = $stmt->fetch();

    if (!$monCompte) {
        $erreur = "Ce compte (administrateur ou accès technique créé manuellement) ne possède pas de carte d'adhérent.";
    } else {
        if (!empty($monCompte['date_expiration'])) {
            $saison = date('Y', strtotime($monCompte['date_expiration']));
        }
        $adherents[] = $monCompte;
        $titrePdf = "Carte Adhérent Les Kamikazes Agathois - " . $monCompte['prenom'] . " " . $monCompte['nom'] . " - Saison " . $saison;
    }
} else {
    $stmt = $pdo->query("
        SELECT * FROM membres 
        WHERE role != 'attente_validation' 
          AND photo_url IS NOT NULL 
          AND photo_url != ''
        ORDER BY nom ASC, prenom ASC
    ");
    $adherents = $stmt->fetchAll();

    if (empty($adherents)) {
        $erreur = "Aucun adhérent avec fiche de carte (importé depuis Yapla) n'est enregistré dans la base.";
    }
}

// Sélection automatique du logo du club
$cheminLogoClub    = 'IMG/Logo_Kamikazes.png';
$emplacementsLogos = [
    'IMG/Logo_Kamikazes.png',
    'IMG/logos/Logo_Kamikazes.png',
    'IMG/logo.png'
];
foreach ($emplacementsLogos as $testLogo) {
    if (is_file($testLogo)) {
        $cheminLogoClub = str_replace(' ', '%20', $testLogo);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="page-title"><?= htmlspecialchars($titrePdf) ?></title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/admin_cartes.css?v=8">
</head>
<body>

    <!-- BARRE D'OUTILS SUPÉRIEURE (NON IMPRIMÉE SUR LE PDF) -->
    <div class="toolbar-no-print">
        <div class="toolbar-top">
            <div>
                <?php if ($modeMaCarte): ?>
                    <h1>🪪 Ma Carte d'Adhérent Les Kamikazes Agathois — Saison <?= htmlspecialchars($saison) ?></h1>
                <?php else: ?>
                    <h1>🪪 Planche des Cartes d'Adhérents Les Kamikazes Agathois (<?= count($adherents) ?> adhérent(s))</h1>
                <?php endif; ?>
                <p class="toolbar-subtitle">
                    Astuce : Cliquez sur <strong>« Enregistrer en PDF / Imprimer »</strong> puis choisissez la destination <strong>« Enregistrer au format PDF »</strong>.
                </p>
            </div>

            <div class="toolbar-controls">
                <?php if (!$modeMaCarte and $estAdmin): ?>
                    <label for="live-saison">Saison :</label>
                    <input type="text" id="live-saison" value="<?= htmlspecialchars($saison) ?>" maxlength="4">
                <?php endif; ?>

                <button type="button" class="btn-print" id="btnPrintPdf">📄 Enregistrer en PDF / Imprimer</button>
                <a href="<?= $modeMaCarte ? 'membres.php' : 'admin_membres.php' ?>" class="btn-secondary-bar">✖ Fermer</a>
            </div>
        </div>

        <?php if ($erreur): ?>
            <div class="toolbar-error">
                <?= htmlspecialchars($erreur) ?>
            </div>
        <?php elseif (!$modeMaCarte and $estAdmin): ?>
            <div class="selector-header">
                <strong class="selector-title">Sélectionner les cartes à inclure dans le PDF :</strong>
                <div class="selector-actions">
                    <button type="button" class="btn-secondary-bar" id="btnCheckAll">☑️ Tout cocher</button>
                    <button type="button" class="btn-secondary-bar" id="btnUncheckAll">⬜ Tout décocher</button>
                </div>
            </div>

            <div class="members-selector">
                <?php foreach ($adherents as $m): ?>
                    <label class="member-chip">
                        <input type="checkbox" class="toggle-card-cb" data-target="card-<?= (int)$m['id'] ?>" checked>
                        <span><?= htmlspecialchars($m['prenom'] . ' ' . $m['nom']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- AFFICHAGE DE LA CARTE (MEMBRE) OU DE LA PLANCHE COMPLÈTE (ADMIN) -->
    <?php if (!empty($adherents)): ?>
        <div class="sheets-container">
            <?php foreach ($adherents as $m): ?>
                <?php
                    $dateNaissFr   = !empty($m['date_naissance']) ? formaterDate($m['date_naissance']) : '';
                    $dateValUasFr  = !empty($m['date_val_uas']) ? date('d/m/Y', strtotime($m['date_val_uas'] . ' +5 years')) : '';
                    $dateFormFr    = !empty($m['date_formation']) ? date('d/m/Y', strtotime($m['date_formation'] . ' +5 years')) : '';
                    $numUasAffiche = !empty($m['num_uas']) ? $m['num_uas'] : 'FRA';
                ?>
                <div class="member-card-row" id="card-<?= (int)$m['id'] ?>">
                    <!-- VOLET GAUCHE : RECTO DE LA CARTE ADHÉRENT -->
                    <div class="card-left">
                        <div class="card-left-body">
                            <div class="col-logo-name">
                                <img src="<?= htmlspecialchars($cheminLogoClub) ?>" alt="Logo Les Kamikazes Agathois" class="card-club-logo">
                                <div class="card-member-names">
                                    <div class="card-prenom"><?= htmlspecialchars($m['prenom']) ?></div>
                                    <div class="card-nom"><?= htmlspecialchars($m['nom']) ?></div>
                                </div>
                            </div>

                            <div class="col-club-details">
                                <div class="txt-saison">Saison <span class="saison-value"><?= htmlspecialchars($saison) ?></span></div>
                                <div class="txt-club-full">Club d'Aéromodélisme d'Agde</div>
                                <div class="txt-affiliation">Affiliation OLAP</div>
                                <div class="txt-smac-red">LES KAMIKAZES AGATHOIS</div>
                                <div class="txt-club-city">34300 Agde</div>
                                <div class="txt-birth">
                                    Né(e) le <?= htmlspecialchars($dateNaissFr) ?>
                                </div>
                            </div>

                            <div class="col-photo">
                                <img src="<?= htmlspecialchars($m['photo_url']) ?>" alt="Photo <?= htmlspecialchars($m['prenom']) ?>" class="member-id-photo">
                            </div>
                        </div>

                        <!-- Bandeau inférieur bleu & jaune -->
                        <div class="card-left-footer">
                            <span class="txt-licence-bottom">
                                Licence Pratiquant N° <?= htmlspecialchars($m['num_licence']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- VOLET DROIT : INFORMATIONS UAS, TÉLÉPILOTE & ASSURANCE -->
                    <div class="card-right">
                        <div class="uas-line">
                            N° UAS : <strong><?= htmlspecialchars($numUasAffiche) ?></strong>
                        </div>
                        <div class="uas-line">
                            Valable jusqu'au <?= htmlspecialchars($dateValUasFr) ?>
                        </div>
                        <div class="telepilote-block">
                            <span>Formation télépilote</span>
                            <span>Valable jusqu'au <?= htmlspecialchars($dateFormFr) ?></span>
                        </div>
                        <div class="assurance-line">
                            Assurance : OLAP (N° police à compléter)
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script src="JS/main.js?v=4"></script>

</body>
</html>
