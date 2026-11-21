<?php
// ==========================================================================
// FONCTIONS UTILITAIRES PARTAGÉES DU SITE LES KAMIKAZES AGATHOIS
// ==========================================================================

// --- 1. FORMATAGE DES DATES, RÔLES ET NOMS D'AUTEURS ---

// Convertit une date SQL (YYYY-MM-DD) au format français (JJ/MM/AAAA)
function formaterDate($dateSql) {
    if (empty($dateSql)) return '';
    return date('d/m/Y', strtotime($dateSql));
}

// Convertit une date/heure SQL au format français (JJ/MM/AAAA à HH:MM) pour le forum
function formaterDateHeure($dateSql) {
    if (empty($dateSql)) return '';
    return date('d/m/Y à H:i', strtotime($dateSql));
}

// Affiche "Prénom N." (compatible UTF-8) ou un texte par défaut si le compte du membre a été supprimé
function afficherNomAuteur($prenom, $nom, $defaut = 'Ancien membre') {
    $prenom = trim((string)$prenom);
    $nom    = trim((string)$nom);
    if ($prenom === '') {
        return $defaut;
    }
    $initiale = $nom !== '' ? mb_substr($nom, 0, 1, 'UTF-8') . '.' : '';
    return htmlspecialchars(trim($prenom . ' ' . $initiale));
}

// Retourne le libellé lisible d'un rôle (ex: 'membre_associe' -> 'Membre associé')
function formaterLibelleRole($role) {
    $libelles = [
        'admin'              => 'Admin',
        'bureau'             => 'Bureau',
        'membre'             => 'Membre',
        'membre_associe'     => 'Membre associé',
        'attente_validation' => 'En attente'
    ];
    return $libelles[$role] ?? ucfirst(str_replace('_', ' ', (string)$role));
}


// --- 2. NETTOYAGE DE TEXTE & EXTRACTION YOUTUBE ---

// Remplace tous les caractères accentués par leur équivalent sans accent
function supprimerAccents($texte) {
    $accents = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
        'è'=>'e','ê'=>'e','ë'=>'e','é'=>'e',
        'ì'=>'i','î'=>'i','ï'=>'i','í'=>'i',
        'ò'=>'o','ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
        'ç'=>'c','ñ'=>'n','ÿ'=>'y',
        'À'=>'a','Â'=>'a','Ä'=>'a','Á'=>'a',
        'È'=>'e','Ê'=>'e','Ë'=>'e','É'=>'e',
        'Ì'=>'i','Î'=>'i','Ï'=>'i','Í'=>'i',
        'Ò'=>'o','Ô'=>'o','Ö'=>'o','Ó'=>'o',
        'Ù'=>'u','Û'=>'u','Ü'=>'u','Ú'=>'u',
        'Ç'=>'c','Ñ'=>'n'
    ];
    return strtr(trim((string)$texte), $accents);
}

// Génère une base propre pour les identifiants membres (ex: "Jean-Pierre" -> "jean-pierre")
function nettoyerTexteIdentifiant($texte) {
    $texte = strtolower(supprimerAccents($texte));
    $texte = preg_replace('/\s+/', '-', $texte);
    return preg_replace('/[^a-z0-9\-]/', '', $texte);
}

// Génère un nom de sous-dossier propre pour un article ou une publication (ex: "KamiSmac 2026 !" -> "kamismac-2026")
function creerSlugDossier($texte) {
    $texte = strtolower(supprimerAccents($texte));
    $texte = preg_replace('/[^a-z0-9]+/', '-', $texte);
    $texte = trim($texte, '-');
    return !empty($texte) ? $texte : 'evenement';
}

// Nettoie un nom de dossier d'album dans le Pêle-mêle (ex: "Repas Club" -> "Repas_Club")
function nettoyerNomDossier($texte) {
    $texte = supprimerAccents($texte);
    $texte = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $texte);
    $texte = preg_replace('/\s+/', '_', $texte);
    return trim($texte, '_-');
}

// Extrait l'identifiant de 11 caractères à partir de n'importe quel format de lien YouTube (classique, court, shorts, embed)
function extraireIdYoutube($url) {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?|shorts)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match)) {
        return $match[1];
    }
    return null;
}


// --- 3. TRAITEMENT DES MÉDIAS : IMAGES (1080p), PDF, PROTECTION PRIVÉE & SUPPRESSION ---

// Redresse physiquement une image JPEG sur le serveur si elle possède une rotation EXIF (Portrait smartphone)
function redresserImageExifAuto($cheminImage) {
    if (!is_file($cheminImage) or !function_exists('exif_read_data') or !function_exists('imagecreatefromjpeg')) {
        return;
    }
    $ext = strtolower(pathinfo($cheminImage, PATHINFO_EXTENSION));
    if ($ext !== 'jpg' and $ext !== 'jpeg') {
        return;
    }
    $exif = @exif_read_data($cheminImage);
    $orientation = $exif['Orientation'] ?? ($exif['IFD0']['Orientation'] ?? null);
    if (!empty($orientation) and in_array((int)$orientation, [3, 6, 8], true)) {
        $src = @imagecreatefromjpeg($cheminImage);
        if ($src) {
            switch ((int)$orientation) {
                case 3: $src = imagerotate($src, 180, 0); break;
                case 6: $src = imagerotate($src, -90, 0); break;
                case 8: $src = imagerotate($src, 90, 0); break;
            }
            imagejpeg($src, $cheminImage, 85);
            imagedestroy($src);
        }
    }
}

// Redimensionne (max 1920px), corrige l'orientation EXIF (Portrait/Paysage) et compresse en JPEG (qualité 82%)
function enregistrerImageOptimisee($tmpName, $originalName, $dossierCible) {
    $dossierCible = rtrim($dossierCible, '/');
    if (!is_dir($dossierCible)) {
        mkdir($dossierCible, 0755, true);
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        return false;
    }

    $nouveauNom = $dossierCible . '/' . uniqid('ka_') . '.jpg';

    if (function_exists('imagecreatefromjpeg')) {
        $info = @getimagesize($tmpName);
        if ($info) {
            list($largeur, $hauteur, $type) = $info;
            $src = null;
            if ($type === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($tmpName);
            elseif ($type === IMAGETYPE_PNG) $src = @imagecreatefrompng($tmpName);
            elseif ($type === IMAGETYPE_WEBP and function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($tmpName);

            if ($src) {
                if ($type === IMAGETYPE_JPEG and function_exists('exif_read_data')) {
                    $exif = @exif_read_data($tmpName);
                    if (!empty($exif['Orientation'])) {
                        switch ((int)$exif['Orientation']) {
                            case 3: $src = imagerotate($src, 180, 0); break;
                            case 6: $src = imagerotate($src, -90, 0); break;
                            case 8: $src = imagerotate($src, 90, 0); break;
                        }
                        $largeur = imagesx($src);
                        $hauteur = imagesy($src);
                    }
                }

                $maxDim      = 1920;
                $ratio       = min($maxDim / $largeur, $maxDim / $hauteur, 1);
                $nouvLargeur = (int)round($largeur * $ratio);
                $nouvHauteur = (int)round($hauteur * $ratio);

                $dst   = imagecreatetruecolor($nouvLargeur, $nouvHauteur);
                $blanc = imagecolorallocate($dst, 255, 255, 255);
                imagefill($dst, 0, 0, $blanc);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nouvLargeur, $nouvHauteur, $largeur, $hauteur);
                imagejpeg($dst, $nouveauNom, 82);
                imagedestroy($src);
                imagedestroy($dst);
                return $nouveauNom;
            }
        }
    }

    $fallbackNom = $dossierCible . '/' . uniqid('ka_') . '.' . $ext;
    return move_uploaded_file($tmpName, $fallbackNom) ? $fallbackNom : false;
}

// Vérifie et enregistre un document PDF dans DOCS/...
function enregistrerPdfArticle($tmpName, $originalName, $dossierCible) {
    $dossierCible = rtrim($dossierCible, '/');
    if (!is_dir($dossierCible)) {
        mkdir($dossierCible, 0755, true);
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return false;
    }

    $fp = @fopen($tmpName, 'rb');
    if (!$fp) {
        return false;
    }
    $entete = fread($fp, 4);
    fclose($fp);
    if ($entete !== '%PDF') {
        return false;
    }

    $nomSansExt = pathinfo($originalName, PATHINFO_FILENAME);
    $nomPropre  = nettoyerNomDossier($nomSansExt);
    if (empty($nomPropre)) {
        $nomPropre = 'Document_Kamikazes';
    }

    $cheminFinal = $dossierCible . '/' . $nomPropre . '.pdf';

    if (is_file($cheminFinal)) {
        $cheminFinal = $dossierCible . '/' . $nomPropre . '_' . substr(uniqid(), -4) . '.pdf';
    }

    return move_uploaded_file($tmpName, $cheminFinal) ? $cheminFinal : false;
}

// Supprime un fichier média (IMG/ ou DOCS/) de manière sécurisée et retire le sous-dossier s'il est devenu vide
function supprimerFichierServeur($cheminFichier) {
    $cheminFichier = str_replace('\\', '/', trim((string)$cheminFichier));
    if ($cheminFichier === '' or strpos($cheminFichier, '..') !== false) {
        return;
    }
    if (!preg_match('#^(IMG|DOCS|docs)/#', $cheminFichier)) {
        return;
    }
    if (is_file($cheminFichier)) {
        $dossierParent = dirname($cheminFichier);
        @unlink($cheminFichier);

        // Si le sous-dossier d'article/publication ne contient plus que le .htaccess de protection, on le nettoie aussi
        if (is_dir($dossierParent) and preg_match('#^(IMG|DOCS)/\d{4}/(articles|membres)/[^/]+$#', $dossierParent)) {
            $restants = array_values(array_diff(@scandir($dossierParent) ?: [], ['.', '..']));
            if ($restants === ['.htaccess']) {
                @unlink($dossierParent . '/.htaccess');
            }
            @rmdir($dossierParent);
        } elseif (is_dir($dossierParent) and !preg_match('#^(IMG|DOCS|docs)(/\d{4}(/compte-rendus)?)?$#', $dossierParent)) {
            @rmdir($dossierParent);
        }
    }
}

// Pose ou retire automatiquement le fichier .htaccess dans les sous-dossiers IMG/ et DOCS/ d'un article ou d'une publication membre
function appliquerProtectionMediaEnregistrement($imagePrincipale, $galerieBrute, $pdfsBruts, $doitProteger) {
    $chemins = [];
    if (!empty($imagePrincipale)) {
        $chemins[] = str_replace('\\', '/', trim((string)$imagePrincipale));
    }
    if (!empty($galerieBrute)) {
        foreach (preg_split('/[\r\n,]+/', (string)$galerieBrute) as $p) {
            $p = str_replace('\\', '/', trim($p));
            if ($p !== '') $chemins[] = $p;
        }
    }
    if (!empty($pdfsBruts)) {
        foreach (preg_split('/[\r\n,]+/', (string)$pdfsBruts) as $p) {
            $p = str_replace('\\', '/', trim($p));
            if ($p !== '') $chemins[] = $p;
        }
    }

    $dossiers = [];
    foreach ($chemins as $c) {
        $dir = dirname($c);
        // Sécurité : uniquement dans un sous-dossier spécifique d'article ou de publication membre
        if (preg_match('#^(IMG|DOCS)/\d{4}/(articles|membres)/[^/]+$#', $dir) and is_dir($dir)) {
            $dossiers[$dir] = true;
        }
    }

    $reglesHtaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    foreach (array_keys($dossiers) as $dir) {
        $fichierHtaccess = $dir . '/.htaccess';
        if ($doitProteger) {
            if (!is_file($fichierHtaccess)) {
                @file_put_contents($fichierHtaccess, $reglesHtaccess);
            }
        } else {
            if (is_file($fichierHtaccess)) {
                @unlink($fichierHtaccess);
            }
        }
    }
}

// Retourne l'URL directe (si public + publié) ou l'URL sécurisée via voir_media.php (si privé ou en attente)
function obtenirUrlMedia($cheminFichier, $estProtege, $typeSource, $idSource, $telecharger = false) {
    $cheminFichier = str_replace('\\', '/', trim((string)$cheminFichier));
    if ($cheminFichier === '') {
        return '';
    }
    $dossierVerrouille = is_file(dirname($cheminFichier) . '/.htaccess');
    if ($estProtege or $dossierVerrouille) {
        return 'voir_media.php?type=' . rawurlencode($typeSource)
            . '&id=' . (int)$idSource
            . '&f=' . rawurlencode($cheminFichier)
            . ($telecharger ? '&dl=1' : '');
    }
    return $cheminFichier;
}


// --- 4. GESTION DES COMPTES MEMBRES, LECTURE EXCEL YAPLA & CARTES ADHÉRENTS ---

// Génère un identifiant unique "prenom.n" (ou "prenom.no", "prenom.nom" en cas d'homonyme)
function genererIdentifiantUnique($pdo, $prenom, $nom) {
    $prenomPropre = nettoyerTexteIdentifiant($prenom);
    $nomPropre    = str_replace('-', '', nettoyerTexteIdentifiant($nom));

    if (empty($prenomPropre)) $prenomPropre = 'membre';
    if (empty($nomPropre))    $nomPropre    = 'kamikazes';

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE identifiant = :id");

    for ($longueur = 1; $longueur <= strlen($nomPropre); $longueur++) {
        $candidat = $prenomPropre . '.' . substr($nomPropre, 0, $longueur);
        $stmtCheck->execute([':id' => $candidat]);
        if ((int)$stmtCheck->fetchColumn() === 0) {
            return $candidat;
        }
    }

    $compteur = 2;
    while (true) {
        $candidat = $prenomPropre . '.' . substr($nomPropre, 0, 1) . $compteur;
        $stmtCheck->execute([':id' => $candidat]);
        if ((int)$stmtCheck->fetchColumn() === 0) {
            return $candidat;
        }
        $compteur++;
    }
}

// Convertit une valeur de date Excel au format SQL YYYY-MM-DD
function convertirDateExcelYapla($valeur) {
    $valeur = trim((string)$valeur);
    if ($valeur === '') {
        return null;
    }

    $dateSql = null;

    if (is_numeric($valeur)) {
        $nb = (float)$valeur;
        if ($nb > 3000 and $nb < 100000) {
            $timestamp = (int)round(($nb - 25569) * 86400);
            $dateSql = gmdate('Y-m-d', $timestamp);
        }
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})/', $valeur, $m)) {
        $dateSql = $m[1];
    } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $valeur, $m)) {
        $dateSql = $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    if ($dateSql === '1900-01-01' or $dateSql === '1899-12-30') {
        return null;
    }

    return $dateSql;
}

// Lit nativement un fichier .xlsx exporté depuis Yapla
function lireFichierExcelYapla($cheminFichier) {
    if (!class_exists('ZipArchive') or !is_file($cheminFichier)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($cheminFichier) !== true) {
        return false;
    }

    $sharedStrings = [];
    $xmlStrings = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlStrings !== false) {
        $sxStrings = @simplexml_load_string($xmlStrings);
        if ($sxStrings) {
            foreach ($sxStrings->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $texteRiche = '';
                    foreach ($si->r as $run) {
                        $texteRiche .= (string)$run->t;
                    }
                    $sharedStrings[] = $texteRiche;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $xmlSheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($xmlSheet === false) {
        return false;
    }

    $sxSheet = @simplexml_load_string($xmlSheet);
    if (!$sxSheet or !isset($sxSheet->sheetData->row)) {
        return false;
    }

    $lignesBrutes = [];
    foreach ($sxSheet->sheetData->row as $row) {
        $ligne = [];
        foreach ($row->c as $cell) {
            $ref       = (string)$cell['r'];
            $colLettre = preg_replace('/[0-9]+/', '', $ref);
            $typeCell  = (string)$cell['t'];
            $valeur    = isset($cell->v) ? (string)$cell->v : '';

            if ($typeCell === 's') {
                $idx    = (int)$valeur;
                $valeur = $sharedStrings[$idx] ?? '';
            } elseif ($typeCell === 'inlineStr' and isset($cell->is->t)) {
                $valeur = (string)$cell->is->t;
            }

            $ligne[$colLettre] = trim($valeur);
        }
        if (!empty($ligne)) {
            $lignesBrutes[] = $ligne;
        }
    }

    if (count($lignesBrutes) < 2) {
        return [];
    }

    $colNumAdherent   = null;
    $colPrenom        = null;
    $colNom           = null;
    $colEmail         = null;
    $colPhoto         = null;
    $colsNaissance    = [];
    $colFormationUas  = null;
    $colNumUas        = null;
    $colValidationUas = null;
    $colTypeAdhesion  = null;
    $colStatut        = null;
    $colExpiration    = null;

    $entetes = $lignesBrutes[0];
    foreach ($entetes as $lettre => $titreCol) {
        $titrePropre = strtolower(supprimerAccents($titreCol));

        if ($colNumAdherent === null and strpos($titrePropre, 'adherent') !== false) {
            $colNumAdherent = $lettre;
        } elseif ($colPrenom === null and ($titrePropre === 'membre - prenom' or $titrePropre === 'prenom')) {
            $colPrenom = $lettre;
        } elseif ($colNom === null and ($titrePropre === 'membre - nom' or $titrePropre === 'nom')) {
            $colNom = $lettre;
        } elseif ($colEmail === null and ($titrePropre === 'membre - courriel' or $titrePropre === 'courriel')) {
            $colEmail = $lettre;
        } elseif ($colPhoto === null and strpos($titrePropre, 'photo d\'identite') !== false) {
            $colPhoto = $lettre;
        } elseif (strpos($titrePropre, 'date de naissance') !== false) {
            $colsNaissance[] = $lettre;
        } elseif ($colFormationUas === null and strpos($titrePropre, 'formation de telepilote') !== false) {
            $colFormationUas = $lettre;
        } elseif ($colNumUas === null and strpos($titrePropre, 'uas') !== false and strpos($titrePropre, 'validation') === false) {
            $colNumUas = $lettre;
        } elseif ($colValidationUas === null and strpos($titrePropre, 'validation uas') !== false) {
            $colValidationUas = $lettre;
        } elseif ($colTypeAdhesion === null and strpos($titrePropre, 'type d\'adhesion') !== false) {
            $colTypeAdhesion = $lettre;
        } elseif ($colStatut === null and strpos($titrePropre, 'statut d\'adhesion') !== false) {
            $colStatut = $lettre;
        } elseif ($colExpiration === null and strpos($titrePropre, 'expiration de l\'adhesion') !== false) {
            $colExpiration = $lettre;
        }
    }

    if ($colNumAdherent === null) $colNumAdherent = 'A';
    if ($colPrenom === null)      $colPrenom      = 'B';
    if ($colNom === null)         $colNom         = 'C';
    if ($colEmail === null)       $colEmail       = 'D';
    if ($colStatut === null)      $colStatut      = 'F';
    if ($colExpiration === null)  $colExpiration  = 'I';

    $colNaissanceMembre = !empty($colsNaissance) ? end($colsNaissance) : null;

    $adherentsYapla = [];
    for ($i = 1; $i < count($lignesBrutes); $i++) {
        $l          = $lignesBrutes[$i];
        $numLicence = strtoupper(trim($l[$colNumAdherent] ?? ''));
        $prenom     = trim($l[$colPrenom] ?? '');
        $nom        = strtoupper(trim($l[$colNom] ?? ''));
        $emailBrut  = strtolower(trim($l[$colEmail] ?? ''));
        $statutBrut = trim($l[$colStatut] ?? '');
        $expBrut    = $l[$colExpiration] ?? '';

        if (!preg_match('/^APHD-\d{2}-\d{2}-\d{3}$/', $numLicence) or empty($prenom) or empty($nom)) {
            continue;
        }

        $statutNormalise = strtolower(supprimerAccents($statutBrut));
        $typeAdhesion    = $colTypeAdhesion ? trim($l[$colTypeAdhesion] ?? '') : '';
        $typeNormalise   = strtolower(supprimerAccents($typeAdhesion));

        if ($statutNormalise === 'validee' or $statutNormalise === 'valide') {
            $roleCalcule = (strpos($typeNormalise, 'associe') !== false) ? 'membre_associe' : 'membre';
        } else {
            $roleCalcule = 'attente_validation';
        }

        $photoUrl      = $colPhoto ? trim($l[$colPhoto] ?? '') : '';
        $dateNaissance = $colNaissanceMembre ? convertirDateExcelYapla($l[$colNaissanceMembre] ?? '') : null;
        $dateFormUas   = $colFormationUas ? convertirDateExcelYapla($l[$colFormationUas] ?? '') : null;
        $numUas        = $colNumUas ? trim($l[$colNumUas] ?? '') : '';
        $dateValUas    = $colValidationUas ? convertirDateExcelYapla($l[$colValidationUas] ?? '') : null;

        $adherentsYapla[$numLicence] = [
            'num_licence'     => $numLicence,
            'prenom'          => $prenom,
            'nom'             => $nom,
            'email'           => !empty($emailBrut) ? $emailBrut : null,
            'role_yapla'      => $roleCalcule,
            'statut_brut'     => $statutBrut,
            'date_expiration' => convertirDateExcelYapla($expBrut),
            'photo_url'       => $photoUrl,
            'date_naissance'  => $dateNaissance,
            'date_formation'  => $dateFormUas,
            'num_uas'         => $numUas,
            'date_val_uas'    => $dateValUas,
            'type_adhesion'   => $typeAdhesion
        ];
    }

    return $adherentsYapla;
}


// --- 5. SYSTÈME D'ENVOI D'E-MAILS (ARTICLES, ESPACE MEMBRES & FORUM) ---

// Construit l'URL de base du site
function obtenirUrlBaseSite() {
    $protocole  = (!empty($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $domaine    = $_SERVER['HTTP_HOST'] ?? 'kamikazesagathois.alwaysdata.net';
    $dossierUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
    return $protocole . '://' . $domaine . $dossierUrl;
}

// Fonction centrale d'expédition d'un e-mail encodé proprement en UTF-8 (nom conservé pour compatibilité)
function envoyerEmailSmac($destinataire, $sujetTexte, $messageTexte) {
    $destinataire = trim((string)$destinataire);
    if (empty($destinataire) or !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $sujetEncode = "=?UTF-8?B?" . base64_encode($sujetTexte) . "?=";

    $entetes  = "MIME-Version: 1.0\r\n";
    $entetes .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $entetes .= "From: Les Kamikazes Agathois <kamikazes34300@gmail.com>\r\n";
    $entetes .= "Reply-To: kamikazes34300@gmail.com\r\n";
    $entetes .= "X-Mailer: PHP/" . phpversion();

    return @mail($destinataire, $sujetEncode, $messageTexte, $entetes);
}

// A. Envoie un e-mail au club lorsqu'un membre soumet un article d'actualité à valider
function envoyerMailArticleEnAttente($articleId, $titre, $datePublication, $resume, $auteurPrenom, $auteurNom) {
    $lienArticle   = obtenirUrlBaseSite() . '/article.php?id=' . (int)$articleId;
    $auteurComplet = trim($auteurPrenom . ' ' . $auteurNom);
    $dateFr        = formaterDate($datePublication);

    $sujet = "[Kamikazes Agathois] Nouvel article à valider : " . $titre;

    $message  = "Bonjour,\r\n\r\n";
    $message .= "Un membre du club vient de proposer un nouvel article sur le site des Kamikazes Agathois.\r\n";
    $message .= "Cet article est actuellement en attente de validation.\r\n\r\n";
    $message .= "--------------------------------------------------\r\n";
    $message .= "Auteur : " . $auteurComplet . "\r\n";
    $message .= "Titre : " . $titre . "\r\n";
    $message .= "Date de l'événement : " . $dateFr . "\r\n";
    $message .= "Résumé : " . $resume . "\r\n";
    $message .= "--------------------------------------------------\r\n\r\n";
    $message .= "Pour relire, modifier ou valider cet article en un clic, rendez-vous sur :\r\n";
    $message .= $lienArticle . "\r\n\r\n";
    $message .= "Bonne journée,\r\n";
    $message .= "Le site des Kamikazes Agathois";

    return envoyerEmailSmac('kamikazes34300@gmail.com', $sujet, $message);
}

// B. Envoie un e-mail au membre auteur lorsque son article d'actualité est validé ou refusé
function envoyerMailDecisionArticleMembre($emailAuteur, $prenomAuteur, $titreArticle, $articleId, $estValide) {
    if ($estValide) {
        $lienArticle = obtenirUrlBaseSite() . '/article.php?id=' . (int)$articleId;
        $sujet = "[Kamikazes Agathois] Votre article a été validé et mis en ligne !";

        $message  = "Bonjour " . $prenomAuteur . ",\r\n\r\n";
        $message .= "Bonne nouvelle : l'article que vous avez proposé (« " . $titreArticle . " ») vient d'être validé par le Bureau des Kamikazes Agathois.\r\n\r\n";
        $message .= "Il est désormais publié en ligne et consultable directement à cette adresse :\r\n";
        $message .= $lienArticle . "\r\n\r\n";
        $message .= "Merci pour votre contribution à la vie du club !\r\n\r\n";
        $message .= "Amicalement,\r\n";
        $message .= "Le Bureau des Kamikazes Agathois";
    } else {
        $sujet = "[Kamikazes Agathois] Information concernant votre proposition d'article";

        $message  = "Bonjour " . $prenomAuteur . ",\r\n\r\n";
        $message .= "L'article que vous aviez proposé (« " . $titreArticle . " ») n'a pas pu être validé pour une mise en ligne sur le site des Kamikazes Agathois.\r\n\r\n";
        $message .= "N'hésitez pas à vous rapprocher d'un membre du Bureau ou de l'Administrateur si vous souhaitez plus d'informations.\r\n\r\n";
        $message .= "Amicalement,\r\n";
        $message .= "Le Bureau des Kamikazes Agathois";
    }

    return envoyerEmailSmac($emailAuteur, $sujet, $message);
}

// C. Envoie un e-mail au club lorsqu'un membre propose une publication dans Membres (Vacances ou Réalisations)
function envoyerMailPublicationMembreEnAttente($pubId, $categorie, $titre, $datePub, $description, $auteurPrenom, $auteurNom) {
    $libelleCat    = ($categorie === 'realisations') ? 'Réalisations des membres' : 'Photos de vacances & Voyages';
    $lienPub       = obtenirUrlBaseSite() . '/membres.php?pub=' . (int)$pubId;
    $auteurComplet = trim($auteurPrenom . ' ' . $auteurNom);
    $dateFr        = formaterDate($datePub);

    $sujet = "[Kamikazes Agathois] Nouvelle publication ($libelleCat) à valider : " . $titre;

    $message  = "Bonjour,\r\n\r\n";
    $message .= "Un membre du club vient de proposer une nouvelle publication dans l'Espace Membres du site des Kamikazes Agathois.\r\n";
    $message .= "Cette publication est actuellement en attente de validation.\r\n\r\n";
    $message .= "--------------------------------------------------\r\n";
    $message .= "Rubrique : " . $libelleCat . "\r\n";
    $message .= "Auteur : " . $auteurComplet . "\r\n";
    $message .= "Titre : " . $titre . "\r\n";
    $message .= "Date : " . $dateFr . "\r\n";
    $message .= "Résumé : " . mb_substr(trim($description), 0, 250, 'UTF-8') . "\r\n";
    $message .= "--------------------------------------------------\r\n\r\n";
    $message .= "Pour relire, modifier ou valider cette publication en un clic, rendez-vous sur :\r\n";
    $message .= $lienPub . "\r\n\r\n";
    $message .= "Bonne journée,\r\n";
    $message .= "Le site des Kamikazes Agathois";

    return envoyerEmailSmac('kamikazes34300@gmail.com', $sujet, $message);
}

// D. Envoie un e-mail au membre auteur lorsque sa publication (Vacances ou Réalisations) est validée ou refusée
function envoyerMailDecisionPublicationMembre($emailAuteur, $prenomAuteur, $titrePub, $categorie, $pubId, $estValide) {
    $libelleCat = ($categorie === 'realisations') ? 'Réalisations des membres' : 'Photos de vacances';

    if ($estValide) {
        $lienPub = obtenirUrlBaseSite() . '/membres.php?pub=' . (int)$pubId;
        $sujet   = "[Kamikazes Agathois] Votre publication ($libelleCat) a été validée et mise en ligne !";

        $message  = "Bonjour " . $prenomAuteur . ",\r\n\r\n";
        $message .= "Bonne nouvelle : votre publication « " . $titrePub . " » dans la rubrique « " . $libelleCat . " » vient d'être validée par le Bureau des Kamikazes Agathois.\r\n\r\n";
        $message .= "Elle est désormais publiée en ligne dans l'Espace Membres :\r\n";
        $message .= $lienPub . "\r\n\r\n";
        $message .= "Merci pour votre partage avec les pilotes du club !\r\n\r\n";
        $message .= "Amicalement,\r\n";
        $message .= "Le Bureau des Kamikazes Agathois";
    } else {
        $sujet = "[Kamikazes Agathois] Information concernant votre proposition de publication ($libelleCat)";

        $message  = "Bonjour " . $prenomAuteur . ",\r\n\r\n";
        $message .= "La publication que vous aviez proposée (« " . $titrePub . " » dans la rubrique « " . $libelleCat . " ») n'a pas pu être validée pour une mise en ligne sur le site des Kamikazes Agathois.\r\n\r\n";
        $message .= "N'hésitez pas à vous rapprocher d'un membre du Bureau ou de l'Administrateur si vous souhaitez plus d'informations.\r\n\r\n";
        $message .= "Amicalement,\r\n";
        $message .= "Le Bureau des Kamikazes Agathois";
    }

    return envoyerEmailSmac($emailAuteur, $sujet, $message);
}

// E. Envoie un e-mail de notification lors d'une nouvelle réponse dans le forum
function envoyerMailNotificationForum($emailDestinataire, $prenomDestinataire, $nomRepondant, $titreSujet, $sujetId, $messageId, $extraitMessage, $estReponseDirecteAuMessage = false) {
    $lienMessage = obtenirUrlBaseSite() . '/forum.php?sujet=' . (int)$sujetId . '#msg-' . (int)$messageId;

    if ($estReponseDirecteAuMessage) {
        $sujet = "[Forum Kamikazes Agathois] " . $nomRepondant . " a répondu à votre message dans « " . $titreSujet . " »";
        $intro = $nomRepondant . " vient de répondre directement à votre message dans la discussion « " . $titreSujet . " » sur le forum des Kamikazes Agathois.";
    } else {
        $sujet = "[Forum Kamikazes Agathois] Nouvelle réponse de " . $nomRepondant . " dans « " . $titreSujet . " »";
        $intro = $nomRepondant . " vient de publier une nouvelle réponse dans votre discussion « " . $titreSujet . " » sur le forum des Kamikazes Agathois.";
    }

    $extraitPropre = mb_substr(trim($extraitMessage), 0, 250, 'UTF-8');
    if (mb_strlen(trim($extraitMessage), 'UTF-8') > 250) {
        $extraitPropre .= '...';
    }

    $message  = "Bonjour " . $prenomDestinataire . ",\r\n\r\n";
    $message .= $intro . "\r\n\r\n";
    $message .= "--------------------------------------------------\r\n";
    $message .= "Message de " . $nomRepondant . " :\r\n";
    $message .= "« " . $extraitPropre . " »\r\n";
    $message .= "--------------------------------------------------\r\n\r\n";
    $message .= "Pour lire la discussion et répondre sur le forum, cliquez ici :\r\n";
    $message .= $lienMessage . "\r\n\r\n";
    $message .= "À bientôt sur le terrain,\r\n";
    $message .= "Le Forum des Kamikazes Agathois";

    return envoyerEmailSmac($emailDestinataire, $sujet, $message);
}


// --- 6. MOTEUR DE MAINTENANCE SANS CODE (CONTENU DYNAMIQUE DU SITE EN JSON) ---

// Convertit une saisie libre (lien iframe, coordonnées décimales ou adresse) en URL d'intégration Google Maps propre
function formaterUrlIframeMaps($saisie) {
    $saisie = trim((string)$saisie);
    if ($saisie === '') {
        return '';
    }
    if (preg_match('/src=["\']([^"\']+)["\']/i', $saisie, $m)) {
        return $m[1];
    }
    if (strpos($saisie, 'output=embed') !== false or strpos($saisie, 'google.com/maps/embed') !== false) {
        return $saisie;
    }
    return 'https://maps.google.com/maps?q=' . rawurlencode($saisie) . '&hl=fr&z=16&output=embed';
}

// Retourne la structure complète par défaut du site (auto-initialisée si DATA/contenu_site.json n'existe pas encore)
function obtenirContenuSiteDefaut() {
    return [
        'accueil' => [
            'hero_titre'        => "Les Kamikazes Agathois",
            'hero_sous_titre'   => "Club d'aéromodélisme à Agde (34) — Aéromodèles Catégorie A (moins de 25 kg).",
            'hero_bouton_texte' => "Rejoindre le club",
            'meteo_titre'       => "🌤️ Anémomètre & Météo du terrain en direct",
            'meteo_texte'       => "Consultez le vent et la météo en temps réel sur notre terrain d'Agde avant de venir voler !",
            'meteo_url'         => "https://www.ecowitt.net/home/share?authorize=AFFR13&device_id=ZnRxaExLNjUyNEZ0NGpieDd5dXRLQT09",
            'video_titre'       => "Découvrez le club en vidéo",
            'video_texte'       => "La Coupe des Polyclubs et nos vols chez Les Kamikazes Agathois.",
            'video_youtube'     => "https://geo.dailymotion.com/player.html?video=x3t21nq"
        ],
        'club' => [
            'presentation' => [
                'titre'       => "Qui sommes-nous ?",
                'paragraphes' => [
                    "Les Kamikazes Agathois sont une association d'aéromodélistes d'Agde et environs (34), possédant un terrain de vol juste à la sortie de la ville. Notre association a pour vocation d'accueillir toute personne voulant pratiquer l'aéromodélisme en Catégorie A (modèles pesant moins de 25 kilos et motorisés à moins de 160cc ou 15kW) : planeurs, avions, hélicoptères et multirotors.",
                    "Association loi 1901 affiliée à l'OLAP (Organisation Languedocienne d'Aéronautique Populaire) et soutenue par la Mairie d'Agde ainsi que le service Jeunesse et Sports, le club réunit des passionnés de tous niveaux dans un cadre convivial, aussi bien sur notre terrain extérieur qu'en vol indoor."
                ]
            ],
            'terrains' => [
                [
                    'id'          => 'terrain_agde',
                    'titre'       => "Terrain d'évolution extérieur (Agde)",
                    'description' => "Notre terrain d'aéromodélisme offre tout le confort d'un site dégagé, juste à la sortie d'Agde en direction de Marseillan.",
                    'points'      => [
                        "Pistes : 2 pistes goudronnées orientées dans le sens des vents dominants et un taxiway d'accès goudronné.",
                        "Aire de préparation : Tables pour les membres dans un enclos sécurisé par un filet de protection.",
                        "Catégorie : Modèles réduits de Catégorie A (moins de 25 kg).",
                        "Équipements : Station météo / anémomètre Ecowitt connecté en direct."
                    ],
                    'gps_nord'    => '43°19\'22.2"',
                    'gps_est'     => '003°29\'44.3"',
                    'maps_embed'  => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1098.012464469947!2d3.4947575155531485!3d43.32282954352218!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNDPCsDE5JzIyLjIiTiAzwrAyOSc0NC4zIkU!5e1!3m2!1sfr!2sfr!4v1515965560645',
                    'maps_lien'   => 'https://www.google.com/maps/search/?api=1&query=43.322833,3.495639',
                    'pdf_fichier' => '',
                    'pdf_libelle' => ''
                ]
            ],
            'gymnases' => [
                [
                    'id'          => 'indoor_agde',
                    'titre'       => "Vol Indoor — Salles Molinié / Charrin (Agde)",
                    'description' => "La ville d'Agde met ponctuellement à disposition des adhérents l'une des salles polyvalentes Henri Molinié ou Philippe Charrin, au 7 Avenue Jean Moulin à Agde.",
                    'points'      => [
                        "Adresse : 7 Avenue Jean Moulin, 34300 Agde.",
                        "Créneaux : Le samedi ou le dimanche matin selon le calendrier du club.",
                        "Modèles : Avions indoor légers, planeurs, micro-hélicoptères et multirotors adaptés.",
                        "Ambiance : Idéal pour progresser au pilotage à l'abri du vent."
                    ],
                    'gps_nord'    => '43°18\'42.2"',
                    'gps_est'     => '003°28\'52.4"',
                    'maps_embed'  => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1451.560688737505!2d3.4812167782435868!3d43.311718492664646!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x12b13c8b73908ce9%3A0x649df38e096d2a4!2sSalle%20Henri%20MOLINI%C3%89!5e0!3m2!1sfr!2sfr!4v1745942414639!5m2!1sfr!2sfr',
                    'maps_lien'   => 'https://www.google.com/maps/search/?api=1&query=Salle+Henri+Molinie+7+Avenue+Jean+Moulin+34300+Agde',
                    'pdf_fichier' => '',
                    'pdf_libelle' => ''
                ]
            ],
            'association' => [
                'paragraphes_legal' => [
                    "Les Kamikazes Agathois est une association à but non lucratif régie par la loi de 1901, affiliée au réseau OLAP et soutenue par la Mairie d'Agde ainsi que Jeunesse et Sports."
                ],
                'bureau'      => [
                    ['poste' => 'Président',  'nom' => 'Norbert JAMMES', 'email' => 'kamikazes34300@gmail.com'],
                    ['poste' => 'Trésorier',  'nom' => 'Marc PERNIN',    'email' => ''],
                    ['poste' => 'Secrétaire', 'nom' => 'Ambroise LONGY', 'email' => '']
                ],
                'documents'   => [
                    ['id' => 'doc_statuts',   'titre' => 'Consulter les statuts du club',           'fichier' => 'DOCS/statuts.pdf'],
                    ['id' => 'doc_reglement', 'titre' => 'Consulter le règlement intérieur (2023)', 'fichier' => 'DOCS/reglement_interieur.pdf']
                ]
            ],
            'inscription' => [
                'titre'             => "Rejoindre Les Kamikazes Agathois",
                'paragraphes_intro' => [
                    "Vous souhaitez découvrir l'aéromodélisme ou rejoindre notre club à Agde ? Effectuez votre adhésion directement en ligne via notre espace Yapla ci-dessous ou venez nous rencontrer sur le terrain !"
                ],
                'titre_tarif'       => "Adhésion & Informations",
                'lignes_tarif'      => [
                    "Affiliation : Club affilié au réseau OLAP.",
                    "Installations : Accès au terrain extérieur (2 pistes goudronnées) et aux séances de vol indoor.",
                    "Note : Toutes les formules d'adhésion sont disponibles dans le formulaire en ligne ci-dessous."
                ],
                'yapla_url'         => "https://kamikazes-agathois.s2.yapla.com/fr/",
                'email_contact'     => "kamikazes34300@gmail.com"
            ]
        ],
        'activites' => [
            'pratiques_intro' => "Les Kamikazes Agathois pratiquent toutes les disciplines de l'aéromodélisme de loisir (Catégorie A, moins de 25 kg).",
            'pratiques'       => [
                [
                    'id'          => 'prat_planeurs',
                    'icone'       => '🦅',
                    'titre'       => 'Vol à voile (Planeurs)',
                    'description' => "Vol thermique, motoplaneurs ou grands planeurs : profitez du ciel agathois et des ascendances pour des vols tout en finesse."
                ],
                [
                    'id'          => 'prat_avions',
                    'icone'       => '✈️',
                    'titre'       => 'Avions',
                    'description' => "Thermiques (méthanol ou essence, 2 temps et 4 temps) et électriques : nos deux pistes goudronnées facilitent tous les décollages et atterrissages."
                ],
                [
                    'id'          => 'prat_helicopteres',
                    'icone'       => '🚁',
                    'titre'       => 'Hélicoptères',
                    'description' => "Thermiques (2 temps / 4 temps) ou électriques, vol maquette ou voltige 3D : les passionnés de voilures tournantes évoluent en toute sécurité sur notre terrain."
                ],
                [
                    'id'          => 'prat_multirotors',
                    'icone'       => '🛸',
                    'titre'       => 'Multirotors (Drones)',
                    'description' => "Drones de loisir, vol à vue ou FPV : le terrain et les séances indoor offrent un cadre idéal pour partager réglages et sessions de vol."
                ],
                [
                    'id'          => 'prat_indoor',
                    'icone'       => '🏟️',
                    'titre'       => 'Vol en Salle (Indoor)',
                    'description' => "Ponctuellement le samedi ou le dimanche matin, nous nous retrouvons aux Salles Molinié / Charrin (7 Avenue Jean Moulin à Agde) avec nos modèles légers."
                ]
            ],
            'evenements_intro' => "La vie des Kamikazes Agathois est rythmée par nos rencontres amicales, nos séances indoor et les rendez-vous interclubs.",
            'evenements'       => [
                [
                    'id'          => 'indoor',
                    'periode'     => 'Saison Hivernale',
                    'titre'       => 'Séances Vol Indoor à Agde',
                    'description' => "Des moments conviviaux à l'abri du vent aux Salles Molinié et Charrin pour faire voler avions en dépron, micro-hélicoptères et petits multirotors.",
                    'placeholder' => 'Photo Vol Indoor Agde',
                    'image'       => is_file('IMG/activites/indoor.jpg') ? 'IMG/activites/indoor.jpg' : ''
                ],
                [
                    'id'          => 'polyclubs',
                    'periode'     => 'Rencontres Club',
                    'titre'       => 'Coupe des Polyclubs & Journées Amicales',
                    'description' => "Journées de vols partagés sur nos pistes goudronnées, démonstrations, barbecues et échanges entre pilotes dans la bonne humeur.",
                    'placeholder' => 'Photo Journée Club',
                    'image'       => is_file('IMG/activites/polyclubs.jpg') ? 'IMG/activites/polyclubs.jpg' : ''
                ],
                [
                    'id'          => 'kamismac',
                    'periode'     => 'Événement Interclubs',
                    'titre'       => 'Le KamiSMAC',
                    'description' => "Rencontre conviviale et riche en vols réunissant passionnés d'aéromodélisme autour de démonstrations et de moments partagés.",
                    'placeholder' => 'Photo KamiSMAC',
                    'image'       => is_file('IMG/activites/kamismac.jpg') ? 'IMG/activites/kamismac.jpg' : ''
                ]
            ]
        ],
        'liens' => [
            'categories' => [
                [
                    'id'    => 'cat_institutions',
                    'titre' => '🏛️ Institutions & Réglementation',
                    'liens' => [
                        [
                            'titre'       => "Ville d'Agde",
                            'url'         => "https://www.ville-agde.fr/",
                            'description' => "Site officiel de la Mairie d'Agde, qui soutient le club et met à disposition nos salles indoor."
                        ],
                        [
                            'titre'       => "OLAP Aéromodélisme",
                            'url'         => "https://www.google.com/search?q=OLAP+aeromodelisme",
                            'description' => "Organisation Languedocienne d'Aéronautique Populaire à laquelle le club est affilié."
                        ],
                        [
                            'titre'       => "AlphaTango (DGAC)",
                            'url'         => "https://alphatango.aviation-civile.gouv.fr/login.jsp",
                            'description' => "Le portail officiel de l'Aviation Civile pour l'enregistrement des pilotes et des modèles."
                        ]
                    ]
                ],
                [
                    'id'    => 'cat_forums',
                    'titre' => '💬 Forums & Communautés',
                    'liens' => [
                        [
                            'titre'       => "Forum Modelisme.com",
                            'url'         => "https://www.modelisme.com/",
                            'description' => "Le plus grand forum francophone généraliste sur le modélisme."
                        ],
                        [
                            'titre'       => "Forum France-Helico",
                            'url'         => "https://www.france-helico.com/",
                            'description' => "La référence en ligne pour tous les passionnés de voilures tournantes."
                        ]
                    ]
                ]
            ]
        ]
    ];
}

// Charge le contenu personnalisé du site depuis DATA/contenu_site.json (avec rétrocompatibilité automatique)
function chargerContenuSite() {
    $cheminJson = dirname(__DIR__) . '/DATA/contenu_site.json';
    $defaut     = obtenirContenuSiteDefaut();

    if (!is_file($cheminJson)) {
        sauvegarderContenuSite($defaut);
        return $defaut;
    }

    $jsonBrut = @file_get_contents($cheminJson);
    $decode   = @json_decode($jsonBrut, true);
    if (!is_array($decode)) {
        return $defaut;
    }

    if (isset($decode['club']['presentation']['paragraphe_1']) and empty($decode['club']['presentation']['paragraphes'])) {
        $decode['club']['presentation']['paragraphes'] = array_values(array_filter([
            trim($decode['club']['presentation']['paragraphe_1'] ?? ''),
            trim($decode['club']['presentation']['paragraphe_2'] ?? '')
        ]));
    }
    if (isset($decode['club']['association']['texte_legal']) and empty($decode['club']['association']['paragraphes_legal'])) {
        $decode['club']['association']['paragraphes_legal'] = [trim($decode['club']['association']['texte_legal'])];
    }
    if (isset($decode['club']['inscription']['texte_intro']) and empty($decode['club']['inscription']['paragraphes_intro'])) {
        $decode['club']['inscription']['paragraphes_intro'] = [trim($decode['club']['inscription']['texte_intro'])];
    }

    $fusion = array_replace_recursive($defaut, $decode);
    if (!empty($decode['club']['presentation']['paragraphes'])) {
        $fusion['club']['presentation']['paragraphes'] = array_values($decode['club']['presentation']['paragraphes']);
    }
    if (!empty($decode['club']['association']['paragraphes_legal'])) {
        $fusion['club']['association']['paragraphes_legal'] = array_values($decode['club']['association']['paragraphes_legal']);
    }
    if (!empty($decode['club']['association']['bureau'])) {
        $fusion['club']['association']['bureau'] = array_values($decode['club']['association']['bureau']);
    }
    if (!empty($decode['club']['inscription']['paragraphes_intro'])) {
        $fusion['club']['inscription']['paragraphes_intro'] = array_values($decode['club']['inscription']['paragraphes_intro']);
    }

    return $fusion;
}

// Enregistre les modifications de contenu dans DATA/contenu_site.json
function sauvegarderContenuSite($contenu) {
    $dossierData = dirname(__DIR__) . '/DATA';
    if (!is_dir($dossierData)) {
        mkdir($dossierData, 0755, true);
    }
    $cheminJson = $dossierData . '/contenu_site.json';
    return @file_put_contents(
        $cheminJson,
        json_encode($contenu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) !== false;
}
?>
