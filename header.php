<?php
// ==========================================================================
// EN-TÊTE ET NAVIGATION PRINCIPALE DU SITE (INCLUS SUR TOUTES LES PAGES)
// ==========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Récupération de l'état de connexion et de la page courante pour surligner l'onglet actif
$estConnecte  = isset($_SESSION['membre']);
$role         = $_SESSION['membre']['role'] ?? null;
$pageActuelle = basename($_SERVER['PHP_SELF']);

// Regroupement logique des sous-pages pour conserver l'onglet parent actif dans le menu
$estSectionActu    = in_array($pageActuelle, ['actualites.php', 'article.php', 'admin_article.php']);
$estSectionMembres = in_array($pageActuelle, ['membres.php', 'admin_publication_membre.php']);
$roleAffiche       = strtoupper(str_replace('_', ' ', (string)$role));
?>

<header>
    <!-- Bandeau dynamique inspiré du site historique des Kamikazes Agathois -->
    <div class="ka-dynamic-banner">
        <a href="index.php" class="ka-banner-link" aria-label="Retour à l'accueil - Les Kamikazes Agathois">
            <img src="IMG/bandeau_fond.jpg" alt="" class="ka-banner-bg" aria-hidden="true">
            <div class="ka-banner-overlay" aria-hidden="true"></div>

            <div class="ka-banner-inner">
                <div class="ka-banner-text">
                    <span class="ka-banner-title">Club d'aéromodélisme Agathois !</span>
                </div>

                <div class="ka-banner-plane-wrap">
                    <img src="IMG/bandeau_avion.png" alt="Pilote en avion - Les Kamikazes Agathois" class="ka-banner-plane">
                </div>
            </div>
        </a>
    </div>

    <!-- Barre de navigation principale -->
    <nav class="navbar">
        <ul>
            <li><a href="index.php" class="<?= $pageActuelle === 'index.php' ? 'active' : '' ?>">Accueil</a></li>
            <li><a href="actualites.php" class="<?= $estSectionActu ? 'active' : '' ?>">Actualités</a></li>
            <!-- <li><a href="pelemele.php" class="<?= $pageActuelle === 'pelemele.php' ? 'active' : '' ?>">Pêle-mêle</a></li> -->
            <li><a href="club.php" class="<?= $pageActuelle === 'club.php' ? 'active' : '' ?>">Le Club</a></li>
            <li><a href="activites.php" class="<?= $pageActuelle === 'activites.php' ? 'active' : '' ?>">Activités</a></li>
            <li><a href="liens.php" class="<?= $pageActuelle === 'liens.php' ? 'active' : '' ?>">Liens</a></li>
            <li><a href="membres.php" class="<?= $estSectionMembres ? 'active' : '' ?>">Membres</a></li>
            <li><a href="forum.php" class="<?= $pageActuelle === 'forum.php' ? 'active' : '' ?>">Forum</a></li>

            <?php if ($role === 'admin'): ?>
                <li><a href="admin_membres.php" class="<?= $pageActuelle === 'admin_membres.php' ? 'active' : '' ?>">⚙️ Gestion Membres</a></li>
            <?php endif; ?>

            <?php if ($estConnecte): ?>
                <li>
                    <a href="connexion.php?action=deconnexion" style="color: #ffff01;">
                        👤 <?= htmlspecialchars($_SESSION['membre']['prenom']) ?> (<?= htmlspecialchars($roleAffiche) ?>) - Déconnexion
                    </a>
                </li>
            <?php else: ?>
                <li><a href="connexion.php" class="<?= $pageActuelle === 'connexion.php' ? 'active' : '' ?>">🔒 Connexion</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>
