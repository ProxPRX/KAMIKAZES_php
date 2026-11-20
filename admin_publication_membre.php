<?php
// ==========================================================================
// AJOUT / MODIFICATION D'UNE PUBLICATION MEMBRE (VACANCES OU RÉALISATIONS) - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// Sécurité : accès autorisé aux Membres, Membres associés, Bureau et Admin
$estConnecte      = isset($_SESSION['membre']);
$role             = $_SESSION['membre']['role'] ?? null;
$membreId         = (int)($_SESSION['membre']['id'] ?? 0);
$estAdminOuBureau = in_array($role, ['admin', 'bureau']);

if (!$estConnecte) {
    header('Location: connexion.php');
    exit;
}

if (!in_array($role, ['membre', 'membre_associe', 'bureau', 'admin'])) {
    header('Location: membres.php');
    exit;
}

// --- 1. DÉTECTION DU MODE (AJOUT OU MODIFICATION) ---
$modeEdition = false;
$pubActuelle = null;

if (isset($_GET['modifier'])) {
    $idModif = (int)$_GET['modifier'];
    $stmt    = $pdo->prepare("SELECT * FROM membres_publications WHERE id = :id");
    $stmt->execute([':id' => $idModif]);
    $pubActuelle = $stmt->fetch();

    if (!$pubActuelle) {
        header('Location: membres.php');
        exit;
    }

    $estAuteurEnAttente = ((int)$pubActuelle['auteur_id'] === $membreId and $pubActuelle['statut'] === 'en_attente');
    if (!$estAdminOuBureau and !$estAuteurEnAttente) {
        header('Location: membres.php');
        exit;
    }

    $modeEdition = true;
}

$categorieDefaut = (($_GET['cat'] ?? 'vacances') === 'realisations') ? 'realisations' : 'vacances';
if ($modeEdition) {
    $categorieDefaut = $pubActuelle['categorie'];
}

$erreur = '';

// --- 2. TRAITEMENT DU FORMULAIRE POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categorie       = (($_POST['categorie'] ?? 'vacances') === 'realisations') ? 'realisations' : 'vacances';
    $titre           = trim($_POST['titre'] ?? '');
    $datePublication = $_POST['date_publication'] ?? date('Y-m-d');
    $visibilite      = (($_POST['visibilite'] ?? 'public') === 'prive') ? 'prive' : 'public';
    $resume          = trim($_POST['resume'] ?? '');
    $contenu         = trim($_POST['contenu'] ?? '');

    $videosSaisies = $_POST['videos_youtube'] ?? [];
    $videosPropres = [];
    if (is_array($videosSaisies)) {
        foreach ($videosSaisies as $vUrl) {
            $vUrl = trim($vUrl);
            if (!empty($vUrl)) {
                $videosPropres[] = $vUrl;
            }
        }
    }
    $videoYoutubeFinal = !empty($videosPropres) ? implode("\n", $videosPropres) : null;

    if (empty($titre) or empty($resume) or empty($contenu)) {
        $erreur = "Veuillez remplir tous les champs obligatoires (Titre, Résumé court et Description complète).";
    } else {
        $anneeDossier = date('Y', strtotime($datePublication));
        $slugTitre    = creerSlugDossier($titre);
        $dossierImg   = 'IMG/' . $anneeDossier . '/membres/' . $slugTitre;
        $dossierDocs  = 'DOCS/' . $anneeDossier . '/membres/' . $slugTitre;

        // A. Gestion de l'image principale (vignette)
        $imagePrincipaleFinale = $modeEdition ? $pubActuelle['image_principale'] : null;

        if ($modeEdition and !empty($_POST['supprimer_image_principale'])) {
            if (!empty($imagePrincipaleFinale)) {
                supprimerFichierServeur($imagePrincipaleFinale);
            }
            $imagePrincipaleFinale = null;
        }

        if (!empty($_FILES['image_principale']['name']) and $_FILES['image_principale']['error'] === UPLOAD_ERR_OK) {
            $uploadMain = enregistrerImageOptimisee(
                $_FILES['image_principale']['tmp_name'],
                $_FILES['image_principale']['name'],
                $dossierImg
            );
            if ($uploadMain) {
                if ($modeEdition and !empty($pubActuelle['image_principale']) and $pubActuelle['image_principale'] !== $uploadMain) {
                    supprimerFichierServeur($pubActuelle['image_principale']);
                }
                $imagePrincipaleFinale = $uploadMain;
            }
        }

        // B. Gestion de la galerie photos
        $galerieFinale = [];
        if ($modeEdition and !empty($pubActuelle['galerie_photos'])) {
            $galerieActuelle = array_filter(array_map('trim', explode("\n", $pubActuelle['galerie_photos'])));
            $photosASuppr    = $_POST['supprimer_galerie'] ?? [];

            foreach ($galerieActuelle as $cheminPhoto) {
                if (in_array($cheminPhoto, $photosASuppr, true)) {
                    supprimerFichierServeur($cheminPhoto);
                } else {
                    $galerieFinale[] = $cheminPhoto;
                }
            }
        }

        if (!empty($_FILES['galerie_photos']['name'][0])) {
            $nbFichiers = count($_FILES['galerie_photos']['name']);
            for ($i = 0; $i < $nbFichiers; $i++) {
                if ($_FILES['galerie_photos']['error'][$i] === UPLOAD_ERR_OK) {
                    $uploadGal = enregistrerImageOptimisee(
                        $_FILES['galerie_photos']['tmp_name'][$i],
                        $_FILES['galerie_photos']['name'][$i],
                        $dossierImg
                    );
                    if ($uploadGal) {
                        $galerieFinale[] = $uploadGal;
                    }
                }
            }
        }

        if (empty($imagePrincipaleFinale) and !empty($galerieFinale)) {
            $imagePrincipaleFinale = $galerieFinale[0];
        }

        $galerieTexte = !empty($galerieFinale) ? implode("\n", $galerieFinale) : null;

        // C. Gestion des documents PDF joints
        $pdfsFinaux = [];
        if ($modeEdition and !empty($pubActuelle['documents_pdf'])) {
            $pdfsActuels = array_filter(array_map('trim', explode("\n", $pubActuelle['documents_pdf'])));
            $pdfsASuppr  = $_POST['supprimer_pdfs'] ?? [];

            foreach ($pdfsActuels as $cheminPdf) {
                if (in_array($cheminPdf, $pdfsASuppr, true)) {
                    supprimerFichierServeur($cheminPdf);
                } else {
                    $pdfsFinaux[] = $cheminPdf;
                }
            }
        }

        if (!empty($_FILES['documents_pdf']['name'][0])) {
            $nbPdfs = count($_FILES['documents_pdf']['name']);
            for ($i = 0; $i < $nbPdfs; $i++) {
                if ($_FILES['documents_pdf']['error'][$i] === UPLOAD_ERR_OK) {
                    $uploadPdf = enregistrerPdfArticle(
                        $_FILES['documents_pdf']['tmp_name'][$i],
                        $_FILES['documents_pdf']['name'][$i],
                        $dossierDocs
                    );
                    if ($uploadPdf) {
                        $pdfsFinaux[] = $uploadPdf;
                    }
                }
            }
        }

        $pdfsTexte = !empty($pdfsFinaux) ? implode("\n", $pdfsFinaux) : null;

        try {
            if ($modeEdition) {
                if ($estAdminOuBureau) {
                    $nouveauStatut = !empty($_POST['valider_directement']) ? 'publie' : $pubActuelle['statut'];
                } else {
                    $nouveauStatut = 'en_attente';
                }

                $stmtUpdate = $pdo->prepare("
                    UPDATE membres_publications 
                    SET categorie = :cat,
                        titre = :titre,
                        date_publication = :date_pub,
                        resume = :resume,
                        contenu = :contenu,
                        image_principale = :img,
                        galerie_photos = :galerie,
                        video_youtube = :yt,
                        documents_pdf = :pdfs,
                        visibilite = :vis,
                        statut = :statut
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':cat'      => $categorie,
                    ':titre'    => $titre,
                    ':date_pub' => $datePublication,
                    ':resume'   => $resume,
                    ':contenu'  => $contenu,
                    ':img'      => $imagePrincipaleFinale,
                    ':galerie'  => $galerieTexte,
                    ':yt'       => $videoYoutubeFinal,
                    ':pdfs'     => $pdfsTexte,
                    ':vis'      => $visibilite,
                    ':statut'   => $nouveauStatut,
                    ':id'       => $pubActuelle['id']
                ]);

                appliquerProtectionMediaEnregistrement(
                    $imagePrincipaleFinale,
                    $galerieTexte,
                    $pdfsTexte,
                    ($visibilite === 'prive' or $nouveauStatut === 'en_attente')
                );

                if ($pubActuelle['statut'] === 'en_attente' and $nouveauStatut === 'publie' and !empty($pubActuelle['auteur_id']) and (int)$pubActuelle['auteur_id'] !== $membreId) {
                    $stmtAut = $pdo->prepare("SELECT prenom, email FROM membres WHERE id = :id");
                    $stmtAut->execute([':id' => (int)$pubActuelle['auteur_id']]);
                    $infoAuteur = $stmtAut->fetch();
                    if ($infoAuteur and !empty($infoAuteur['email'])) {
                        envoyerMailDecisionPublicationMembre(
                            $infoAuteur['email'],
                            $infoAuteur['prenom'],
                            $titre,
                            $categorie,
                            $pubActuelle['id'],
                            true
                        );
                    }
                }

                header("Location: membres.php?pub=" . (int)$pubActuelle['id']);
                exit;
            } else {
                $statutInitial = $estAdminOuBureau ? 'publie' : 'en_attente';

                $stmtInsert = $pdo->prepare("
                    INSERT INTO membres_publications (
                        categorie, titre, date_publication, resume, contenu,
                        image_principale, galerie_photos, video_youtube, documents_pdf,
                        visibilite, statut, auteur_id
                    ) VALUES (
                        :cat, :titre, :date_pub, :resume, :contenu,
                        :img, :galerie, :yt, :pdfs,
                        :vis, :statut, :auteur
                    )
                ");
                $stmtInsert->execute([
                    ':cat'      => $categorie,
                    ':titre'    => $titre,
                    ':date_pub' => $datePublication,
                    ':resume'   => $resume,
                    ':contenu'  => $contenu,
                    ':img'      => $imagePrincipaleFinale,
                    ':galerie'  => $galerieTexte,
                    ':yt'       => $videoYoutubeFinal,
                    ':pdfs'     => $pdfsTexte,
                    ':vis'      => $visibilite,
                    ':statut'   => $statutInitial,
                    ':auteur'   => $membreId
                ]);

                $nouvelId = (int)$pdo->lastInsertId();

                appliquerProtectionMediaEnregistrement(
                    $imagePrincipaleFinale,
                    $galerieTexte,
                    $pdfsTexte,
                    ($visibilite === 'prive' or $statutInitial === 'en_attente')
                );

                if ($statutInitial === 'en_attente') {
                    envoyerMailPublicationMembreEnAttente(
                        $nouvelId,
                        $categorie,
                        $titre,
                        $datePublication,
                        $resume,
                        $_SESSION['membre']['prenom'] ?? '',
                        $_SESSION['membre']['nom'] ?? ''
                    );
                }

                header("Location: membres.php?pub=" . $nouvelId);
                exit;
            }
        } catch (PDOException $e) {
            $erreur = "Erreur technique lors de l'enregistrement de la publication.";
        }
    }
}

$videosExistantes = ($modeEdition and !empty($pubActuelle['video_youtube'])) ? array_filter(array_map('trim', explode("\n", $pubActuelle['video_youtube']))) : [''];
$galerieExistante = ($modeEdition and !empty($pubActuelle['galerie_photos'])) ? array_filter(array_map('trim', explode("\n", $pubActuelle['galerie_photos']))) : [];
$pdfsExistants    = ($modeEdition and !empty($pubActuelle['documents_pdf'])) ? array_filter(array_map('trim', explode("\n", $pubActuelle['documents_pdf']))) : [];
$estEditProtegee  = ($modeEdition and (($pubActuelle['visibilite'] ?? 'public') === 'prive' or $pubActuelle['statut'] === 'en_attente'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modeEdition ? 'Modifier la publication' : ($estAdminOuBureau ? 'Publier dans Espace Membres' : 'Proposer une publication') ?> - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/admin.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <main>
        <div class="admin-box">
            <?php if ($modeEdition): ?>
                <h1>✏️ Modifier la publication (Espace Membres)</h1>
            <?php elseif ($estAdminOuBureau): ?>
                <h1>➕ Publier dans Photos de vacances ou Réalisations</h1>
            <?php else: ?>
                <h1>✍️ Proposer une publication (Photos de vacances / Réalisations)</h1>
                <div class="info-moderation">
                    ℹ️ <strong>Information :</strong> Votre publication sera transmise au Président et aux membres du Bureau pour relecture avant sa mise en ligne dans l'Espace Membres.
                </div>
            <?php endif; ?>

            <?php if ($erreur): ?>
                <div class="alert-error"><?= htmlspecialchars($erreur) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label for="categorie">Rubrique de destination *</label>
                        <select id="categorie" name="categorie" required>
                            <option value="vacances" <?= $categorieDefaut === 'vacances' ? 'selected' : '' ?>>📸 Photos de vacances &amp; Voyages</option>
                            <option value="realisations" <?= $categorieDefaut === 'realisations' ? 'selected' : '' ?>>🛠️ Les réalisations des membres (Atelier)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date_publication">Date *</label>
                        <input type="date" id="date_publication" name="date_publication" required value="<?= htmlspecialchars($modeEdition ? $pubActuelle['date_publication'] : date('Y-m-d')) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="titre">Titre de la publication *</label>
                        <input type="text" id="titre" name="titre" required placeholder="Ex: Vol de pente en vacances ou Construction de mon planeur" value="<?= htmlspecialchars($modeEdition ? $pubActuelle['titre'] : '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="visibilite">Visibilité *</label>
                        <select id="visibilite" name="visibilite">
                            <option value="public" <?= ($modeEdition and $pubActuelle['visibilite'] === 'public') ? 'selected' : '' ?>>🌍 Public (Visible par tous les visiteurs)</option>
                            <option value="prive" <?= ($modeEdition and $pubActuelle['visibilite'] === 'prive') ? 'selected' : '' ?>>🔒 Privé (Réservé aux membres connectés)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="resume">Résumé court (affiché sur la carte de présentation) *</label>
                    <textarea id="resume" name="resume" rows="2" required placeholder="Une ou deux phrases d'accroche affichées sur la vignette..."><?= htmlspecialchars($modeEdition ? $pubActuelle['resume'] : '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="contenu">Contenu complet de l'article *</label>
                    <textarea id="contenu" name="contenu" rows="6" required placeholder="Racontez votre voyage, le terrain visité ou les détails techniques de votre réalisation..."><?= htmlspecialchars($modeEdition ? $pubActuelle['contenu'] : '') ?></textarea>
                </div>

                <!-- IMAGE PRINCIPALE DE COUVERTURE -->
                <div class="form-group">
                    <label for="image_principale">Photo de couverture (vignette)</label>
                    <?php if ($modeEdition and !empty($pubActuelle['image_principale'])): ?>
                        <?php $urlPreviewMain = obtenirUrlMedia($pubActuelle['image_principale'], $estEditProtegee, 'membre_pub', $pubActuelle['id']); ?>
                        <div class="existing-photos-grid" style="margin-bottom: 10px;">
                            <label class="existing-photo-item">
                                <img src="<?= htmlspecialchars($urlPreviewMain) ?>" alt="Couverture actuelle">
                                <input type="checkbox" name="supprimer_image_principale" value="1"> Suppr.
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" id="image_principale" name="image_principale" accept="image/jpeg,image/png,image/webp">
                    <span class="form-help">Optionnel : si vous ne choisissez pas de photo de couverture, la 1ère photo de la galerie servira automatiquement de vignette.</span>
                </div>

                <!-- GALERIE PHOTOS SUPPLÉMENTAIRES -->
                <div class="form-group">
                    <label for="galerie_photos">Galerie de photos (sélection multiple possible)</label>
                    <input type="file" id="galerie_photos" name="galerie_photos[]" accept="image/jpeg,image/png,image/webp" multiple>

                    <?php if ($modeEdition and !empty($galerieExistante)): ?>
                        <p class="form-help" style="margin-top:10px; font-weight:bold;">Photos déjà présentes dans la galerie (cochez pour supprimer) :</p>
                        <div class="existing-photos-grid">
                            <?php foreach ($galerieExistante as $photoPath): ?>
                                <?php $urlPreviewGal = obtenirUrlMedia($photoPath, $estEditProtegee, 'membre_pub', $pubActuelle['id']); ?>
                                <label class="existing-photo-item">
                                    <img src="<?= htmlspecialchars($urlPreviewGal) ?>" alt="Photo">
                                    <input type="checkbox" name="supprimer_galerie[]" value="<?= htmlspecialchars($photoPath) ?>"> Suppr.
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- DOCUMENTS PDF OPTIONNELS -->
                <div class="form-group">
                    <label for="documents_pdf">📄 Document(s) PDF joint(s) (Plan, fiche technique... - Optionnel)</label>
                    <input type="file" id="documents_pdf" name="documents_pdf[]" accept="application/pdf,.pdf" multiple>

                    <?php if ($modeEdition and !empty($pdfsExistants)): ?>
                        <p class="form-help" style="margin-top:10px; font-weight:bold;">Documents PDF déjà joints (cochez pour supprimer) :</p>
                        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                            <?php foreach ($pdfsExistants as $pdfPath): ?>
                                <?php $urlPreviewPdf = obtenirUrlMedia($pdfPath, $estEditProtegee, 'membre_pub', $pubActuelle['id']); ?>
                                <label style="display:flex; align-items:center; justify-content:space-between; background:var(--ka-gris-back); padding:8px 12px; border:1px solid var(--ka-gris-clair); border-radius:5px; font-weight:normal; cursor:pointer;">
                                    <span>📄 <a href="<?= htmlspecialchars($urlPreviewPdf) ?>" target="_blank" style="color:var(--ka-bleu); font-weight:bold;"><?= htmlspecialchars(basename($pdfPath)) ?></a></span>
                                    <span style="color:var(--ka-rouge); font-size:0.85rem; font-weight:bold;">
                                        <input type="checkbox" name="supprimer_pdfs[]" value="<?= htmlspecialchars($pdfPath) ?>"> Supprimer
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- VIDÉOS YOUTUBE OPTIONNELLES -->
                <div class="form-group">
                    <label>Vidéo(s) YouTube (Optionnel)</label>
                    <div id="youtube-inputs-container">
                        <?php foreach ($videosExistantes as $index => $ytUrl): ?>
                            <div class="youtube-input-row" style="display:flex; gap:10px; margin-bottom:10px;">
                                <input type="url" name="videos_youtube[]" placeholder="Ex: https://youtu.be/..." value="<?= htmlspecialchars($ytUrl) ?>">
                                <?php if ($index > 0): ?>
                                    <button type="button" class="btn-small btn-small-danger" onclick="this.parentElement.remove()">✖</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-youtube-btn" class="btn-small" style="margin-top:5px;">
                        ➕ Ajouter une autre vidéo YouTube
                    </button>
                </div>

                <?php if ($modeEdition and $estAdminOuBureau and $pubActuelle['statut'] === 'en_attente'): ?>
                    <div class="form-group" style="background:var(--ka-blanc); padding:12px 15px; border-radius:6px; border:1px solid var(--ka-orange); border-left:4px solid var(--ka-orange);">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0; color:var(--ka-orange);">
                            <input type="checkbox" name="valider_directement" value="1" checked style="width:auto;">
                            <strong>✅ Valider et mettre en ligne cette publication dès l'enregistrement</strong>
                        </label>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn-primary btn-submit">
                        <?= $modeEdition ? '💾 Enregistrer les modifications' : ($estAdminOuBureau ? '🚀 Publier maintenant' : '📨 Envoyer pour validation') ?>
                    </button>
                    <a href="<?= $modeEdition ? 'membres.php?pub=' . (int)$pubActuelle['id'] : 'membres.php' ?>" class="back-link">Annuler</a>
                </div>
            </form>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
