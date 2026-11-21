<?php
// ==========================================================================
// PAGE LIENS UTILES (AVEC GESTION ADMIN SANS CODE DES CATÉGORIES & LIENS)
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte = isset($_SESSION['membre']);
$role        = $_SESSION['membre']['role'] ?? null;
$estAdmin    = ($role === 'admin');

$contenuSite = chargerContenuSite();
$liensData   = $contenuSite['liens'];

$messageSucces = '';
$messageErreur = '';

// --- TRAITEMENT DES ACTIONS ADMIN (CATÉGORIES & LIENS UTILES) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' and $estAdmin and isset($_POST['action_cms_liens'])) {
    $action = $_POST['action_cms_liens'];

    // 1. Ajouter une nouvelle catégorie de liens
    if ($action === 'ajouter_categorie') {
        $titreCat = trim($_POST['titre_categorie'] ?? '');
        $lTitre   = trim($_POST['premier_lien_titre'] ?? '');
        $lUrl     = trim($_POST['premier_lien_url'] ?? '');
        $lDesc    = trim($_POST['premier_lien_desc'] ?? '');

        if ($titreCat === '' or $lTitre === '' or $lUrl === '') {
            $messageErreur = "Veuillez renseigner le titre de la catégorie et au moins un premier lien.";
        } else {
            $liensData['categories'][] = [
                'id'    => uniqid('cat_'),
                'titre' => $titreCat,
                'liens' => [
                    [
                        'titre'       => $lTitre,
                        'url'         => $lUrl,
                        'description' => $lDesc
                    ]
                ]
            ];
            $contenuSite['liens'] = $liensData;
            sauvegarderContenuSite($contenuSite);
            $messageSucces = "✅ La catégorie « $titreCat » a bien été créée.";
        }
    }

    // 2. Renommer une catégorie existante
    elseif ($action === 'renommer_categorie') {
        $catId    = trim($_POST['cat_id'] ?? '');
        $titreCat = trim($_POST['titre_categorie'] ?? '');
        if ($titreCat !== '') {
            foreach ($liensData['categories'] as $idx => $cat) {
                if (($cat['id'] ?? '') === $catId) {
                    $liensData['categories'][$idx]['titre'] = $titreCat;
                    $contenuSite['liens'] = $liensData;
                    sauvegarderContenuSite($contenuSite);
                    $messageSucces = "✅ Le titre de la catégorie a été mis à jour.";
                    break;
                }
            }
        }
    }

    // 3. Supprimer une catégorie complète (sans blocage)
    elseif ($action === 'supprimer_categorie') {
        $catId = trim($_POST['cat_id'] ?? '');
        foreach ($liensData['categories'] as $idx => $cat) {
            if (($cat['id'] ?? '') === $catId) {
                unset($liensData['categories'][$idx]);
                $liensData['categories'] = array_values($liensData['categories']);
                $contenuSite['liens']    = $liensData;
                sauvegarderContenuSite($contenuSite);
                $messageSucces = "🗑️ Le bloc catégorie a bien été supprimé.";
                break;
            }
        }
    }

    // 4. Ajouter ou Modifier un lien dans une catégorie
    elseif ($action === 'sauvegarder_lien') {
        $catId     = trim($_POST['cat_id'] ?? '');
        $lienIndex = isset($_POST['lien_index']) and $_POST['lien_index'] !== '' ? (int)$_POST['lien_index'] : null;
        $titre     = trim($_POST['titre'] ?? '');
        $url       = trim($_POST['url'] ?? '');
        $desc      = trim($_POST['description'] ?? '');

        if ($titre === '' or $url === '') {
            $messageErreur = "Veuillez renseigner le nom du site et son adresse URL.";
        } else {
            foreach ($liensData['categories'] as $idx => $cat) {
                if (($cat['id'] ?? '') === $catId) {
                    $nouveauLien = [
                        'titre'       => $titre,
                        'url'         => $url,
                        'description' => $desc
                    ];
                    if ($lienIndex !== null and isset($liensData['categories'][$idx]['liens'][$lienIndex])) {
                        $liensData['categories'][$idx]['liens'][$lienIndex] = $nouveauLien;
                        $messageSucces = "✅ Le lien « $titre » a bien été modifié.";
                    } else {
                        $liensData['categories'][$idx]['liens'][] = $nouveauLien;
                        $messageSucces = "✅ Le lien « $titre » a bien été ajouté.";
                    }
                    $contenuSite['liens'] = $liensData;
                    sauvegarderContenuSite($contenuSite);
                    break;
                }
            }
        }
    }

    // 5. Supprimer un lien d'une catégorie
    elseif ($action === 'supprimer_lien') {
        $catId     = trim($_POST['cat_id'] ?? '');
        $lienIndex = (int)($_POST['lien_index'] ?? -1);
        foreach ($liensData['categories'] as $idx => $cat) {
            if (($cat['id'] ?? '') === $catId and isset($cat['liens'][$lienIndex])) {
                unset($liensData['categories'][$idx]['liens'][$lienIndex]);
                $liensData['categories'][$idx]['liens'] = array_values($liensData['categories'][$idx]['liens']);
                $contenuSite['liens'] = $liensData;
                sauvegarderContenuSite($contenuSite);
                $messageSucces = "🗑️ Le lien a été supprimé.";
                break;
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
    <meta name="description" content="Liens utiles, réglementation et ressources aéromodélisme du club Les Kamikazes Agathois.">
    <title>Liens Utiles - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/liens.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <!-- BANNIÈRE LIENS UTILES -->
    <section class="hero liens-hero">
        <div class="hero-content">
            <h2>Liens Utiles</h2>
            <p>Institutions, réglementation et forums de passionnés.</p>
        </div>
    </section>

    <main>
        <?php if ($messageSucces): ?>
            <div class="cms-feedback cms-feedback-success"><?= htmlspecialchars($messageSucces) ?></div>
        <?php endif; ?>

        <?php if ($messageErreur): ?>
            <div class="cms-feedback cms-feedback-error"><?= htmlspecialchars($messageErreur) ?></div>
        <?php endif; ?>

        <section class="links-section">
            <?php if (empty($liensData['categories'])): ?>
                <div class="info-box" style="text-align: center;">
                    <p>Aucune catégorie de liens pour le moment.<?= $estAdmin ? ' Utilisez le bloc ci-dessous pour en créer une.' : '' ?></p>
                </div>
            <?php else: ?>
                <div class="links-grid">
                    <?php foreach ($liensData['categories'] as $categorie): ?>
                        <div class="link-category">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:20px; padding-bottom:10px; border-bottom:2px solid var(--ka-orange);">
                                <h3 style="margin-bottom:0; padding-bottom:0; border-bottom:none;">
                                    <?= htmlspecialchars($categorie['titre']) ?>
                                </h3>

                                <?php if ($estAdmin): ?>
                                    <form method="POST" action="liens.php" style="margin:0; flex-shrink:0;" onsubmit="return confirm('Supprimer définitivement le bloc « <?= htmlspecialchars(addslashes($categorie['titre'])) ?> » et tous ses liens ?');">
                                        <input type="hidden" name="action_cms_liens" value="supprimer_categorie">
                                        <input type="hidden" name="cat_id" value="<?= htmlspecialchars($categorie['id']) ?>">
                                        <button type="submit" class="btn-cms-delete" style="padding:5px 10px; font-size:0.78rem;" title="Supprimer ce bloc">🗑️ Supprimer ce bloc</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <?php if (empty($categorie['liens'])): ?>
                                <p style="color: var(--ka-gris-fonce); font-style: italic; font-size: 0.92rem; margin-bottom: 10px;">
                                    Aucun lien dans cette catégorie pour le moment.
                                </p>
                            <?php else: ?>
                                <ul class="styled-link-list">
                                    <?php foreach ($categorie['liens'] as $lIdx => $lien): ?>
                                        <li>
                                            <a href="<?= htmlspecialchars($lien['url']) ?>" target="_blank" rel="noopener noreferrer">
                                                <?= htmlspecialchars($lien['titre']) ?>
                                            </a>
                                            <?php if (!empty($lien['description'])): ?>
                                                <p><?= htmlspecialchars($lien['description']) ?></p>
                                            <?php endif; ?>

                                            <?php if ($estAdmin): ?>
                                                <details style="margin-top: 8px;">
                                                    <summary style="cursor: pointer; font-size: 0.8rem; color: var(--ka-bleu); font-weight: bold;">✏️ Modifier / Supprimer ce lien</summary>
                                                    <div style="background: var(--ka-gris-back); padding: 10px; border-radius: 6px; margin-top: 6px; border: 1px solid var(--ka-gris-clair);">
                                                        <form method="POST" action="liens.php" style="display:flex; flex-direction:column; gap:8px; margin-bottom:8px;">
                                                            <input type="hidden" name="action_cms_liens" value="sauvegarder_lien">
                                                            <input type="hidden" name="cat_id" value="<?= htmlspecialchars($categorie['id']) ?>">
                                                            <input type="hidden" name="lien_index" value="<?= (int)$lIdx ?>">
                                                            <input type="text" name="titre" value="<?= htmlspecialchars($lien['titre']) ?>" required placeholder="Titre du site" style="padding:6px; font-size:0.85rem;">
                                                            <input type="url" name="url" value="<?= htmlspecialchars($lien['url']) ?>" required placeholder="https://..." style="padding:6px; font-size:0.85rem;">
                                                            <input type="text" name="description" value="<?= htmlspecialchars($lien['description'] ?? '') ?>" placeholder="Description courte" style="padding:6px; font-size:0.85rem;">
                                                            <button type="submit" class="btn-cms-save" style="padding:6px 10px; font-size:0.82rem;">💾 Enregistrer</button>
                                                        </form>
                                                        <form method="POST" action="liens.php" onsubmit="return confirm('Supprimer ce lien ?');">
                                                            <input type="hidden" name="action_cms_liens" value="supprimer_lien">
                                                            <input type="hidden" name="cat_id" value="<?= htmlspecialchars($categorie['id']) ?>">
                                                            <input type="hidden" name="lien_index" value="<?= (int)$lIdx ?>">
                                                            <button type="submit" class="btn-cms-delete" style="padding:5px 10px; font-size:0.8rem; width:100%;">🗑️ Supprimer ce lien</button>
                                                        </form>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($estAdmin): ?>
                                <details class="cms-admin-details" style="margin-top: 20px; margin-bottom: 0;">
                                    <summary class="cms-admin-summary" style="font-size: 0.85rem;">✏️ Renommer ou ➕ Ajouter un lien (Admin)</summary>
                                    <div class="cms-admin-form" style="padding: 15px;">
                                        <form method="POST" action="liens.php" style="display:flex; flex-direction:column; gap:10px; padding-bottom:15px; border-bottom:1px dashed var(--ka-gris-clair);">
                                            <input type="hidden" name="action_cms_liens" value="sauvegarder_lien">
                                            <input type="hidden" name="cat_id" value="<?= htmlspecialchars($categorie['id']) ?>">
                                            <strong style="color:var(--ka-bleu); font-size:0.9rem;">➕ Ajouter un nouveau lien ici :</strong>
                                            <div class="cms-field">
                                                <label>Nom du site *</label>
                                                <input type="text" name="titre" required placeholder="Ex: Météo France">
                                            </div>
                                            <div class="cms-field">
                                                <label>Adresse URL *</label>
                                                <input type="url" name="url" required placeholder="https://...">
                                            </div>
                                            <div class="cms-field">
                                                <label>Description</label>
                                                <input type="text" name="description" placeholder="Courte présentation du lien...">
                                            </div>
                                            <button type="submit" class="btn-cms-save">➕ Ajouter ce lien</button>
                                        </form>

                                        <form method="POST" action="liens.php" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-top:5px;">
                                            <input type="hidden" name="action_cms_liens" value="renommer_categorie">
                                            <input type="hidden" name="cat_id" value="<?= htmlspecialchars($categorie['id']) ?>">
                                            <div class="cms-field" style="flex:1; min-width:160px;">
                                                <label>Renommer le bloc catégorie</label>
                                                <input type="text" name="titre_categorie" value="<?= htmlspecialchars($categorie['titre']) ?>" required>
                                            </div>
                                            <button type="submit" class="btn-cms-save">💾 Renommer</button>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details cms-add-block" style="margin-top: 35px;">
                    <summary class="cms-admin-summary">➕ Créer une nouvelle catégorie de liens (ex: 🛒 Boutiques &amp; Matériel) (Admin)</summary>
                    <form method="POST" action="liens.php" class="cms-admin-form">
                        <input type="hidden" name="action_cms_liens" value="ajouter_categorie">
                        <div class="cms-field">
                            <label>Titre de la nouvelle catégorie *</label>
                            <input type="text" name="titre_categorie" required placeholder="Ex: 🛒 Boutiques & Simulateurs">
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Titre du 1er lien *</label>
                                <input type="text" name="premier_lien_titre" required placeholder="Ex: Simulateur Heli-X">
                            </div>
                            <div class="cms-field">
                                <label>URL du 1er lien *</label>
                                <input type="url" name="premier_lien_url" required placeholder="https://...">
                            </div>
                        </div>
                        <div class="cms-field">
                            <label>Description du 1er lien</label>
                            <input type="text" name="premier_lien_desc" placeholder="Courte description...">
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">➕ Créer cette catégorie</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
