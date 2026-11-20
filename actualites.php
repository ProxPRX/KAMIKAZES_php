<?php
// ==========================================================================
// PAGE LISTANT TOUTES LES ACTUALITÉS DU CLUB - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte         = isset($_SESSION['membre']);
$role                = $_SESSION['membre']['role'] ?? null;
$membreId            = (int)($_SESSION['membre']['id'] ?? 0);
$estAdminOuBureau    = in_array($role, ['admin', 'bureau'], true);
$peutProposerArticle = in_array($role, ['membre', 'membre_associe', 'bureau', 'admin'], true);

// Récupération des articles selon le profil du visiteur
if ($estAdminOuBureau) {
    $stmt = $pdo->query("
        SELECT * FROM articles 
        ORDER BY FIELD(statut, 'en_attente', 'publie'), date_publication DESC, id DESC
    ");
} elseif ($estConnecte) {
    $stmt = $pdo->prepare("
        SELECT * FROM articles 
        WHERE statut = 'publie' OR auteur_id = :mon_id 
        ORDER BY FIELD(statut, 'en_attente', 'publie'), date_publication DESC, id DESC
    ");
    $stmt->execute([':mon_id' => $membreId]);
} else {
    $stmt = $pdo->query("
        SELECT * FROM articles 
        WHERE visibilite = 'public' AND statut = 'publie' 
        ORDER BY date_publication DESC, id DESC
    ");
}
$articles = $stmt->fetchAll();

// Verrouillage automatique des dossiers médias pour les articles privés ou en attente déjà existants
foreach ($articles as $artCheck) {
    if ($artCheck['statut'] === 'en_attente' or ($artCheck['visibilite'] ?? 'public') === 'prive') {
        appliquerProtectionMediaEnregistrement(
            $artCheck['image_principale'] ?? '',
            $artCheck['galerie_photos'] ?? '',
            $artCheck['documents_pdf'] ?? '',
            true
        );
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Toutes les actualités et événements des Kamikazes Agathois">
    <title>Actualités - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/accueil.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <section class="hero">
        <div class="hero-content">
            <h2>Actualités du Club</h2>
            <p>Retrouvez toute la vie des Kamikazes Agathois, nos événements et les nouveautés du terrain.</p>
        </div>
    </section>

    <main>
        <section class="news-section">
            <div class="news-header-flex">
                <h2>Tous les articles (<?= count($articles) ?>)</h2>

                <?php if ($peutProposerArticle): ?>
                    <a href="admin_article.php" class="btn-primary">
                        <?= $estAdminOuBureau ? '➕ Publier un article' : '✍️ Proposer un article' ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="news-grid">
                <?php foreach ($articles as $article): ?>
                    <?php
                        $estArticleProtege = ($article['statut'] === 'en_attente' or ($article['visibilite'] ?? 'public') === 'prive');
                        $vignette          = $article['image_principale'];
                        if (empty($vignette) and !empty($article['galerie_photos'])) {
                            $premieresPhotos = array_filter(array_map('trim', explode(',', $article['galerie_photos'])));
                            $vignette = $premieresPhotos[0] ?? null;
                        }
                        $urlVignette = !empty($vignette) ? obtenirUrlMedia($vignette, $estArticleProtege, 'article', $article['id']) : '';
                    ?>
                    <article class="news-card <?= $article['statut'] === 'en_attente' ? 'news-card-pending' : '' ?>">
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
                                <?php if ($article['statut'] === 'en_attente'): ?>
                                    <strong class="badge-attente-carte">⏳ En attente</strong>
                                <?php endif; ?>
                            </span>
                            <h3><?= htmlspecialchars($article['titre']) ?></h3>
                            <p><?= htmlspecialchars($article['resume']) ?></p>
                            <a href="article.php?id=<?= (int)$article['id'] ?>" class="read-more">
                                <?= ($article['statut'] === 'en_attente' and $estAdminOuBureau) ? '🔎 Vérifier et valider &rarr;' : 'Lire la suite &rarr;' ?>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
