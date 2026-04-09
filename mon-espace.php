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
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .card { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 3px 10px rgba(0,0,0,0.05); border: 1px solid #f0e8df; }
        .card-img { position: relative; height: 180px; }
        .card-img img { width: 100%; height: 100%; object-fit: cover; }
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
</div>

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