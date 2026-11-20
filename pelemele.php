<?php
// ==========================================================================
// GALERIE PHOTO PÊLE-MÊLE CLASSÉE PAR ANNÉE - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte       = isset($_SESSION['membre']);
$role              = $_SESSION['membre']['role'] ?? null;
$peutAjouterPhotos = in_array($role, ['admin', 'bureau']);

$erreur = '';
$succes = '';

// Vérifie par sécurité qu'un chemin manipulé reste strictement dans un sous-dossier IMG/{YYYY}
function cheminPeleMeleValide($chemin) {
    return (strpos($chemin, '..') === false and preg_match('#^IMG/\d{4}(/[a-zA-Z0-9_\-\.]+)*$#', $chemin));
}

// --- 1. TRAITEMENT DES ACTIONS POST (AJOUT, DÉPLACEMENT D'ANNÉE & SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' and $peutAjouterPhotos and isset($_POST['action'])) {
    $action = $_POST['action'];

    // A. Envoi de nouvelles photos dans une année (avec ou sans sous-dossier d'événement)
    if ($action === 'upload_pelemele') {
        $annee    = (int)($_POST['annee'] ?? 0);
        $nomAlbum = trim($_POST['nom_album'] ?? '');

        if ($annee < 2000 or $annee > 2100) {
            $erreur = "Veuillez saisir une année valide à 4 chiffres (ex: 2026 ou 2027).";
        } elseif (empty($_FILES['photos']['name'][0])) {
            $erreur = "Veuillez sélectionner au moins une photo.";
        } else {
            if (!empty($nomAlbum)) {
                $slugAlbum          = nettoyerNomDossier($nomAlbum);
                $dossierDestination = "IMG/$annee/articles/$slugAlbum";
            } else {
                $dossierDestination = "IMG/$annee";
            }

            $nbAjoutees = 0;
            foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                if ($_FILES['photos']['error'][$idx] === UPLOAD_ERR_OK) {
                    if (enregistrerImageOptimisee($tmpName, $_FILES['photos']['name'][$idx], $dossierDestination)) {
                        $nbAjoutees++;
                    }
                }
            }

            if ($nbAjoutees > 0) {
                $succes = "✅ $nbAjoutees photo(s) ajoutée(s) avec succès dans l'année $annee !";
            } else {
                $erreur = "Aucune photo valide n'a pu être enregistrée.";
            }
        }
    }

    // B. Déplacement d'un album complet vers une autre année
    elseif ($action === 'deplacer_album') {
        $dossierSource = trim($_POST['dossier_source'] ?? '');
        $nouvelleAnnee = (int)($_POST['nouvelle_annee'] ?? 0);

        if ($nouvelleAnnee < 2000 or $nouvelleAnnee > 2100) {
            $erreur = "Veuillez entrer une année de destination valide (ex: 2026).";
        } elseif (cheminPeleMeleValide($dossierSource) and is_dir($dossierSource)) {
            if (preg_match('#^IMG/\d{4}/(.+)$#', $dossierSource, $matches)) {
                $sousChemin   = $matches[1];
                $dossierCible = "IMG/$nouvelleAnnee/$sousChemin";
                $parentCible  = dirname($dossierCible);

                if (!is_dir($parentCible)) {
                    mkdir($parentCible, 0755, true);
                }

                if (!is_dir($dossierCible)) {
                    rename($dossierSource, $dossierCible);
                } else {
                    foreach (scandir($dossierSource) as $f) {
                        if ($f !== '.' and $f !== '..') {
                            rename("$dossierSource/$f", "$dossierCible/$f");
                        }
                    }
                    @rmdir($dossierSource);
                }
                $succes = "📂 L'album a bien été déplacé vers l'année $nouvelleAnnee !";
            } elseif (preg_match('#^IMG/\d{4}$#', $dossierSource)) {
                $dossierCible = "IMG/$nouvelleAnnee";
                if (!is_dir($dossierCible)) {
                    mkdir($dossierCible, 0755, true);
                }
                foreach (scandir($dossierSource) as $f) {
                    if ($f !== '.' and $f !== '..' and is_file("$dossierSource/$f")) {
                        rename("$dossierSource/$f", "$dossierCible/$f");
                    }
                }
                $succes = "📂 Les photos ont bien été déplacées vers l'année $nouvelleAnnee !";
            }
        }
    }

    // C. Suppression d'une photo individuelle
    elseif ($action === 'supprimer_photo') {
        $photoCible = trim($_POST['photo_path'] ?? '');
        if (cheminPeleMeleValide($photoCible) and is_file($photoCible)) {
            supprimerFichierServeur($photoCible);
            $succes = "🗑️ La photo a été supprimée.";
        }
    }

    // D. Suppression d'un album complet
    elseif ($action === 'supprimer_album') {
        $dossierCible = trim($_POST['dossier_source'] ?? '');
        if (cheminPeleMeleValide($dossierCible) and is_dir($dossierCible)) {
            foreach (scandir($dossierCible) as $f) {
                if ($f !== '.' and $f !== '..' and is_file("$dossierCible/$f")) {
                    @unlink("$dossierCible/$f");
                }
            }
            if (!preg_match('#^IMG/\d{4}$#', $dossierCible)) {
                @rmdir($dossierCible);
            }
            $succes = "🗑️ L'album et ses photos ont été supprimés.";
        }
    }
}

// --- 2. RÉCUPÉRATION DES PHOTOS D'ARTICLES & PUBLICATIONS MEMBRES (UNIQUEMENT PUBLICS ET PUBLIÉS) ---
$albumsBddParAnnee = [];
$dossiersGeresBdd  = [];
$photosGereesBdd   = [];

function extrairePhotosEnregistrement($imagePrincipale, $galerieBrute) {
    $liste = [];
    if (!empty($imagePrincipale)) {
        $p = str_replace('\\', '/', trim($imagePrincipale));
        if ($p !== '') $liste[] = $p;
    }
    if (!empty($galerieBrute)) {
        foreach (preg_split('/[\r\n,]+/', $galerieBrute) as $p) {
            $p = str_replace('\\', '/', trim($p));
            if ($p !== '' and !in_array($p, $liste, true)) {
                $liste[] = $p;
            }
        }
    }
    return $liste;
}

// A. Parcours des articles d'actualités
$stmtArt = $pdo->query("SELECT id, titre, date_publication, image_principale, galerie_photos, visibilite, statut FROM articles ORDER BY date_publication DESC, id DESC");
foreach ($stmtArt->fetchAll() as $art) {
    $photosArt    = extrairePhotosEnregistrement($art['image_principale'], $art['galerie_photos']);
    $photosDisque = [];
    $dossierRef   = null;

    foreach ($photosArt as $cheminPhoto) {
        $photosGereesBdd[$cheminPhoto] = true;
        $dir = dirname($cheminPhoto);
        if ($dir !== '.' and $dir !== 'IMG') {
            $dossiersGeresBdd[$dir] = true;
            if ($dossierRef === null) $dossierRef = $dir;
        }
        if (is_file($cheminPhoto)) {
            $photosDisque[] = $cheminPhoto;
        }
    }

    if ($art['statut'] === 'publie' and $art['visibilite'] === 'public' and !empty($photosDisque)) {
        $anneeArt = date('Y', strtotime($art['date_publication']));
        if (preg_match('#^IMG/(\d{4})/#', $photosDisque[0], $mAnnee)) {
            $anneeArt = $mAnnee[1];
        }
        $albumsBddParAnnee[$anneeArt][] = [
            'titre'       => $art['titre'],
            'url_lecture' => 'article.php?id=' . (int)$art['id'],
            'dossier'     => $dossierRef,
            'photos'      => $photosDisque
        ];
    }
}

// B. Parcours des publications de l'Espace Membres
$stmtPubs = $pdo->query("SELECT id, categorie, titre, date_publication, image_principale, galerie_photos, visibilite, statut FROM membres_publications ORDER BY date_publication DESC, id DESC");
foreach ($stmtPubs->fetchAll() as $pub) {
    $photosPub    = extrairePhotosEnregistrement($pub['image_principale'], $pub['galerie_photos']);
    $photosDisque = [];
    $dossierRef   = null;

    foreach ($photosPub as $cheminPhoto) {
        $photosGereesBdd[$cheminPhoto] = true;
        $dir = dirname($cheminPhoto);
        if ($dir !== '.' and $dir !== 'IMG') {
            $dossiersGeresBdd[$dir] = true;
            if ($dossierRef === null) $dossierRef = $dir;
        }
        if (is_file($cheminPhoto)) {
            $photosDisque[] = $cheminPhoto;
        }
    }

    if ($pub['statut'] === 'publie' and ($pub['visibilite'] ?? 'public') === 'public' and !empty($photosDisque)) {
        $anneePub = date('Y', strtotime($pub['date_publication']));
        if (preg_match('#^IMG/(\d{4})/#', $photosDisque[0], $mAnnee)) {
            $anneePub = $mAnnee[1];
        }
        $albumsBddParAnnee[$anneePub][] = [
            'titre'       => $pub['titre'],
            'url_lecture' => 'membres.php?pub=' . (int)$pub['id'],
            'dossier'     => $dossierRef,
            'photos'      => $photosDisque
        ];
    }
}

// --- 3. EXPLORATION AUTOMATIQUE DES ALBUMS AUTONOMES DANS IMG/{YYYY} ---
function listerImagesDossierSimple($dir, $photosExclues = []) {
    if (!is_dir($dir)) return [];
    $imgs = [];
    $exts = ['jpg', 'jpeg', 'png', 'webp'];
    foreach (scandir($dir) as $f) {
        if ($f === '.' or $f === '..') continue;
        $chemin = str_replace('\\', '/', $dir . '/' . $f);
        if (isset($photosExclues[$chemin])) continue;
        if (is_file($chemin) and in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) {
            $imgs[] = $chemin;
        }
    }
    return $imgs;
}

function formaterTitreDossier($nomDossier) {
    return ucfirst(str_replace(['_', '-'], ' ', $nomDossier));
}

$peleMeleParAnnee  = [];
$anneesDisponibles = array_keys($albumsBddParAnnee);

if (is_dir('IMG')) {
    foreach (scandir('IMG') as $d) {
        if (preg_match('/^\d{4}$/', $d) and is_dir('IMG/' . $d)) {
            if (!in_array($d, $anneesDisponibles, true)) {
                $anneesDisponibles[] = $d;
            }
        }
    }
}

rsort($anneesDisponibles);

foreach ($anneesDisponibles as $annee) {
    $cheminAnnee      = 'IMG/' . $annee;
    $albumsDeLAnnee   = [];
    $totalPhotosAnnee = 0;

    if (!empty($albumsBddParAnnee[$annee])) {
        foreach ($albumsBddParAnnee[$annee] as $albumBdd) {
            $albumsDeLAnnee[]  = $albumBdd;
            $totalPhotosAnnee += count($albumBdd['photos']);
        }
    }

    if (is_dir($cheminAnnee)) {
        foreach (['articles', 'membres'] as $sousCat) {
            $cheminSousCat = $cheminAnnee . '/' . $sousCat;
            if (is_dir($cheminSousCat)) {
                foreach (scandir($cheminSousCat) as $sd) {
                    if ($sd === '.' or $sd === '..') continue;
                    $cheminCompletSd = $cheminSousCat . '/' . $sd;
                    if (is_dir($cheminCompletSd) and !isset($dossiersGeresBdd[$cheminCompletSd])) {
                        $photos = listerImagesDossierSimple($cheminCompletSd, $photosGereesBdd);
                        if (!empty($photos)) {
                            $albumsDeLAnnee[] = [
                                'titre'       => formaterTitreDossier($sd),
                                'url_lecture' => null,
                                'dossier'     => $cheminCompletSd,
                                'photos'      => $photos
                            ];
                            $totalPhotosAnnee += count($photos);
                        }
                    }
                }
            }
        }

        foreach (scandir($cheminAnnee) as $element) {
            if ($element === '.' or $element === '..' or $element === 'articles' or $element === 'membres') continue;
            $cheminElement = $cheminAnnee . '/' . $element;
            if (is_dir($cheminElement) and !isset($dossiersGeresBdd[$cheminElement])) {
                $photos = listerImagesDossierSimple($cheminElement, $photosGereesBdd);
                if (!empty($photos)) {
                    $albumsDeLAnnee[] = [
                        'titre'       => formaterTitreDossier($element),
                        'url_lecture' => null,
                        'dossier'     => $cheminElement,
                        'photos'      => $photos
                    ];
                    $totalPhotosAnnee += count($photos);
                }
            }
        }

        $photosBrutes = listerImagesDossierSimple($cheminAnnee, $photosGereesBdd);
        if (!empty($photosBrutes)) {
            $albumsDeLAnnee[] = [
                'titre'       => "Photos diverses de la saison $annee",
                'url_lecture' => null,
                'dossier'     => $cheminAnnee,
                'photos'      => $photosBrutes
            ];
            $totalPhotosAnnee += count($photosBrutes);
        }
    }

    if (!empty($albumsDeLAnnee)) {
        $peleMeleParAnnee[$annee] = [
            'total'  => $totalPhotosAnnee,
            'albums' => $albumsDeLAnnee
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Pêle-mêle photo de tous les événements et saisons des Kamikazes Agathois.">
    <title>Pêle-mêle Photos - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/admin.css?v=8">
    <link rel="stylesheet" href="CSS/pelemele.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <section class="hero pelemele-hero">
        <div class="hero-content">
            <h2>📸 Pêle-mêle Les Kamikazes Agathois</h2>
            <p>Voici les photos publiques des différents événements, voyages et réalisations du club, année par année.</p>
        </div>
    </section>

    <!-- BARRE DE NAVIGATION COLLANTE PAR ANNÉE -->
    <?php if (!empty($peleMeleParAnnee)): ?>
        <nav id="subnav" class="subnav">
            <ul>
                <?php $first = true; foreach (array_keys($peleMeleParAnnee) as $anneeNav): ?>
                    <li>
                        <a href="#annee-<?= htmlspecialchars($anneeNav) ?>" class="subnav-link <?= $first ? 'active' : '' ?>">
                            <?= htmlspecialchars($anneeNav) ?>
                        </a>
                    </li>
                <?php $first = false; endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <main>
        <!-- PANNEAU D'AJOUT RAPIDE DE PHOTOS (VISIBLE PAR ADMIN & BUREAU) -->
        <?php if ($peutAjouterPhotos): ?>
            <div class="admin-box admin-box-wide upload-panel">
                <h1>➕ Ajouter des photos au Pêle-mêle (sans créer d'article)</h1>

                <?php if ($erreur): ?>
                    <div class="alert-error"><?= htmlspecialchars($erreur) ?></div>
                <?php endif; ?>
                <?php if ($succes): ?>
                    <div class="alert-success"><?= htmlspecialchars($succes) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_pelemele">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="annee">Année de l'événement (4 chiffres) *</label>
                            <input type="text" id="annee" name="annee" required pattern="\d{4}" maxlength="4" placeholder="Tapez l'année (ex: 2025, 2026...)">
                            <span class="form-help">Obligatoire : indiquez l'année dans laquelle ranger ces photos.</span>
                        </div>

                        <div class="form-group">
                            <label for="nom_album">Titre de l'événement (Optionnel)</label>
                            <input type="text" id="nom_album" name="nom_album" placeholder="Ex: Rencontre amicale Agde 2026">
                            <span class="form-help">Laissez vide pour mettre les photos en vrac dans l'année.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="photos">Sélectionnez les photos à ajouter *</label>
                        <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
                        <span class="form-help">Redimensionnement automatique en 1080p et correction Portrait/Paysage incluse.</span>
                    </div>

                    <button type="submit" class="btn-primary btn-submit">📤 Envoyer dans le Pêle-mêle</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- AFFICHAGE DES GALERIES PAR ANNÉE -->
        <?php if (empty($peleMeleParAnnee)): ?>
            <div class="info-box" style="text-align:center;">
                <h3>Aucune photo publique dans les dossiers d'années pour le moment</h3>
                <p>Publiez un article public avec photos ou utilisez le formulaire ci-dessus pour alimenter le Pêle-mêle !</p>
            </div>
        <?php else: ?>
            <?php foreach ($peleMeleParAnnee as $annee => $dataAnnee): ?>
                <section id="annee-<?= htmlspecialchars($annee) ?>" class="year-section">
                    <div class="year-banner">
                        <span>📅 Saison <?= htmlspecialchars($annee) ?></span>
                        <span class="year-count"><?= (int)$dataAnnee['total'] ?> photo(s)</span>
                    </div>

                    <?php foreach ($dataAnnee['albums'] as $album): ?>
                        <div class="album-card">
                            <div class="album-header">
                                <h3><?= htmlspecialchars($album['titre']) ?></h3>

                                <div class="album-admin-tools">
                                    <?php if (!empty($album['url_lecture'])): ?>
                                        <a href="<?= htmlspecialchars($album['url_lecture']) ?>" class="album-link">
                                            📖 Lire la publication complète &rarr;
                                        </a>
                                    <?php elseif ($peutAjouterPhotos): ?>
                                        <form method="POST" class="album-admin-tools" onsubmit="return confirm('Déplacer cet album vers la nouvelle année indiquée ?');">
                                            <input type="hidden" name="action" value="deplacer_album">
                                            <input type="hidden" name="dossier_source" value="<?= htmlspecialchars($album['dossier']) ?>">
                                            <input type="text" name="nouvelle_annee" required pattern="\d{4}" maxlength="4" placeholder="Année" class="input-move-year" title="Entrez la bonne année (ex: 2026)">
                                            <button type="submit" class="btn-small" title="Déplacer vers cette année">📂 Déplacer</button>
                                        </form>

                                        <form method="POST" onsubmit="return confirm('Supprimer définitivement cet album et toutes ses photos ?');">
                                            <input type="hidden" name="action" value="supprimer_album">
                                            <input type="hidden" name="dossier_source" value="<?= htmlspecialchars($album['dossier']) ?>">
                                            <button type="submit" class="btn-small btn-small-danger" title="Supprimer tout l'album">🗑️ Suppr. album</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="pelemele-grid">
                                <?php foreach ($album['photos'] as $photoPath): ?>
                                    <div class="pelemele-item" onclick="ouvrirLightbox('<?= htmlspecialchars($photoPath, ENT_QUOTES) ?>')">
                                        <?php if ($peutAjouterPhotos and empty($album['url_lecture'])): ?>
                                            <form method="POST" class="pelemele-del-photo" onclick="event.stopPropagation();" onsubmit="return confirm('Supprimer cette photo ?');">
                                                <input type="hidden" name="action" value="supprimer_photo">
                                                <input type="hidden" name="photo_path" value="<?= htmlspecialchars($photoPath) ?>">
                                                <button type="submit" title="Supprimer cette photo">✖</button>
                                            </form>
                                        <?php endif; ?>
                                        <img src="<?= htmlspecialchars($photoPath) ?>" alt="<?= htmlspecialchars($album['titre']) ?>" loading="lazy">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
