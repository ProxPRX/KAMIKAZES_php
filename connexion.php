<?php
// ==========================================================================
// CONNEXION ET DÉCONNEXION DES MEMBRES - LES KAMIKAZES AGATHOIS
// ==========================================================================
require_once 'PHP/db.php';

// --- 1. DÉCONNEXION D'UN MEMBRE ---
if (isset($_GET['action']) and$_GET['action'] === 'deconnexion') {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

$erreur = '';

// --- 2. VÉRIFICATION DES IDENTIFIANTS À LA SOUMISSION DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant  = trim($_POST['identifiant'] ?? '');
    $mot_de_passe = trim($_POST['mot_de_passe'] ?? '');

    $stmt =$pdo->prepare("SELECT * FROM membres WHERE identifiant = :identifiant");
    $stmt->execute([':identifiant' =>$identifiant]);
    $membre =$stmt->fetch();

    // Vérifie le mot de passe chiffré en base de données
    if ($membre and password_verify($mot_de_passe,$membre['mot_de_passe'])) {

        // Cas 1 : Le compte a été créé via Yapla mais l'adhésion n'est pas encore validée
        if ($membre['role'] === 'attente_validation') {$erreur = "⏳ Votre compte est actuellement en attente de validation. Veuillez contacter un administrateur du club.";
        }
        // Cas 2 : La date d'expiration de l'adhésion est dépassée (les comptes 'admin' ne sont jamais bloqués)
        elseif (!empty($membre['date_expiration']) and$membre['date_expiration'] < date('Y-m-d') and $membre['role'] !== 'admin') {$dateExpFr = formaterDate($membre['date_expiration']);$erreur = "⚠️ Attention : votre adhésion est arrivée à expiration le $dateExpFr. Veuillez renouveler votre licence sur Yapla (rubrique Le Club > Inscription) pour retrouver l'accès à votre espace membre.";
        }
        // Cas 3 : Compte valide et actif -> ouverture sécurisée de la session
        else {
            session_regenerate_id(true);
            $_SESSION['membre'] = [
                'id'          => (int)$membre['id'],
                'prenom'      => $membre['prenom'],
                'nom'         => $membre['nom'],
                'identifiant' => $membre['identifiant'],
                'role'        => $membre['role'] // 'membre', 'membre_associe', 'bureau' ou 'admin'
            ];
            header('Location: index.php');
            exit;
        }
    } else {
        $erreur = "Identifiant ou mot de passe incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion Membres - Les Kamikazes Agathois</title>
    <link rel="icon" type="image/png" href="IMG/favicon.png">
    <link rel="stylesheet" href="CSS/style.css?v=8">
    <link rel="stylesheet" href="CSS/admin.css?v=8">
</head>
<body>

    <?php include 'header.php'; ?>

    <main>
        <div class="login-box">
            <h2>🔒 Espace Membres</h2>

            <?php if ($erreur): ?>
                <div class="error-msg">
                    <?= htmlspecialchars($erreur) ?>
                    <?php if (strpos($erreur, 'Yapla') !== false): ?>
                        <div style="margin-top: 10px;">
                            <a href="club.php#inscription" class="btn-small" style="display: inline-block;">🔄 Renouveler sur Yapla</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="connexion.php">
                <div class="form-group">
                    <label for="identifiant">Identifiant</label>
                    <input type="text" id="identifiant" name="identifiant" required autocomplete="username" placeholder="Votre identifiant">
                </div>

                <div class="form-group">
                    <label for="mot_de_passe">Mot de passe</label>
                    <div class="password-wrapper">
                        <input type="password" id="mot_de_passe" name="mot_de_passe" required autocomplete="current-password" placeholder="Votre mot de passe">
                        <!-- Bouton d'affichage/masquage du mot de passe piloté par JS/main.js -->
                        <button type="button" id="togglePasswordBtn" class="toggle-password" title="Afficher/Masquer le mot de passe">
                            <svg id="eyeIcon" viewBox="0 0 24 24">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%; border: none; cursor: pointer;">Se connecter</button>
            </form>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
