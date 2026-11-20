<?php
// ==========================================================================
// PAGE NOS ACTIVITÉS (AVEC GESTION ADMIN SANS CODE : TEXTES, BLOCS & PHOTOS)
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte = isset($_SESSION['membre']);
$role        = $_SESSION['membre']['role'] ?? null;
$estAdmin    = ($role === 'admin');

// Dossier dédié aux photos d'illustration d'Activités (hors IMG/{YYYY} -> n'apparaît jamais dans le Pêle-mêle)
$dossierDecorActivites = 'IMG/activites';

$contenuSite = chargerContenuSite();
$actData     = $contenuSite['activites'];

$messageSucces = '';
$messageErreur = '';

// --- TRAITEMENT DES ACTIONS ADMIN (DISCIPLINES, ÉVÉNEMENTS & PHOTOS DE DÉCOR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' and $estAdmin and isset($_POST['action_cms_activites'])) {
    $action = $_POST['action_cms_activites'];

    // 1. Modifier la phrase d'introduction de "Ce que nous faisons"
    if ($action === 'modifier_intro_pratiques') {
        $actData['pratiques_intro'] = trim($_POST['pratiques_intro'] ?? '');
        $contenuSite['activites']   = $actData;
        sauvegarderContenuSite($contenuSite);
        $messageSucces = "✅ Le texte d'introduction des activités a été mis à jour.";
    }

    // 2. Ajouter ou Modifier une carte de discipline (Planeurs, Avions, Hélicoptères, Multirotors...)
    elseif ($action === 'sauvegarder_pratique') {
        $idPrat = trim($_POST['pratique_id'] ?? '');
        $icone  = trim($_POST['icone'] ?? '✈️');
        $titre  = trim($_POST['titre'] ?? '');
        $desc   = trim($_POST['description'] ?? '');

        if ($titre === '' or $desc === '') {
            $messageErreur = "Veuillez remplir le titre et la description de l'activité.";
        } else {
            $nouvellePratique = [
                'id'          => $idPrat !== '' ? $idPrat : uniqid('prat_'),
                'icone'       => $icone !== '' ? $icone : '✈️',
                'titre'       => $titre,
                'description' => $desc
            ];

            $indexTrouve = null;
            foreach ($actData['pratiques'] as $idx => $p) {
                if (($p['id'] ?? '') === $idPrat) {
                    $indexTrouve = $idx;
                    break;
                }
            }

            if ($indexTrouve !== null) {
                $actData['pratiques'][$indexTrouve] = $nouvellePratique;
                $messageSucces = "✅ L'activité « $titre » a bien été modifiée.";
            } else {
                $actData['pratiques'][] = $nouvellePratique;
                $messageSucces = "✅ La nouvelle activité « $titre » a bien été ajoutée.";
            }

            $contenuSite['activites'] = $actData;
            sauvegarderContenuSite($contenuSite);
        }
    }

    // 3. Supprimer une carte de discipline
    elseif ($action === 'supprimer_pratique') {
        $idPrat = trim($_POST['pratique_id'] ?? '');
        if (count($actData['pratiques']) <= 1) {
            $messageErreur = "Il doit rester au minimum une carte d'activité.";
        } else {
            foreach ($actData['pratiques'] as $idx => $p) {
                if (($p['id'] ?? '') === $idPrat) {
                    unset($actData['pratiques'][$idx]);
                    $actData['pratiques']     = array_values($actData['pratiques']);
                    $contenuSite['activites'] = $actData;
                    sauvegarderContenuSite($contenuSite);
                    $messageSucces = "🗑️ La carte d'activité a été supprimée.";
                    break;
                }
            }
        }
    }

    // 4. Modifier la phrase d'introduction de "Événements & Convivialité"
    elseif ($action === 'modifier_intro_evenements') {
        $actData['evenements_intro'] = trim($_POST['evenements_intro'] ?? '');
        $contenuSite['activites']    = $actData;
        sauvegarderContenuSite($contenuSite);
        $messageSucces = "✅ Le texte d'introduction des événements a été mis à jour.";
    }

    // 5. Changer rapidement la photo d'un événement en 1 clic
    elseif ($action === 'upload_photo_evenement') {
        $idEvt = trim($_POST['evenement_id'] ?? '');
        foreach ($actData['evenements'] as $idx => $ev) {
            if (($ev['id'] ?? '') === $idEvt) {
                if (!empty($_FILES['photo_decor']['name']) and $_FILES['photo_decor']['error'] === UPLOAD_ERR_OK) {
                    $tempUpload = enregistrerImageOptimisee(
                        $_FILES['photo_decor']['tmp_name'],
                        $_FILES['photo_decor']['name'],
                        $dossierDecorActivites
                    );
                    if ($tempUpload) {
                        $cheminFixe = $dossierDecorActivites . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $idEvt) . '.jpg';
                        if (is_file($cheminFixe)) {
                            @unlink($cheminFixe);
                        }
                        rename($tempUpload, $cheminFixe);
                        $actData['evenements'][$idx]['image'] = $cheminFixe;
                        $contenuSite['activites']             = $actData;
                        sauvegarderContenuSite($contenuSite);
                        $messageSucces = "✅ La photo de « " . $ev['titre'] . " » a bien été mise à jour.";
                    } else {
                        $messageErreur = "Format d'image non reconnu (utilisez JPG, PNG ou WEBP).";
                    }
                }
                break;
            }
        }
    }

    // 6. Supprimer la photo d'un événement (rétablit le rectangle gris par défaut)
    elseif ($action === 'supprimer_photo_evenement') {
        $idEvt = trim($_POST['evenement_id'] ?? '');
        foreach ($actData['evenements'] as $idx => $ev) {
            if (($ev['id'] ?? '') === $idEvt) {
                $cheminFixe = $dossierDecorActivites . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $idEvt) . '.jpg';
                if (!empty($ev['image']) and is_file($ev['image'])) {
                    @unlink($ev['image']);
                }
                if (is_file($cheminFixe)) {
                    @unlink($cheminFixe);
                }
                $actData['evenements'][$idx]['image'] = '';
                $contenuSite['activites']             = $actData;
                sauvegarderContenuSite($contenuSite);
                $messageSucces = "🗑️ La photo a été retirée (le fond gris « " . ($ev['placeholder'] ?? 'Photo') . " » est rétabli).";
                break;
            }
        }
    }

    // 7. Ajouter ou Modifier le texte complet d'un événement annuel
    elseif ($action === 'sauvegarder_evenement') {
        $idEvt       = trim($_POST['evenement_id'] ?? '');
        $idPropre    = $idEvt !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $idEvt) : uniqid('evt_');
        $periode     = trim($_POST['periode'] ?? '');
        $titre       = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $placeholder = trim($_POST['placeholder'] ?? 'Photo ' . $titre);

        if ($periode === '' or $titre === '' or $description === '') {
            $messageErreur = "Veuillez remplir la période, le titre et la description de l'événement.";
        } else {
            $indexTrouve = null;
            foreach ($actData['evenements'] as $idx => $ev) {
                if (($ev['id'] ?? '') === $idEvt) {
                    $indexTrouve = $idx;
                    break;
                }
            }

            $imageActuelle = ($indexTrouve !== null) ? ($actData['evenements'][$indexTrouve]['image'] ?? '') : '';

            if (!empty($_FILES['photo_decor']['name']) and $_FILES['photo_decor']['error'] === UPLOAD_ERR_OK) {
                $tempUpload = enregistrerImageOptimisee(
                    $_FILES['photo_decor']['tmp_name'],
                    $_FILES['photo_decor']['name'],
                    $dossierDecorActivites
                );
                if ($tempUpload) {
                    $cheminFixe = $dossierDecorActivites . '/' . $idPropre . '.jpg';
                    if (is_file($cheminFixe)) {
                        @unlink($cheminFixe);
                    }
                    rename($tempUpload, $cheminFixe);
                    $imageActuelle = $cheminFixe;
                }
            }

            $nouvelEvt = [
                'id'          => $idPropre,
                'periode'     => $periode,
                'titre'       => $titre,
                'description' => $description,
                'placeholder' => $placeholder !== '' ? $placeholder : 'Photo Kamikazes Agathois',
                'image'       => $imageActuelle
            ];

            if ($indexTrouve !== null) {
                $actData['evenements'][$indexTrouve] = $nouvelEvt;
                $messageSucces = "✅ L'événement « $titre » a bien été mis à jour.";
            } else {
                $actData['evenements'][] = $nouvelEvt;
                $messageSucces = "✅ Le nouvel événement « $titre » a bien été ajouté.";
            }

            $contenuSite['activites'] = $actData;
            sauvegarderContenuSite($contenuSite);
        }
    }

    // 8. Supprimer un bloc événement complet
    elseif ($action === 'supprimer_evenement') {
        $idEvt = trim($_POST['evenement_id'] ?? '');
        if (count($actData['evenements']) <= 1) {
            $messageErreur = "Il doit rester au minimum un événement dans cette section.";
        } else {
            foreach ($actData['evenements'] as $idx => $ev) {
                if (($ev['id'] ?? '') === $idEvt) {
                    if (!empty($ev['image']) and is_file($ev['image'])) {
                        @unlink($ev['image']);
                    }
                    $cheminFixe = $dossierDecorActivites . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $idEvt) . '.jpg';
                    if (is_file($cheminFixe)) {
                        @unlink($cheminFixe);
                    }
                    unset($actData['evenements'][$idx]);
                    $actData['evenements']    = array_values($actData['evenements']);
                    $contenuSite['activites'] = $actData;
                    sauvegarderContenuSite($contenuSite);
                    $messageSucces = "🗑️ L'événement a bien été supprimé.";
                    break;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Découvrez les activités des Kamikazes Agathois : planeurs, avions, hélicoptères, multirotors, vol indoor et nos événements.">
    <title>Activités - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=7">
    <link rel="stylesheet" href="CSS/activites.css?v=7">
</head>
<body>

    <?php include 'header.php'; ?>

    <!-- BANNIÈRE ACTIVITÉS -->
    <section class="hero activites-hero">
        <div class="hero-content">
            <h2>Nos Activités</h2>
            <p>Planeurs, avions, hélicoptères, multirotors, vol indoor et convivialité.</p>
        </div>
    </section>

    <main>
        <?php if ($messageSucces): ?>
            <div class="cms-feedback cms-feedback-success"><?= htmlspecialchars($messageSucces) ?></div>
        <?php endif; ?>

        <?php if ($messageErreur): ?>
            <div class="cms-feedback cms-feedback-error"><?= htmlspecialchars($messageErreur) ?></div>
        <?php endif; ?>

        <!-- ==================================================================
             SECTION 1 : LES GRANDES PRATIQUES DU CLUB
             ================================================================== -->
        <section class="practices-section" id="pratiques">
            <h2>Ce que nous faisons</h2>
            <p style="margin-bottom: 30px; font-size: 1.1rem; color: #555;">
                <?= htmlspecialchars($actData['pratiques_intro']) ?>
            </p>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details" style="margin-bottom: 25px;">
                    <summary class="cms-admin-summary">✏️ Modifier la phrase d'introduction (Admin)</summary>
                    <form method="POST" action="activites.php#pratiques" class="cms-admin-form">
                        <input type="hidden" name="action_cms_activites" value="modifier_intro_pratiques">
                        <div class="cms-field">
                            <label>Phrase d'introduction</label>
                            <input type="text" name="pratiques_intro" value="<?= htmlspecialchars($actData['pratiques_intro']) ?>" required>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">💾 Enregistrer</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>

            <div class="practices-grid">
                <?php foreach ($actData['pratiques'] as $pratique): ?>
                    <div class="practice-card">
                        <div class="practice-icon"><?= htmlspecialchars($pratique['icone']) ?></div>
                        <h3><?= htmlspecialchars($pratique['titre']) ?></h3>
                        <p><?= nl2br(htmlspecialchars($pratique['description'])) ?></p>

                        <?php if ($estAdmin): ?>
                            <details class="cms-admin-details" style="margin-top: 15px; margin-bottom: 0;">
                                <summary class="cms-admin-summary" style="font-size: 0.82rem; padding: 7px 12px;">✏️ Modifier cette carte</summary>
                                <form method="POST" action="activites.php#pratiques" class="cms-admin-form" style="padding: 12px;">
                                    <input type="hidden" name="action_cms_activites" value="sauvegarder_pratique">
                                    <input type="hidden" name="pratique_id" value="<?= htmlspecialchars($pratique['id']) ?>">
                                    <div class="cms-field">
                                        <label>Icône (Emoji)</label>
                                        <input type="text" name="icone" value="<?= htmlspecialchars($pratique['icone']) ?>" required>
                                    </div>
                                    <div class="cms-field">
                                        <label>Titre *</label>
                                        <input type="text" name="titre" value="<?= htmlspecialchars($pratique['titre']) ?>" required>
                                    </div>
                                    <div class="cms-field">
                                        <label>Description *</label>
                                        <textarea name="description" rows="4" required><?= htmlspecialchars($pratique['description']) ?></textarea>
                                    </div>
                                    <div class="cms-actions">
                                        <button type="submit" class="btn-cms-save" style="width:100%;">💾 Enregistrer</button>
                                    </div>
                                </form>
                                <?php if (count($actData['pratiques']) > 1): ?>
                                    <form method="POST" action="activites.php#pratiques" style="padding: 0 12px 12px 12px;" onsubmit="return confirm('Supprimer cette carte d\'activité ?');">
                                        <input type="hidden" name="action_cms_activites" value="supprimer_pratique">
                                        <input type="hidden" name="pratique_id" value="<?= htmlspecialchars($pratique['id']) ?>">
                                        <button type="submit" class="btn-cms-delete" style="width:100%;">🗑️ Supprimer</button>
                                    </form>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details cms-add-block" style="margin-top: 25px;">
                    <summary class="cms-admin-summary">➕ Ajouter une nouvelle discipline / pratique (Admin)</summary>
                    <form method="POST" action="activites.php#pratiques" class="cms-admin-form">
                        <input type="hidden" name="action_cms_activites" value="sauvegarder_pratique">
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Icône (Emoji) *</label>
                                <input type="text" name="icone" value="🚁" required>
                            </div>
                            <div class="cms-field">
                                <label>Titre de la discipline *</label>
                                <input type="text" name="titre" required placeholder="Ex: Vol FPV & Hélicoptères">
                            </div>
                        </div>
                        <div class="cms-field">
                            <label>Description *</label>
                            <textarea name="description" rows="3" required placeholder="Décrivez cette activité..."></textarea>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">➕ Ajouter cette discipline</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- ==================================================================
             SECTION 2 : TEMPS FORTS ET ÉVÉNEMENTS ANNUELS (AVEC PHOTOS DE DÉCOR)
             ================================================================== -->
        <section class="events-section" id="evenements">
            <h2>Événements & Convivialité</h2>
            <p style="margin-bottom: 40px; font-size: 1.1rem; color: #555;">
                <?= htmlspecialchars($actData['evenements_intro']) ?>
            </p>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details" style="margin-bottom: 25px;">
                    <summary class="cms-admin-summary">✏️ Modifier la phrase d'introduction des événements (Admin)</summary>
                    <form method="POST" action="activites.php#evenements" class="cms-admin-form">
                        <input type="hidden" name="action_cms_activites" value="modifier_intro_evenements">
                        <div class="cms-field">
                            <label>Phrase d'introduction</label>
                            <input type="text" name="evenements_intro" value="<?= htmlspecialchars($actData['evenements_intro']) ?>" required>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">💾 Enregistrer</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>

            <div class="events-timeline">
                <?php foreach ($actData['evenements'] as $evt): ?>
                    <?php
                        $idEvtPropre   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $evt['id']);
                        $cheminParId   = $dossierDecorActivites . '/' . $idEvtPropre . '.jpg';
                        $cheminImgEvt  = (!empty($evt['image']) and is_file($evt['image']))
                            ? $evt['image']
                            : (is_file($cheminParId) ? $cheminParId : '');
                        $aPhotoEvt     = ($cheminImgEvt !== '');
                        $urlPhotoEvt   = $aPhotoEvt ? $cheminImgEvt . '?v=' . filemtime($cheminImgEvt) : '';
                        $texteGrisEvt  = !empty($evt['placeholder']) ? $evt['placeholder'] : ('Photo ' . $evt['titre']);
                    ?>
                    <div>
                        <div class="event-item">
                            <div class="event-media-col">
                                <?php if ($aPhotoEvt): ?>
                                    <div class="event-image has-photo" onclick="ouvrirLightbox('<?= htmlspecialchars($urlPhotoEvt, ENT_QUOTES) ?>')" title="Cliquez pour agrandir">
                                        <img src="<?= htmlspecialchars($urlPhotoEvt) ?>" alt="<?= htmlspecialchars($evt['titre']) ?>" loading="lazy">
                                    </div>
                                <?php else: ?>
                                    <div class="event-image placeholder-img"><?= htmlspecialchars($texteGrisEvt) ?></div>
                                <?php endif; ?>

                                <?php if ($estAdmin): ?>
                                    <div class="event-admin-bar">
                                        <form method="POST" action="activites.php#evenements" enctype="multipart/form-data" class="event-admin-form">
                                            <input type="hidden" name="action_cms_activites" value="upload_photo_evenement">
                                            <input type="hidden" name="evenement_id" value="<?= htmlspecialchars($evt['id']) ?>">
                                            <label class="btn-decor-upload">
                                                📷 <?= $aPhotoEvt ? 'Changer la photo' : 'Mettre une photo' ?>
                                                <input type="file" name="photo_decor" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()" hidden>
                                            </label>
                                        </form>

                                        <?php if ($aPhotoEvt): ?>
                                            <form method="POST" action="activites.php#evenements" onsubmit="return confirm('Supprimer cette photo et revenir au bloc gris « <?= htmlspecialchars(addslashes($texteGrisEvt)) ?> » ?');">
                                                <input type="hidden" name="action_cms_activites" value="supprimer_photo_evenement">
                                                <input type="hidden" name="evenement_id" value="<?= htmlspecialchars($evt['id']) ?>">
                                                <button type="submit" class="btn-decor-delete" title="Retirer la photo">🗑️ Supprimer la photo</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="event-details">
                                <span class="event-date"><?= htmlspecialchars($evt['periode']) ?></span>
                                <h3><?= htmlspecialchars($evt['titre']) ?></h3>
                                <p><?= nl2br(htmlspecialchars($evt['description'])) ?></p>
                            </div>
                        </div>

                        <?php if ($estAdmin): ?>
                            <details class="cms-admin-details" style="margin-top: 8px; margin-bottom: 0;">
                                <summary class="cms-admin-summary">✏️ Modifier le texte de « <?= htmlspecialchars($evt['titre']) ?> » (Admin)</summary>
                                <form method="POST" action="activites.php#evenements" enctype="multipart/form-data" class="cms-admin-form">
                                    <input type="hidden" name="action_cms_activites" value="sauvegarder_evenement">
                                    <input type="hidden" name="evenement_id" value="<?= htmlspecialchars($evt['id']) ?>">
                                    <div class="cms-form-row">
                                        <div class="cms-field">
                                            <label>Badge de période *</label>
                                            <input type="text" name="periode" value="<?= htmlspecialchars($evt['periode']) ?>" required>
                                        </div>
                                        <div class="cms-field">
                                            <label>Titre de l'événement *</label>
                                            <input type="text" name="titre" value="<?= htmlspecialchars($evt['titre']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="cms-field">
                                        <label>Description *</label>
                                        <textarea name="description" rows="3" required><?= htmlspecialchars($evt['description']) ?></textarea>
                                    </div>
                                    <div class="cms-field">
                                        <label>Texte affiché sur le fond gris si aucune photo n'est mise</label>
                                        <input type="text" name="placeholder" value="<?= htmlspecialchars($texteGrisEvt) ?>">
                                    </div>
                                    <div class="cms-actions">
                                        <button type="submit" class="btn-cms-save">💾 Enregistrer les modifications</button>
                                    </div>
                                </form>

                                <?php if (count($actData['evenements']) > 1): ?>
                                    <form method="POST" action="activites.php#evenements" style="padding: 0 20px 20px 20px;" onsubmit="return confirm('Supprimer définitivement cet événement ?');">
                                        <input type="hidden" name="action_cms_activites" value="supprimer_evenement">
                                        <input type="hidden" name="evenement_id" value="<?= htmlspecialchars($evt['id']) ?>">
                                        <button type="submit" class="btn-cms-delete">🗑️ Supprimer cet événement</button>
                                    </form>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details cms-add-block" style="margin-top: 30px;">
                    <summary class="cms-admin-summary">➕ Ajouter un nouvel événement dans la chronologie (Admin)</summary>
                    <form method="POST" action="activites.php#evenements" enctype="multipart/form-data" class="cms-admin-form">
                        <input type="hidden" name="action_cms_activites" value="sauvegarder_evenement">
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Badge de période *</label>
                                <input type="text" name="periode" required placeholder="Ex: Chaque Printemps">
                            </div>
                            <div class="cms-field">
                                <label>Titre de l'événement *</label>
                                <input type="text" name="titre" required placeholder="Ex: Rencontre Interclubs">
                            </div>
                        </div>
                        <div class="cms-field">
                            <label>Description *</label>
                            <textarea name="description" rows="3" required placeholder="Présentez cet événement..."></textarea>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Photo d'illustration (Optionnel)</label>
                                <input type="file" name="photo_decor" accept="image/jpeg,image/png,image/webp">
                            </div>
                            <div class="cms-field">
                                <label>Texte du fond gris (si aucune photo)</label>
                                <input type="text" name="placeholder" placeholder="Ex: Photo Interclubs">
                            </div>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">➕ Créer cet événement</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
