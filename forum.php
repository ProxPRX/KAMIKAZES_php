<?php
// ==========================================================================
// FORUM PRIVÉ DES MEMBRES (AVEC CITATIONS & NOTIFICATIONS MAIL) - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// Sécurité : l'accès au forum est réservé aux membres connectés
$estConnecte = isset($_SESSION['membre']);
if (!$estConnecte) {
    header('Location: connexion.php');
    exit;
}

$membreId         = (int)$_SESSION['membre']['id'];
$role             = $_SESSION['membre']['role'];
$estAdmin         = ($role === 'admin');
$estAdminOuBureau = in_array($role, ['admin', 'bureau']);
$nomConnecte      = afficherNomAuteur($_SESSION['membre']['prenom'] ?? '', $_SESSION['membre']['nom'] ?? '');

$sujetId       = isset($_GET['sujet']) ? (int)$_GET['sujet'] : 0;
$editMsgId     = isset($_GET['edit_msg']) ? (int)$_GET['edit_msg'] : 0;
$replyToId     = isset($_GET['reply_to']) ? (int)$_GET['reply_to'] : 0;
$editTitreMode = isset($_GET['edit_titre']);
$erreur        = '';

// --- 1. TRAITEMENT DES ACTIONS POST (CRÉATION, RÉPONSE, ÉDITION, SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. Créer un nouveau sujet de discussion et enregistrer son premier message
    if ($action === 'nouveau_sujet') {
        $titre   = trim($_POST['titre'] ?? '');
        $contenu = trim($_POST['contenu'] ?? '');

        if (empty($titre) or empty($contenu)) {
            $erreur = "Veuillez remplir le titre du sujet et votre message.";
        } else {
            $stmtSujet = $pdo->prepare("INSERT INTO forum_sujets (titre, auteur_id) VALUES (:titre, :auteur)");
            $stmtSujet->execute([':titre' => $titre, ':auteur' => $membreId]);
            $nouveauSujetId = (int)$pdo->lastInsertId();

            $stmtMsg = $pdo->prepare("INSERT INTO forum_messages (sujet_id, auteur_id, contenu) VALUES (:sujet, :auteur, :contenu)");
            $stmtMsg->execute([
                ':sujet'   => $nouveauSujetId,
                ':auteur'  => $membreId,
                ':contenu' => $contenu
            ]);

            header("Location: forum.php?sujet=$nouveauSujetId");
            exit;
        }
    }

    // B. Modifier le titre d'un sujet (autorisé pour le créateur du sujet, le Bureau ou l'Admin)
    elseif ($action === 'modifier_titre_sujet' and $sujetId > 0) {
        $nouveauTitre = trim($_POST['titre'] ?? '');
        $stmtCheckS = $pdo->prepare("SELECT auteur_id FROM forum_sujets WHERE id = :id");
        $stmtCheckS->execute([':id' => $sujetId]);
        $sujetVerif = $stmtCheckS->fetch();

        if ($sujetVerif and !empty($nouveauTitre) and ((int)$sujetVerif['auteur_id'] === $membreId or $estAdminOuBureau)) {
            $stmtUpdTitre = $pdo->prepare("UPDATE forum_sujets SET titre = :titre, date_modification = NOW() WHERE id = :id");
            $stmtUpdTitre->execute([':titre' => $nouveauTitre, ':id' => $sujetId]);
        }
        header("Location: forum.php?sujet=$sujetId");
        exit;
    }

    // C. Répondre à un sujet existant (réponse générale ou réponse à un message précis)
    elseif ($action === 'repondre' and $sujetId > 0) {
        $contenu    = trim($_POST['contenu'] ?? '');
        $reponseAId = !empty($_POST['reponse_a_id']) ? (int)$_POST['reponse_a_id'] : null;

        if (!empty($contenu)) {
            // Vérification que le message cité appartient bien à ce sujet
            $infoMessageCite = null;
            if ($reponseAId !== null) {
                $stmtCite = $pdo->prepare("
                    SELECT fm.id, fm.auteur_id, m.prenom, m.nom, m.email 
                    FROM forum_messages fm 
                    LEFT JOIN membres m ON fm.auteur_id = m.id 
                    WHERE fm.id = :id AND fm.sujet_id = :sujet
                ");
                $stmtCite->execute([':id' => $reponseAId, ':sujet' => $sujetId]);
                $infoMessageCite = $stmtCite->fetch();
                if (!$infoMessageCite) {
                    $reponseAId = null;
                }
            }

            // Enregistrement de la réponse en base de données
            $stmtMsg = $pdo->prepare("
                INSERT INTO forum_messages (sujet_id, auteur_id, reponse_a_id, contenu) 
                VALUES (:sujet, :auteur, :reponse_a, :contenu)
            ");
            $stmtMsg->execute([
                ':sujet'     => $sujetId,
                ':auteur'    => $membreId,
                ':reponse_a' => $reponseAId,
                ':contenu'   => $contenu
            ]);
            $nouveauMsgId = (int)$pdo->lastInsertId();

            // --- LOGIQUE D'ENVOI DES NOTIFICATIONS E-MAIL CROISÉES ---
            $stmtInfoSujet = $pdo->prepare("
                SELECT s.titre, s.auteur_id, m.prenom, m.nom, m.email 
                FROM forum_sujets s 
                LEFT JOIN membres m ON s.auteur_id = m.id 
                WHERE s.id = :id
            ");
            $stmtInfoSujet->execute([':id' => $sujetId]);
            $infoSujet = $stmtInfoSujet->fetch();

            if ($infoSujet) {
                $proprietaireSujetId = !empty($infoSujet['auteur_id']) ? (int)$infoSujet['auteur_id'] : 0;
                $auteurMsgCiteId     = ($infoMessageCite and !empty($infoMessageCite['auteur_id'])) ? (int)$infoMessageCite['auteur_id'] : 0;

                // 1. Notification à l'auteur du message cité
                if ($auteurMsgCiteId > 0 and $auteurMsgCiteId !== $membreId and !empty($infoMessageCite['email'])) {
                    envoyerMailNotificationForum(
                        $infoMessageCite['email'],
                        $infoMessageCite['prenom'],
                        $nomConnecte,
                        $infoSujet['titre'],
                        $sujetId,
                        $nouveauMsgId,
                        $contenu,
                        true
                    );
                }

                // 2. Notification au propriétaire de la discussion
                if (
                    $proprietaireSujetId > 0 and
                    $proprietaireSujetId !== $membreId and
                    $proprietaireSujetId !== $auteurMsgCiteId and
                    !empty($infoSujet['email'])
                ) {
                    envoyerMailNotificationForum(
                        $infoSujet['email'],
                        $infoSujet['prenom'],
                        $nomConnecte,
                        $infoSujet['titre'],
                        $sujetId,
                        $nouveauMsgId,
                        $contenu,
                        false
                    );
                }
            }

            header("Location: forum.php?sujet=$sujetId#msg-$nouveauMsgId");
            exit;
        }
    }

    // D. Modifier un message (autorisé pour l'auteur du message, le Bureau ou un Administrateur)
    elseif ($action === 'modifier_message' and $sujetId > 0) {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $contenu   = trim($_POST['contenu'] ?? '');

        $stmtCheck = $pdo->prepare("SELECT auteur_id FROM forum_messages WHERE id = :id AND sujet_id = :sujet");
        $stmtCheck->execute([':id' => $messageId, ':sujet' => $sujetId]);
        $msg = $stmtCheck->fetch();

        if ($msg and !empty($contenu) and ((int)$msg['auteur_id'] === $membreId or $estAdminOuBureau)) {
            $stmtUpd = $pdo->prepare("UPDATE forum_messages SET contenu = :contenu, date_modification = NOW() WHERE id = :id");
            $stmtUpd->execute([':contenu' => $contenu, ':id' => $messageId]);
        }
        header("Location: forum.php?sujet=$sujetId#msg-$messageId");
        exit;
    }

    // E. Supprimer une réponse
    elseif ($action === 'supprimer_message' and $sujetId > 0) {
        $messageId = (int)($_POST['message_id'] ?? 0);

        $stmtFirst = $pdo->prepare("SELECT id FROM forum_messages WHERE sujet_id = :sujet ORDER BY date_publication ASC, id ASC LIMIT 1");
        $stmtFirst->execute([':sujet' => $sujetId]);
        $premierMsgId = (int)$stmtFirst->fetchColumn();

        $stmtCheck = $pdo->prepare("SELECT auteur_id FROM forum_messages WHERE id = :id AND sujet_id = :sujet");
        $stmtCheck->execute([':id' => $messageId, ':sujet' => $sujetId]);
        $msg = $stmtCheck->fetch();

        if ($msg) {
            $estSonPropreMessage = ((int)$msg['auteur_id'] === $membreId);
            $estPremierMessage   = ($messageId === $premierMsgId);

            if ($estAdminOuBureau or ($estSonPropreMessage and !$estPremierMessage)) {
                $stmtDel = $pdo->prepare("DELETE FROM forum_messages WHERE id = :id AND sujet_id = :sujet");
                $stmtDel->execute([':id' => $messageId, ':sujet' => $sujetId]);
            }
        }
        header("Location: forum.php?sujet=$sujetId");
        exit;
    }

    // F. Supprimer un sujet complet et tous ses messages (Admin et Bureau)
    elseif ($action === 'supprimer_sujet' and $estAdminOuBureau) {
        $cibleSujetId = (int)($_POST['sujet_id'] ?? 0);
        $stmtDelSujet = $pdo->prepare("DELETE FROM forum_sujets WHERE id = :id");
        $stmtDelSujet->execute([':id' => $cibleSujetId]);
        header("Location: forum.php");
        exit;
    }
}

// --- 2. CHARGEMENT DES DONNÉES SELON LA VUE ---
$sujetActuel      = null;
$messages         = [];
$sujets           = [];
$messageAEditer   = null;
$messageARepondre = null;
$premierMessageId = 0;

if ($sujetId > 0) {
    $stmtS = $pdo->prepare("
        SELECT s.*, m.prenom, m.nom 
        FROM forum_sujets s 
        LEFT JOIN membres m ON s.auteur_id = m.id 
        WHERE s.id = :id
    ");
    $stmtS->execute([':id' => $sujetId]);
    $sujetActuel = $stmtS->fetch();

    if (!$sujetActuel) {
        header('Location: forum.php');
        exit;
    }

    $stmtM = $pdo->prepare("
        SELECT fm.*, 
               m.prenom, m.nom, m.role,
               parent.contenu AS parent_contenu,
               pm.prenom AS parent_prenom,
               pm.nom AS parent_nom
        FROM forum_messages fm 
        LEFT JOIN membres m ON fm.auteur_id = m.id 
        LEFT JOIN forum_messages parent ON fm.reponse_a_id = parent.id
        LEFT JOIN membres pm ON parent.auteur_id = pm.id
        WHERE fm.sujet_id = :sujet 
        ORDER BY fm.date_publication ASC, fm.id ASC
    ");
    $stmtM->execute([':sujet' => $sujetId]);
    $messages = $stmtM->fetchAll();

    if (!empty($messages)) {
        $premierMessageId = (int)$messages[0]['id'];
    }

    foreach ($messages as $m) {
        if ($editMsgId > 0 and (int)$m['id'] === $editMsgId and ((int)$m['auteur_id'] === $membreId or $estAdminOuBureau)) {
            $messageAEditer = $m;
        }
        if ($replyToId > 0 and (int)$m['id'] === $replyToId) {
            $messageARepondre = $m;
        }
    }
} else {
    $sujets = $pdo->query("
        SELECT s.*, m.prenom, m.nom,
               (SELECT COUNT(*) FROM forum_messages fm WHERE fm.sujet_id = s.id) AS nb_messages,
               (SELECT MAX(date_publication) FROM forum_messages fm WHERE fm.sujet_id = s.id) AS derniere_activite
        FROM forum_sujets s
        LEFT JOIN membres m ON s.auteur_id = m.id
        ORDER BY COALESCE(derniere_activite, s.date_creation) DESC
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forum Membres - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/admin.css?v=8">
    <link rel="stylesheet" href="CSS/forum.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <section class="hero">
        <div class="hero-content">
            <h2>💬 Forum des Membres</h2>
            <p>Espace d'échange, d'entraide technique et de rendez-vous au terrain des Kamikazes Agathois.</p>
        </div>
    </section>

    <main>
        <?php if ($sujetActuel): ?>
            <!-- VUE 1 : LECTURE D'UN SUJET ET DE SES MESSAGES -->
            <div class="topic-top-bar">
                <a href="forum.php" class="back-link">&larr; Retour à la liste des sujets</a>

                <div class="message-actions">
                    <?php if ((int)$sujetActuel['auteur_id'] === $membreId or $estAdminOuBureau): ?>
                        <a href="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>&edit_titre=1" class="btn-small">✏️ Modifier le titre</a>
                    <?php endif; ?>

                    <?php if ($estAdminOuBureau): ?>
                        <form method="POST" onsubmit="return confirm('Supprimer définitivement cette discussion et toutes ses réponses ?');">
                            <input type="hidden" name="action" value="supprimer_sujet">
                            <input type="hidden" name="sujet_id" value="<?= (int)$sujetActuel['id'] ?>">
                            <button type="submit" class="btn-small btn-small-danger">🗑️ Supprimer tout le sujet</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Titre du sujet (ou formulaire de modification du titre) -->
            <?php if ($editTitreMode and ((int)$sujetActuel['auteur_id'] === $membreId or $estAdminOuBureau)): ?>
                <div class="admin-box admin-box-wide" style="padding: 20px; margin-bottom: 25px;">
                    <form method="POST" action="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <input type="hidden" name="action" value="modifier_titre_sujet">
                        <input type="text" name="titre" value="<?= htmlspecialchars($sujetActuel['titre']) ?>" required style="flex:1; min-width:240px; padding:10px; border:1px solid var(--ka-gris-clair); border-radius:5px;">
                        <button type="submit" class="btn-small">💾 Enregistrer le titre</button>
                        <a href="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>" class="back-link">Annuler</a>
                    </form>
                </div>
            <?php else: ?>
                <div class="topic-title-block">
                    <h2><?= htmlspecialchars($sujetActuel['titre']) ?></h2>
                    <?php if (!empty($sujetActuel['date_modification'])): ?>
                        <span class="edited-tag" style="display:block; margin-top:-12px; margin-bottom:18px;">
                            (Titre modifié le <?= formaterDateHeure($sujetActuel['date_modification']) ?>)
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="messages-list">
                <?php foreach ($messages as $m): ?>
                    <?php
                        $estSonMessage     = ((int)$m['auteur_id'] === $membreId);
                        $estPremierDuSujet = ((int)$m['id'] === $premierMessageId);
                    ?>
                    <article class="message-card" id="msg-<?= (int)$m['id'] ?>">
                        <div class="message-header">
                            <div class="author-info">
                                <span>👤 <?= afficherNomAuteur($m['prenom'], $m['nom']) ?></span>
                                <?php if (!empty($m['role'])): ?>
                                    <span class="role-badge role-<?= htmlspecialchars($m['role']) ?>">
                                        <?= htmlspecialchars(formaterLibelleRole($m['role'])) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="message-date">
                                    • Le <?= formaterDateHeure($m['date_publication']) ?>
                                </span>
                                <?php if (!empty($m['date_modification'])): ?>
                                    <span class="edited-tag">(Message modifié le <?= formaterDateHeure($m['date_modification']) ?>)</span>
                                <?php endif; ?>
                            </div>

                            <div class="message-actions">
                                <a href="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>&reply_to=<?= (int)$m['id'] ?>#form-message" class="btn-small btn-reply-msg" title="Répondre à ce message">
                                    ↩️ Répondre
                                </a>

                                <?php if ($estSonMessage or $estAdminOuBureau): ?>
                                    <a href="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>&edit_msg=<?= (int)$m['id'] ?>#form-message" class="btn-small">✏️ Modifier</a>
                                <?php endif; ?>

                                <?php if ($estAdminOuBureau or ($estSonMessage and !$estPremierDuSujet)): ?>
                                    <form method="POST" onsubmit="return confirm('Supprimer définitivement cette réponse ?');">
                                        <input type="hidden" name="action" value="supprimer_message">
                                        <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                                        <button type="submit" class="btn-small btn-small-danger" title="Supprimer cette réponse">🗑️</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="message-body">
                            <?php if (!empty($m['reponse_a_id']) and !empty($m['parent_contenu'])): ?>
                                <div class="message-quote">
                                    <div class="message-quote-header">
                                        ↩️ En réponse à <strong><?= afficherNomAuteur($m['parent_prenom'], $m['parent_nom']) ?></strong> :
                                    </div>
                                    <div class="message-quote-text">
                                        « <?= htmlspecialchars(mb_substr($m['parent_contenu'], 0, 160, 'UTF-8')) ?><?= mb_strlen($m['parent_contenu'], 'UTF-8') > 160 ? '...' : '' ?> »
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?= nl2br(htmlspecialchars($m['contenu'])) ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Formulaire de réponse ou de modification d'un message -->
            <div class="admin-box admin-box-wide" id="form-message">
                <?php if ($messageAEditer): ?>
                    <h1>✏️ Modifier le message</h1>
                    <form method="POST" action="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>">
                        <input type="hidden" name="action" value="modifier_message">
                        <input type="hidden" name="message_id" value="<?= (int)$messageAEditer['id'] ?>">
                        <div class="form-group">
                            <label for="contenu">Votre message *</label>
                            <textarea id="contenu" name="contenu" rows="5" required><?= htmlspecialchars($messageAEditer['contenu']) ?></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-primary btn-submit">💾 Enregistrer la modification</button>
                            <a href="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>" class="back-link">Annuler</a>
                        </div>
                    </form>
                <?php else: ?>
                    <h1>
                        <?php if ($messageARepondre): ?>
                            ↩️ Répondre au message de <?= afficherNomAuteur($messageARepondre['prenom'], $messageARepondre['nom']) ?>
                        <?php else: ?>
                            💬 Répondre à cette discussion
                        <?php endif; ?>
                    </h1>

                    <?php if ($messageARepondre): ?>
                        <div class="replying-to-banner">
                            <div>
                                <strong>Message cité :</strong>
                                « <?= htmlspecialchars(mb_substr($messageARepondre['contenu'], 0, 180, 'UTF-8')) ?><?= mb_strlen($messageARepondre['contenu'], 'UTF-8') > 180 ? '...' : '' ?> »
                            </div>
                            <a href="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>#form-message" class="btn-small btn-small-danger">✖ Ne plus citer</a>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="forum.php?sujet=<?= (int)$sujetActuel['id'] ?>">
                        <input type="hidden" name="action" value="repondre">
                        <input type="hidden" name="reponse_a_id" value="<?= $messageARepondre ? (int)$messageARepondre['id'] : '' ?>">
                        <div class="form-group">
                            <label for="contenu">Votre réponse *</label>
                            <textarea id="contenu" name="contenu" rows="5" required placeholder="Écrivez votre message ici..."></textarea>
                        </div>
                        <button type="submit" class="btn-primary btn-submit">Envoyer la réponse</button>
                    </form>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- VUE 2 : LISTE DE TOUS LES SUJETS DU FORUM -->
            <div class="forum-header-flex">
                <h2>Sujets de discussion (<?= count($sujets) ?>)</h2>
                <a href="#nouveau-sujet" class="btn-primary">➕ Nouveau sujet</a>
            </div>

            <?php if (empty($sujets)): ?>
                <div class="empty-forum">
                    <p>Aucune discussion pour le moment. Soyez le premier à lancer un sujet ci-dessous !</p>
                </div>
            <?php else: ?>
                <div class="topics-list">
                    <?php foreach ($sujets as $s): ?>
                        <div class="topic-card">
                            <div class="topic-info">
                                <h3><a href="forum.php?sujet=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['titre']) ?></a></h3>
                                <div class="topic-meta">
                                    Lancé par <strong><?= afficherNomAuteur($s['prenom'], $s['nom']) ?></strong> 
                                    le <?= formaterDateHeure($s['date_creation']) ?>
                                    <?php if (!empty($s['date_modification'])): ?>
                                        <em>(Titre modifié)</em>
                                    <?php endif; ?>
                                    <?php if (!empty($s['derniere_activite'])): ?>
                                        • Dernier message le <?= formaterDateHeure($s['derniere_activite']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="topic-stats">
                                <span class="reply-count">💬 <?= (int)$s['nb_messages'] ?> message(s)</span>
                                <a href="forum.php?sujet=<?= (int)$s['id'] ?>" class="btn-small">Ouvrir &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Formulaire d'ouverture d'une nouvelle discussion -->
            <div class="admin-box admin-box-wide" id="nouveau-sujet">
                <h1>➕ Ouvrir une nouvelle discussion</h1>

                <?php if ($erreur): ?>
                    <div class="alert-error"><?= htmlspecialchars($erreur) ?></div>
                <?php endif; ?>

                <form method="POST" action="forum.php">
                    <input type="hidden" name="action" value="nouveau_sujet">
                    <div class="form-group">
                        <label for="titre">Titre du sujet *</label>
                        <input type="text" id="titre" name="titre" required placeholder="Ex: Qui vient voler ce samedi sur le terrain d'Agde ?">
                    </div>
                    <div class="form-group">
                        <label for="contenu">Premier message *</label>
                        <textarea id="contenu" name="contenu" rows="6" required placeholder="Détaillez votre question ou votre proposition..."></textarea>
                    </div>
                    <button type="submit" class="btn-primary btn-submit">🚀 Publier le sujet</button>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
