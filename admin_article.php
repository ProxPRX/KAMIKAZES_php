<?php
// ==========================================================================
// CRÉATION, PROPOSITION ET MODIFICATION D'UN ARTICLE - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// Sécurité : accès autorisé aux Membres, Membres associés, Bureau et Admin
$estConnecte      = isset($_SESSION['membre']);
$role             = $_SESSION['membre']['role'] ?? null;
$membreId         = (int)($_SESSION['membre']['id'] ?? 0);
$estAdminOuBureau = in_array($role, ['admin', 'bureau'], true);

if (!$estConnecte) {
    header('Location: connexion.php');
    exit;
}

// Seuls les comptes en attente de validation sont bloqués
if (!in_array($role, ['membre', 'membre_associe', 'bureau', 'admin'], true)) {
    header('Location: actualites.php');
    exit;
}

// Détection du mode : Modification (si ?id=... est présent) ou Création
$id          = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$modeEdition = ($id > 0);
$erreur      = '';

// Valeurs par défaut d'un nouvel article
$article = [
    'titre'            => '',
    'date_publication' => date('Y-m-d'),
    'resume'           => '',
    'contenu'          => '',
    'image_principale' => '',
    'galerie_photos'   => '',
    'video_youtube'    => '',
    'documents_pdf'    => '',
    'visibilite'       => 'public',
    'statut'           => $estAdminOuBureau ? 'publie' : 'en_attente'
];

// --- 1. CHARGEMENT DE L'ARTICLE EXISTANT EN MODE MODIFICATION ---
if ($modeEdition) {
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $existant = $stmt->fetch();

    if (!$existant) {
        header('Location: actualites.php');
        exit;
    }

    // Un membre ou membre associé ne peut modifier que son propre article et uniquement s'il est encore en attente
    $estAuteurEnAttente = ((int)$existant['auteur_id'] === $membreId and $existant['statut'] === 'en_attente');
    if (!$estAdminOuBureau and !$estAuteurEnAttente) {
        header('Location: actualites.php');
        exit;
    }

    $article = $existant;
}

// --- 2. TRAITEMENT DU FORMULAIRE À LA SOUMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre            = trim($_POST['titre'] ?? '');
    $date_publication = $_POST['date_publication'] ?? date('Y-m-d');
    $visibilite       = ($_POST['visibilite'] ?? 'public') === 'prive' ? 'prive' : 'public';
    $resume           = trim($_POST['resume'] ?? '');
    $contenu          = trim($_POST['contenu'] ?? '');

    if ($estAdminOuBureau) {
        $statut = ($_POST['statut'] ?? 'publie') === 'en_attente' ? 'en_attente' : 'publie';
    } else {
        $statut = 'en_attente';
    }

    // Regroupement des liens YouTube saisis (un par ligne en base de données)
    $videosSaisies = $_POST['videos_youtube'] ?? [];
    if (is_array($videosSaisies)) {
        $videosPropres = array_filter(array_map('trim', $videosSaisies));
        $video_youtube = implode("\n", $videosPropres);
    } else {
        $video_youtube = trim((string)$videosSaisies);
    }

    if (empty($titre) or empty($resume) or empty($contenu)) {
        $erreur = "Veuillez remplir le titre, le résumé et le contenu de l'article.";
    } else {
        $anneeArticle    = date('Y', strtotime($date_publication));
        $slugArticle     = creerSlugDossier($titre);
        $dossierCibleImg = "IMG/$anneeArticle/articles/$slugArticle";
        $dossierCibleDoc = "DOCS/$anneeArticle/articles/$slugArticle";

        // A. Image principale (vignette)
        $imagePrincipale = $article['image_principale'];
        if ($modeEdition and !empty($_POST['supprimer_image_principale'])) {
            if (!empty($imagePrincipale)) {
                supprimerFichierServeur($imagePrincipale);
            }
            $imagePrincipale = '';
        }

        if (!empty($_FILES['image_principale']['name']) and $_FILES['image_principale']['error'] === UPLOAD_ERR_OK) {
            $upload = enregistrerImageOptimisee($_FILES['image_principale']['tmp_name'], $_FILES['image_principale']['name'], $dossierCibleImg);
            if ($upload) {
                if ($modeEdition and !empty($article['image_principale']) and $article['image_principale'] !== $upload) {
                    supprimerFichierServeur($article['image_principale']);
                }
                $imagePrincipale = $upload;
            }
        }

        // B. Suppression des photos cochées dans la galerie existante
        $galerieActuelle = !empty($article['galerie_photos']) ? array_filter(array_map('trim', explode(',', $article['galerie_photos']))) : [];
        if (!empty($_POST['supprimer_photos']) and is_array($_POST['supprimer_photos'])) {
            foreach ($_POST['supprimer_photos'] as $photoASupprimer) {
                $photoASupprimer = trim($photoASupprimer);
                if (in_array($photoASupprimer, $galerieActuelle, true)) {
                    supprimerFichierServeur($photoASupprimer);
                }
            }
            $galerieActuelle = array_diff($galerieActuelle, $_POST['supprimer_photos']);
        }

        // C. Ajout des nouvelles photos de la galerie
        if (!empty($_FILES['galerie_photos']['name'][0])) {
            foreach ($_FILES['galerie_photos']['tmp_name'] as $index => $tmpName) {
                if ($_FILES['galerie_photos']['error'][$index] === UPLOAD_ERR_OK) {
                    $uploadGal = enregistrerImageOptimisee($tmpName, $_FILES['galerie_photos']['name'][$index], $dossierCibleImg);
                    if ($uploadGal) {
                        $galerieActuelle[] = $uploadGal;
                    }
                }
            }
        }
        $galerieFinale = implode(',', $galerieActuelle);

        // D. Suppression des documents PDF cochés et ajout des nouveaux PDF
        $pdfsActuels = !empty($article['documents_pdf']) ? array_filter(array_map('trim', explode(',', $article['documents_pdf']))) : [];
        if (!empty($_POST['supprimer_pdfs']) and is_array($_POST['supprimer_pdfs'])) {
            foreach ($_POST['supprimer_pdfs'] as $pdfASupprimer) {
                $pdfASupprimer = trim($pdfASupprimer);
                if (in_array($pdfASupprimer, $pdfsActuels, true)) {
                    supprimerFichierServeur($pdfASupprimer);
                }
            }
            $pdfsActuels = array_diff($pdfsActuels, $_POST['supprimer_pdfs']);
        }

        if (!empty($_FILES['documents_pdf']['name'][0])) {
            foreach ($_FILES['documents_pdf']['tmp_name'] as $index => $tmpName) {
                if ($_FILES['documents_pdf']['error'][$index] === UPLOAD_ERR_OK) {
                    $uploadPdf = enregistrerPdfArticle($tmpName, $_FILES['documents_pdf']['name'][$index], $dossierCibleDoc);
                    if ($uploadPdf) {
                        $pdfsActuels[] = $uploadPdf;
                    }
                }
            }
        }
        $pdfsFinaux = implode(',', $pdfsActuels);

        // E. Sauvegarde en base de données (UPDATE ou INSERT)
        $doitProtegerMedias = ($visibilite === 'prive' or $statut === 'en_attente');

        if ($modeEdition) {
            $stmtSave = $pdo->prepare("
                UPDATE articles 
                SET titre = :titre, date_publication = :date_pub, resume = :resume, contenu = :contenu, 
                    image_principale = :img, galerie_photos = :galerie, video_youtube = :video, 
                    documents_pdf = :pdfs, visibilite = :visibilite, statut = :statut
                WHERE id = :id
            ");
            $stmtSave->execute([
                ':titre'      => $titre,
                ':date_pub'   => $date_publication,
                ':resume'     => $resume,
                ':contenu'    => $contenu,
                ':img'        => $imagePrincipale,
                ':galerie'    => $galerieFinale,
                ':video'      => $video_youtube,
                ':pdfs'       => $pdfsFinaux,
                ':visibilite' => $visibilite,
                ':statut'     => $statut,
                ':id'         => $id
            ]);

            appliquerProtectionMediaEnregistrement($imagePrincipale, $galerieFinale, $pdfsFinaux, $doitProtegerMedias);

            if ($article['statut'] === 'en_attente' and $statut === 'publie' and !empty($article['auteur_id']) and (int)$article['auteur_id'] !== $membreId) {
                $stmtAut = $pdo->prepare("SELECT prenom, email FROM membres WHERE id = :id");
                $stmtAut->execute([':id' => (int)$article['auteur_id']]);
                $infoAuteur = $stmtAut->fetch();
                if ($infoAuteur and !empty($infoAuteur['email'])) {
                    envoyerMailDecisionArticleMembre($infoAuteur['email'], $infoAuteur['prenom'], $titre, $id, true);
                }
            }

            header("Location: article.php?id=$id");
            exit;
        } else {
            $stmtSave = $pdo->prepare("
                INSERT INTO articles (titre, date_publication, resume, contenu, image_principale, galerie_photos, video_youtube, documents_pdf, visibilite, statut, auteur_id)
                VALUES (:titre, :date_pub, :resume, :contenu, :img, :galerie, :video, :pdfs, :visibilite, :statut, :auteur)
            ");
            $stmtSave->execute([
                ':titre'      => $titre,
                ':date_pub'   => $date_publication,
                ':resume'     => $resume,
                ':contenu'    => $contenu,
                ':img'        => $imagePrincipale,
                ':galerie'    => $galerieFinale,
                ':video'      => $video_youtube,
                ':pdfs'       => $pdfsFinaux,
                ':visibilite' => $visibilite,
                ':statut'     => $statut,
                ':auteur'     => $membreId
            ]);
            $nouvelId = (int)$pdo->lastInsertId();

            appliquerProtectionMediaEnregistrement($imagePrincipale,$galerieFinale, $pdfsFinaux,$doitProtegerMedias);

            if ($statut === 'en_attente') {
                envoyerMailArticleEnAttente(
                    $nouvelId,$titre,
                    $date_publication,$resume,
                    $_SESSION['membre']['prenom'] ?? '',$_SESSION['membre']['nom'] ?? ''
                );
            }

            header("Location: article.php?id=$nouvelId");
            exit;
        }
    }
}

$galerieListe     = !empty($article['galerie_photos']) ? array_filter(array_map('trim', explode(',', $article['galerie_photos']))) : [];
$pdfsListe        = !empty($article['documents_pdf']) ? array_filter(array_map('trim', explode(',', $article['documents_pdf']))) : [];$videosExistantes = !empty($article['video_youtube']) ? array_filter(array_map('trim', preg_split('/[\r\n,]+/', $article['video_youtube']))) : [''];
$estEditProtege   = ($modeEdition and (($article['visibilite'] ?? 'public') === 'prive' or$article['statut'] === 'en_attente'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modeEdition ? "Modifier l'article" : ($estAdminOuBureau ? "Publier un article" : "Proposer un article") ?> - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/admin.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <main>
        <div class="admin-box">
            <h1>
                <?php if ($modeEdition): ?>
                    ✏️ Modifier l'article
                <?php elseif ($estAdminOuBureau): ?>
                    ➕ Publier un nouvel article
                <?php else: ?>
                    ✍️ Proposer un article au club
                <?php endif; ?>
            </h1>

            <?php if (!$estAdminOuBureau): ?>
                <div class="info-moderation">
                    ℹ️ <strong>Information :</strong> Votre article sera transmis au Bureau et à l'Administrateur pour relecture. Dès qu'il sera validé, il apparaîtra automatiquement en ligne sur le site !
                </div>
            <?php endif; ?>

            <?php if ($erreur): ?>
                <div class="alert-error"><?= htmlspecialchars($erreur) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="titre">Titre de l'article *</label>
                    <input type="text" id="titre" name="titre" required value="<?= htmlspecialchars($article['titre']) ?>" placeholder="Ex: Retour sur le KamiSMAC 2026 !">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="date_publication">Date de l'événement / publication *</label>
                        <input type="date" id="date_publication" name="date_publication" required value="<?= htmlspecialchars($article['date_publication']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="visibilite">Qui peut voir cet article ? *</label>
                        <select id="visibilite" name="visibilite">
                            <option value="public" <?= $article['visibilite'] === 'public' ? 'selected' : '' ?>>🌍 Public (Tout le monde)</option>
                            <option value="prive" <?= $article['visibilite'] === 'prive' ? 'selected' : '' ?>>🔒 Privé (Membres connectés uniquement)</option>
                        </select>
                    </div>
                </div>

                <?php if ($estAdminOuBureau): ?>
                    <div class="form-group">
                        <label for="statut">Statut de publication *</label>
                        <select id="statut" name="statut">
                            <option value="publie" <?= ($article['statut'] ?? 'publie') === 'publie' ? 'selected' : '' ?>>✅ Publié en ligne immédiatement</option>
                            <option value="en_attente" <?= ($article['statut'] ?? '') === 'en_attente' ? 'selected' : '' ?>>⏳ En attente de validation (Non visible du public)</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="resume">Résumé court (affiché sur la carte d'accueil) *</label>
                    <textarea id="resume" name="resume" rows="2" required placeholder="Une ou deux phrases d'accroche..."><?= htmlspecialchars($article['resume']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="contenu">Contenu complet de l'article *</label>
                    <textarea id="contenu" name="contenu" rows="8" required placeholder="Racontez l'événement en détail..."><?= htmlspecialchars($article['contenu']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="image_principale">Photo de couverture (Vignette de l'article - Optionnel)</label>
                    <?php if (!empty($article['image_principale'])): ?>
                        <?php $urlPreviewMain = obtenirUrlMedia($article['image_principale'], $estEditProtege, 'article',$id); ?>
                        <div class="existing-photos-grid" style="margin-bottom: 10px;">
                            <label class="existing-photo-item">
                                <img src="<?= htmlspecialchars($urlPreviewMain) ?>" alt="Couverture actuelle">
                                <input type="checkbox" name="supprimer_image_principale" value="1"> Suppr.
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" id="image_principale" name="image_principale" accept="image/jpeg,image/png,image/webp">
                    <span class="form-help">Les photos en portrait sont automatiquement redressées et rangées dans <code>IMG/Année/articles/NomArticle/</code>.</span>
                </div>

                <div class="form-group">
                    <label for="galerie_photos">Galerie photos de l'événement (Optionnel - Plusieurs photos possibles)</label>
                    <input type="file" id="galerie_photos" name="galerie_photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                    <span class="form-help">Astuce : Maintenez la touche Ctrl (ou sélectionnez plusieurs photos sur mobile) pour en ajouter plusieurs d'un coup.</span>

                    <?php if (!empty($galerieListe)): ?>
                        <p class="form-help" style="margin-top:10px; font-weight:bold;">Photos déjà présentes dans la galerie (cochez pour supprimer) :</p>
                        <div class="existing-photos-grid">
                            <?php foreach ($galerieListe as$photo): ?>
                                <?php $urlPreviewGal = obtenirUrlMedia($photo, $estEditProtege, 'article',$id); ?>
                                <label class="existing-photo-item">
                                    <img src="<?= htmlspecialchars($urlPreviewGal) ?>" alt="Photo galerie">
                                    <input type="checkbox" name="supprimer_photos[]" value="<?= htmlspecialchars($photo) ?>"> Suppr.
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Gestion des documents PDF rattachés à l'article -->
                <div class="form-group">
                    <label for="documents_pdf">📄 Document(s) PDF joint(s) (Optionnel - Plusieurs PDF possibles)</label>
                    <input type="file" id="documents_pdf" name="documents_pdf[]" accept="application/pdf,.pdf" multiple>
                    <span class="form-help">Les fichiers PDF sont rangés automatiquement dans <code>DOCS/Année/articles/NomArticle/</code> et affichés directement dans l'article.</span>

                    <?php if (!empty($pdfsListe)): ?>
                        <p class="form-help" style="margin-top:10px; font-weight:bold;">Documents PDF déjà joints à l'article (cochez pour supprimer) :</p>
                        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                            <?php foreach ($pdfsListe as$cheminPdf): ?>
                                <?php $urlPreviewPdf = obtenirUrlMedia($cheminPdf, $estEditProtege, 'article',$id); ?>
                                <label style="display:flex; align-items:center; justify-content:space-between; background:var(--ka-gris-back); padding:8px 12px; border:1px solid var(--ka-gris-clair); border-radius:5px; font-weight:normal; cursor:pointer;">
                                    <span>📄 <a href="<?= htmlspecialchars($urlPreviewPdf) ?>" target="_blank" style="color:var(--ka-bleu); font-weight:bold;"><?= htmlspecialchars(basename($cheminPdf)) ?></a></span>
                                    <span style="color:var(--ka-rouge); font-size:0.85rem; font-weight:bold;">
                                        <input type="checkbox" name="supprimer_pdfs[]" value="<?= htmlspecialchars($cheminPdf) ?>"> Supprimer
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Gestion multi-vidéos YouTube (pilotée par JS/main.js) -->
                <div class="form-group">
                    <label>Vidéo(s) YouTube (Optionnel - Plusieurs vidéos possibles)</label>
                    <div id="youtube-inputs-container">
                        <?php foreach ($videosExistantes as $index =>$videoUrl): ?>
                            <div class="youtube-input-row" style="display:flex; gap:10px; margin-bottom:10px;">
                                <input type="url" name="videos_youtube[]" value="<?= htmlspecialchars($videoUrl) ?>" placeholder="Ex: https://youtu.be/...">
                                <?php if ($index > 0): ?>
                                    <button type="button" class="btn-small btn-small-danger" onclick="this.parentElement.remove()">✖</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-youtube-btn" class="btn-small" style="margin-top:5px;">➕ Ajouter une autre vidéo YouTube</button>
                    <span class="form-help">Collez un ou plusieurs liens YouTube (même Non répertoriés), ils s'afficheront tous dans l'article.</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary btn-submit">
                        <?php if ($modeEdition): ?>
                            💾 Enregistrer les modifications
                        <?php elseif ($estAdminOuBureau): ?>
                            🚀 Publier l'article
                        <?php else: ?>
                            📨 Envoyer pour validation
                        <?php endif; ?>
                    </button>
                    <a href="<?= $modeEdition ? 'article.php?id=' . (int)$id : 'actualites.php' ?>" class="back-link">Annuler</a>
                </div>
            </form>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
