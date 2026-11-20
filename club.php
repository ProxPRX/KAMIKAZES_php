<?php
// ==========================================================================
// PAGE PRÉSENTATION DU CLUB (AVEC MAINTENANCE SANS CODE POUR L'ADMIN) - KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

$estConnecte = isset($_SESSION['membre']);
$role        = $_SESSION['membre']['role'] ?? null;
$estAdmin    = ($role === 'admin');

$contenuSite = chargerContenuSite();
$clubData    = $contenuSite['club'];

$messageSucces = '';
$messageErreur = '';

// --- TRAITEMENT DES MODIFICATIONS ADMIN SANS TOUCHER AU CODE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' and $estAdmin and isset($_POST['action_cms_club'])) {
    $action = $_POST['action_cms_club'];

    // 1. Modifier la section Présentation ("Qui sommes-nous ?" avec nombre illimité de paragraphes)
    if ($action === 'modifier_presentation') {
        $titre              = trim($_POST['titre'] ?? 'Qui sommes-nous ?');
        $paragraphesSaisis  = $_POST['paragraphes'] ?? [];
        $paragraphesPropres = [];

        if (is_array($paragraphesSaisis)) {
            foreach ($paragraphesSaisis as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $paragraphesPropres[] = $p;
                }
            }
        }

        if (empty($paragraphesPropres)) {
            $messageErreur = "Il doit rester au minimum un paragraphe dans la présentation.";
        } else {
            $clubData['presentation']['titre']       = $titre !== '' ? $titre : 'Qui sommes-nous ?';
            $clubData['presentation']['paragraphes'] = $paragraphesPropres;
            unset($clubData['presentation']['paragraphe_1'], $clubData['presentation']['paragraphe_2']);
            $contenuSite['club'] = $clubData;
            sauvegarderContenuSite($contenuSite);
            $messageSucces = "✅ La présentation du club (" . count($paragraphesPropres) . " paragraphe(s)) a bien été mise à jour.";
        }
    }

    // 2. Modifier ou Ajouter un Terrain ou un Gymnase / Salle Indoor
    elseif (in_array($action, ['sauvegarder_terrain', 'sauvegarder_gymnase'], true)) {
        $cleListe   = ($action === 'sauvegarder_terrain') ? 'terrains' : 'gymnases';
        $idElement  = trim($_POST['element_id'] ?? '');
        $titre      = trim($_POST['titre'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $pointsBrut = trim($_POST['points'] ?? '');
        $gpsNord    = trim($_POST['gps_nord'] ?? '');
        $gpsEst     = trim($_POST['gps_est'] ?? '');
        $mapsEmbed  = formaterUrlIframeMaps($_POST['maps_embed'] ?? '');
        $mapsLien   = trim($_POST['maps_lien'] ?? '');
        $pdfLibelle = trim($_POST['pdf_libelle'] ?? 'Télécharger le document (PDF)');

        $points = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $pointsBrut))));

        if ($titre === '' or $desc === '') {
            $messageErreur = "Veuillez renseigner au minimum le titre et la description.";
        } else {
            $indexTrouve = null;
            foreach ($clubData[$cleListe] as $idx => $item) {
                if (($item['id'] ?? '') === $idElement) {
                    $indexTrouve = $idx;
                    break;
                }
            }

            $pdfActuel = ($indexTrouve !== null) ? ($clubData[$cleListe][$indexTrouve]['pdf_fichier'] ?? '') : '';

            if (!empty($_POST['supprimer_pdf']) and !empty($pdfActuel)) {
                supprimerFichierServeur($pdfActuel);
                $pdfActuel = '';
            }

            if (!empty($_FILES['nouveau_pdf']['name']) and $_FILES['nouveau_pdf']['error'] === UPLOAD_ERR_OK) {
                $uploadPdf = enregistrerPdfArticle($_FILES['nouveau_pdf']['tmp_name'], $_FILES['nouveau_pdf']['name'], 'DOCS/club');
                if ($uploadPdf) {
                    if (!empty($pdfActuel) and $pdfActuel !== $uploadPdf) {
                        supprimerFichierServeur($pdfActuel);
                    }
                    $pdfActuel = $uploadPdf;
                } else {
                    $messageErreur = "Le fichier envoyé n'est pas un PDF valide.";
                }
            }

            if ($messageErreur === '') {
                $nouvelObjet = [
                    'id'          => $idElement !== '' ? $idElement : uniqid($cleListe . '_'),
                    'titre'       => $titre,
                    'description' => $desc,
                    'points'      => $points,
                    'gps_nord'    => $gpsNord,
                    'gps_est'     => $gpsEst,
                    'maps_embed'  => $mapsEmbed,
                    'maps_lien'   => $mapsLien,
                    'pdf_fichier' => $pdfActuel,
                    'pdf_libelle' => $pdfLibelle
                ];

                if ($indexTrouve !== null) {
                    $clubData[$cleListe][$indexTrouve] = $nouvelObjet;
                    $messageSucces = "✅ Les informations de « $titre » ont bien été mises à jour.";
                } else {
                    $clubData[$cleListe][] = $nouvelObjet;
                    $messageSucces = "✅ La nouvelle section « $titre » a bien été ajoutée.";
                }

                $contenuSite['club'] = $clubData;
                sauvegarderContenuSite($contenuSite);
            }
        }
    }

    // 3. Supprimer un Terrain ou un Gymnase
    elseif (in_array($action, ['supprimer_terrain', 'supprimer_gymnase'], true)) {
        $cleListe  = ($action === 'supprimer_terrain') ? 'terrains' : 'gymnases';
        $idElement = trim($_POST['element_id'] ?? '');

        if (count($clubData[$cleListe]) <= 1) {
            $messageErreur = "Il doit rester au minimum un élément dans cette section.";
        } else {
            foreach ($clubData[$cleListe] as $idx => $item) {
                if (($item['id'] ?? '') === $idElement) {
                    if (!empty($item['pdf_fichier'])) {
                        supprimerFichierServeur($item['pdf_fichier']);
                    }
                    unset($clubData[$cleListe][$idx]);
                    $clubData[$cleListe] = array_values($clubData[$cleListe]);
                    $contenuSite['club'] = $clubData;
                    sauvegarderContenuSite($contenuSite);
                    $messageSucces = "🗑️ La section a bien été supprimée.";
                    break;
                }
            }
        }
    }

    // 4. Modifier l'Association (paragraphes multiples) & le Bureau Directeur (lignes dynamiques)
    elseif ($action === 'modifier_association') {
        $paragraphesLegal = [];
        if (!empty($_POST['paragraphes']) and is_array($_POST['paragraphes'])) {
            foreach ($_POST['paragraphes'] as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $paragraphesLegal[] = $p;
                }
            }
        }
        if (!empty($paragraphesLegal)) {
            $clubData['association']['paragraphes_legal'] = $paragraphesLegal;
            unset($clubData['association']['texte_legal']);
        }

        $postes = $_POST['bureau_poste'] ?? [];
        $noms   = $_POST['bureau_nom'] ?? [];
        $emails = $_POST['bureau_email'] ?? [];
        $nouveauBureau = [];

        if (is_array($postes)) {
            foreach ($postes as $i => $poste) {
                $poste = trim((string)$poste);
                $nom   = trim((string)($noms[$i] ?? ''));
                $email = trim((string)($emails[$i] ?? ''));
                if ($poste !== '' and $nom !== '') {
                    $nouveauBureau[] = [
                        'poste' => $poste,
                        'nom'   => $nom,
                        'email' => $email
                    ];
                }
            }
        }

        if (!empty($nouveauBureau)) {
            $clubData['association']['bureau'] = $nouveauBureau;
        }
        $contenuSite['club'] = $clubData;
        sauvegarderContenuSite($contenuSite);
        $messageSucces = "✅ Les informations de l'Association et du Bureau ont été mises à jour.";
    }

    // 5. Ajouter ou Remplacer un Document PDF officiel (Statuts, Règlement intérieur...)
    elseif ($action === 'sauvegarder_doc_officiel') {
        $docId    = trim($_POST['doc_id'] ?? '');
        $docTitre = trim($_POST['doc_titre'] ?? '');

        if ($docTitre === '') {
            $messageErreur = "Veuillez indiquer le titre du document.";
        } else {
            $indexDoc = null;
            foreach ($clubData['association']['documents'] as $idx => $d) {
                if (($d['id'] ?? '') === $docId) {
                    $indexDoc = $idx;
                    break;
                }
            }

            $cheminFichier = ($indexDoc !== null) ? ($clubData['association']['documents'][$indexDoc]['fichier'] ?? '') : '';

            if (!empty($_FILES['fichier_pdf']['name']) and $_FILES['fichier_pdf']['error'] === UPLOAD_ERR_OK) {
                $uploadPdf = enregistrerPdfArticle($_FILES['fichier_pdf']['tmp_name'], $_FILES['fichier_pdf']['name'], 'DOCS/club');
                if ($uploadPdf) {
                    if (!empty($cheminFichier) and $cheminFichier !== $uploadPdf) {
                        supprimerFichierServeur($cheminFichier);
                    }
                    $cheminFichier = $uploadPdf;
                } else {
                    $messageErreur = "Veuillez sélectionner un fichier au format PDF valide.";
                }
            }

            if ($messageErreur === '') {
                if ($cheminFichier === '') {
                    $messageErreur = "Veuillez joindre un fichier PDF.";
                } else {
                    $docObjet = [
                        'id'      => $docId !== '' ? $docId : uniqid('doc_'),
                        'titre'   => $docTitre,
                        'fichier' => $cheminFichier
                    ];
                    if ($indexDoc !== null) {
                        $clubData['association']['documents'][$indexDoc] = $docObjet;
                        $messageSucces = "🔄 Le document « $docTitre » a bien été mis à jour.";
                    } else {
                        $clubData['association']['documents'][] = $docObjet;
                        $messageSucces = "✅ Le document « $docTitre » a bien été ajouté.";
                    }
                    $contenuSite['club'] = $clubData;
                    sauvegarderContenuSite($contenuSite);
                }
            }
        }
    }

    // 6. Supprimer un Document PDF officiel
    elseif ($action === 'supprimer_doc_officiel') {
        $docId = trim($_POST['doc_id'] ?? '');
        foreach ($clubData['association']['documents'] as $idx => $d) {
            if (($d['id'] ?? '') === $docId) {
                if (!empty($d['fichier'])) {
                    supprimerFichierServeur($d['fichier']);
                }
                unset($clubData['association']['documents'][$idx]);
                $clubData['association']['documents'] = array_values($clubData['association']['documents']);
                $contenuSite['club'] = $clubData;
                sauvegarderContenuSite($contenuSite);
                $messageSucces = "🗑️ Le document officiel a été supprimé.";
                break;
            }
        }
    }

    // 7. Modifier la section Inscription & Tarifs Yapla (avec paragraphes multiples)
    elseif ($action === 'modifier_inscription') {
        $clubData['inscription']['titre']         = trim($_POST['titre'] ?? 'Rejoindre les Kamikazes Agathois');
        $clubData['inscription']['titre_tarif']   = trim($_POST['titre_tarif'] ?? '');
        $clubData['inscription']['yapla_url']     = trim($_POST['yapla_url'] ?? '');
        $clubData['inscription']['email_contact'] = trim($_POST['email_contact'] ?? 'kamikazes34300@gmail.com');

        $paragraphesIntro = [];
        if (!empty($_POST['paragraphes']) and is_array($_POST['paragraphes'])) {
            foreach ($_POST['paragraphes'] as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $paragraphesIntro[] = $p;
                }
            }
        }
        if (!empty($paragraphesIntro)) {
            $clubData['inscription']['paragraphes_intro'] = $paragraphesIntro;
            unset($clubData['inscription']['texte_intro']);
        }

        $lignesTarif = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $_POST['lignes_tarif'] ?? ''))));
        if (!empty($lignesTarif)) {
            $clubData['inscription']['lignes_tarif'] = $lignesTarif;
        }

        $contenuSite['club'] = $clubData;
        sauvegarderContenuSite($contenuSite);
        $messageSucces = "✅ La section Inscription & Tarifs a bien été mise à jour.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Découvrez Les Kamikazes Agathois, notre terrain d'aéromodélisme à Agde, nos salles indoor, l'association et comment nous rejoindre.">
    <title>Le Club - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=7">
    <link rel="stylesheet" href="CSS/club.css?v=7">
</head>
<body>

    <?php include 'header.php'; ?>

    <!-- BANNIÈRE DE PRÉSENTATION DU CLUB -->
    <section class="hero club-hero">
        <div class="hero-content">
            <h2>À propos des Kamikazes Agathois</h2>
            <p>L'association, nos installations à Agde et comment nous rejoindre.</p>
        </div>
    </section>

    <!-- SOUS-MENU COLLANT DE NAVIGATION RAPIDE (GÉRÉ PAR JS/main.js) -->
    <nav id="subnav" class="subnav">
        <ul>
            <li><a href="#presentation" class="subnav-link active">Présentation</a></li>
            <li><a href="#terrain" class="subnav-link"><?= count($clubData['terrains']) > 1 ? 'Terrains' : 'Terrain' ?></a></li>
            <li><a href="#gymnase" class="subnav-link"><?= count($clubData['gymnases']) > 1 ? 'Vol Indoor' : 'Vol Indoor' ?></a></li>
            <li><a href="#association" class="subnav-link">L'Association</a></li>
            <li><a href="#inscription" class="subnav-link">Inscription</a></li>
        </ul>
    </nav>

    <main>
        <?php if ($messageSucces): ?>
            <div class="cms-feedback cms-feedback-success"><?= htmlspecialchars($messageSucces) ?></div>
        <?php endif; ?>

        <?php if ($messageErreur): ?>
            <div class="cms-feedback cms-feedback-error"><?= htmlspecialchars($messageErreur) ?></div>
        <?php endif; ?>

        <!-- ==================================================================
             SECTION 1 : PRÉSENTATION GÉNÉRALE (PARAGRAPHES DYNAMIQUES ILLIMITÉS)
             ================================================================== -->
        <section id="presentation" class="club-section">
            <h2><?= htmlspecialchars($clubData['presentation']['titre']) ?></h2>
            <?php foreach ($clubData['presentation']['paragraphes'] as $idxP => $texteParagraphe): ?>
                <p style="<?= $idxP > 0 ? 'margin-top: 12px;' : '' ?>">
                    <?= nl2br(htmlspecialchars($texteParagraphe)) ?>
                </p>
            <?php endforeach; ?>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details">
                    <summary class="cms-admin-summary">✏️ Modifier la présentation ou ajouter un paragraphe (Admin)</summary>
                    <form method="POST" action="club.php#presentation" class="cms-admin-form">
                        <input type="hidden" name="action_cms_club" value="modifier_presentation">
                        <div class="cms-field">
                            <label>Titre de la section *</label>
                            <input type="text" name="titre" value="<?= htmlspecialchars($clubData['presentation']['titre']) ?>" required>
                        </div>

                        <div id="container-paragraphes-presentation" style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($clubData['presentation']['paragraphes'] as $numP => $texteP): ?>
                                <div class="cms-field cms-paragraph-item" style="background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:6px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <label style="margin:0;">Paragraphe <?= (int)$numP + 1 ?></label>
                                        <?php if ($numP > 0): ?>
                                            <button type="button" class="btn-cms-delete" style="padding:4px 10px; font-size:0.78rem;" onclick="this.closest('.cms-paragraph-item').remove()">🗑️ Supprimer ce paragraphe</button>
                                        <?php endif; ?>
                                    </div>
                                    <textarea name="paragraphes[]" rows="3" required><?= htmlspecialchars($texteP) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="cms-actions">
                            <button type="button" class="btn-secondary btn-add-paragraph" data-target="container-paragraphes-presentation" style="margin-top:0; padding:8px 15px; font-size:0.88rem; border:none; cursor:pointer;">
                                ➕ Ajouter un paragraphe
                            </button>
                            <button type="submit" class="btn-cms-save">💾 Enregistrer la présentation</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- ==================================================================
             SECTION 2 : TERRAIN(S) D'ÉVOLUTION EXTÉRIEUR
             ================================================================== -->
        <section id="terrain" class="club-section">
            <?php foreach ($clubData['terrains'] as $indexT => $terrain): ?>
                <div class="installation-block" style="<?= $indexT > 0 ? 'margin-top: 45px;' : '' ?>">
                    <h2><?= htmlspecialchars($terrain['titre']) ?></h2>
                    <div class="dashboard">
                        <div class="info-box">
                            <h3 class="info-box-title-red">🌳 Informations Pratiques</h3>
                            <p><?= nl2br(htmlspecialchars($terrain['description'])) ?></p>
                            <?php if (!empty($terrain['points'])): ?>
                                <ul>
                                    <?php foreach ($terrain['points'] as $pt): ?>
                                        <li>
                                            <?php
                                                if (strpos($pt, ':') !== false) {
                                                    list($labelPt, $restePt) = explode(':', $pt, 2);
                                                    echo '<strong>' . htmlspecialchars($labelPt) . ' :</strong>' . htmlspecialchars($restePt);
                                                } else {
                                                    echo htmlspecialchars($pt);
                                                }
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="info-box">
                            <h3 class="info-box-title-red">📍 Localisation</h3>
                            <?php if (!empty($terrain['gps_nord']) or !empty($terrain['gps_est'])): ?>
                                <ul style="list-style-type: none; margin-bottom: 15px; margin-left: 0;">
                                    <?php if (!empty($terrain['gps_nord'])): ?>
                                        <li><strong>GPS Nord :</strong> <?= htmlspecialchars($terrain['gps_nord']) ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($terrain['gps_est'])): ?>
                                        <li><strong>GPS Est :</strong> <?= htmlspecialchars($terrain['gps_est']) ?></li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (!empty($terrain['maps_embed'])): ?>
                                <iframe src="<?= htmlspecialchars($terrain['maps_embed']) ?>" width="100%" height="250" style="border:0; border-radius: 8px; margin-bottom: 15px;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?= htmlspecialchars($terrain['titre']) ?>"></iframe>
                            <?php endif; ?>

                            <?php if (!empty($terrain['maps_lien'])): ?>
                                <a href="<?= htmlspecialchars($terrain['maps_lien']) ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary" style="display: block; text-align: center; margin-bottom: 10px;">Voir sur Google Maps</a>
                            <?php endif; ?>

                            <?php if (!empty($terrain['pdf_fichier'])): ?>
                                <a href="<?= htmlspecialchars($terrain['pdf_fichier']) ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary" style="background-color: #666; display: block; text-align: center; margin-top: 0;">
                                    <?= htmlspecialchars(!empty($terrain['pdf_libelle']) ? $terrain['pdf_libelle'] : 'Télécharger le plan de vol (PDF)') ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($estAdmin): ?>
                        <details class="cms-admin-details">
                            <summary class="cms-admin-summary">✏️ Modifier « <?= htmlspecialchars($terrain['titre']) ?> » ou changer son PDF (Admin)</summary>
                            <form method="POST" action="club.php#terrain" enctype="multipart/form-data" class="cms-admin-form">
                                <input type="hidden" name="action_cms_club" value="sauvegarder_terrain">
                                <input type="hidden" name="element_id" value="<?= htmlspecialchars($terrain['id']) ?>">

                                <div class="cms-field">
                                    <label>Titre du terrain *</label>
                                    <input type="text" name="titre" value="<?= htmlspecialchars($terrain['titre']) ?>" required>
                                </div>
                                <div class="cms-field">
                                    <label>Description *</label>
                                    <textarea name="description" rows="2" required><?= htmlspecialchars($terrain['description']) ?></textarea>
                                </div>
                                <div class="cms-field">
                                    <label>Liste des points pratiques (1 point par ligne)</label>
                                    <textarea name="points" rows="4"><?= htmlspecialchars(implode("\n", $terrain['points'] ?? [])) ?></textarea>
                                    <span class="cms-help">Astuce : Ce qui est placé avant les deux-points « : » (ex: <code>Pistes : 2 pistes goudronnées</code>) s'affichera automatiquement en gras.</span>
                                </div>
                                <div class="cms-form-row">
                                    <div class="cms-field">
                                        <label>GPS Nord</label>
                                        <input type="text" name="gps_nord" value="<?= htmlspecialchars($terrain['gps_nord'] ?? '') ?>">
                                    </div>
                                    <div class="cms-field">
                                        <label>GPS Est</label>
                                        <input type="text" name="gps_est" value="<?= htmlspecialchars($terrain['gps_est'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="cms-form-row">
                                    <div class="cms-field">
                                        <label>Carte Google Maps (Adresse, coordonnées ou URL iframe)</label>
                                        <input type="text" name="maps_embed" value="<?= htmlspecialchars($terrain['maps_embed'] ?? '') ?>">
                                    </div>
                                    <div class="cms-field">
                                        <label>Lien du bouton « Voir sur Google Maps »</label>
                                        <input type="url" name="maps_lien" value="<?= htmlspecialchars($terrain['maps_lien'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="cms-form-row">
                                    <div class="cms-field">
                                        <label>Remplacer / Joindre un PDF (ex: Plan de vol)</label>
                                        <input type="file" name="nouveau_pdf" accept="application/pdf,.pdf">
                                        <?php if (!empty($terrain['pdf_fichier'])): ?>
                                            <label style="font-weight:normal; margin-top:6px; cursor:pointer;">
                                                <input type="checkbox" name="supprimer_pdf" value="1"> Supprimer le PDF actuel (<code><?= htmlspecialchars(basename($terrain['pdf_fichier'])) ?></code>)
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cms-field">
                                        <label>Texte du bouton PDF</label>
                                        <input type="text" name="pdf_libelle" value="<?= htmlspecialchars($terrain['pdf_libelle'] ?? 'Télécharger le plan de vol (PDF)') ?>">
                                    </div>
                                </div>
                                <div class="cms-actions">
                                    <button type="submit" class="btn-cms-save">💾 Enregistrer les modifications</button>
                                </div>
                            </form>

                            <?php if (count($clubData['terrains']) > 1): ?>
                                <form method="POST" action="club.php#terrain" style="padding: 0 20px 20px 20px;" onsubmit="return confirm('Supprimer définitivement ce terrain ?');">
                                    <input type="hidden" name="action_cms_club" value="supprimer_terrain">
                                    <input type="hidden" name="element_id" value="<?= htmlspecialchars($terrain['id']) ?>">
                                    <button type="submit" class="btn-cms-delete">🗑️ Supprimer ce terrain</button>
                                </form>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details cms-add-block">
                    <summary class="cms-admin-summary">➕ Ajouter un autre terrain d'évolution extérieur (Admin)</summary>
                    <form method="POST" action="club.php#terrain" enctype="multipart/form-data" class="cms-admin-form">
                        <input type="hidden" name="action_cms_club" value="sauvegarder_terrain">
                        <div class="cms-field">
                            <label>Titre du nouveau terrain *</label>
                            <input type="text" name="titre" required placeholder="Ex: Second terrain d'évolution">
                        </div>
                        <div class="cms-field">
                            <label>Description *</label>
                            <textarea name="description" rows="2" required placeholder="Situation et présentation du terrain..."></textarea>
                        </div>
                        <div class="cms-field">
                            <label>Liste des points pratiques (1 point par ligne)</label>
                            <textarea name="points" rows="4" placeholder="Pistes : 2 pistes goudronnées&#10;Accès : Direction Marseillan"></textarea>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>GPS Nord (Optionnel)</label>
                                <input type="text" name="gps_nord" placeholder='Ex: 43°19&#39;...'>
                            </div>
                            <div class="cms-field">
                                <label>GPS Est (Optionnel)</label>
                                <input type="text" name="gps_est" placeholder='Ex: 003°29&#39;...'>
                            </div>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Adresse ou coordonnées pour la carte Google Maps</label>
                                <input type="text" name="maps_embed" placeholder="Ex: Terrain aéromodélisme Agde">
                            </div>
                            <div class="cms-field">
                                <label>Lien Google Maps (bouton)</label>
                                <input type="url" name="maps_lien" placeholder="https://www.google.com/maps/...">
                            </div>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Document PDF joint (Optionnel)</label>
                                <input type="file" name="nouveau_pdf" accept="application/pdf,.pdf">
                            </div>
                            <div class="cms-field">
                                <label>Texte du bouton PDF</label>
                                <input type="text" name="pdf_libelle" value="Télécharger le plan de vol (PDF)">
                            </div>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">➕ Créer ce terrain</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- ==================================================================
             SECTION 3 : SALLE(S) DE VOL INDOOR (SALLES MOLINIÉ / CHARRIN...)
             ================================================================== -->
        <section id="gymnase" class="club-section">
            <?php foreach ($clubData['gymnases'] as $indexG => $gymnase): ?>
                <div class="installation-block" style="<?= $indexG > 0 ? 'margin-top: 45px;' : '' ?>">
                    <h2><?= htmlspecialchars($gymnase['titre']) ?></h2>
                    <div class="dashboard">
                        <div class="info-box">
                            <h3 class="info-box-title-red">🏛️ Équipements & Accès</h3>
                            <p><?= nl2br(htmlspecialchars($gymnase['description'])) ?></p>
                            <?php if (!empty($gymnase['points'])): ?>
                                <ul>
                                    <?php foreach ($gymnase['points'] as $pt): ?>
                                        <li>
                                            <?php
                                                if (strpos($pt, ':') !== false) {
                                                    list($labelPt, $restePt) = explode(':', $pt, 2);
                                                    echo '<strong>' . htmlspecialchars($labelPt) . ' :</strong>' . htmlspecialchars($restePt);
                                                } else {
                                                    echo '<em>' . htmlspecialchars($pt) . '</em>';
                                                }
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="info-box">
                            <h3 class="info-box-title-red">📍 Localisation</h3>
                            <?php if (!empty($gymnase['gps_nord']) or !empty($gymnase['gps_est'])): ?>
                                <ul style="list-style-type: none; margin-bottom: 15px; margin-left: 0;">
                                    <?php if (!empty($gymnase['gps_nord'])): ?>
                                        <li><strong>GPS Nord :</strong> <?= htmlspecialchars($gymnase['gps_nord']) ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($gymnase['gps_est'])): ?>
                                        <li><strong>GPS Est :</strong> <?= htmlspecialchars($gymnase['gps_est']) ?></li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (!empty($gymnase['maps_embed'])): ?>
                                <iframe src="<?= htmlspecialchars($gymnase['maps_embed']) ?>" width="100%" height="250" style="border:0; border-radius: 8px; margin-bottom: 15px;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?= htmlspecialchars($gymnase['titre']) ?>"></iframe>
                            <?php endif; ?>

                            <?php if (!empty($gymnase['maps_lien'])): ?>
                                <a href="<?= htmlspecialchars($gymnase['maps_lien']) ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary" style="display: block; text-align: center; margin-top: 0; margin-bottom: 10px;">Voir sur Google Maps</a>
                            <?php endif; ?>

                            <?php if (!empty($gymnase['pdf_fichier'])): ?>
                                <a href="<?= htmlspecialchars($gymnase['pdf_fichier']) ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary" style="background-color: #666; display: block; text-align: center; margin-top: 0;">
                                    <?= htmlspecialchars(!empty($gymnase['pdf_libelle']) ? $gymnase['pdf_libelle'] : 'Télécharger le document (PDF)') ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($estAdmin): ?>
                        <details class="cms-admin-details">
                            <summary class="cms-admin-summary">✏️ Modifier « <?= htmlspecialchars($gymnase['titre']) ?> » (Horaires, accès, carte...) (Admin)</summary>
                            <form method="POST" action="club.php#gymnase" enctype="multipart/form-data" class="cms-admin-form">
                                <input type="hidden" name="action_cms_club" value="sauvegarder_gymnase">
                                <input type="hidden" name="element_id" value="<?= htmlspecialchars($gymnase['id']) ?>">

                                <div class="cms-field">
                                    <label>Nom du gymnase / de la salle *</label>
                                    <input type="text" name="titre" value="<?= htmlspecialchars($gymnase['titre']) ?>" required>
                                </div>
                                <div class="cms-field">
                                    <label>Adresse / Description *</label>
                                    <textarea name="description" rows="2" required><?= htmlspecialchars($gymnase['description']) ?></textarea>
                                </div>
                                <div class="cms-field">
                                    <label>Horaires, surface & équipements (1 ligne par point)</label>
                                    <textarea name="points" rows="5"><?= htmlspecialchars(implode("\n", $gymnase['points'] ?? [])) ?></textarea>
                                    <span class="cms-help">Ex: <code>Adresse : 7 Avenue Jean Moulin, 34300 Agde.</code></span>
                                </div>
                                <div class="cms-form-row">
                                    <div class="cms-field">
                                        <label>GPS Nord</label>
                                        <input type="text" name="gps_nord" value="<?= htmlspecialchars($gymnase['gps_nord'] ?? '') ?>">
                                    </div>
                                    <div class="cms-field">
                                        <label>GPS Est</label>
                                        <input type="text" name="gps_est" value="<?= htmlspecialchars($gymnase['gps_est'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="cms-form-row">
                                    <div class="cms-field">
                                        <label>Carte Google Maps (Adresse, coordonnées ou URL iframe)</label>
                                        <input type="text" name="maps_embed" value="<?= htmlspecialchars($gymnase['maps_embed'] ?? '') ?>">
                                    </div>
                                    <div class="cms-field">
                                        <label>Lien du bouton « Voir sur Google Maps »</label>
                                        <input type="url" name="maps_lien" value="<?= htmlspecialchars($gymnase['maps_lien'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="cms-form-row">
                                    <div class="cms-field">
                                        <label>Joindre / Remplacer un PDF (Optionnel)</label>
                                        <input type="file" name="nouveau_pdf" accept="application/pdf,.pdf">
                                        <?php if (!empty($gymnase['pdf_fichier'])): ?>
                                            <label style="font-weight:normal; margin-top:6px; cursor:pointer;">
                                                <input type="checkbox" name="supprimer_pdf" value="1"> Supprimer le PDF actuel
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cms-field">
                                        <label>Texte du bouton PDF</label>
                                        <input type="text" name="pdf_libelle" value="<?= htmlspecialchars($gymnase['pdf_libelle'] ?? 'Télécharger le document (PDF)') ?>">
                                    </div>
                                </div>
                                <div class="cms-actions">
                                    <button type="submit" class="btn-cms-save">💾 Enregistrer cette salle</button>
                                </div>
                            </form>

                            <?php if (count($clubData['gymnases']) > 1): ?>
                                <form method="POST" action="club.php#gymnase" style="padding: 0 20px 20px 20px;" onsubmit="return confirm('Supprimer définitivement cette salle ?');">
                                    <input type="hidden" name="action_cms_club" value="supprimer_gymnase">
                                    <input type="hidden" name="element_id" value="<?= htmlspecialchars($gymnase['id']) ?>">
                                    <button type="submit" class="btn-cms-delete">🗑️ Supprimer cette salle</button>
                                </form>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details cms-add-block">
                    <summary class="cms-admin-summary">➕ Ajouter une nouvelle salle de vol indoor (Admin)</summary>
                    <form method="POST" action="club.php#gymnase" enctype="multipart/form-data" class="cms-admin-form">
                        <input type="hidden" name="action_cms_club" value="sauvegarder_gymnase">
                        <div class="cms-field">
                            <label>Nom de la salle / du gymnase *</label>
                            <input type="text" name="titre" required placeholder="Ex: Salles Molinié / Charrin (Indoor)">
                        </div>
                        <div class="cms-field">
                            <label>Adresse / Présentation *</label>
                            <textarea name="description" rows="2" required placeholder="Ex: Situé au 7 Avenue Jean Moulin à Agde."></textarea>
                        </div>
                        <div class="cms-field">
                            <label>Horaires, surface & équipements (1 ligne par point)</label>
                            <textarea name="points" rows="4" placeholder="Adresse : 7 Avenue Jean Moulin, 34300 Agde.&#10;Modèles : Modèles indoor légers, micro-hélicos et petits multirotors."></textarea>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>GPS Nord (Optionnel)</label>
                                <input type="text" name="gps_nord" placeholder='Ex: 43°18&#39;...'>
                            </div>
                            <div class="cms-field">
                                <label>GPS Est (Optionnel)</label>
                                <input type="text" name="gps_est" placeholder='Ex: 03°28&#39;...'>
                            </div>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Adresse ou coordonnées pour la carte Google Maps</label>
                                <input type="text" name="maps_embed" placeholder="Ex: 7 Avenue Jean Moulin, 34300 Agde">
                                <span class="cms-help">Tapez simplement le nom de la salle et la ville : la carte Google Maps se créera toute seule !</span>
                            </div>
                            <div class="cms-field">
                                <label>Lien Google Maps (bouton)</label>
                                <input type="url" name="maps_lien" placeholder="https://www.google.com/maps/...">
                            </div>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">➕ Ajouter cette salle</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- ==================================================================
             SECTION 4 : BUREAU DIRECTEUR & DOCUMENTS OFFICIELS (PDF)
             ================================================================== -->
        <section id="association" class="club-section">
            <h2>L'Association</h2>
            <?php foreach ($clubData['association']['paragraphes_legal'] as $idxLeg => $texteLeg): ?>
                <p style="<?= $idxLeg > 0 ? 'margin-top: 10px;' : '' ?>">
                    <?= nl2br(htmlspecialchars($texteLeg)) ?>
                </p>
            <?php endforeach; ?>

            <h3 class="subtitle">Le Bureau</h3>
            <ul class="club-list">
                <?php foreach ($clubData['association']['bureau'] as $membreBureau): ?>
                    <li>
                        <strong><?= htmlspecialchars($membreBureau['poste']) ?> :</strong>
                        <?= htmlspecialchars($membreBureau['nom']) ?>
                        <?php if (!empty($membreBureau['email'])): ?>
                            (<a href="mailto:<?= htmlspecialchars($membreBureau['email']) ?>"><?= htmlspecialchars($membreBureau['email']) ?></a>)
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details">
                    <summary class="cms-admin-summary">✏️ Modifier le texte de l'Association ou le Bureau (Admin)</summary>
                    <form method="POST" action="club.php#association" class="cms-admin-form">
                        <input type="hidden" name="action_cms_club" value="modifier_association">

                        <div id="container-paragraphes-legal" style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($clubData['association']['paragraphes_legal'] as $numL => $texteL): ?>
                                <div class="cms-field cms-paragraph-item" style="background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:6px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <label style="margin:0;">Paragraphe de présentation <?= (int)$numL + 1 ?></label>
                                        <?php if ($numL > 0): ?>
                                            <button type="button" class="btn-cms-delete" style="padding:4px 10px; font-size:0.78rem;" onclick="this.closest('.cms-paragraph-item').remove()">🗑️ Supprimer ce paragraphe</button>
                                        <?php endif; ?>
                                    </div>
                                    <textarea name="paragraphes[]" rows="2" required><?= htmlspecialchars($texteL) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div>
                            <button type="button" class="btn-secondary btn-add-paragraph" data-target="container-paragraphes-legal" style="margin-top:0; padding:7px 14px; font-size:0.85rem; border:none; cursor:pointer;">
                                ➕ Ajouter un paragraphe à l'Association
                            </button>
                        </div>

                        <div class="cms-field" style="margin-top: 10px;">
                            <label>Membres du Bureau (Poste, Prénom NOM et E-mail optionnel)</label>
                            <div id="container-membres-bureau">
                                <?php foreach ($clubData['association']['bureau'] as $idxB => $mb): ?>
                                    <div class="cms-bureau-row" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:8px;">
                                        <input type="text" name="bureau_poste[]" value="<?= htmlspecialchars($mb['poste']) ?>" required placeholder="Poste" style="flex:1; min-width:160px;">
                                        <input type="text" name="bureau_nom[]" value="<?= htmlspecialchars($mb['nom']) ?>" required placeholder="Prénom NOM" style="flex:1; min-width:160px;">
                                        <input type="email" name="bureau_email[]" value="<?= htmlspecialchars($mb['email'] ?? '') ?>" placeholder="E-mail (optionnel)" style="flex:1; min-width:180px;">
                                        <?php if ($idxB > 0): ?>
                                            <button type="button" class="btn-cms-delete" style="padding:8px 12px;" onclick="this.parentElement.remove()" title="Retirer ce poste">✖</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="btnAddMembreBureau" class="btn-secondary" style="margin-top:5px; padding:7px 14px; font-size:0.85rem; border:none; cursor:pointer;">
                                ➕ Ajouter un membre au Bureau
                            </button>
                        </div>

                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">💾 Enregistrer l'Association & le Bureau</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>

            <h3 class="subtitle">Documents Utiles</h3>
            <ul class="club-list">
                <?php foreach ($clubData['association']['documents'] as $doc): ?>
                    <li style="margin-bottom: 8px;">
                        <a href="<?= htmlspecialchars($doc['fichier']) ?>" target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars($doc['titre']) ?>
                        </a>

                        <?php if ($estAdmin): ?>
                            <details style="display: inline-block; margin-left: 10px; vertical-align: middle;">
                                <summary style="cursor: pointer; font-size: 0.8rem; color: var(--smac-bleu-fonce); font-weight: bold;">⚙️ Remplacer / Suppr. ce PDF</summary>
                                <div style="background: #fff; border: 1px solid var(--smac-gris-bordure); padding: 12px; border-radius: 6px; margin-top: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                                    <form method="POST" action="club.php#association" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:8px; margin-bottom:8px;">
                                        <input type="hidden" name="action_cms_club" value="sauvegarder_doc_officiel">
                                        <input type="hidden" name="doc_id" value="<?= htmlspecialchars($doc['id']) ?>">
                                        <input type="text" name="doc_titre" value="<?= htmlspecialchars($doc['titre']) ?>" required style="padding:6px; font-size:0.85rem;">
                                        <input type="file" name="fichier_pdf" accept="application/pdf,.pdf" style="font-size:0.8rem;">
                                        <button type="submit" class="btn-cms-save" style="padding:6px 10px; font-size:0.82rem;">🔄 Mettre à jour</button>
                                    </form>
                                    <form method="POST" action="club.php#association" onsubmit="return confirm('Supprimer ce document PDF ?');">
                                        <input type="hidden" name="action_cms_club" value="supprimer_doc_officiel">
                                        <input type="hidden" name="doc_id" value="<?= htmlspecialchars($doc['id']) ?>">
                                        <button type="submit" class="btn-cms-delete" style="padding:5px 10px; font-size:0.8rem; width:100%;">🗑️ Supprimer</button>
                                    </form>
                                </div>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details cms-add-block">
                    <summary class="cms-admin-summary">➕ Ajouter un nouveau document PDF officiel (Statuts, Règlement...) (Admin)</summary>
                    <form method="POST" action="club.php#association" enctype="multipart/form-data" class="cms-admin-form">
                        <input type="hidden" name="action_cms_club" value="sauvegarder_doc_officiel">
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Titre du lien *</label>
                                <input type="text" name="doc_titre" required placeholder="Ex: Consulter le règlement intérieur 2023">
                            </div>
                            <div class="cms-field">
                                <label>Fichier PDF *</label>
                                <input type="file" name="fichier_pdf" accept="application/pdf,.pdf" required>
                            </div>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">➕ Publier ce document PDF</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <!-- ==================================================================
             SECTION 5 : ADHÉSION EN LIGNE YAPLA & TARIFS (PARAGRAPHES ILLIMITÉS)
             ================================================================== -->
        <section id="inscription" class="club-section join-us">
            <h2><?= htmlspecialchars($clubData['inscription']['titre']) ?></h2>
            <?php foreach ($clubData['inscription']['paragraphes_intro'] as $idxIn => $texteIn): ?>
                <p style="<?= $idxIn > 0 ? 'margin-top: 10px;' : '' ?>">
                    <?= nl2br(htmlspecialchars($texteIn)) ?>
                </p>
            <?php endforeach; ?>

            <?php if (!empty($clubData['inscription']['titre_tarif'])): ?>
                <h3 class="subtitle"><?= htmlspecialchars($clubData['inscription']['titre_tarif']) ?></h3>
            <?php endif; ?>

            <?php if (!empty($clubData['inscription']['lignes_tarif'])): ?>
                <ul class="club-list">
                    <?php foreach ($clubData['inscription']['lignes_tarif'] as $ligneT): ?>
                        <li>
                            <?php
                                if (strpos($ligneT, ':') !== false and stripos($ligneT, 'note :') !== 0) {
                                    list($montantT, $descT) = explode(':', $ligneT, 2);
                                    echo '<strong>' . htmlspecialchars($montantT) . ' :</strong>' . htmlspecialchars($descT);
                                } else {
                                    echo '<em>' . htmlspecialchars($ligneT) . '</em>';
                                }
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($estAdmin): ?>
                <details class="cms-admin-details">
                    <summary class="cms-admin-summary">✏️ Modifier les textes d'inscription, les tarifs ou le lien Yapla (Admin)</summary>
                    <form method="POST" action="club.php#inscription" class="cms-admin-form">
                        <input type="hidden" name="action_cms_club" value="modifier_inscription">
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>Titre de la section *</label>
                                <input type="text" name="titre" value="<?= htmlspecialchars($clubData['inscription']['titre']) ?>" required>
                            </div>
                            <div class="cms-field">
                                <label>Sous-titre Tarif global</label>
                                <input type="text" name="titre_tarif" value="<?= htmlspecialchars($clubData['inscription']['titre_tarif']) ?>">
                            </div>
                        </div>

                        <div id="container-paragraphes-inscription" style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($clubData['inscription']['paragraphes_intro'] as $numIn => $texteIn): ?>
                                <div class="cms-field cms-paragraph-item" style="background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:6px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <label style="margin:0;">Paragraphe d'introduction <?= (int)$numIn + 1 ?></label>
                                        <?php if ($numIn > 0): ?>
                                            <button type="button" class="btn-cms-delete" style="padding:4px 10px; font-size:0.78rem;" onclick="this.closest('.cms-paragraph-item').remove()">🗑️ Supprimer ce paragraphe</button>
                                        <?php endif; ?>
                                    </div>
                                    <textarea name="paragraphes[]" rows="2" required><?= htmlspecialchars($texteIn) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div>
                            <button type="button" class="btn-secondary btn-add-paragraph" data-target="container-paragraphes-inscription" style="margin-top:0; padding:7px 14px; font-size:0.85rem; border:none; cursor:pointer;">
                                ➕ Ajouter un paragraphe d'introduction
                            </button>
                        </div>

                        <div class="cms-field">
                            <label>Détail des cotisations (1 ligne par point)</label>
                            <textarea name="lignes_tarif" rows="4"><?= htmlspecialchars(implode("\n", $clubData['inscription']['lignes_tarif'] ?? [])) ?></textarea>
                        </div>
                        <div class="cms-form-row">
                            <div class="cms-field">
                                <label>URL de l'espace Yapla du club (Optionnel)</label>
                                <input type="url" name="yapla_url" value="<?= htmlspecialchars($clubData['inscription']['yapla_url'] ?? '') ?>" placeholder="https://...">
                                <span class="cms-help">Laissez vide si le club n'utilise pas encore d'inscription en ligne Yapla.</span>
                            </div>
                            <div class="cms-field">
                                <label>E-mail de contact en bas de bloc</label>
                                <input type="email" name="email_contact" value="<?= htmlspecialchars($clubData['inscription']['email_contact']) ?>" required>
                            </div>
                        </div>
                        <div class="cms-actions">
                            <button type="submit" class="btn-cms-save">💾 Enregistrer les tarifs & Yapla</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>

            <?php if (!empty($clubData['inscription']['yapla_url'])): ?>
                <!-- Iframe de l'espace membre et d'adhésion Yapla -->
                <h3 class="subtitle" style="text-align: center; margin-top: 30px;">💳 Inscription et paiement en ligne</h3>
                <div class="yapla-iframe-wrapper">
                    <iframe
                        src="<?= htmlspecialchars($clubData['inscription']['yapla_url']) ?>"
                        width="950"
                        height="750"
                        allow="payment"
                        loading="lazy"
                        title="Espace Membres et Adhésion Yapla - Les Kamikazes Agathois">
                    </iframe>
                </div>

                <!-- Bouton d'ouverture directe du formulaire Yapla en plein écran -->
                <div class="join-us-action">
                    <a href="<?= htmlspecialchars($clubData['inscription']['yapla_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-primary">
                        🔗 Ouvrir le formulaire en plein écran
                    </a>
                </div>
            <?php endif; ?>

            <p class="join-us-footer">
                Des questions ? Contactez-nous à <a href="mailto:<?= htmlspecialchars($clubData['inscription']['email_contact']) ?>"><?= htmlspecialchars($clubData['inscription']['email_contact']) ?></a> ou venez nous rencontrer le week-end sur le terrain.
            </p>
        </section>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
