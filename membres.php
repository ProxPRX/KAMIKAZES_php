<?php
// ==========================================================================
// PAGE ESPACE MEMBRES (COMPTES RENDUS, CARTE, TROMBI, VACANCES & RÉALISATIONS)
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte         = isset($_SESSION['membre']);
$membreId            = (int)($_SESSION['membre']['id'] ?? 0);
$monProfil           = null;
$aUneCarteMembre     = false;
$role                = '';
$estAdmin            = false;
$estAdminOuBureau    = false;
$peutProposerContenu = false;
$membresTrombi       = [];
$comptesRendus       = [];
$erreurCr            = '';
$succesCr            = '';
$messagePub          = '';

// Protège un dossier de comptes rendus contre tout accès direct par URL publique
function protegerDossierCompteRendus($dossier) {
    if (!is_dir($dossier)) {
        return;
    }
    $fichierHtaccess = rtrim($dossier, '/') . '/.htaccess';
    if (!is_file($fichierHtaccess)) {
        $regles = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        @file_put_contents($fichierHtaccess, $regles);
    }
}

if ($estConnecte) {
    $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = :id");
    $stmt->execute([':id' => $membreId]);
    $monProfil = $stmt->fetch();

    if ($monProfil) {
        $role = trim((string)($monProfil['role'] ?? ''));

        // Synchronise le rôle en session avec la base de données pour éviter tout décalage avec header.php
        $_SESSION['membre']['role'] = $role;

        $estAdmin            = ($role === 'admin');
        $estAdminOuBureau    = in_array($role, ['admin', 'bureau'], true);
        $peutProposerContenu = in_array($role, ['membre', 'membre_associe', 'bureau', 'admin'], true);

        if (!empty($monProfil['photo_url'])) {
            $aUneCarteMembre = true;
        }
    }

    // --- 1. GESTION DES COMPTES RENDUS (AJOUT, MODIFICATION & SUPPRESSION : ADMIN ET BUREAU UNIQUEMENT) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' and isset($_POST['action_cr'])) {
        $actionCr = $_POST['action_cr'];

        // A. Publication d'un nouveau compte rendu (Admin et Bureau uniquement)
        if ($actionCr === 'ajouter_cr' and $estAdminOuBureau) {
            $titre       = trim($_POST['titre'] ?? '');
            $dateReunion = $_POST['date_reunion'] ?? date('Y-m-d');

            if (empty($titre) or empty($_FILES['fichier_pdf']['name'])) {
                $erreurCr = "Veuillez renseigner le titre du compte rendu et sélectionner un fichier PDF.";
            } else {
                $anneeCr      = date('Y', strtotime($dateReunion));
                $dossierCible = "DOCS/$anneeCr/compte-rendus";
                $uploadPdf    = enregistrerPdfArticle($_FILES['fichier_pdf']['tmp_name'], $_FILES['fichier_pdf']['name'], $dossierCible);

                if ($uploadPdf) {
                    protegerDossierCompteRendus($dossierCible);
                    $stmtIns = $pdo->prepare("INSERT INTO compte_rendus (titre, date_reunion, fichier_pdf) VALUES (:titre, :date_reunion, :pdf)");
                    $stmtIns->execute([
                        ':titre'        => $titre,
                        ':date_reunion' => $dateReunion,
                        ':pdf'          => $uploadPdf
                    ]);
                    $succesCr = "✅ Compte rendu publié avec succès !";
                } else {
                    $erreurCr = "Erreur lors du téléversement du fichier PDF (vérifiez qu'il s'agit d'un vrai PDF valide).";
                }
            }
        }
        // B. Modification d'un compte rendu
        elseif ($actionCr === 'modifier_cr' and $estAdminOuBureau) {
            $crId        = (int)($_POST['cr_id'] ?? 0);
            $titre       = trim($_POST['titre'] ?? '');
            $dateReunion = $_POST['date_reunion'] ?? '';

            if ($crId <= 0 or empty($titre) or empty($dateReunion)) {
                $erreurCr = "Veuillez renseigner le titre et la date de la réunion pour modifier le compte rendu.";
            } else {
                $stmtOld = $pdo->prepare("SELECT * FROM compte_rendus WHERE id = :id");
                $stmtOld->execute([':id' => $crId]);
                $ancienCr = $stmtOld->fetch();

                if ($ancienCr) {
                    $fichierPdfFinal = $ancienCr['fichier_pdf'];

                    if (!empty($_FILES['nouveau_pdf']['name'])) {
                        if ($_FILES['nouveau_pdf']['error'] === UPLOAD_ERR_OK) {
                            $anneeCr      = date('Y', strtotime($dateReunion));
                            $dossierCible = "DOCS/$anneeCr/compte-rendus";
                            $uploadPdf    = enregistrerPdfArticle($_FILES['nouveau_pdf']['tmp_name'], $_FILES['nouveau_pdf']['name'], $dossierCible);

                            if ($uploadPdf) {
                                protegerDossierCompteRendus($dossierCible);
                                if (!empty($ancienCr['fichier_pdf']) and $ancienCr['fichier_pdf'] !== $uploadPdf) {
                                    supprimerFichierServeur($ancienCr['fichier_pdf']);
                                }
                                $fichierPdfFinal = $uploadPdf;
                            } else {
                                $erreurCr = "Erreur lors du remplacement : vérifiez qu'il s'agit bien d'un fichier PDF valide.";
                            }
                        } else {
                            $erreurCr = "Erreur lors de l'envoi du nouveau fichier PDF.";
                        }
                    }

                    if (empty($erreurCr)) {
                        $stmtUpd = $pdo->prepare("
                            UPDATE compte_rendus 
                            SET titre = :titre, date_reunion = :date_reunion, fichier_pdf = :pdf 
                            WHERE id = :id
                        ");
                        $stmtUpd->execute([
                            ':titre'        => $titre,
                            ':date_reunion' => $dateReunion,
                            ':pdf'          => $fichierPdfFinal,
                            ':id'           => $crId
                        ]);
                        $succesCr = "✏️ Le compte rendu a bien été modifié !";
                    }
                }
            }
        }
        // C. Suppression d'un compte rendu
        elseif ($actionCr === 'supprimer_cr' and $estAdminOuBureau) {
            $crId = (int)($_POST['cr_id'] ?? 0);
            if ($crId > 0) {
                $stmtOld = $pdo->prepare("SELECT fichier_pdf FROM compte_rendus WHERE id = :id");
                $stmtOld->execute([':id' => $crId]);
                $ancienCr = $stmtOld->fetch();

                if ($ancienCr) {
                    if (!empty($ancienCr['fichier_pdf'])) {
                        supprimerFichierServeur($ancienCr['fichier_pdf']);
                    }
                    $stmtDel = $pdo->prepare("DELETE FROM compte_rendus WHERE id = :id");
                    $stmtDel->execute([':id' => $crId]);
                    $succesCr = "🗑️ Le compte rendu a été supprimé définitivement.";
                }
            }
        } else {
            $erreurCr = "Accès refusé : seuls les membres du Bureau et les Administrateurs peuvent gérer les comptes rendus.";
        }
    }

    // --- 2. VALIDATION / REFUS / SUPPRESSION D'UNE PUBLICATION MEMBRE (VACANCES OU RÉALISATIONS) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' and isset($_POST['action_pub_membre'])) {
        $actionPub = $_POST['action_pub_membre'];
        $pubId     = (int)($_POST['pub_id'] ?? 0);

        $stmtCible = $pdo->prepare("
            SELECT mp.*, m.prenom AS auteur_prenom, m.email AS auteur_email
            FROM membres_publications mp
            LEFT JOIN membres m ON mp.auteur_id = m.id
            WHERE mp.id = :id
        ");
        $stmtCible->execute([':id' => $pubId]);
        $pubCible = $stmtCible->fetch();

        if ($pubCible) {
            $estAuteurEnAttente = ((int)$pubCible['auteur_id'] === $membreId and $pubCible['statut'] === 'en_attente');

            // A. Validation et mise en ligne (Admin ou Bureau uniquement)
            if ($actionPub === 'valider_pub' and $estAdminOuBureau) {
                $stmtVal = $pdo->prepare("UPDATE membres_publications SET statut = 'publie' WHERE id = :id");
                $stmtVal->execute([':id' => $pubId]);

                $doitResterProtege = (($pubCible['visibilite'] ?? 'public') === 'prive');
                appliquerProtectionMediaEnregistrement(
                    $pubCible['image_principale'] ?? '',
                    $pubCible['galerie_photos'] ?? '',
                    $pubCible['documents_pdf'] ?? '',
                    $doitResterProtege
                );

                if (!empty($pubCible['auteur_email']) and (int)$pubCible['auteur_id'] !== $membreId) {
                    envoyerMailDecisionPublicationMembre(
                        $pubCible['auteur_email'],
                        $pubCible['auteur_prenom'] ?? 'Membre',
                        $pubCible['titre'],
                        $pubCible['categorie'],
                        $pubId,
                        true
                    );
                }
                header("Location: membres.php?pub=$pubId&valide=1");
                exit;
            }

            // B. Suppression ou refus
            elseif ($actionPub === 'supprimer_pub' and ($estAdminOuBureau or $estAuteurEnAttente)) {
                $etaitEnAttente = ($pubCible['statut'] === 'en_attente');

                if (!empty($pubCible['image_principale'])) {
                    supprimerFichierServeur($pubCible['image_principale']);
                }
                if (!empty($pubCible['galerie_photos'])) {
                    foreach (array_filter(array_map('trim', explode("\n", $pubCible['galerie_photos']))) as $imgP) {
                        supprimerFichierServeur($imgP);
                    }
                }
                if (!empty($pubCible['documents_pdf'])) {
                    foreach (array_filter(array_map('trim', explode("\n", $pubCible['documents_pdf']))) as $pdfP) {
                        supprimerFichierServeur($pdfP);
                    }
                }

                $stmtDel = $pdo->prepare("DELETE FROM membres_publications WHERE id = :id");
                $stmtDel->execute([':id' => $pubId]);

                if ($etaitEnAttente and $estAdminOuBureau and !empty($pubCible['auteur_email']) and (int)$pubCible['auteur_id'] !== $membreId) {
                    envoyerMailDecisionPublicationMembre(
                        $pubCible['auteur_email'],
                        $pubCible['auteur_prenom'] ?? 'Membre',
                        $pubCible['titre'],
                        $pubCible['categorie'],
                        $pubId,
                        false
                    );
                }

                $ancreRetour = ($pubCible['categorie'] === 'realisations') ? '#realisations' : '#photos';
                header("Location: membres.php" . $ancreRetour);
                exit;
            }
        }
    }
}

// --- 3. MODE LECTURE COMPLÈTE D'UNE PUBLICATION (membres.php?pub=ID) ---
$publicationDetail = null;
if (isset($_GET['pub'])) {
    $idLecture = (int)$_GET['pub'];
    $stmtDetail = $pdo->prepare("
        SELECT mp.*, m.prenom AS auteur_prenom, m.nom AS auteur_nom
        FROM membres_publications mp
        LEFT JOIN membres m ON mp.auteur_id = m.id
        WHERE mp.id = :id
    ");
    $stmtDetail->execute([':id' => $idLecture]);
    $publicationDetail = $stmtDetail->fetch();

    if (!$publicationDetail) {
        header('Location: membres.php');
        exit;
    }

    $estEnAttente       = ($publicationDetail['statut'] === 'en_attente');
    $estAuteurEnAttente = ($estConnecte and (int)$publicationDetail['auteur_id'] === $membreId and $estEnAttente);

    if ($estEnAttente and !$estAdminOuBureau and !$estAuteurEnAttente) {
        header('Location: membres.php');
        exit;
    }

    if (($publicationDetail['visibilite'] ?? 'public') === 'prive' and !$estConnecte) {
        header('Location: connexion.php');
        exit;
    }

    $estDetailProtege = ($estEnAttente or ($publicationDetail['visibilite'] ?? 'public') === 'prive');
    if ($estDetailProtege) {
        appliquerProtectionMediaEnregistrement(
            $publicationDetail['image_principale'] ?? '',
            $publicationDetail['galerie_photos'] ?? '',
            $publicationDetail['documents_pdf'] ?? '',
            true
        );
    }
} else {
    // --- 4. MODE LISTE GÉNÉRALE DE L'ESPACE MEMBRES ---
    if ($estConnecte) {
        $stmtTrombi = $pdo->query("
            SELECT prenom, nom, photo_url 
            FROM membres 
            WHERE role != 'attente_validation' 
              AND photo_url IS NOT NULL 
              AND photo_url != ''
            ORDER BY nom ASC, prenom ASC
        ");
        $membresTrombi = $stmtTrombi->fetchAll();

        $comptesRendus = $pdo->query("SELECT * FROM compte_rendus ORDER BY date_reunion DESC, id DESC")->fetchAll();
        foreach ($comptesRendus as $crItem) {
            if (!empty($crItem['fichier_pdf'])) {
                protegerDossierCompteRendus(dirname($crItem['fichier_pdf']));
            }
        }
    }

    if ($estAdminOuBureau) {
        $stmtPubs = $pdo->query("
            SELECT mp.*, m.prenom AS auteur_prenom, m.nom AS auteur_nom
            FROM membres_publications mp
            LEFT JOIN membres m ON mp.auteur_id = m.id
            ORDER BY FIELD(mp.statut, 'en_attente', 'publie'), mp.date_publication DESC, mp.id DESC
        ");
    } elseif ($estConnecte) {
        $stmtPubs = $pdo->prepare("
            SELECT mp.*, m.prenom AS auteur_prenom, m.nom AS auteur_nom
            FROM membres_publications mp
            LEFT JOIN membres m ON mp.auteur_id = m.id
            WHERE mp.statut = 'publie' OR mp.auteur_id = :mon_id
            ORDER BY FIELD(mp.statut, 'en_attente', 'publie'), mp.date_publication DESC, mp.id DESC
        ");
        $stmtPubs->execute([':mon_id' => $membreId]);
    } else {
        $stmtPubs = $pdo->query("
            SELECT mp.*, m.prenom AS auteur_prenom, m.nom AS auteur_nom
            FROM membres_publications mp
            LEFT JOIN membres m ON mp.auteur_id = m.id
            WHERE mp.statut = 'publie' AND mp.visibilite = 'public'
            ORDER BY mp.date_publication DESC, mp.id DESC
        ");
    }

    $toutesPublications = $stmtPubs->fetchAll();
    $listeVacances      = [];
    $listeRealisations  = [];
    $nbPubsEnAttente    = 0;

    foreach ($toutesPublications as $pub) {
        if ($pub['statut'] === 'en_attente') {
            $nbPubsEnAttente++;
        }
        if ($pub['statut'] === 'en_attente' or ($pub['visibilite'] ?? 'public') === 'prive') {
            appliquerProtectionMediaEnregistrement(
                $pub['image_principale'] ?? '',
                $pub['galerie_photos'] ?? '',
                $pub['documents_pdf'] ?? '',
                true
            );
        }
        if ($pub['categorie'] === 'realisations') {
            $listeRealisations[] = $pub;
        } else {
            $listeVacances[] = $pub;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Espace Membres des Kamikazes Agathois : comptes rendus, carte d'adhérent, trombinoscope, photos de vacances et réalisations.">
    <title><?= $publicationDetail ? htmlspecialchars($publicationDetail['titre']) . ' - Les Kamikazes Agathois' : 'Membres - Les Kamikazes Agathois' ?></title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/membres.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <?php if ($publicationDetail): ?>
        <!-- ==================================================================
             VUE DÉTAILLÉE D'UNE PUBLICATION ("LIRE LA SUITE")
             ================================================================== -->
        <?php
            $ancreCat           = ($publicationDetail['categorie'] === 'realisations') ? '#realisations' : '#photos';
            $labelCat           = ($publicationDetail['categorie'] === 'realisations') ? '🛠️ Réalisations des membres' : '📸 Photos de vacances & Voyages';
            $estEnAttente       = ($publicationDetail['statut'] === 'en_attente');
            $estAuteurEnAttente = ($estConnecte and (int)$publicationDetail['auteur_id'] === $membreId and $estEnAttente);
            $estPubProtegee     = ($estEnAttente or ($publicationDetail['visibilite'] ?? 'public') === 'prive');
            $photosPub          = !empty($publicationDetail['galerie_photos']) ? array_filter(array_map('trim', explode("\n", $publicationDetail['galerie_photos']))) : [];
            $pdfsPub            = !empty($publicationDetail['documents_pdf']) ? array_filter(array_map('trim', explode("\n", $publicationDetail['documents_pdf']))) : [];

            $youtubeIds = [];
            if (!empty($publicationDetail['video_youtube'])) {
                foreach (preg_split('/[\r\n,\s]+/', $publicationDetail['video_youtube']) as $vUrl) {
                    $ytId = extraireIdYoutube($vUrl);
                    if ($ytId and !in_array($ytId, $youtubeIds)) {
                        $youtubeIds[] = $ytId;
                    }
                }
            }
        ?>
        <main>
            <div style="margin-bottom: 20px;">
                <a href="membres.php<?= $ancreCat ?>" class="btn-secondary" style="margin-top: 0; text-decoration: none; display: inline-block;">
                    &larr; Retour à l'Espace Membres
                </a>
            </div>

            <?php if (isset($_GET['valide'])): ?>
                <div class="cms-feedback cms-feedback-success">
                    ✅ <strong>Publication validée !</strong> Elle est désormais visible en ligne dans l'Espace Membres (un e-mail de confirmation a été envoyé à l'auteur).
                </div>
            <?php endif; ?>

            <?php if ($estAdminOuBureau or $estAuteurEnAttente): ?>
                <div class="pub-moderation-bar <?= $estEnAttente ? 'is-pending' : '' ?>">
                    <div>
                        <?php if ($estEnAttente and $estAdminOuBureau): ?>
                            <strong>⏳ Publication proposée par <?= afficherNomAuteur($publicationDetail['auteur_prenom'], $publicationDetail['auteur_nom']) ?> (en attente de validation)</strong>
                        <?php elseif ($estEnAttente): ?>
                            <strong>⏳ Votre publication est en attente de validation par le Bureau ou l'Administrateur.</strong>
                        <?php else: ?>
                            <strong>⚙️ Administration de la publication</strong>
                        <?php endif; ?>
                    </div>

                    <div class="pub-admin-actions">
                        <?php if ($estEnAttente and $estAdminOuBureau): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Valider et publier cette proposition en ligne ?');">
                                <input type="hidden" name="action_pub_membre" value="valider_pub">
                                <input type="hidden" name="pub_id" value="<?= (int)$publicationDetail['id'] ?>">
                                <button type="submit" class="btn-pub-action btn-pub-validate">✅ Valider et mettre en ligne</button>
                            </form>
                        <?php endif; ?>

                        <a href="admin_publication_membre.php?modifier=<?= (int)$publicationDetail['id'] ?>" class="btn-pub-action btn-pub-edit">✏️ Modifier</a>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('<?= ($estEnAttente and $estAdminOuBureau) ? 'Refuser et supprimer cette proposition ? Un e-mail sera envoyé au membre.' : 'Supprimer définitivement cette publication ?' ?>');">
                            <input type="hidden" name="action_pub_membre" value="supprimer_pub">
                            <input type="hidden" name="pub_id" value="<?= (int)$publicationDetail['id'] ?>">
                            <button type="submit" class="btn-pub-action btn-pub-delete">
                                <?= ($estEnAttente and $estAdminOuBureau) ? '❌ Refuser la proposition' : '🗑️ Supprimer' ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <article class="pub-detail-box">
                <div class="pub-detail-meta">
                    <span>📅 <?= formaterDate($publicationDetail['date_publication']) ?></span>
                    <span>•</span>
                    <span><?= $labelCat ?></span>
                    <span>•</span>
                    <span>✍️ Par <strong><?= afficherNomAuteur($publicationDetail['auteur_prenom'], $publicationDetail['auteur_nom'], 'Les Kamikazes Agathois') ?></strong></span>
                    <?php if (($publicationDetail['visibilite'] ?? 'public') === 'prive'): ?>
                        <span class="badge-prive">🔒 Membres</span>
                    <?php endif; ?>
                </div>

                <h1><?= htmlspecialchars($publicationDetail['titre']) ?></h1>

                <?php if (!empty($publicationDetail['image_principale'])): ?>
                    <?php $urlImgPrincipale = obtenirUrlMedia($publicationDetail['image_principale'], $estPubProtegee, 'membre_pub', $publicationDetail['id']); ?>
                    <div class="pub-main-img-wrapper">
                        <img src="<?= htmlspecialchars($urlImgPrincipale) ?>" 
                             alt="<?= htmlspecialchars($publicationDetail['titre']) ?>" 
                             class="pub-main-img"
                             title="Cliquez pour agrandir en plein écran"
                             onclick="ouvrirLightbox('<?= htmlspecialchars($urlImgPrincipale, ENT_QUOTES) ?>')">
                    </div>
                <?php endif; ?>

                <div class="pub-detail-content">
                    <?= nl2br(htmlspecialchars($publicationDetail['contenu'])) ?>
                </div>

                <!-- GALERIE PHOTOS CLIQUABLE EN PLEIN ÉCRAN -->
                <?php if (!empty($photosPub)): ?>
                    <div class="pub-detail-section">
                        <h3>📸 Galerie photos (<?= count($photosPub) ?> — cliquez sur une photo pour l'agrandir)</h3>
                        <div class="gallery-grid">
                            <?php foreach ($photosPub as $imgP): ?>
                                <?php $urlImgGal = obtenirUrlMedia($imgP, $estPubProtegee, 'membre_pub', $publicationDetail['id']); ?>
                                <div class="gallery-item" onclick="ouvrirLightbox('<?= htmlspecialchars($urlImgGal, ENT_QUOTES) ?>')">
                                    <img src="<?= htmlspecialchars($urlImgGal) ?>" alt="<?= htmlspecialchars($publicationDetail['titre']) ?>" loading="lazy">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- DOCUMENTS PDF JOINTS -->
                <?php if (!empty($pdfsPub)): ?>
                    <div class="pub-detail-section">
                        <h3>📄 <?= count($pdfsPub) > 1 ? "Documents PDF joints (" . count($pdfsPub) . ")" : "Document PDF joint" ?></h3>
                        <div class="pdf-list">
                            <?php foreach ($pdfsPub as $cheminPdf): ?>
                                <?php
                                    $nomFichierPdf   = basename($cheminPdf);
                                    $titreLisiblePdf = str_replace('_', ' ', pathinfo($nomFichierPdf, PATHINFO_FILENAME));
                                    $urlPdfVue       = obtenirUrlMedia($cheminPdf, $estPubProtegee, 'membre_pub', $publicationDetail['id'], false);
                                    $urlPdfDl        = obtenirUrlMedia($cheminPdf, $estPubProtegee, 'membre_pub', $publicationDetail['id'], true);
                                ?>
                                <div class="pdf-card">
                                    <div class="pdf-card-header">
                                        <span class="pdf-title">📄 <?= htmlspecialchars($titreLisiblePdf) ?></span>
                                        <div class="pdf-actions">
                                            <a href="<?= htmlspecialchars($urlPdfVue) ?>" target="_blank" class="btn-secondary" style="margin-top:0; padding:8px 14px; font-size:0.88rem; text-decoration:none;">
                                                🔗 Ouvrir en plein écran
                                            </a>
                                            <a href="<?= htmlspecialchars($urlPdfDl) ?>" download="<?= htmlspecialchars($nomFichierPdf) ?>" class="btn-primary" style="padding:8px 14px; font-size:0.88rem; text-decoration:none;">
                                                📥 Télécharger le PDF
                                            </a>
                                        </div>
                                    </div>
                                    <iframe src="<?= htmlspecialchars($urlPdfVue) ?>" class="pdf-viewer" title="<?= htmlspecialchars($titreLisiblePdf) ?>"></iframe>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- VIDÉOS YOUTUBE -->
                <?php if (!empty($youtubeIds)): ?>
                    <div class="pub-detail-section">
                        <h3>🎬 <?= count($youtubeIds) > 1 ? "Vidéos (" . count($youtubeIds) . ")" : "Vidéo" ?></h3>
                        <?php foreach ($youtubeIds as $ytId): ?>
                            <div class="video-container" style="margin-top: 15px;">
                                <iframe src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($ytId) ?>?rel=0&modestbranding=1" 
                                        title="<?= htmlspecialchars($publicationDetail['titre']) ?>" 
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                        allowfullscreen>
                                </iframe>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </main>

    <?php else: ?>
        <!-- ==================================================================
             VUE PRINCIPALE DE L'ESPACE MEMBRES
             ================================================================== -->
        <section class="hero membres-hero">
            <div class="hero-content">
                <h2>Espace Membres</h2>
                <p>Partagez vos photos, vos voyages et vos plus belles réalisations.</p>
            </div>
        </section>

        <!-- SOUS-MENU COLLANT -->
        <nav id="subnav" class="subnav">
            <ul>
                <?php if ($estConnecte): ?>
                    <li><a href="#comptes-rendus" class="subnav-link active">📋 Comptes Rendus</a></li>
                    <?php if ($aUneCarteMembre): ?>
                        <li><a href="#ma-carte" class="subnav-link">🪪 Ma Carte Membre</a></li>
                    <?php endif; ?>
                    <li><a href="#trombinoscope" class="subnav-link">👥 Trombinoscope</a></li>
                <?php endif; ?>
                <li><a href="#photos" class="subnav-link <?= !$estConnecte ? 'active' : '' ?>">📸 Photos & Voyages</a></li>
                <li><a href="#realisations" class="subnav-link">🛠️ Réalisations</a></li>
            </ul>
        </nav>

        <main>
            <?php if ($estAdminOuBureau and $nbPubsEnAttente > 0): ?>
                <div class="pending-pub-banner">
                    <span>⏳ <strong><?= $nbPubsEnAttente ?> publication(s) proposée(s) par des membres</strong> (Vacances / Réalisations) <?= $nbPubsEnAttente > 1 ? 'sont' : 'est' ?> en attente de validation.</span>
                    <a href="#photos" class="btn-primary" style="padding: 8px 16px; font-size: 0.9rem;">Voir et valider &darr;</a>
                </div>
            <?php endif; ?>

            <!-- SECTIONS PRIVÉES (UNIQUEMENT VISIBLES PAR LES MEMBRES CONNECTÉS) -->
            <?php if ($estConnecte): ?>

                <!-- SECTION PRIVÉE 0 : COMPTES RENDUS RÉUNION BUREAU & AG -->
                <section id="comptes-rendus" class="membres-section">
                    <h2>📋 Comptes Rendus (Réunions de Bureau & AG)</h2>
                    <p style="margin-bottom: 25px; font-size: 1.05rem; color: var(--ka-gris-fonce);">
                        Cliquez sur un bandeau ci-dessous pour ouvrir et consulter le compte rendu correspondant<?= !$estAdminOuBureau ? ' (consultation en lecture seule)' : '' ?>.
                    </p>

                    <?php if ($erreurCr): ?>
                        <div class="cms-feedback cms-feedback-error" style="margin-bottom: 20px;"><?= htmlspecialchars($erreurCr) ?></div>
                    <?php endif; ?>

                    <?php if ($succesCr): ?>
                        <div class="cms-feedback cms-feedback-success" style="margin-bottom: 20px;"><?= htmlspecialchars($succesCr) ?></div>
                    <?php endif; ?>

                    <!-- Formulaire de publication strictement réservé aux Administrateurs et Membres du Bureau -->
                    <?php if ($estAdminOuBureau): ?>
                        <div class="admin-box" style="margin-bottom: 35px; background: var(--ka-blanc); padding: 25px; border-radius: 8px; border: 1px solid var(--ka-gris-clair); border-top: 4px solid var(--ka-bleu);">
                            <h3 style="color: var(--ka-bleu); margin-bottom: 15px;">➕ Publier un nouveau compte rendu (PDF)</h3>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action_cr" value="ajouter_cr">
                                <div class="form-row" style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:15px;">
                                    <div class="form-group" style="flex:2; min-width:220px;">
                                        <label for="titre">Titre du compte rendu *</label>
                                        <input type="text" id="titre" name="titre" required placeholder="Ex: AG Ordinaire 2026 ou Réunion de Bureau de mars">
                                    </div>
                                    <div class="form-group" style="flex:1; min-width:160px;">
                                        <label for="date_reunion">Date de la réunion *</label>
                                        <input type="date" id="date_reunion" name="date_reunion" required value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="fichier_pdf">Fichier PDF officiel *</label>
                                    <input type="file" id="fichier_pdf" name="fichier_pdf" accept="application/pdf,.pdf" required>
                                </div>
                                <button type="submit" class="btn-primary">🚀 Publier le compte rendu</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($comptesRendus)): ?>
                        <div class="empty-forum">
                            <p>Aucun compte rendu n'a été publié pour le moment.</p>
                        </div>
                    <?php else: ?>
                        <div class="cr-accordion-list">
                            <?php foreach ($comptesRendus as $cr): ?>
                                <?php $urlCrSecurisee = 'voir_cr.php?id=' . (int)$cr['id']; ?>
                                <div class="cr-accordion-item" id="cr-item-<?= (int)$cr['id'] ?>">
                                    <div class="cr-accordion-header" role="button" tabindex="0" aria-expanded="false">
                                        <div class="cr-header-info">
                                            <span class="cr-title">📄 <?= htmlspecialchars($cr['titre']) ?></span>
                                            <span class="cr-date">Réunion du <?= formaterDate($cr['date_reunion']) ?></span>
                                        </div>

                                        <div class="cr-header-right">
                                            <span class="cr-toggle-badge">
                                                <span class="cr-toggle-label">👁️ Consulter</span>
                                                <span class="cr-chevron">▼</span>
                                            </span>

                                            <?php if ($estAdminOuBureau): ?>
                                                <div class="cr-header-actions">
                                                    <a href="<?= htmlspecialchars($urlCrSecurisee) ?>" target="_blank" class="btn-secondary" style="margin-top:0; padding:7px 12px; font-size:0.84rem; text-decoration:none;">
                                                        🔗 Plein écran
                                                    </a>
                                                    <a href="<?= htmlspecialchars($urlCrSecurisee) ?>" download="<?= htmlspecialchars(basename($cr['fichier_pdf'])) ?>" class="btn-primary" style="padding:7px 12px; font-size:0.84rem; text-decoration:none;">
                                                        📥 Télécharger
                                                    </a>
                                                    <button type="button"
                                                            class="btn-pub-action btn-pub-edit"
                                                            style="padding:7px 12px; font-size:0.84rem;"
                                                            title="Modifier le nom, la date ou le fichier PDF"
                                                            onclick="var p = document.getElementById('cr-edit-<?= (int)$cr['id'] ?>'); p.style.display = (p.style.display === 'block' ? 'none' : 'block');">
                                                        ✏️ Modifier
                                                    </button>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer définitivement ce compte rendu ?');">
                                                        <input type="hidden" name="action_cr" value="supprimer_cr">
                                                        <input type="hidden" name="cr_id" value="<?= (int)$cr['id'] ?>">
                                                        <button type="submit" class="btn-pub-action btn-pub-delete" style="padding:7px 12px; font-size:0.84rem;" title="Supprimer ce compte rendu">
                                                            🗑️ Supprimer
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($estAdminOuBureau): ?>
                                        <div id="cr-edit-<?= (int)$cr['id'] ?>" style="display:none; background:var(--ka-gris-back); border-bottom:1px solid var(--ka-gris-clair);">
                                            <form method="POST" action="membres.php#comptes-rendus" enctype="multipart/form-data" class="cms-admin-form">
                                                <input type="hidden" name="action_cr" value="modifier_cr">
                                                <input type="hidden" name="cr_id" value="<?= (int)$cr['id'] ?>">

                                                <div class="cms-form-row">
                                                    <div class="cms-field">
                                                        <label>Titre du compte rendu *</label>
                                                        <input type="text" name="titre" value="<?= htmlspecialchars($cr['titre']) ?>" required>
                                                    </div>
                                                    <div class="cms-field">
                                                        <label>Date de la réunion *</label>
                                                        <input type="date" name="date_reunion" value="<?= htmlspecialchars($cr['date_reunion']) ?>" required>
                                                    </div>
                                                </div>

                                                <div class="cms-field">
                                                    <label>Remplacer le fichier PDF (Optionnel — laissez vide pour conserver le PDF actuel)</label>
                                                    <input type="file" name="nouveau_pdf" accept="application/pdf,.pdf">
                                                    <span class="cms-help">Fichier actuel : <code><?= htmlspecialchars(basename($cr['fichier_pdf'])) ?></code></span>
                                                </div>

                                                <div class="cms-actions">
                                                    <button type="submit" class="btn-cms-save">💾 Enregistrer les modifications</button>
                                                    <button type="button" class="btn-secondary" style="margin-top:0; padding:8px 14px; font-size:0.86rem; border:none; cursor:pointer;" onclick="document.getElementById('cr-edit-<?= (int)$cr['id'] ?>').style.display='none';">
                                                        Annuler
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <div class="cr-accordion-body">
                                        <?php if ($estAdminOuBureau): ?>
                                            <iframe data-src="<?= htmlspecialchars($urlCrSecurisee) ?>" class="pdf-viewer cr-lazy-iframe" title="<?= htmlspecialchars($cr['titre']) ?>"></iframe>
                                        <?php else: ?>
                                            <div class="cr-readonly-viewer" data-pdf-url="<?= htmlspecialchars($urlCrSecurisee) ?>" data-loaded="0">
                                                <div class="cr-readonly-topbar">
                                                    <span>🔒 Document officiel Les Kamikazes Agathois — Consultation en lecture seule</span>
                                                    <span class="cr-page-count"></span>
                                                </div>
                                                <div class="cr-canvas-pages">
                                                    <p class="cr-loading-msg">⏳ Chargement du document en cours...</p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- SECTION PRIVÉE 1 : MA CARTE D'ADHÉRENT -->
                <?php if ($aUneCarteMembre): ?>
                    <?php
                        $saisonMembre = !empty($monProfil['date_expiration'])
                            ? date('Y', strtotime($monProfil['date_expiration']))
                            : (((int)date('n') >= 9) ? ((int)date('Y') + 1) : (int)date('Y'));
                    ?>
                    <section id="ma-carte" class="membres-section">
                        <h2>🪪 Ma Carte d'Adhérent</h2>
                        <p style="margin-bottom: 20px; font-size: 1.05rem; color: var(--ka-gris-fonce);">
                            Retrouvez ici votre carte officielle des <strong>Kamikazes Agathois</strong> pour la <strong>Saison <?= htmlspecialchars($saisonMembre) ?></strong>, prête à être enregistrée en PDF sur votre téléphone ou imprimée.
                        </p>

                        <div class="member-card-box">
                            <div class="member-card-box-left">
                                <img src="<?= htmlspecialchars($monProfil['photo_url']) ?>" 
                                     alt="Photo de <?= htmlspecialchars($monProfil['prenom']) ?>" 
                                     width="80" height="100"
                                     class="member-card-avatar">

                                <div class="member-card-summary">
                                    <h3><?= htmlspecialchars($monProfil['prenom'] . ' ' . $monProfil['nom']) ?></h3>
                                    <ul>
                                        <li><strong>N° Licence Pratiquant :</strong> <code><?= htmlspecialchars($monProfil['num_licence']) ?></code></li>
                                        <?php if (!empty($monProfil['num_uas'])): ?>
                                            <li><strong>N° Exploitant UAS :</strong> <?= htmlspecialchars($monProfil['num_uas']) ?></li>
                                        <?php endif; ?>
                                        <?php if (!empty($monProfil['date_expiration'])): ?>
                                            <li><strong>Validité de l'adhésion :</strong> jusqu'au <?= formaterDate($monProfil['date_expiration']) ?></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>

                            <div class="member-card-box-action">
                                <a href="admin_cartes.php?ma_carte=1" target="_blank" class="btn-primary">
                                    📥 Télécharger / Imprimer ma carte membre (PDF)
                                </a>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- SECTION PRIVÉE 2 : TROMBINOSCOPE DU CLUB -->
                <section id="trombinoscope" class="membres-section">
                    <h2>👥 Trombinoscope du Club (<?= count($membresTrombi) ?> membres)</h2>
                    <p style="margin-bottom: 25px; font-size: 1.05rem; color: var(--ka-gris-fonce);">
                        Retrouvez l'ensemble des adhérents des <strong>Kamikazes Agathois</strong>.
                    </p>

                    <div class="trombi-grid">
                        <?php foreach ($membresTrombi as $membreClub): ?>
                            <div class="trombi-card">
                                <div class="trombi-photo-wrapper">
                                    <img src="<?= htmlspecialchars($membreClub['photo_url']) ?>" 
                                         alt="<?= htmlspecialchars($membreClub['nom'] . ' ' . $membreClub['prenom']) ?>" 
                                         width="140" height="175"
                                         class="trombi-photo" 
                                         loading="lazy">
                                </div>
                                <div class="trombi-info">
                                    <strong class="trombi-nom"><?= htmlspecialchars($membreClub['nom']) ?></strong>
                                    <span class="trombi-prenom"><?= htmlspecialchars($membreClub['prenom']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <!-- MESSAGE D'INCITATION À LA CONNEXION POUR LES VISITEURS PUBLICS -->
                <div class="admin-box" style="margin-bottom: 40px; text-align: center; background: var(--ka-blanc); padding: 30px; border-radius: 8px; border: 1px solid var(--ka-gris-clair); border-top: 4px solid var(--ka-bleu);">
                    <h3 style="color: var(--ka-bleu); margin-bottom: 10px;">🔒 Espace Membres Sécurisé</h3>
                    <p style="margin-bottom: 15px; color: var(--ka-gris-fonce);">Vous consultez actuellement la partie publique de l'espace membres. Connectez-vous avec votre compte pour accéder aux comptes rendus de réunions, au trombinoscope, à votre carte d'adhérent et partager vos publications.</p>
                    <a href="connexion.php" class="btn-primary">Se connecter à mon compte</a>
                </div>
            <?php endif; ?>

            <!-- SECTION 1 : PHOTOS DE VACANCES & VOYAGES -->
            <section id="photos" class="membres-section">
                <div class="section-header-flex">
                    <div>
                        <h2>Photos de vacances</h2>
                        <p style="margin-bottom: 20px; font-size: 1.1rem; color: var(--ka-gris-fonce);">Nos membres font aussi voler leurs machines autour du globe !</p>
                    </div>

                    <?php if ($peutProposerContenu): ?>
                        <a href="admin_publication_membre.php?cat=vacances" class="btn-primary">
                            <?= $estAdminOuBureau ? '➕ Publier dans Photos & Voyages' : '✍️ Proposer mes photos de vacances' ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($listeVacances)): ?>
                    <div class="empty-forum">
                        <p>Aucune publication dans « Photos de vacances » pour le moment.</p>
                    </div>
                <?php else: ?>
                    <div class="news-grid">
                        <?php foreach ($listeVacances as $pub): ?>
                            <?php
                                $estPubProtegee = ($pub['statut'] === 'en_attente' or ($pub['visibilite'] ?? 'public') === 'prive');
                                $vignette       = $pub['image_principale'];
                                if (empty($vignette) and !empty($pub['galerie_photos'])) {
                                    $premieresPhotos = array_filter(array_map('trim', explode("\n", $pub['galerie_photos'])));
                                    $vignette = $premieresPhotos[0] ?? null;
                                }
                                $urlVignette = !empty($vignette) ? obtenirUrlMedia($vignette, $estPubProtegee, 'membre_pub', $pub['id']) : '';
                                $texteResume = !empty($pub['resume']) ? $pub['resume'] : mb_substr($pub['contenu'], 0, 140, 'UTF-8') . '...';
                            ?>
                            <article class="news-card <?= $pub['statut'] === 'en_attente' ? 'news-card-pending' : '' ?>" id="pub-<?= (int)$pub['id'] ?>">
                                <?php if (!empty($urlVignette)): ?>
                                    <img src="<?= htmlspecialchars($urlVignette) ?>" alt="<?= htmlspecialchars($pub['titre']) ?>" class="news-image" loading="lazy">
                                <?php else: ?>
                                    <div class="news-image placeholder-img">Photo Kamikazes Agathois</div>
                                <?php endif; ?>

                                <div class="news-content">
                                    <span class="news-date">
                                        <?= formaterDate($pub['date_publication']) ?> • <?= afficherNomAuteur($pub['auteur_prenom'], $pub['auteur_nom'], 'Les Kamikazes Agathois') ?>
                                        <?php if (($pub['visibilite'] ?? 'public') === 'prive'): ?>
                                            <strong class="badge-prive">🔒 Membres</strong>
                                        <?php endif; ?>
                                        <?php if ($pub['statut'] === 'en_attente'): ?>
                                            <strong class="badge-attente-carte">⏳ En attente</strong>
                                        <?php endif; ?>
                                    </span>
                                    <h3><?= htmlspecialchars($pub['titre']) ?></h3>
                                    <p><?= htmlspecialchars($texteResume) ?></p>
                                    <a href="membres.php?pub=<?= (int)$pub['id'] ?>" class="read-more">
                                        <?= ($pub['statut'] === 'en_attente' and $estAdminOuBureau) ? '🔎 Vérifier et valider &rarr;' : 'Lire la suite &rarr;' ?>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- SECTION 2 : RÉALISATIONS EN ATELIER -->
            <section id="realisations" class="membres-section">
                <div class="section-header-flex">
                    <div>
                        <h2>Les réalisations des membres</h2>
                        <p style="margin-bottom: 20px; font-size: 1.1rem; color: var(--ka-gris-fonce);">Dans l'atelier des Kamikazes Agathois, la passion prend forme.</p>
                    </div>

                    <?php if ($peutProposerContenu): ?>
                        <a href="admin_publication_membre.php?cat=realisations" class="btn-primary">
                            <?= $estAdminOuBureau ? '➕ Publier une réalisation' : '✍️ Proposer une réalisation' ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($listeRealisations)): ?>
                    <div class="empty-forum">
                        <p>Aucune réalisation publiée pour le moment.</p>
                    </div>
                <?php else: ?>
                    <div class="news-grid">
                        <?php foreach ($listeRealisations as $pub): ?>
                            <?php
                                $estPubProtegee = ($pub['statut'] === 'en_attente' or ($pub['visibilite'] ?? 'public') === 'prive');
                                $vignette       = $pub['image_principale'];
                                if (empty($vignette) and !empty($pub['galerie_photos'])) {
                                    $premieresPhotos = array_filter(array_map('trim', explode("\n", $pub['galerie_photos'])));
                                    $vignette = $premieresPhotos[0] ?? null;
                                }
                                $urlVignette = !empty($vignette) ? obtenirUrlMedia($vignette, $estPubProtegee, 'membre_pub', $pub['id']) : '';
                                $texteResume = !empty($pub['resume']) ? $pub['resume'] : mb_substr($pub['contenu'], 0, 140, 'UTF-8') . '...';
                            ?>
                            <article class="news-card <?= $pub['statut'] === 'en_attente' ? 'news-card-pending' : '' ?>" id="pub-<?= (int)$pub['id'] ?>">
                                <?php if (!empty($urlVignette)): ?>
                                    <img src="<?= htmlspecialchars($urlVignette) ?>" alt="<?= htmlspecialchars($pub['titre']) ?>" class="news-image" loading="lazy">
                                <?php else: ?>
                                    <div class="news-image placeholder-img">Photo Kamikazes Agathois</div>
                                <?php endif; ?>

                                <div class="news-content">
                                    <span class="news-date">
                                        <?= formaterDate($pub['date_publication']) ?> • <?= afficherNomAuteur($pub['auteur_prenom'], $pub['auteur_nom'], 'Les Kamikazes Agathois') ?>
                                        <?php if (($pub['visibilite'] ?? 'public') === 'prive'): ?>
                                            <strong class="badge-prive">🔒 Membres</strong>
                                        <?php endif; ?>
                                        <?php if ($pub['statut'] === 'en_attente'): ?>
                                            <strong class="badge-attente-carte">⏳ En attente</strong>
                                        <?php endif; ?>
                                    </span>
                                    <h3><?= htmlspecialchars($pub['titre']) ?></h3>
                                    <p><?= htmlspecialchars($texteResume) ?></p>
                                    <a href="membres.php?pub=<?= (int)$pub['id'] ?>" class="read-more">
                                        <?= ($pub['statut'] === 'en_attente' and $estAdminOuBureau) ? '🔎 Vérifier et valider &rarr;' : 'Lire la suite &rarr;' ?>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    <?php endif; ?>

    <?php if ($estConnecte and !$estAdminOuBureau and !empty($comptesRendus)): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <?php endif; ?>

    <?php include 'footer.php'; ?>

</body>
</html>
