<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: connexion.php');
    exit();
}

$userId = getCurrentUserId();

// Get user's animals
$stmt = $pdo->prepare("
    SELECT a.*, 
           COUNT(da.id) as nb_demandes,
           SUM(CASE WHEN da.statut = 'ACCEPTEE' THEN 1 ELSE 0 END) as demandes_acceptees
    FROM animaux a 
    LEFT JOIN annonces an ON a.id = an.id_animal
    LEFT JOIN demandes_adoption da ON an.id = da.id_annonce
    WHERE a.id_proprietaire = ? 
    GROUP BY a.id
    ORDER BY a.date_creation DESC
");
$stmt->execute([$userId]);
$myAnimals = $stmt->fetchAll();

// Get adoption requests for user's pets
$stmt = $pdo->prepare("
    SELECT da.*, a.nom as animal_nom, a.id as animal_id, a.photos,
           u.prenom as adoptant_prenom, u.nom as adoptant_nom, u.email as adoptant_email, u.telephone as adoptant_tel,
           an.id as annonce_id
    FROM demandes_adoption da
    JOIN annonces an ON da.id_annonce = an.id
    JOIN animaux a ON an.id_animal = a.id
    JOIN utilisateurs u ON da.id_adoptant = u.id
    WHERE a.id_proprietaire = ?
    ORDER BY da.date_demande DESC
");
$stmt->execute([$userId]);
$requestsForMe = $stmt->fetchAll();

// Get my adoption requests
$stmt = $pdo->prepare("
    SELECT da.*, a.nom as animal_nom, a.id as animal_id, a.photos, a.espece,
           u.prenom as proprio_prenom, u.nom as proprio_nom,
           u.email as proprio_email, u.telephone as proprio_tel,
           CASE WHEN v.statut_validation = 'VALIDE' THEN 1 ELSE 0 END as proprio_est_vet
    FROM demandes_adoption da
    JOIN annonces an ON da.id_annonce = an.id
    JOIN animaux a ON an.id_animal = a.id
    JOIN utilisateurs u ON a.id_proprietaire = u.id
    LEFT JOIN veterinaires v ON u.email = v.email
    WHERE da.id_adoptant = ?
    ORDER BY da.date_demande DESC
");
$stmt->execute([$userId]);
$myRequests = $stmt->fetchAll();

// Fetch vet info by matching email from utilisateurs
$vet = null;
$vetSuccess = '';
$vetError = '';
$stmtVetCheck = $pdo->prepare("
    SELECT v.* FROM veterinaires v
    JOIN utilisateurs u ON v.email = u.email
    WHERE u.id = ? AND v.statut_validation = 'VALIDE'
");
$stmtVetCheck->execute([$userId]);
$vet = $stmtVetCheck->fetch() ?: null;

if ($vet) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vet_profile'])) {
        try {
            verifyCsrf();
            $nomCabinet = sanitizeString($_POST['nom_cabinet'] ?? '', 200);
            $adresse    = sanitizeString($_POST['adresse_cabinet'] ?? '', 500);
            $telCabinet = sanitizeString($_POST['telephone_cabinet'] ?? '', 20);
            $latitude   = sanitizeFloat($_POST['latitude'] ?? null, -90, 90) ?: null;
            $longitude  = sanitizeFloat($_POST['longitude'] ?? null, -180, 180) ?: null;

            $days = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
            $schedule = [];
            foreach ($days as $day) {
                $open = !empty($_POST['schedule'][$day]['open']);
                $schedule[$day] = $open
                        ? ['open' => true, 'from' => sanitizeString($_POST['schedule'][$day]['from'] ?? '09:00'), 'to' => sanitizeString($_POST['schedule'][$day]['to'] ?? '18:00')]
                        : ['open' => false];
            }
            $horaires = json_encode($schedule);

            $photoProfil = $vet['photo_profil'];
            if (isset($_FILES['photo_profil']) && $_FILES['photo_profil']['error'] === UPLOAD_ERR_OK) {
                $newPhoto = secureImageUpload($_FILES['photo_profil'], 'uploads/vets/', 2097152);
                if ($newPhoto) {
                    if ($photoProfil && file_exists($photoProfil)) unlink($photoProfil);
                    $photoProfil = $newPhoto;
                }
            }

            $pdo->prepare("UPDATE veterinaires SET nom_cabinet=?, adresse_cabinet=?, telephone_cabinet=?, horaires=?, latitude=?, longitude=?, photo_profil=? WHERE id=?")
                    ->execute([$nomCabinet, $adresse, $telCabinet, $horaires, $latitude, $longitude, $photoProfil, $vet['id']]);

            // Re-fetch updated vet
            $stmtVetCheck->execute([$userId]);
            $vet = $stmtVetCheck->fetch() ?: $vet;
            $vetSuccess = 'Profil cabinet mis à jour avec succès !';
        } catch (Exception $e) {
            $vetError = $e->getMessage();
        }
    }
}

// Handle accept/reject
if (isset($_POST['action']) && isset($_POST['demande_id'])) {
    verifyCsrf();
    $demandeId = $_POST['demande_id'];
    $action = $_POST['action'];

    $stmt = $pdo->prepare("
        SELECT da.*, a.id_proprietaire, an.id_animal, an.id as annonce_id
        FROM demandes_adoption da
        JOIN annonces an ON da.id_annonce = an.id
        JOIN animaux a ON an.id_animal = a.id
        WHERE da.id = ?
    ");
    $stmt->execute([$demandeId]);
    $demande = $stmt->fetch();

    if ($demande && $demande['id_proprietaire'] == $userId) {
        $pdo->prepare("UPDATE demandes_adoption SET statut = ? WHERE id = ?")->execute([$action, $demandeId]);

        if ($action === 'ACCEPTEE') {
            $pdo->prepare("UPDATE animaux SET statut_adoption = 'EN_COURS' WHERE id = ?")->execute([$demande['id_animal']]);
            $pdo->prepare("UPDATE demandes_adoption SET statut = 'REFUSEE' WHERE id_annonce = ? AND id != ? AND statut = 'EN_ATTENTE'")->execute([$demande['annonce_id'], $demandeId]);
        }

        header('Location: mon-espace.php');
        exit();
    }
}

// Handle mark as adopted
if (isset($_POST['mark_adopted']) && isset($_POST['animal_id'])) {
    verifyCsrf();
    $animalId = $_POST['animal_id'];
    $stmt = $pdo->prepare("SELECT id_proprietaire FROM animaux WHERE id = ?");
    $stmt->execute([$animalId]);
    $animal = $stmt->fetch();

    if ($animal && $animal['id_proprietaire'] == $userId) {
        $pdo->prepare("UPDATE animaux SET statut_adoption = 'ADOPTE' WHERE id = ?")->execute([$animalId]);
        header('Location: mon-espace.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Espace - PetAdoption</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #faf7f2; min-height: 100vh; }
        .header { background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); position: fixed; width: 100%; top: 0; z-index: 1000; }
        .nav { max-width: 1400px; margin: 0 auto; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .logo-text span { color: #8b6946; }
        .nav-links { display: flex; gap: 2rem; list-style: none; }
        .nav-links a { text-decoration: none; color: #5a5a5a; font-weight: 500; }
        .nav-links a:hover, .nav-links a.active { color: #2c5e2a; }
        .container { max-width: 1200px; margin: 100px auto 40px; padding: 0 2rem; }
        h1 { color: #2c5e2a; margin-bottom: 2rem; }
        .tabs { display: flex; gap: 1rem; margin-bottom: 2rem; border-bottom: 2px solid #f0e8df; }
        .tab { padding: 1rem 2rem; background: none; border: none; color: #8b6946; font-weight: 500; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.3s; }
        .tab.active { color: #2c5e2a; border-bottom-color: #2c5e2a; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .section-title { font-size: 1.3rem; color: #2c5e2a; margin-bottom: 1.5rem; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1.5rem; }
        .card { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 3px 10px rgba(0,0,0,0.05); border: 1px solid #f0e8df; }
        .card-img { position: relative; height: 140px; }
        .card-img img { width: 100%; height: 100%; object-fit: cover; object-position: center top; }
        .status-badge { position: absolute; top: 10px; right: 10px; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 500; color: white; }
        .status-DISPONIBLE { background: #2c5e2a; }
        .status-EN_COURS { background: #ffa500; }
        .status-ADOPTE { background: #8b6946; }
        .card-content { padding: 1.2rem; }
        .card h3 { color: #2c5e2a; margin-bottom: 0.5rem; }
        .card-meta { color: #8b8b8b; font-size: 0.85rem; margin-bottom: 1rem; }
        .request-count { background: #f5f0e8; padding: 0.5rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .btn { padding: 0.6rem 1rem; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; transition: all 0.3s; text-decoration: none; display: inline-block; text-align: center; }
        .btn-primary { background: #2c5e2a; color: white; }
        .btn-success { background: #4CAF50; color: white; }
        .btn-danger { background: #c96b4a; color: white; }
        .btn-secondary { background: #f0e8df; color: #8b6946; }
        .btn-block { width: 100%; }
        .request-card { background: white; border-radius: 15px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 3px 10px rgba(0,0,0,0.05); border: 1px solid #f0e8df; display: flex; gap: 1.5rem; align-items: center; }
        .request-img { width: 100px; height: 100px; border-radius: 10px; object-fit: cover; }
        .request-info { flex: 1; }
        .request-info h4 { color: #2c5e2a; margin-bottom: 0.3rem; }
        .request-info p { color: #5a5a5a; font-size: 0.9rem; margin-bottom: 0.3rem; }
        .request-status { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 15px; font-size: 0.8rem; font-weight: 500; margin-top: 0.5rem; }
        .status-EN_ATTENTE { background: #fff3cd; color: #856404; }
        .status-ACCEPTEE { background: #d4edda; color: #155724; }
        .status-REFUSEE { background: #f8d7da; color: #721c24; }
        .request-actions { display: flex; gap: 0.5rem; }
        .empty-state { text-align: center; padding: 3rem; background: white; border-radius: 15px; color: #8b6946; }
        .footer { background: white; color: #9b9b9b; text-align: center; padding: 2rem; margin-top: 3rem; border-top: 1px solid #f0e8df; }
        @media (max-width: 768px) { .request-card { flex-direction: column; text-align: center; } }
    </style>
</head>
<body>
<header class="header">
    <nav class="nav">
        <a href="accueil.php" style="text-decoration:none;"><div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div></a>
        <ul class="nav-links">
            <li><a href="accueil.php">Accueil</a></li>
            <li><a href="mon-espace.php" class="active">Mon Espace</a></li>
            <li><a href="aider.html">En savoir plus</a></li>
            <li><a href="contact.html">Contact</a></li>
        </ul>
        <div class="user-info" style="display: flex; align-items: center; gap: 1rem;">
            <span style="color: #4a4a4a;"><?php echo $_SESSION['user_name']; ?></span>
            <button class="btn btn-secondary" onclick="window.location.href='logout.php'">Déconnexion</button>
        </div>
    </nav>
</header>

<div class="container">
    <h1>👤 Mon Espace</h1>

    <div class="tabs">
        <button class="tab active" onclick="showTab('mes-animaux')">🐾 Mes Animaux</button>
        <button class="tab" onclick="showTab('demandes-recues')">📨 Demandes Reçues</button>
        <button class="tab" onclick="showTab('mes-demandes')">📤 Mes Demandes</button>
        <?php if ($vet): ?>
            <button class="tab" onclick="showTab('mon-cabinet')">🏥 Mon Cabinet</button>
        <?php endif; ?>
    </div>

    <!-- Mes Animaux -->
    <div id="mes-animaux" class="tab-content active">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 class="section-title" style="margin-bottom: 0;">Mes animaux en adoption</h2>
            <a href="ajouter-animal.php" class="btn btn-primary">➕ Ajouter un animal</a>
        </div>

        <?php if (empty($myAnimals)): ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🐾</div>
                <p>Vous n'avez pas encore d'animaux en adoption</p>
            </div>
        <?php else: ?>
            <div class="card-grid">
                <?php foreach ($myAnimals as $animal):
                    $photo = !empty($animal['photos']) ? explode(',', $animal['photos'])[0] : 'https://via.placeholder.com/300x200?text=Pas+d%27image';
                    ?>
                    <div class="card">
                        <div class="card-img">
                            <img src="<?php echo htmlspecialchars($photo); ?>" alt="<?php echo htmlspecialchars($animal['nom']); ?>">
                            <span class="status-badge status-<?php echo $animal['statut_adoption']; ?>">
                            <?php
                            $labels = ['DISPONIBLE' => 'Disponible', 'EN_COURS' => 'En cours', 'ADOPTE' => 'Adopté !'];
                            echo $labels[$animal['statut_adoption']] ?? $animal['statut_adoption'];
                            ?>
                        </span>
                        </div>
                        <div class="card-content">
                            <h3><?php echo htmlspecialchars($animal['nom']); ?></h3>
                            <div class="card-meta">
                                <?php echo $animal['espece']; ?> • <?php echo $animal['age']; ?> an(s) • <?php echo $animal['poids']; ?> kg
                            </div>

                            <?php if ($animal['nb_demandes'] > 0): ?>
                                <div class="request-count">
                                    📬 <?php echo $animal['nb_demandes']; ?> demande(s)
                                    <?php if ($animal['demandes_acceptees'] > 0): ?>
                                        (<?php echo $animal['demandes_acceptees']; ?> acceptée(s))
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; gap: 0.5rem;">
                                <a href="animal-details.php?id=<?php echo $animal['id']; ?>" class="btn btn-secondary btn-block">Voir</a>
                                <?php if ($animal['statut_adoption'] === 'EN_COURS'): ?>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="animal_id" value="<?php echo $animal['id']; ?>">
                                        <?php echo csrfField(); ?>
                                        <button type="submit" name="mark_adopted" class="btn btn-success btn-block" onclick="return confirm('Marquer cet animal comme adopté ?')">✓ Adopté</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Demandes Reçues -->
    <div id="demandes-recues" class="tab-content">
        <h2 class="section-title">Demandes d'adoption pour mes animaux</h2>

        <?php if (empty($requestsForMe)): ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
                <p>Vous n'avez pas encore reçu de demandes d'adoption</p>
            </div>
        <?php else: ?>
            <?php foreach ($requestsForMe as $req):
                $photo = !empty($req['photos']) ? explode(',', $req['photos'])[0] : 'https://via.placeholder.com/100?text=Pas+d%27image';
                ?>
                <div class="request-card">
                    <img src="<?php echo htmlspecialchars($photo); ?>" class="request-img">
                    <div class="request-info">
                        <h4><?php echo htmlspecialchars($req['animal_nom']); ?></h4>
                        <p><strong>Demandeur:</strong> <?php echo htmlspecialchars($req['adoptant_prenom'] . ' ' . $req['adoptant_nom']); ?></p>
                        <p>📧 <?php echo htmlspecialchars($req['adoptant_email']); ?> | 📞 <?php echo htmlspecialchars($req['adoptant_tel'] ?? 'Non renseigné'); ?></p>
                        <p style="color: #9b9b9b; font-size: 0.85rem;">Demande envoyée le <?php echo date('d/m/Y à H:i', strtotime($req['date_demande'])); ?></p>

                        <?php if ($req['statut'] === 'EN_ATTENTE'): ?>
                            <span class="request-status status-EN_ATTENTE">⏳ En attente</span>
                        <?php elseif ($req['statut'] === 'ACCEPTEE'): ?>
                            <span class="request-status status-ACCEPTEE">✅ Acceptée</span>
                        <?php else: ?>
                            <span class="request-status status-REFUSEE">❌ Refusée</span>
                        <?php endif; ?>
                    </div>
                    <div class="request-actions">
                        <?php if ($req['statut'] === 'EN_ATTENTE'): ?>
                            <form method="POST">
                                <input type="hidden" name="demande_id" value="<?php echo $req['id']; ?>">
                                <?php echo csrfField(); ?>
                                <button type="submit" name="action" value="ACCEPTEE" class="btn btn-success" onclick="return confirm('Accepter cette demande ?')">✓ Accepter</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="demande_id" value="<?php echo $req['id']; ?>">
                                <?php echo csrfField(); ?>
                                <button type="submit" name="action" value="REFUSEE" class="btn btn-danger" onclick="return confirm('Refuser cette demande ?')">✕ Refuser</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Mes Demandes -->
    <div id="mes-demandes" class="tab-content">
        <h2 class="section-title">Mes demandes d'adoption envoyées</h2>

        <?php if (empty($myRequests)): ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🔍</div>
                <p>Vous n'avez pas encore fait de demandes d'adoption</p>
                <a href="accueil.php" class="btn btn-primary" style="margin-top: 1rem;">Découvrir les animaux</a>
            </div>
        <?php else: ?>
            <div class="card-grid">
                <?php foreach ($myRequests as $req):
                    $photo = !empty($req['photos']) ? explode(',', $req['photos'])[0] : 'https://via.placeholder.com/300x200?text=Pas+d%27image';
                    ?>
                    <div class="card">
                        <div class="card-img">
                            <img src="<?php echo htmlspecialchars($photo); ?>" alt="<?php echo htmlspecialchars($req['animal_nom']); ?>">
                        </div>
                        <div class="card-content">
                            <h3><?php echo htmlspecialchars($req['animal_nom']); ?></h3>
                            <div class="card-meta">
                                <?php echo $req['espece']; ?> • Propriétaire: <?php echo htmlspecialchars($req['proprio_prenom']); ?>
                                <?php if ($req['proprio_est_vet']): ?> ⭐<?php endif; ?>
                            </div>

                            <div style="margin: 1rem 0;">
                                <?php if ($req['statut'] === 'EN_ATTENTE'): ?>
                                    <span class="request-status status-EN_ATTENTE">⏳ En attente de réponse</span>
                                <?php elseif ($req['statut'] === 'ACCEPTEE'): ?>
                                    <span class="request-status status-ACCEPTEE">✅ Adoption acceptée !</span>
                                    <div style="margin-top: 0.8rem; padding: 0.8rem; background: #d4edda; border-radius: 8px; border: 1px solid #c3e6cb;">
                                        <p style="color: #155724; font-weight: 600; margin-bottom: 0.4rem;">📬 Coordonnées du propriétaire :</p>
                                        <p style="color: #155724;">📧 <?php echo htmlspecialchars($req['proprio_email']); ?></p>
                                        <?php if (!empty($req['proprio_tel'])): ?>
                                            <p style="color: #155724;">📞 <?php echo htmlspecialchars($req['proprio_tel']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="request-status status-REFUSEE">❌ Refusée</span>
                                <?php endif; ?>
                            </div>

                            <p style="color: #9b9b9b; font-size: 0.85rem;">
                                Demandé le <?php echo date('d/m/Y', strtotime($req['date_demande'])); ?>
                            </p>

                            <a href="animal-details.php?id=<?php echo $req['animal_id']; ?>" class="btn btn-secondary btn-block" style="margin-top: 0.5rem;">Voir l'animal</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <!-- Mon Cabinet (vets only) -->
    <?php if ($vet): ?>
        <div id="mon-cabinet" class="tab-content">
            <h2 class="section-title">🏥 Mon Cabinet Vétérinaire</h2>

            <?php if ($vetSuccess): ?>
                <div style="background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:1rem;border-radius:10px;margin-bottom:1.5rem;"><?php echo e($vetSuccess); ?></div>
            <?php endif; ?>
            <?php if ($vetError): ?>
                <div style="background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:1rem;border-radius:10px;margin-bottom:1.5rem;"><?php echo e($vetError); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" style="background:white;border-radius:15px;padding:2rem;box-shadow:0 3px 10px rgba(0,0,0,0.05);border:1px solid #f0e8df;max-width:700px;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="update_vet_profile" value="1">

                <!-- Photo de profil -->
                <div style="margin-bottom:1.5rem;">
                    <label style="display:block;margin-bottom:0.5rem;color:#4a4a4a;font-weight:500;">📷 Photo de profil</label>
                    <?php if (!empty($vet['photo_profil'])): ?>
                        <img src="<?php echo e($vet['photo_profil']); ?>" alt="Photo" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e0d5c8;margin-bottom:0.8rem;display:block;">
                    <?php endif; ?>
                    <label style="display:block;border:2px dashed #e0d5c8;border-radius:12px;padding:1rem;text-align:center;cursor:pointer;color:#8b6946;font-size:0.9rem;">
                        <input type="file" name="photo_profil" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        Cliquez pour ajouter / modifier la photo (max 2MB)
                    </label>
                </div>

                <!-- Nom du cabinet -->
                <div style="margin-bottom:1.2rem;">
                    <label style="display:block;margin-bottom:0.5rem;color:#4a4a4a;font-weight:500;">🏥 Nom du cabinet</label>
                    <input type="text" name="nom_cabinet" value="<?php echo e(html_entity_decode($vet['nom_cabinet'] ?? '', ENT_QUOTES|ENT_HTML5, 'UTF-8')); ?>" placeholder="Ex: Cabinet Vétérinaire du Centre" maxlength="200"
                           style="width:100%;padding:0.8rem 1rem;border:1px solid #e0d5c8;border-radius:10px;font-size:0.95rem;font-family:inherit;">
                </div>

                <!-- Adresse -->
                <div style="margin-bottom:1.2rem;">
                    <label style="display:block;margin-bottom:0.5rem;color:#4a4a4a;font-weight:500;">📍 Adresse</label>
                    <textarea name="adresse_cabinet" rows="2" placeholder="Adresse complète..." maxlength="500"
                              style="width:100%;padding:0.8rem 1rem;border:1px solid #e0d5c8;border-radius:10px;font-size:0.95rem;font-family:inherit;resize:vertical;"><?php echo e(html_entity_decode($vet['adresse_cabinet'] ?? '', ENT_QUOTES|ENT_HTML5, 'UTF-8')); ?></textarea>
                </div>

                <!-- Téléphone cabinet -->
                <div style="margin-bottom:1.2rem;">
                    <label style="display:block;margin-bottom:0.5rem;color:#4a4a4a;font-weight:500;">📞 Téléphone du cabinet</label>
                    <input type="tel" name="telephone_cabinet" value="<?php echo e(html_entity_decode($vet['telephone_cabinet'] ?? '', ENT_QUOTES|ENT_HTML5, 'UTF-8')); ?>" placeholder="20 123 456" maxlength="20"
                           style="width:100%;padding:0.8rem 1rem;border:1px solid #e0d5c8;border-radius:10px;font-size:0.95rem;font-family:inherit;">
                </div>

                <!-- GPS -->
                <div style="margin-bottom:1.2rem;">
                    <label style="display:block;margin-bottom:0.5rem;color:#4a4a4a;font-weight:500;">🗺️ Coordonnées GPS</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <input type="number" step="any" name="latitude"  value="<?php echo e($vet['latitude']  ?? ''); ?>" placeholder="Latitude  (ex: 36.8065)" min="-90"  max="90"
                               style="padding:0.8rem 1rem;border:1px solid #e0d5c8;border-radius:10px;font-size:0.95rem;">
                        <input type="number" step="any" name="longitude" value="<?php echo e($vet['longitude'] ?? ''); ?>" placeholder="Longitude (ex: 10.1815)" min="-180" max="180"
                               style="padding:0.8rem 1rem;border:1px solid #e0d5c8;border-radius:10px;font-size:0.95rem;">
                    </div>
                    <small style="color:#8b6946;">Laissez vide pour masquer la carte</small>
                </div>

                <!-- Horaires -->
                <div style="margin-bottom:1.5rem;">
                    <label style="display:block;margin-bottom:0.8rem;color:#4a4a4a;font-weight:500;">⏰ Horaires d'ouverture</label>
                    <?php
                    $days = ['lundi'=>'Lundi','mardi'=>'Mardi','mercredi'=>'Mercredi','jeudi'=>'Jeudi','vendredi'=>'Vendredi','samedi'=>'Samedi','dimanche'=>'Dimanche'];
                    $scheduleData = [];
                    $rawH = html_entity_decode($vet['horaires'] ?? '', ENT_QUOTES|ENT_HTML5, 'UTF-8');
                    $dec  = json_decode($rawH, true);
                    if (is_array($dec)) $scheduleData = $dec;
                    ?>
                    <div style="display:flex;flex-direction:column;gap:0.5rem;">
                        <?php foreach ($days as $key => $label):
                            $d = $scheduleData[$key] ?? [];
                            $isOpen = !empty($d['open']);
                            $from   = $d['from'] ?? '09:00';
                            $to     = $d['to']   ?? '18:00';
                            ?>
                            <div style="display:flex;align-items:center;gap:1rem;padding:0.6rem 1rem;background:#faf7f2;border-radius:10px;border:1px solid #e0d5c8;">
                                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;min-width:110px;font-weight:500;color:#4a4a4a;">
                                    <input type="checkbox" name="schedule[<?php echo $key; ?>][open]" value="1" <?php echo $isOpen ? 'checked' : ''; ?>
                                           onchange="toggleDayMe('<?php echo $key; ?>', this.checked)"
                                           style="width:17px;height:17px;accent-color:#2c5e2a;cursor:pointer;">
                                    <?php echo $label; ?>
                                </label>
                                <div id="me-times-<?php echo $key; ?>" style="display:flex;align-items:center;gap:0.5rem;flex:1;<?php echo $isOpen ? '' : 'opacity:0.35;pointer-events:none;'; ?>">
                                    <input type="time" name="schedule[<?php echo $key; ?>][from]" value="<?php echo e($from); ?>"
                                           style="padding:0.35rem 0.6rem;border:1px solid #e0d5c8;border-radius:8px;font-size:0.9rem;width:110px;">
                                    <span style="color:#8b6946;font-weight:bold;">→</span>
                                    <input type="time" name="schedule[<?php echo $key; ?>][to]" value="<?php echo e($to); ?>"
                                           style="padding:0.35rem 0.6rem;border:1px solid #e0d5c8;border-radius:8px;font-size:0.9rem;width:110px;">
                                </div>
                                <span id="me-status-<?php echo $key; ?>" style="font-size:0.8rem;font-weight:600;min-width:45px;text-align:right;color:<?php echo $isOpen ? '#2c5e2a' : '#9b9b9b'; ?>">
                            <?php echo $isOpen ? 'Ouvert' : 'Fermé'; ?>
                        </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" style="background:#2c5e2a;color:white;padding:0.8rem 2rem;border:none;border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;">
                    💾 Enregistrer les modifications
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
    function toggleDayMe(key, isOpen) {
        const times  = document.getElementById('me-times-'  + key);
        const status = document.getElementById('me-status-' + key);
        times.style.opacity       = isOpen ? '1'    : '0.35';
        times.style.pointerEvents = isOpen ? 'auto' : 'none';
        status.textContent        = isOpen ? 'Ouvert' : 'Fermé';
        status.style.color        = isOpen ? '#2c5e2a' : '#9b9b9b';
    }
</script>

<footer class="footer">
    <p>🐾 PetAdoption - Refuge pour animaux en Tunisie</p>
    <p>📍 Tunisie | 📞 20 123 456 | ✉️ petadoption@gmail.com</p>
</footer>

<script>
    function showTab(tabId) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        event.target.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }
</script>
</body>
</html>