<?php
// ==========================================================================
// GESTION DES MEMBRES, SYNCHRONISATION YAPLA & CARTES - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// Sécurité : accès strictement réservé aux administrateurs
$estConnecte = isset($_SESSION['membre']);
$role        = $_SESSION['membre']['role'] ?? null;

if (!$estConnecte or $role !== 'admin') {
    header('Location: index.php');
    exit;
}

$erreur = '';
$succes = '';

$rolesAutorises  = ['attente_validation', 'membre_associe', 'membre', 'bureau', 'admin'];
$saisonParDefaut = ((int)date('n') >= 9) ? ((int)date('Y') + 1) : (int)date('Y');

// --- 1. TRAITEMENT DES ACTIONS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. SYNCHRONISATION AUTOMATIQUE DEPUIS LE FICHIER EXCEL COMPLET YAPLA (.XLSX)
    if ($action === 'importer_excel') {
        if (empty($_FILES['fichier_excel']['name']) or $_FILES['fichier_excel']['error'] !== UPLOAD_ERR_OK) {
            $erreur = "Veuillez sélectionner un fichier Excel (.xlsx) valide exporté depuis Yapla.";
        } else {
            $ext = strtolower(pathinfo($_FILES['fichier_excel']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                $erreur = "Format invalide : veuillez importer uniquement un fichier au format .xlsx.";
            } else {
                $adherentsExcel = lireFichierExcelYapla($_FILES['fichier_excel']['tmp_name']);

                if ($adherentsExcel === false) {
                    $erreur = "Impossible de lire le fichier Excel. Vérifiez qu'il s'agit bien de l'export Yapla non corrompu.";
                } elseif (empty($adherentsExcel)) {
                    $erreur = "Aucun adhérent avec un numéro au format APHD-YY-MM-000 n'a été trouvé dans ce fichier.";
                } else {
                    $membresActuels = [];
                    foreach ($pdo->query("SELECT * FROM membres")->fetchAll() as $m) {
                        $membresActuels[$m['num_licence']] = $m;
                    }

                    $nbAjoutes   = 0;
                    $nbMisAJour  = 0;
                    $nbSupprimes = 0;

                    foreach ($adherentsExcel as $numLicence => $infoExcel) {
                        $photoUrl      = !empty($infoExcel['photo_url']) ? $infoExcel['photo_url'] : null;
                        $dateNaissance = !empty($infoExcel['date_naissance']) ? $infoExcel['date_naissance'] : null;
                        $numUas        = !empty($infoExcel['num_uas']) ? $infoExcel['num_uas'] : null;
                        $dateValUas    = !empty($infoExcel['date_val_uas']) ? $infoExcel['date_val_uas'] : null;
                        $dateFormation = !empty($infoExcel['date_formation']) ? $infoExcel['date_formation'] : null;

                        if (!isset($membresActuels[$numLicence])) {
                            $identifiant = genererIdentifiantUnique($pdo, $infoExcel['prenom'], $infoExcel['nom']);
                            $mdpHash     = password_hash($numLicence, PASSWORD_DEFAULT);

                            $stmtIns = $pdo->prepare("
                                INSERT INTO membres (
                                    nom, prenom, email, photo_url, date_naissance,
                                    num_licence, identifiant, mot_de_passe, role,
                                    date_expiration, num_uas, date_val_uas, date_formation
                                ) VALUES (
                                    :nom, :prenom, :email, :photo, :naissance,
                                    :licence, :identifiant, :mdp, :role,
                                    :date_exp, :num_uas, :val_uas, :form_uas
                                )
                            ");
                            $stmtIns->execute([
                                ':nom'         => $infoExcel['nom'],
                                ':prenom'      => $infoExcel['prenom'],
                                ':email'       => $infoExcel['email'],
                                ':photo'       => $photoUrl,
                                ':naissance'   => $dateNaissance,
                                ':licence'     => $numLicence,
                                ':identifiant' => $identifiant,
                                ':mdp'         => $mdpHash,
                                ':role'        => $infoExcel['role_yapla'],
                                ':date_exp'    => $infoExcel['date_expiration'],
                                ':num_uas'     => $numUas,
                                ':val_uas'     => $dateValUas,
                                ':form_uas'    => $dateFormation
                            ]);
                            $nbAjoutes++;
                        } else {
                            $existant = $membresActuels[$numLicence];

                            if (in_array($existant['role'], ['admin', 'bureau'])) {
                                $nouveauRole = $existant['role'];
                            } elseif ($existant['role'] === 'membre_associe' and $infoExcel['role_yapla'] !== 'attente_validation') {
                                $nouveauRole = 'membre_associe';
                            } else {
                                $nouveauRole = $infoExcel['role_yapla'];
                            }

                            $nouvelleDateExp = $infoExcel['date_expiration'];
                            $nouvelEmail     = $infoExcel['email'];

                            if (
                                $existant['role'] !== $nouveauRole or
                                $existant['date_expiration'] !== $nouvelleDateExp or
                                $existant['email'] !== $nouvelEmail or
                                $existant['nom'] !== $infoExcel['nom'] or
                                $existant['prenom'] !== $infoExcel['prenom'] or
                                ($existant['photo_url'] ?? null) !== $photoUrl or
                                ($existant['date_naissance'] ?? null) !== $dateNaissance or
                                ($existant['num_uas'] ?? null) !== $numUas or
                                ($existant['date_val_uas'] ?? null) !== $dateValUas or
                                ($existant['date_formation'] ?? null) !== $dateFormation
                            ) {
                                $stmtUpd = $pdo->prepare("
                                    UPDATE membres 
                                    SET nom = :nom, prenom = :prenom, email = :email,
                                        photo_url = :photo, date_naissance = :naissance,
                                        role = :role, date_expiration = :date_exp,
                                        num_uas = :num_uas, date_val_uas = :val_uas, date_formation = :form_uas
                                    WHERE id = :id
                                ");
                                $stmtUpd->execute([
                                    ':nom'       => $infoExcel['nom'],
                                    ':prenom'    => $infoExcel['prenom'],
                                    ':email'     => $nouvelEmail,
                                    ':photo'     => $photoUrl,
                                    ':naissance' => $dateNaissance,
                                    ':role'      => $nouveauRole,
                                    ':date_exp'  => $nouvelleDateExp,
                                    ':num_uas'   => $numUas,
                                    ':val_uas'   => $dateValUas,
                                    ':form_uas'  => $dateFormation,
                                    ':id'        => $existant['id']
                                ]);
                                $nbMisAJour++;
                            }
                        }
                    }

                    foreach ($membresActuels as $numLicence => $mBase) {
                        if (!isset($adherentsExcel[$numLicence]) and $mBase['role'] !== 'admin') {
                            $stmtDel = $pdo->prepare("DELETE FROM membres WHERE id = :id");
                            $stmtDel->execute([':id' => $mBase['id']]);
                            $nbSupprimes++;
                        }
                    }

                    $succes = "📊 Synchronisation Yapla terminée (Comptes & Cartes) : <strong>$nbAjoutes</strong> compte(s) créé(s), <strong>$nbMisAJour</strong> mis à jour, et <strong>$nbSupprimes</strong> sortant(s) supprimé(s) (les comptes Admin sont toujours conservés).";
                }
            }
        }
    }

    // B. AJOUTER MANUELLEMENT UN MEMBRE OU UN COMPTE ADMIN HORS-CLUB
    elseif ($action === 'ajouter') {
        $prenom          = trim($_POST['prenom'] ?? '');
        $nom             = strtoupper(trim($_POST['nom'] ?? ''));
        $email           = strtolower(trim($_POST['email'] ?? ''));
        $email           = !empty($email) ? $email : null;
        $num_licence     = strtoupper(trim($_POST['num_licence'] ?? ''));
        $nouveau_role    = $_POST['role'] ?? 'attente_validation';
        $date_expiration = !empty($_POST['date_expiration']) ? $_POST['date_expiration'] : null;

        if (!in_array($nouveau_role, $rolesAutorises)) {
            $nouveau_role = 'attente_validation';
        }

        if (empty($prenom) or empty($nom) or empty($num_licence)) {
            $erreur = "Veuillez remplir le prénom, le nom et le numéro d'adhérent.";
        } elseif (!preg_match('/^APHD-\d{2}-\d{2}-\d{3}$/', $num_licence)) {
            $erreur = "Le numéro d'adhérent doit respecter exactement le format Yapla : APHD-YY-MM-000 (ex: APHD-26-10-001).";
        } else {
            $checkLicence = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE num_licence = :licence");
            $checkLicence->execute([':licence' => $num_licence]);
            if ((int)$checkLicence->fetchColumn() > 0) {
                $erreur = "⚠️ Attention : Ce numéro d'adhérent ($num_licence) existe déjà dans la base de données.";
            } else {
                $identifiant = genererIdentifiantUnique($pdo, $prenom, $nom);
                $mdpHash     = password_hash($num_licence, PASSWORD_DEFAULT);

                try {
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO membres (nom, prenom, email, num_licence, identifiant, mot_de_passe, role, date_expiration)
                        VALUES (:nom, :prenom, :email, :licence, :identifiant, :mdp, :role, :date_exp)
                    ");
                    $stmtInsert->execute([
                        ':nom'         => $nom,
                        ':prenom'      => $prenom,
                        ':email'       => $email,
                        ':licence'     => $num_licence,
                        ':identifiant' => $identifiant,
                        ':mdp'         => $mdpHash,
                        ':role'        => $nouveau_role,
                        ':date_exp'    => $date_expiration
                    ]);

                    $succes = "✅ Compte créé ! Identifiant : <strong>$identifiant</strong> | Mot de passe : <strong>$num_licence</strong>";
                } catch (PDOException $e) {
                    $erreur = "Erreur technique lors de l'enregistrement du membre.";
                }
            }
        }
    }

    // C. MODIFIER MANUELLEMENT LE RÔLE, L'EMAIL OU LA DATE D'EXPIRATION D'UN MEMBRE
    elseif ($action === 'changer_role') {
        $membreId       = (int)($_POST['membre_id'] ?? 0);
        $nouveauRole    = $_POST['nouveau_role'] ?? 'membre';
        $dateExpiration = !empty($_POST['date_expiration']) ? $_POST['date_expiration'] : null;
        $nouvelEmail    = strtolower(trim($_POST['email'] ?? ''));
        $nouvelEmail    = !empty($nouvelEmail) ? $nouvelEmail : null;

        if ($membreId === (int)$_SESSION['membre']['id'] and $nouveauRole !== 'admin') {
            $erreur = "Par sécurité, vous ne pouvez pas retirer votre propre rôle d'administrateur.";
        } elseif (in_array($nouveauRole, $rolesAutorises)) {
            $stmtRole = $pdo->prepare("UPDATE membres SET role = :role, date_expiration = :date_exp, email = :email WHERE id = :id");
            $stmtRole->execute([
                ':role'     => $nouveauRole,
                ':date_exp' => $dateExpiration,
                ':email'    => $nouvelEmail,
                ':id'       => $membreId
            ]);
            $succes = "✅ Les informations du membre (statut, e-mail et expiration) ont bien été mises à jour.";
        }
    }

    // D. MODIFIER MANUELLEMENT LE N° ADHÉRENT / MOT DE PASSE
    elseif ($action === 'reset_mdp') {
        $membreId          = (int)($_POST['membre_id'] ?? 0);
        $nouveauNumLicence = strtoupper(trim($_POST['nouveau_num_licence'] ?? ''));

        if (!preg_match('/^APHD-\d{2}-\d{2}-\d{3}$/', $nouveauNumLicence)) {
            $erreur = "Le nouveau numéro / mot de passe doit respecter le format APHD-YY-MM-000 (ex: APHD-26-10-001).";
        } else {
            $checkDoublon = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE num_licence = :licence AND id != :id");
            $checkDoublon->execute([':licence' => $nouveauNumLicence, ':id' => $membreId]);

            if ((int)$checkDoublon->fetchColumn() > 0) {
                $erreur = "⚠️ Attention : Le numéro $nouveauNumLicence est déjà attribué à un autre membre dans la base.";
            } else {
                $stmtMembre = $pdo->prepare("SELECT prenom, nom FROM membres WHERE id = :id");
                $stmtMembre->execute([':id' => $membreId]);
                $m = $stmtMembre->fetch();

                if ($m) {
                    $stmtReset = $pdo->prepare("
                        UPDATE membres 
                        SET num_licence = :licence, mot_de_passe = :mdp 
                        WHERE id = :id
                    ");
                    $stmtReset->execute([
                        ':licence' => $nouveauNumLicence,
                        ':mdp'     => password_hash($nouveauNumLicence, PASSWORD_DEFAULT),
                        ':id'      => $membreId
                    ]);
                    $succes = "🔑 N° d'adhérent et mot de passe mis à jour pour <strong>" . htmlspecialchars($m['prenom'] . ' ' . $m['nom']) . "</strong> : <strong>$nouveauNumLicence</strong>";
                }
            }
        }
    }

    // E. SUPPRIMER MANUELLEMENT UN MEMBRE
    elseif ($action === 'supprimer') {
        $membreId = (int)($_POST['membre_id'] ?? 0);
        if ($membreId === (int)$_SESSION['membre']['id']) {
            $erreur = "Vous ne pouvez pas supprimer votre propre compte administrateur.";
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM membres WHERE id = :id");
            $stmtDel->execute([':id' => $membreId]);
            $succes = "🗑️ Le compte du membre a été supprimé (ses articles et messages du forum ont été conservés).";
        }
    }
}

// --- 2. RÉCUPÉRATION DE LA LISTE DES MEMBRES ---
$listeMembres = $pdo->query("
    SELECT * FROM membres 
    ORDER BY FIELD(role, 'admin', 'bureau', 'membre', 'membre_associe', 'attente_validation'), nom ASC, prenom ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Membres - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/admin.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <main>
        <?php if ($erreur): ?>
            <div class="admin-box admin-box-wide" style="padding: 20px; margin-bottom: 20px;">
                <div class="alert-error" style="margin-bottom: 0;"><?= htmlspecialchars($erreur) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($succes): ?>
            <div class="admin-box admin-box-wide" style="padding: 20px; margin-bottom: 20px;">
                <div class="alert-success" style="margin-bottom: 0;"><?= $succes ?></div>
            </div>
        <?php endif; ?>

        <!-- BLOC 1 : IMPORT ET SYNCHRONISATION DU FICHIER EXCEL COMPLET YAPLA (.XLSX) -->
        <div class="admin-box admin-box-wide">
            <h1>📥 Synchroniser les adhérents et les cartes depuis Yapla (.xlsx)</h1>
            <p style="margin-bottom: 20px; color: var(--ka-gris-fonce);">
                Importez le fichier Excel complet généré par Yapla (<em>Liste des inscriptions de membres</em>). Le site mettra automatiquement à jour les comptes (ajout des nouveaux, e-mails, dates d'expiration, suppression des anciens membres sortants hors Admin) <strong>ainsi que toutes les informations des cartes membres et photos d'identité</strong>.
            </p>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="importer_excel">
                <div class="form-group">
                    <label for="fichier_excel">Fichier Excel Yapla complet (.xlsx) *</label>
                    <input type="file" id="fichier_excel" name="fichier_excel" accept=".xlsx" required>
                    <span class="form-help">
                        Colonnes synchronisées : <code>N° d'adhérent</code>, <code>Prénom</code>, <code>Nom</code>, <code>Courriel</code>, <code>Photo d'identité</code>, <code>Date de naissance</code>, <code>N° UAS</code>, <code>Validité UAS</code>, <code>Formation télépilote</code>, <code>Type &amp; Statut d'adhésion</code> et <code>Expiration</code>.
                    </span>
                </div>
                <button type="submit" class="btn-primary btn-submit">🔄 Lancer la synchronisation Yapla</button>
            </form>
        </div>

        <!-- BLOC 2 : GÉNÉRATEUR DES CARTES D'ADHÉRENTS DEPUIS LA BASE DE DONNÉES -->
        <div class="admin-box admin-box-wide">
            <h1>🪪 Générer la planche des cartes d'adhérents (PDF / Impression)</h1>
            <p style="margin-bottom: 20px; color: var(--ka-gris-fonce);">
                Générez en un clic la planche PDF de toutes les cartes des membres enregistrés dans la base de données. Chaque membre connecté peut également télécharger sa propre carte individuelle depuis son <strong>Espace Membres</strong>.
            </p>

            <form method="GET" action="admin_cartes.php" target="_blank">
                <div class="form-row">
                    <div class="form-group">
                        <label for="saison_cartes">Année de la Saison à afficher sur les cartes *</label>
                        <input type="text" id="saison_cartes" name="saison" value="<?= $saisonParDefaut ?>" required pattern="\d{4}" maxlength="4" placeholder="Ex: 2027">
                        <span class="form-help">S'adapte automatiquement (ex : <strong>Saison <?= $saisonParDefaut ?></strong>) et reste modifiable.</span>
                    </div>

                    <div class="form-group" style="display:flex; align-items:center; padding-top:15px;">
                        <button type="submit" class="btn-primary btn-submit" style="width:100%;">
                            🪪 Ouvrir et générer les cartes d'adhérents (PDF)
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- BLOC 3 : AJOUT MANUEL D'UN MEMBRE OU D'UN COMPTE ADMIN DÉDIÉ AU SITE -->
        <div class="admin-box admin-box-wide">
            <h1>➕ Ajouter manuellement un compte (Membre, Associé ou Admin hors-club)</h1>

            <form method="POST">
                <input type="hidden" name="action" value="ajouter">

                <div class="form-row">
                    <div class="form-group">
                        <label for="prenom">Prénom *</label>
                        <input type="text" id="prenom" name="prenom" required placeholder="Ex: Norbert">
                    </div>

                    <div class="form-group">
                        <label for="nom">Nom *</label>
                        <input type="text" id="nom" name="nom" required placeholder="Ex: JAMMES">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Adresse e-mail (Notifications forum &amp; articles)</label>
                        <input type="text" id="email" name="email" placeholder="Ex: pilote@exemple.fr">
                    </div>

                    <div class="form-group">
                        <label for="num_licence">N° d'adhérent Yapla (Mot de passe) *</label>
                        <input type="text" id="num_licence" name="num_licence" required maxlength="14" pattern="APHD-\d{2}-\d{2}-\d{3}" placeholder="Ex: APHD-26-10-001" title="Format obligatoire : APHD-YY-MM-000 (ex: APHD-26-10-001)">
                        <span class="form-help">Format strict : <strong>APHD-YY-MM-000</strong>.</span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="date_expiration">Date d'expiration de l'adhésion (Optionnel)</label>
                        <input type="date" id="date_expiration" name="date_expiration">
                        <span class="form-help">Laissez vide pour un compte Admin permanent ou en attente.</span>
                    </div>

                    <div class="form-group">
                        <label for="role">Statut / Rôle sur le site *</label>
                        <select id="role" name="role">
                            <option value="attente_validation">⏳ En attente de validation (Aucun accès)</option>
                            <option value="membre_associe">🤝 Membre associé (Lecture privée + Forum + Carte + Proposition d'article)</option>
                            <option value="membre" selected>👤 Membre validé (Lecture privée + Forum + Proposition d'article)</option>
                            <option value="bureau">📝 Membre du Bureau (Peut publier/valider des articles)</option>
                            <option value="admin">👑 Administrateur / Président (Accès total + Protégé)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-primary btn-submit">Créer le compte manuellement</button>
            </form>
        </div>

        <!-- BLOC 4 : TABLEAU DES MEMBRES ENREGISTRÉS -->
        <div class="admin-box admin-box-wide">
            <h1>👥 Comptes enregistrés sur le site (<?= count($listeMembres) ?>)</h1>

            <div class="table-responsive">
                <table class="members-table">
                    <thead>
                        <tr>
                            <th>Nom &amp; Prénom</th>
                            <th>Identifiant</th>
                            <th>N° Adhérent (MDP)</th>
                            <th>Expiration</th>
                            <th>Statut</th>
                            <th>Actions (Statut, E-mail &amp; Expiration / MDP / Suppr.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listeMembres as $m): ?>
                            <?php
                                $estExpire = (!empty($m['date_expiration']) and $m['date_expiration'] < date('Y-m-d') and $m['role'] !== 'admin');
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($m['nom'] . ' ' . $m['prenom']) ?></strong>
                                    <?php if (!empty($m['email'])): ?>
                                        <br><span class="form-help" style="margin-top:2px;">✉️ <?= htmlspecialchars($m['email']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($m['identifiant']) ?></code></td>
                                <td><code><?= htmlspecialchars($m['num_licence']) ?></code></td>
                                <td>
                                    <?php if ($m['role'] === 'admin' and empty($m['date_expiration'])): ?>
                                        <span class="exp-badge exp-illimite">Permanent</span>
                                    <?php elseif (!empty($m['date_expiration'])): ?>
                                        <span class="exp-badge <?= $estExpire ? 'exp-expire' : 'exp-valide' ?>">
                                            <?= formaterDate($m['date_expiration']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="form-help">Non définie</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="role-badge role-<?= htmlspecialchars($m['role']) ?>">
                                        <?= htmlspecialchars(formaterLibelleRole($m['role'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <form method="POST" class="table-actions">
                                            <input type="hidden" name="action" value="changer_role">
                                            <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                                            <select name="nouveau_role" class="select-role-inline" title="Statut du compte">
                                                <option value="attente_validation" <?= $m['role'] === 'attente_validation' ? 'selected' : '' ?>>⏳ Attente</option>
                                                <option value="membre_associe" <?= $m['role'] === 'membre_associe' ? 'selected' : '' ?>>🤝 Associé</option>
                                                <option value="membre" <?= $m['role'] === 'membre' ? 'selected' : '' ?>>👤 Membre</option>
                                                <option value="bureau" <?= $m['role'] === 'bureau' ? 'selected' : '' ?>>📝 Bureau</option>
                                                <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>👑 Admin</option>
                                            </select>
                                            <input type="text" name="email" value="<?= htmlspecialchars($m['email'] ?? '') ?>" placeholder="E-mail" class="input-aphd-inline" style="width:155px;" title="Adresse e-mail du membre">
                                            <input type="date" name="date_expiration" value="<?= htmlspecialchars($m['date_expiration'] ?? '') ?>" class="input-date-inline" title="Date d'expiration de l'adhésion">
                                            <button type="submit" class="btn-small" title="Enregistrer">💾 OK</button>
                                        </form>

                                        <form method="POST" class="table-actions" onsubmit="return confirm('Modifier le numéro d\'adhérent et réinitialiser le mot de passe de ce membre ?');">
                                            <input type="hidden" name="action" value="reset_mdp">
                                            <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                                            <input type="text" name="nouveau_num_licence" required maxlength="14" pattern="APHD-\d{2}-\d{2}-\d{3}" placeholder="APHD-YY-MM-000" class="input-aphd-inline" title="Format obligatoire : APHD-YY-MM-000">
                                            <button type="submit" class="btn-small" title="Mettre à jour le mot de passe">🔑 MDP</button>
                                        </form>

                                        <?php if ((int)$m['id'] !== (int)$_SESSION['membre']['id']): ?>
                                            <form method="POST" onsubmit="return confirm('Supprimer définitivement ce compte ? Ses articles et messages du forum seront conservés.');">
                                                <input type="hidden" name="action" value="supprimer">
                                                <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                                                <button type="submit" class="btn-small btn-small-danger" title="Supprimer le membre">🗑️</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="form-help">Votre compte</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
