<?php
// ==========================================================================
// PAGE D'ACCUEIL DU SITE (AVEC MAINTENANCE SANS CODE POUR L'ADMIN) - KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte         = isset($_SESSION['membre']);
$role                = $_SESSION['membre']['role'] ?? null;
$estAdmin            = ($role === 'admin');
$estAdminOuBureau    = in_array($role, ['admin', 'bureau'], true);
$peutProposerArticle = in_array($role, ['membre', 'membre_associe', 'bureau', 'admin'], true);

$contenuSite = chargerContenuSite();
$accueilData = $contenuSite['accueil'];
$succesCms   = '';

// --- 0. GESTION ADMIN SANS CODE (BANNIÈRE, STATION MÉTÉO & VIDÉO D'ACCUEIL) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' and $estAdmin and isset($_POST['action_cms_accueil'])) {
    $actionCms = $_POST['action_cms_accueil'];

    if ($actionCms === 'modifier_hero') {
        $accueilData['hero_titre']        = trim($_POST['hero_titre'] ?? '');
        $accueilData['hero_sous_titre']   = trim($_POST['hero_sous_titre'] ?? '');
        $accueilData['hero_bouton_texte'] = trim($_POST['hero_bouton_texte'] ?? '');
        $contenuSite['accueil']           = $accueilData;
        sauvegarderContenuSite($contenuSite);
        $succesCms = "✅ La bannière d'accueil a bien été mise à jour.";
    } elseif ($actionCms === 'modifier_meteo') {
        $accueilData['meteo_titre'] = trim($_POST['meteo_titre'] ?? '');
        $accueilData['meteo_texte'] = trim($_POST['meteo_texte'] ?? '');
        $accueilData['meteo_url']   = trim($_POST['meteo_url'] ?? '');
        $contenuSite['accueil']     = $accueilData;
        sauvegarderContenuSite($contenuSite);
        $succesCms = "✅ La section Météo du terrain a bien été mise à jour.";
    } elseif ($actionCms === 'modifier_video') {
        $accueilData['video_titre']   = trim($_POST['video_titre'] ?? '');
        $accueilData['video_texte']   = trim($_POST['video_texte'] ?? '');
        $saisieVideo                  = trim($_POST['video_youtube'] ?? '');
        $idYt                         = extraireIdYoutube($saisieVideo);
        $accueilData['video_youtube'] = $idYt ? ('https://www.youtube.com/embed/' . $idYt) : $saisieVideo;
        $contenuSite['accueil']       = $accueilData;
        sauvegarderContenuSite($contenuSite);
        $succesCms = "✅ La vidéo de présentation a bien été mise à jour.";
    }
}

// --- 1. GESTION DES "DATES À RETENIR" (MINIMUM 1 / MAXIMUM 3 DATES) ---
$erreurDate = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' and $estAdmin and isset($_POST['action_date'])) {
    $nbDates = (int)$pdo->query("SELECT COUNT(*) FROM dates_retenir")->fetchColumn();

    if ($_POST['action_date'] === 'ajouter_date') {
        $dateTexte = trim($_POST['date_texte'] ?? '');
        $descTexte = trim($_POST['description'] ?? '');
        if ($nbDates >= 3) {
            $erreurDate = "Maximum 3 dates atteint. Supprimez-en une avant d'en ajouter.";
        } elseif (!empty($dateTexte) and !empty($descTexte)) {
            $stmtAdd = $pdo->prepare("INSERT INTO dates_retenir (date_texte, description) VALUES (:d, :desc)");
            $stmtAdd->execute([':d' => $dateTexte, ':desc' => $descTexte]);
            header("Location: index.php");
            exit;
        }
    } elseif ($_POST['action_date'] === 'supprimer_date') {
        $dateId = (int)($_POST['date_id'] ?? 0);
        if ($nbDates <= 1) {
            $erreurDate = "Il doit rester au minimum 1 date à retenir.";
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM dates_retenir WHERE id = :id");
            $stmtDel->execute([':id' => $dateId]);
            header("Location: index.php");
            exit;
        }
    }
}

$datesRetenir = $pdo->query("SELECT * FROM dates_retenir ORDER BY id ASC")->fetchAll();

// --- 2. SÉLECTION ALÉATOIRE DE "L'IMAGE DU MOMENT" DANS LES PHOTOS PUBLIQUES ---
function obtenirImageAleatoirePublique($pdo, $dossierRacine = 'IMG') {
    if (!is_dir($dossierRacine)) return null;

    $dossiersExclus = [];
    $photosExclues  = [];

    // Exclusion des articles non publics ou non publiés
    $stmtExclArt = $pdo->query("SELECT image_principale, galerie_photos FROM articles WHERE statut != 'publie' OR visibilite != 'public'");
    foreach ($stmtExclArt->fetchAll() as $row) {
        foreach (preg_split('/[\r\n,]+/', $row['image_principale'] . ',' . $row['galerie_photos']) as $p) {
            $p = str_replace('\\', '/', trim($p));
            if ($p !== '') {
                $photosExclues[$p] = true;
                $dir = dirname($p);
                if ($dir !== '.' and $dir !== 'IMG') $dossiersExclus[$dir] = true;
            }
        }
    }

    // Exclusion des publications membres non publiques ou non publiées
    $stmtExclPub = $pdo->query("SELECT image_principale, galerie_photos FROM membres_publications WHERE statut != 'publie' OR visibilite != 'public'");
    foreach ($stmtExclPub->fetchAll() as $row) {
        foreach (preg_split('/[\r\n,]+/', $row['image_principale'] . "\n" . $row['galerie_photos']) as $p) {
            $p = str_replace('\\', '/', trim($p));
            if ($p !== '') {
                $photosExclues[$p] = true;
                $dir = dirname($p);
                if ($dir !== '.' and $dir !== 'IMG') $dossiersExclus[$dir] = true;
            }
        }
    }

    $images     = [];
    $extensions = ['jpg', 'jpeg', 'png', 'webp'];

    $elementsRacine = scandir($dossierRacine);
    foreach ($elementsRacine as $dossierAnnee) {
        if ($dossierAnnee === '.' or $dossierAnnee === '..') continue;

        $cheminAnnee = $dossierRacine . '/' . $dossierAnnee;

        if (is_dir($cheminAnnee) and preg_match('/^\d{4}$/', $dossierAnnee)) {
            $iterateur = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cheminAnnee, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterateur as $fichier) {
                if ($fichier->isFile()) {
                    $ext = strtolower(pathinfo($fichier->getFilename(), PATHINFO_EXTENSION));
                    if (in_array($ext, $extensions)) {
                        $cheminNorm = str_replace('\\', '/', $fichier->getPathname());
                        $dirNorm    = dirname($cheminNorm);
                        if (!isset($photosExclues[$cheminNorm]) and !isset($dossiersExclus[$dirNorm])) {
                            $images[] = $cheminNorm;
                        }
                    }
                }
            }
        }
    }

    if (empty($images)) return null;
    $choisie = $images[array_rand($images)];
    redresserImageExifAuto($choisie); // Fonction partagée dans PHP/fonctions.php
    return $choisie;
}

$imageDuJour = obtenirImageAleatoirePublique($pdo, 'IMG');

// Détection des dimensions pour adapter le cadre CSS au format Portrait ou Paysage natif de la photo
$classeOrientation = 'paysage';
if ($imageDuJour and is_file($imageDuJour)) {
    $dims = @getimagesize($imageDuJour);
    if ($dims and $dims[1] > $dims[0]) {
        $classeOrientation = 'portrait';
    }
}

// --- 3. RÉCUPÉRATION DES 3 DERNIERS ARTICLES PUBLIÉS (ET COMPTEUR D'ARTICLES EN ATTENTE) ---
if ($estConnecte) {
    $stmt = $pdo->query("SELECT * FROM articles WHERE statut = 'publie' ORDER BY date_publication DESC, id DESC LIMIT 3");
} else {
    $stmt = $pdo->query("SELECT * FROM articles WHERE visibilite = 'public' AND statut = 'publie' ORDER BY date_publication DESC, id DESC LIMIT 3");
}
$articles = $stmt->fetchAll();

// Verrouillage automatique des dossiers médias pour les articles privés affichés sur l'accueil
foreach ($articles as $artCheck) {
    if (($artCheck['visibilite'] ?? 'public') === 'prive') {
        appliquerProtectionMediaEnregistrement(
            $artCheck['image_principale'] ?? '',
            $artCheck['galerie_photos'] ?? '',
            $artCheck['documents_pdf'] ?? '',
            true
        );
    }
}

// Compteur d'articles en attente de validation pour alerter l'Admin et le Bureau
$nbArticlesEnAttente = 0;
if ($estAdminOuBureau) {
    $nbArticlesEnAttente = (int)$pdo->query("SELECT COUNT(*) FROM articles WHERE statut = 'en_attente'")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Site officiel des Kamikazes Agathois - Club d'aéromodélisme à Agde (Hérault)">
    <title>Les Kamikazes Agathois - Aéromodélisme</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=7">
    <link rel="stylesheet" href="CSS/accueil.css?v=7">
</head>
<body>

    <?php include 'header.php'; ?>

    <!-- BANNIÈRE D'ACCUEIL AVEC LOGOS OLAP (GAUCHE) ET VILLE D'AGDE (DROITE) -->
    <section class="hero">
        <div class="hero-inner">
            <img src="IMG/OLAP_LOGO2.png" alt="Logo OLAP Aéromodélisme" class="hero-logo hero-logo-left">

            <div class="hero-content">
                <h2><?= htmlspecialchars($accueilData['hero_titre']) ?></h2>
                <p><?= htmlspecialchars($accueilData['hero_sous_titre']) ?></p>
                <a href="club.php#inscription" class="btn-primary"><?= htmlspecialchars($accueilData['hero_bouton_texte']) ?></a>
            </div>

            <img src="IMG/LOGO_FFAM_CB.png" alt="Logo Ville d'Agde" class="hero-logo hero-logo-right">
        </div>
    </section>

    <main>
        <?php if ($succesCms): ?>
            <div class="cms-feedback cms-feedback-success"><?= htmlspecialchars($succesCms) ?></div>
        <?php endif; ?>

        <?php if ($estAdmin): ?>
            <details class="cms-admin-details" style="margin-bottom: 30px;">
                <summary class="cms-admin-summary">✏️ Modifier la bannière d'accueil (Titre, slogan ou texte du bouton) (Admin)</summary>
                <form method="POST" action="index.php" class="cms-admin-form">
                    <input type="hidden" name="action_cms_accueil" value="modifier_hero">
                    <div class="cms-form-row">
                        <div class="cms-field">
                            <label>Titre principal de la bannière *</label>
                            <input type="text" name="hero_titre" value="<?= htmlspecialchars($accueilData['hero_titre']) ?>" required>
                        </div>
                        <div class="cms-field">
                            <label>Texte du bouton d'inscription *</label>
                            <input type="text" name="hero_bouton_texte" value="<?= htmlspecialchars($accueilData['hero_bouton_texte']) ?>" required>
                        </div>
                    </div>
                    <div class="cms-field">
                        <label>Sous-titre / Slogan *</label>
                        <input type="text" name="hero_sous_titre" value="<?= htmlspecialchars($accueilData['hero_sous_titre']) ?>" required>
                    </div>
                    <div class="cms-actions">
                        <button type="submit" class="btn-cms-save">💾 Enregistrer la bannière</button>
                    </div>
                </form>
            </details>
        <?php endif; ?>

        <!-- ALERTE POUR LE BUREAU / ADMIN SI DES ARTICLES SONT EN ATTENTE DE VALIDATION -->
        <?php if ($nbArticlesEnAttente > 0): ?>
            <div class="pending-alert-banner">
                <span>⏳ <strong><?= $nbArticlesEnAttente ?> article(s) proposé(s) par des membres</strong> <?= $nbArticlesEnAttente > 1 ? 'sont' : 'est' ?> en attente de validation.</span>
                <a href="actualites.php" class="btn-primary" style="padding: 8px 16px; font-size: 0.9rem;">Voir et valider &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- SECTION 1 : IMAGE DU MOMENT & ACCÈS AU PÊLE-MÊLE -->
        <?php if ($imageDuJour): ?>
            <section class="photo-of-day-section">
                <h2>📸 L'image du moment</h2>
                <div class="photo-of-day-wrapper <?= $classeOrientation ?>">
                    <img src="<?= htmlspecialchars($imageDuJour) ?>"
                         alt="Photo souvenir Les Kamikazes Agathois"
                         class="photo-of-day-img <?= $classeOrientation ?>"
                         title="Cliquez pour agrandir en plein écran"
                         onclick="ouvrirLightbox('<?= htmlspecialchars($imageDuJour, ENT_QUOTES) ?>')">
                </div>
                <a href="pelemele.php" class="btn-secondary">🖼️ Découvrir le Pêle-mêle du club &rarr;</a>
            </section>
        <?php endif; ?>

        <!-- SECTION 2 : DATES À RETENIR (PLACÉE AVANT LA MÉTÉO) -->
        <section class="dates-section">
            <h2>📅 Dates à retenir</h2>

            <?php if ($erreurDate): ?>
                <p style="color: var(--smac-rouge); font-size: 0.9rem; font-weight: bold; margin-bottom: 12px;">
                    <?= htmlspecialchars($erreurDate) ?>
                </p>
            <?php endif; ?>

            <ul class="dates-list">
                <?php foreach ($datesRetenir as $d): ?>
                    <li>
                        <span>📌 <strong><?= htmlspecialchars($d['date_texte']) ?> :</strong> <?= htmlspecialchars($d['description']) ?></span>
                        <?php if ($estAdmin and count($datesRetenir) > 1): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette date ?');">
                                <input type="hidden" name="action_date" value="supprimer_date">
                                <input type="hidden" name="date_id" value="<?= (int)$d['id'] ?>">
                                <button type="submit" class="btn-tiny-del" title="Supprimer cette date">✖</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($estAdmin and count($datesRetenir) < 3): ?>
                <form method="POST" class="admin-date-form">
                    <input type="hidden" name="action_date" value="ajouter_date">
                    <div class="admin-date-inputs">
                        <input type="text" name="date_texte" required placeholder="Date (ex: 14 juin 2027)">
                        <input type="text" name="description" required placeholder="Événement (ex: Rencontre amicale)">
                    </div>
                    <button type="submit" class="btn-secondary" style="margin-top: 5px; padding: 8px 15px; font-size: 0.85rem; border: none; cursor: pointer;">➕ Ajouter une date (<?= count($datesRetenir) ?>/3)</button>
                </form>
            <?php endif; ?>
        </section>

        <!-- SECTION 3 : STATION MÉTÉO DU TERRAIN EN DIRECT (IFRAME INTÉGRÉE) -->
        <section class="weather-section" id="meteo">
            <h2><?= htmlspecialchars($accueilData['meteo_titre']) ?></h2>
            <p><?= htmlspecialchars($accueilData['meteo_texte']) ?></p>

            <div class="weather-iframe-wrapper">
                <iframe
                    src="<?= htmlspecialchars($accueilData['meteo_url']) ?>"
                    loading="lazy"
                    title="Station météo en direct du terrain des Kamikazes Agathois"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen>
                </iframe>
            </div>

            <div class="weather-actions">
                <a href="<?= htmlspecialchars($accueilData['meteo_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary">
                    🔗 Ouvrir la station météo en plein écran &rarr;
                </a>
            </div>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details" style="margin-top: 20px; margin-bottom: 0;">
                    <summary class="cms-admin-summary">✏️ Modifier le lien ou le texte de la station météo (Admin)</summary>
                    <form method="POST" action="index.php#meteo" class="cms-admin-form">
                        <input type="hidden" name="action_cms_accueil" value="modifier_meteo">
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Titre de la section météo *</label>
                                <input type="text" name="meteo_titre" value="<?= htmlspecialchars($accueilData['meteo_titre']) ?>" required>
                            </div>
                            <div class="cms-field">
                                <label>URL de partage de la station météo (Ecowitt...) *</label>
                                <input type="url" name="meteo_url" value="<?= htmlspecialchars($accueilData['meteo_url']) ?>" required>
                            </div>
                        </div>
                        <div class="cms-field">
                            <label>Phrase d'introduction *</label>
                            <input type="text" name="meteo_texte" value="<?= htmlspecialchars($accueilData['meteo_texte']) ?>" required>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">💾 Enregistrer la météo</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- SECTION 4 : VIDÉO DE PRÉSENTATION DU CLUB -->
        <section class="video-section" id="video-presentation">
            <h2><?= htmlspecialchars($accueilData['video_titre']) ?></h2>
            <p><?= htmlspecialchars($accueilData['video_texte']) ?></p>
            <div class="video-container">
                <iframe width="560" height="315" src="<?= htmlspecialchars($accueilData['video_youtube']) ?>" title="Vidéo de présentation des Kamikazes Agathois" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
            </div>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details" style="margin-top: 20px; margin-bottom: 0;">
                    <summary class="cms-admin-summary">✏️ Changer la vidéo de présentation ou son titre (Admin)</summary>
                    <form method="POST" action="index.php#video-presentation" class="cms-admin-form">
                        <input type="hidden" name="action_cms_accueil" value="modifier_video">
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Titre de la section vidéo *</label>
                                <input type="text" name="video_titre" value="<?= htmlspecialchars($accueilData['video_titre']) ?>" required>
                            </div>
                            <div class="cms-field">
                                <label>Lien YouTube ou URL embed (Dailymotion...) *</label>
                                <input type="url" name="video_youtube" value="<?= htmlspecialchars($accueilData['video_youtube']) ?>" required placeholder="Ex: https://youtu.be/... ou https://www.dailymotion.com/embed/video/...">
                                <span class="cms-help">Les liens YouTube sont convertis automatiquement ; les liens embed Dailymotion fonctionnent directement.</span>
                            </div>
                        </div>
                        <div class="cms-field">
                            <label>Description sous le titre *</label>
                            <input type="text" name="video_texte" value="<?= htmlspecialchars($accueilData['video_texte']) ?>" required>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">💾 Enregistrer la vidéo</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- SECTION 5 : LES 3 DERNIERS ARTICLES -->
        <section class="news-section">
            <div class="news-header-flex">
                <h2>Dernières mises à jour</h2>

                <?php if ($peutProposerArticle): ?>
                    <a href="admin_article.php" class="btn-primary">
                        <?= $estAdminOuBureau ? '➕ Publier un article' : '✍️ Proposer un article' ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="news-grid">
                <?php foreach ($articles as $article): ?>
                    <?php
                        $estArticleProtege = (($article['visibilite'] ?? 'public') === 'prive');
                        $vignette          = $article['image_principale'];
                        if (empty($vignette) and !empty($article['galerie_photos'])) {
                            $premieresPhotos = array_filter(array_map('trim', explode(',', $article['galerie_photos'])));
                            $vignette = $premieresPhotos[0] ?? null;
                        }
                        $urlVignette = !empty($vignette) ? obtenirUrlMedia($vignette, $estArticleProtege, 'article', $article['id']) : '';
                    ?>
                    <article class="news-card">
                        <?php if (!empty($urlVignette)): ?>
                            <img src="<?= htmlspecialchars($urlVignette) ?>" alt="<?= htmlspecialchars($article['titre']) ?>" class="news-image" loading="lazy">
                        <?php else: ?>
                            <div class="news-image placeholder-img">Photo Kamikazes Agathois</div>
                        <?php endif; ?>

                        <div class="news-content">
                            <span class="news-date">
                                <?= formaterDate($article['date_publication']) ?>
                                <?php if ($article['visibilite'] === 'prive'): ?>
                                    <strong class="badge-prive">🔒 Membres</strong>
                                <?php endif; ?>
                            </span>
                            <h3><?= htmlspecialchars($article['titre']) ?></h3>
                            <p><?= htmlspecialchars($article['resume']) ?></p>
                            <a href="article.php?id=<?= (int)$article['id'] ?>" class="read-more">Lire la suite &rarr;</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
