<?php
// ==========================================================================
// LECTURE DÉTAILLÉE ET VALIDATION D'UN ARTICLE - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte            = isset($_SESSION['membre']);
$role                   = $_SESSION['membre']['role'] ?? null;
$membreId               = (int)($_SESSION['membre']['id'] ?? 0);
$peutAdministrerArticle = in_array($role, ['admin', 'bureau'], true);

// Vérification de l'identifiant de l'article passé dans l'URL (?id=...)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

// --- 1. CHARGEMENT DE L'ARTICLE ET DE SON AUTEUR (AVEC SON E-MAIL) ---
$stmt = $pdo->prepare("
    SELECT a.*, m.prenom, m.nom, m.email AS auteur_email 
    FROM articles a 
    LEFT JOIN membres m ON a.auteur_id = m.id 
    WHERE a.id = :id
");
$stmt->execute([':id' => $id]);
$article = $stmt->fetch();

if (!$article) {
    header('Location: index.php');
    exit;
}

$estEnAttente       = ($article['statut'] === 'en_attente');
$estAuteurEnAttente = ($estConnecte and (int)$article['auteur_id'] === $membreId and $estEnAttente);
$estArticleProtege  = ($estEnAttente or ($article['visibilite'] ?? 'public') === 'prive');

// --- 2. CONTRÔLE DES DROITS D'ACCÈS À L'ARTICLE ---
if ($estEnAttente and !$peutAdministrerArticle and !$estAuteurEnAttente) {
    header('Location: actualites.php');
    exit;
}

if (($article['visibilite'] ?? 'public') === 'prive' and !$estConnecte) {
    header('Location: connexion.php');
    exit;
}

// Verrouillage automatique des dossiers médias si l'article est privé ou en attente
if ($estArticleProtege) {
    appliquerProtectionMediaEnregistrement(
        $article['image_principale'] ?? '',
        $article['galerie_photos'] ?? '',
        $article['documents_pdf'] ?? '',
        true
    );
}

// --- 3. ACTIONS POST (VALIDATION PAR ADMIN/BUREAU OU SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' and isset($_POST['action'])) {
    // A. Validation et mise en ligne d'un article en attente (Admin ou Bureau uniquement)
    if ($_POST['action'] === 'valider' and $peutAdministrerArticle) {
        $stmtVal = $pdo->prepare("UPDATE articles SET statut = 'publie' WHERE id = :id");
        $stmtVal->execute([':id' => $id]);

        $doitResterProtege = (($article['visibilite'] ?? 'public') === 'prive');
        appliquerProtectionMediaEnregistrement(
            $article['image_principale'] ?? '',
            $article['galerie_photos'] ?? '',
            $article['documents_pdf'] ?? '',
            $doitResterProtege
        );

        if (!empty($article['auteur_email']) and (int)$article['auteur_id'] !== $membreId) {
            envoyerMailDecisionArticleMembre(
                $article['auteur_email'],
                $article['prenom'],
                $article['titre'],
                $id,
                true
            );
        }

        header("Location: article.php?id=$id&valide=1");
        exit;
    }

    // B. Suppression ou refus de l'article
    if ($_POST['action'] === 'supprimer' and ($peutAdministrerArticle or $estAuteurEnAttente)) {
        if ($estEnAttente and $peutAdministrerArticle and !empty($article['auteur_email']) and (int)$article['auteur_id'] !== $membreId) {
            envoyerMailDecisionArticleMembre(
                $article['auteur_email'],
                $article['prenom'],
                $article['titre'],
                $id,
                false
            );
        }

        if (!empty($article['image_principale'])) {
            supprimerFichierServeur($article['image_principale']);
        }
        if (!empty($article['galerie_photos'])) {
            foreach (array_filter(array_map('trim', explode(',', $article['galerie_photos']))) as $imgP) {
                supprimerFichierServeur($imgP);
            }
        }
        if (!empty($article['documents_pdf'])) {
            foreach (array_filter(array_map('trim', explode(',', $article['documents_pdf']))) as $pdfP) {
                supprimerFichierServeur($pdfP);
            }
        }

        $stmtDel = $pdo->prepare("DELETE FROM articles WHERE id = :id");
        $stmtDel->execute([':id' => $id]);
        header('Location: actualites.php');
        exit;
    }
}

// --- 4. EXTRACTION DES VIDÉOS YOUTUBE, DES DOCUMENTS PDF ET DE LA GALERIE PHOTOS ---
$youtubeIds = [];
if (!empty($article['video_youtube'])) {
    $lignesVideos = preg_split('/[\r\n,\s]+/', $article['video_youtube']);
    foreach ($lignesVideos as $lien) {
        $idYt = extraireIdYoutube(trim($lien));
        if ($idYt and !in_array($idYt, $youtubeIds)) {
            $youtubeIds[] = $idYt;
        }
    }
}

$documentsPdf = [];
if (!empty($article['documents_pdf'])) {
    $documentsPdf = array_filter(array_map('trim', explode(',', $article['documents_pdf'])));
}

$galerie = [];
if (!empty($article['galerie_photos'])) {
    $galerie = array_filter(array_map('trim', explode(',', $article['galerie_photos'])));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($article['titre']) ?> - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/article.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <main>
        <article class="article-container">
            <!-- BANDEAU D'ÉTAT SI L'ARTICLE EST EN ATTENTE OU VIENT D'ÊTRE VALIDÉ -->
            <?php if (isset($_GET['valide'])): ?>
                <div class="validation-banner validation-success">
                    ✅ <strong>Article validé !</strong> Cet article est désormais publié en ligne (un e-mail de confirmation a été envoyé à l'auteur).
                </div>
            <?php elseif ($estEnAttente): ?>
                <div class="validation-banner validation-pending">
                    <div>
                        ⏳ <strong>Article en attente de validation :</strong>
                        <?php if ($peutAdministrerArticle): ?>
                            Cet article a été proposé par <strong><?= afficherNomAuteur($article['prenom'], $article['nom']) ?></strong>. Vérifiez son contenu avant de le mettre en ligne.
                        <?php else: ?>
                            Votre article a bien été enregistré. Vous recevrez un e-mail dès qu'un membre du Bureau ou un Administrateur l'aura validé.
                        <?php endif; ?>
                    </div>

                    <?php if ($peutAdministrerArticle): ?>
                        <form method="POST" onsubmit="return confirm('Valider et publier cet article en ligne ?');">
                            <input type="hidden" name="action" value="valider">
                            <button type="submit" class="btn-validate">✅ Valider et mettre en ligne</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Barre supérieure : lien retour et boutons d'administration -->
            <div class="article-top-bar">
                <a href="actualites.php" class="back-link">&larr; Retour aux actualités</a>

                <?php if ($peutAdministrerArticle or $estAuteurEnAttente): ?>
                    <div class="admin-actions">
                        <a href="admin_article.php?id=<?= (int)$article['id'] ?>" class="btn-edit">✏️ Modifier l'article</a>

                        <form method="POST" onsubmit="return confirm('Voulez-vous vraiment supprimer définitivement cet article ?');">
                            <input type="hidden" name="action" value="supprimer">
                            <button type="submit" class="btn-delete">
                                <?= ($estEnAttente and $peutAdministrerArticle) ? '🗑️ Refuser / Supprimer' : '🗑️ Supprimer' ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Titre et métadonnées de publication -->
            <div class="article-header">
                <h1><?= htmlspecialchars($article['titre']) ?></h1>
                <div class="article-meta">
                    Publié le <span class="date"><?= formaterDate($article['date_publication']) ?></span>
                    par <strong><?= afficherNomAuteur($article['prenom'], $article['nom'], 'Le Bureau des Kamikazes Agathois') ?></strong>

                    <?php if ($article['visibilite'] === 'prive'): ?>
                        <span class="badge-prive">🔒 Réservé aux membres</span>
                    <?php endif; ?>

                    <?php if ($estEnAttente): ?>
                        <span class="badge-attente">⏳ En attente de validation</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Photo principale -->
            <?php if (!empty($article['image_principale'])): ?>
                <?php $urlImgPrincipale = obtenirUrlMedia($article['image_principale'], $estArticleProtege, 'article', $article['id']); ?>
                <img src="<?= htmlspecialchars($urlImgPrincipale) ?>" 
                     alt="<?= htmlspecialchars($article['titre']) ?>" 
                     class="article-main-image"
                     title="Cliquez pour agrandir en plein écran"
                     onclick="ouvrirLightbox('<?= htmlspecialchars($urlImgPrincipale, ENT_QUOTES) ?>')">
            <?php endif; ?>

            <div class="article-body">
                <?= nl2br(htmlspecialchars($article['contenu'])) ?>
            </div>

            <!-- Document(s) PDF joint(s) -->
            <?php if (!empty($documentsPdf)): ?>
                <div class="article-pdf-section">
                    <h3>📄 <?= count($documentsPdf) > 1 ? "Documents joints (" . count($documentsPdf) . ")" : "Document joint (PDF)" ?></h3>
                    <div class="pdf-list">
                        <?php foreach ($documentsPdf as $cheminPdf): ?>
                            <?php
                                $nomFichierPdf   = basename($cheminPdf);
                                $titreLisiblePdf = str_replace('_', ' ', pathinfo($nomFichierPdf, PATHINFO_FILENAME));
                                $urlPdfVue       = obtenirUrlMedia($cheminPdf, $estArticleProtege, 'article', $article['id'], false);
                                $urlPdfDl        = obtenirUrlMedia($cheminPdf, $estArticleProtege, 'article', $article['id'], true);
                            ?>
                            <div class="pdf-card">
                                <div class="pdf-card-header">
                                    <span class="pdf-title">📄 <?= htmlspecialchars($titreLisiblePdf) ?></span>
                                    <div class="pdf-actions">
                                        <a href="<?= htmlspecialchars($urlPdfVue) ?>" target="_blank" class="btn-secondary" style="margin-top:0; padding:8px 14px; font-size:0.88rem;">
                                            🔗 Ouvrir en plein écran
                                        </a>
                                        <a href="<?= htmlspecialchars($urlPdfDl) ?>" download="<?= htmlspecialchars($nomFichierPdf) ?>" class="btn-primary" style="padding:8px 14px; font-size:0.88rem;">
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

            <!-- Vidéo(s) YouTube -->
            <?php if (!empty($youtubeIds)): ?>
                <div class="article-video-section">
                    <h3>🎥 <?= count($youtubeIds) > 1 ? "Vidéos de l'événement (" . count($youtubeIds) . ")" : "Vidéo de l'événement" ?></h3>
                    <div class="videos-grid">
                        <?php foreach ($youtubeIds as $ytId): ?>
                            <div class="video-container">
                                <iframe src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($ytId) ?>?rel=0&modestbranding=1" 
                                        title="<?= htmlspecialchars($article['titre']) ?>" 
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                        allowfullscreen>
                                </iframe>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Galerie photos -->
            <?php if (!empty($galerie)): ?>
                <div class="article-gallery-section">
                    <h3>📸 Galerie photos (<?= count($galerie) ?> — cliquez sur une photo pour l'agrandir)</h3>
                    <div class="article-gallery-grid">
                        <?php foreach ($galerie as $photo): ?>
                            <?php $urlPhotoGal = obtenirUrlMedia($photo, $estArticleProtege, 'article', $article['id']); ?>
                            <div class="article-gallery-item" onclick="ouvrirLightbox('<?= htmlspecialchars($urlPhotoGal, ENT_QUOTES) ?>')" title="Cliquez pour agrandir">
                                <img src="<?= htmlspecialchars($urlPhotoGal) ?>" alt="Photo de l'article" loading="lazy">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </article>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
