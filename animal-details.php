<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
    header('Location: accueil.php');
    exit();
}

$animalId = sanitizeInt($_GET['id'], 1);

try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.prenom, u.nom as nom_user, u.email, u.telephone,
               v.statut_validation as vet_status, v.nom_cabinet, v.photo_profil as vet_photo
        FROM animaux a 
        JOIN utilisateurs u ON a.id_proprietaire = u.id 
        LEFT JOIN veterinaires v ON u.email = v.email
        WHERE a.id = ?
    ");
    $stmt->execute([$animalId]);
    $animal = $stmt->fetch();

    if (!$animal) {
        header('Location: accueil.php');
        exit();
    }
} catch(PDOException $e) {
    die("Erreur de base de données");
}

$regions = getRegions();
$healthOptions = getHealthStatusOptions();
$regionName = $regions[$animal['region']]['name'] ?? 'Tunisie';

$photos = !empty($animal['photos']) ? explode(',', $animal['photos']) : ['https://via.placeholder.com/800x600?text=Pas+d%27image'];

$alreadyRequested = false;
$isOwner = false;

if (isLoggedIn()) {
    $userId = getCurrentUserId();
    $isOwner = ($animal['id_proprietaire'] == $userId);

    if (!$isOwner) {
        $stmt = $pdo->prepare("
            SELECT da.* FROM demandes_adoption da
            JOIN annonces an ON da.id_annonce = an.id
            WHERE an.id_animal = ? AND da.id_adoptant = ?
        ");
        $stmt->execute([$animalId, $userId]);
        $alreadyRequested = $stmt->fetch() !== false;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?php echo e($animal['nom']); ?> - PetAdoption</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #faf7f2; min-height: 100vh; }
        .header { background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); position: fixed; width: 100%; top: 0; z-index: 1000; }
        .nav { max-width: 1400px; margin: 0 auto; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .container { max-width: 1200px; margin: 100px auto 40px; padding: 0 2rem; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #8b6946; text-decoration: none; margin-bottom: 1.5rem; font-weight: 500; }

        .pet-container { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .gallery { position: relative; background: #f5f0e8; min-height: 500px; }
        .main-image { width: 100%; height: 500px; object-fit: cover; }
        .image-nav { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; border: 2px solid white; }
        .dot.active { background: #2c5e2a; }

        .info-section { padding: 2rem; }
        .pet-header { margin-bottom: 1.5rem; }
        .pet-name { font-size: 2rem; color: #2c5e2a; margin-bottom: 0.5rem; }
        .badges { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .badge { padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .badge-primary { background: #2c5e2a; color: white; }
        .badge-secondary { background: #f5f0e8; color: #8b6946; }
        .badge-vet { background: #ffd700; color: #333; }
        .badge-vet-pending { background: #ffa500; color: #333; }
        .badge-stray { background: #6c757d; color: white; }

        /* Health Status Badges */
        .badge-health-stable { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .badge-health-urgent { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .badge-health-critique { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; animation: pulse 2s infinite; }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }

        .quick-facts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; padding: 1rem; background: #faf7f2; border-radius: 12px; }
        .fact { text-align: center; }
        .fact-icon { font-size: 1.5rem; margin-bottom: 0.3rem; }
        .fact-label { font-size: 0.75rem; color: #9b9b9b; text-transform: uppercase; }
        .fact-value { font-weight: 600; color: #4a4a4a; }

        .section { margin-bottom: 2rem; }
        .section-title { font-size: 1.1rem; color: #2c5e2a; margin-bottom: 0.8rem; font-weight: 600; }
        .section-content { color: #5a5a5a; line-height: 1.6; }

        .health-details { background: #f8f9fa; padding: 1rem; border-radius: 12px; margin-top: 0.5rem; border-left: 4px solid; }
        .health-details.stable { border-left-color: #28a745; }
        .health-details.urgent { border-left-color: #ffc107; }
        .health-details.critique { border-left-color: #dc3545; background: #f8d7da; }

        /* GPS Map for strays */
        .gps-section { background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px; padding: 1rem; margin-bottom: 2rem; }
        .gps-section h3 { color: #856404; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
        #map { height: 300px; border-radius: 8px; margin-top: 1rem; }

        .owner-card { background: #f5f0e8; padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; display: flex; align-items: center; gap: 1rem; }
        .owner-avatar { width: 60px; height: 60px; border-radius: 50%; overflow: hidden; background: white; display: flex; align-items: center; justify-content: center; font-size: 2rem; }
        .owner-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .owner-info h4 { color: #2c5e2a; margin-bottom: 0.2rem; }
        .owner-info p { color: #8b6946; font-size: 0.9rem; }

        .adopt-btn { width: 100%; padding: 1rem; background: #2c5e2a; color: white; border: none; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .adopt-btn:hover { background: #1e461c; transform: scale(1.02); }
        .adopt-btn:disabled { background: #9b9b9b; cursor: not-allowed; transform: none; }

        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }

        @media (max-width: 968px) { .pet-container { grid-template-columns: 1fr; } .main-image { height: 300px; } }
    </style>
</head>
<body>
<header class="header">
    <nav class="nav">
        <a href="accueil.php" style="text-decoration:none;"><div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div></a>
        <ul class="nav-links" style="display: flex; gap: 2rem; list-style: none;">
            <li><a href="accueil.php">Accueil</a></li>
            <?php if (isLoggedIn()): ?>
                <li><a href="mon-espace.php">Mon Espace</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<div class="container">
    <a href="accueil.php" class="back-link">← Retour aux animaux</a>

    <div class="pet-container">
        <div class="gallery">
            <img src="<?php echo e($photos[0]); ?>" class="main-image" id="mainImage">
            <?php if (count($photos) > 1): ?>
                <div class="image-nav">
                    <?php foreach ($photos as $index => $photo): ?>
                        <div class="dot <?php echo $index === 0 ? 'active' : ''; ?>" onclick="changeImage('<?php echo e($photo); ?>', this)"></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="info-section">
            <div class="pet-header">
                <h1 class="pet-name"><?php echo e($animal['nom']); ?></h1>
                <p style="color: #8b6946;"><?php echo e($animal['race'] ?? 'Race inconnue'); ?> • <?php echo e($animal['espece']); ?></p>
            </div>

            <div class="badges">
                <span class="badge badge-primary"><?php echo $animal['sexe'] === 'MALE' ? '♂️ Mâle' : ($animal['sexe'] === 'FEMELLE' ? '♀️ Femelle' : 'Sexe inconnu'); ?></span>
                <span class="badge badge-secondary">📍 <?php echo e($regionName); ?></span>
                <span class="badge badge-secondary">⚖️ <?php echo e($animal['poids']); ?> kg</span>
                <?php if ($animal['vet_status'] === 'VALIDE'): ?>
                    <span class="badge badge-vet">⭐ Vétérinaire Vérifié</span>
                <?php elseif ($animal['vet_status'] === 'EN_ATTENTE'): ?>
                    <span class="badge badge-vet-pending">⏳ Vétérinaire</span>
                <?php endif; ?>
                <?php if ($animal['errant']): ?>
                    <span class="badge badge-stray">🐾 Animal errant</span>
                <?php endif; ?>

                <!-- Health Status Badge -->
                <?php
                $healthUpper = strtoupper(trim($animal['statut_sante'] ?? 'STABLE'));
                $health = $healthOptions[$healthUpper] ?? $healthOptions['STABLE'];
                $healthClass = 'badge-health-' . strtolower($healthUpper);
                ?>
                <span class="badge <?php echo $healthClass; ?>">
                    <?php echo $health['icon'] . ' ' . $health['label']; ?>
                </span>
            </div>

            <!-- Health Details Section - only for non-stable -->
            <?php
            $healthUpper = strtoupper(trim($animal['statut_sante'] ?? 'STABLE'));
            $health = $healthOptions[$healthUpper] ?? $healthOptions['STABLE'];
            $healthClass = 'badge-health-' . strtolower($healthUpper);
            if ($healthUpper !== 'STABLE'):
                ?>
                <div class="section">
                    <h3 class="section-title">🏥 État de santé</h3>
                    <div class="health-details <?php echo strtolower($healthUpper); ?>">
                        <?php if (!empty($animal['description_maladie'])): ?>
                            <p><?php echo nl2br(e(html_entity_decode($animal['description_maladie'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))); ?></p>
                        <?php endif; ?>
                        <?php if ($healthUpper === 'CRITIQUE'): ?>
                            <p style="margin-top: 0.5rem; color: #721c24; font-weight: bold;">⚠️ Cet animal nécessite une intervention vétérinaire immédiate !</p>
                        <?php elseif ($healthUpper === 'URGENT'): ?>
                            <p style="margin-top: 0.5rem; color: #856404;">⚠️ Cet animal nécessite des soins dans les 24-48h.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- GPS Section for Strays -->
            <?php if ($animal['errant'] && !empty($animal['latitude']) && !empty($animal['longitude'])): ?>
                <div class="gps-section">
                    <h3>📍 Dernière position connue</h3>
                    <p style="font-size: 0.9rem; margin-bottom: 0.5rem;">Cet animal errant a été signalé à cet endroit:</p>
                    <div id="map"></div>
                    <p style="font-size: 0.85rem; margin-top: 0.5rem; font-style: italic;">
                        Coordonnées: <?php echo e($animal['latitude']); ?>, <?php echo e($animal['longitude']); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="quick-facts">
                <div class="fact">
                    <div class="fact-icon">🎂</div>
                    <div class="fact-label">Âge</div>
                    <div class="fact-value"><?php echo e($animal['age']); ?> an(s)</div>
                </div>
                <div class="fact">
                    <div class="fact-icon">📏</div>
                    <div class="fact-label">Taille</div>
                    <div class="fact-value"><?php echo $animal['poids'] < 10 ? 'Petit' : ($animal['poids'] > 25 ? 'Grand' : 'Moyen'); ?></div>
                </div>
                <div class="fact">
                    <div class="fact-icon">💉</div>
                    <div class="fact-label">Vacciné</div>
                    <div class="fact-value"><?php echo $animal['vaccine'] ? 'Oui' : 'Non'; ?></div>
                </div>
            </div>

            <div class="section">
                <h3 class="section-title">À propos</h3>
                <p class="section-content"><?php echo nl2br(e(html_entity_decode($animal['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))); ?></p>
            </div>

            <?php if ($animal['sterilise'] || $animal['vet_status'] === 'VALIDE' || $animal['vet_status'] === 'EN_ATTENTE'): ?>
                <div class="section">
                    <h3 class="section-title">Caractéristiques</h3>
                    <p class="section-content">
                        <?php if ($animal['sterilise']): ?>✅ Stérilisé<br><?php endif; ?>
                        <?php if ($animal['vet_status'] === 'VALIDE'): ?>⭐ Vétérinaire vérifié<?php elseif ($animal['vet_status'] === 'EN_ATTENTE'): ?>⏳ Vétérinaire (en attente de validation)<?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="owner-card">
                <div class="owner-avatar">
                    <?php if (($animal['vet_status'] === 'VALIDE' || $animal['vet_status'] === 'EN_ATTENTE') && !empty($animal['vet_photo'])): ?>
                        <img src="<?php echo e($animal['vet_photo']); ?>" alt="Vet">
                    <?php else: ?>
                        👤
                    <?php endif; ?>
                </div>
                <div class="owner-info">
                    <h4><?php echo e($animal['prenom'] . ' ' . $animal['nom_user']); ?> <?php if ($animal['vet_status'] === 'VALIDE' || $animal['vet_status'] === 'EN_ATTENTE') echo '⭐'; ?></h4>
                    <p><?php echo ($animal['vet_status'] === 'VALIDE' || $animal['vet_status'] === 'EN_ATTENTE') ? 'Vétérinaire' : 'Propriétaire'; ?></p>
                    <?php if (($animal['vet_status'] === 'VALIDE' || $animal['vet_status'] === 'EN_ATTENTE') && $animal['nom_cabinet']): ?>
                        <p style="font-size: 0.85rem;">🏥 <?php echo e(html_entity_decode($animal['nom_cabinet'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($animal['telephone'])): ?>
                        <p style="margin-top: 0.5rem; font-weight: 600; color: #2c5e2a;">📞 <?php echo e($animal['telephone']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOwner): ?>
                <div class="alert alert-info">
                    🏠 C'est votre animal. <a href="mon-espace.php" style="color: #0c5460; font-weight: bold;">Gérer dans Mon Espace</a>
                </div>
                <button class="adopt-btn" disabled>Votre animal</button>
            <?php elseif (!isLoggedIn()): ?>
                <div class="alert alert-warning">
                    🔒 <a href="connexion.php" style="color: #856404; font-weight: bold;">Connectez-vous</a> pour demander l'adoption
                </div>
                <button class="adopt-btn" disabled>Demander l'adoption</button>
            <?php elseif ($alreadyRequested): ?>
                <div class="alert alert-success">
                    ✅ Demande déjà envoyée. <a href="mon-espace.php" style="color: #155724; font-weight: bold;">Voir dans Mon Espace</a>
                </div>
                <button class="adopt-btn" disabled>Demande en cours</button>
            <?php else: ?>
                <button class="adopt-btn" onclick="adoptAnimal(<?php echo $animal['id']; ?>)">
                    Demander l'adoption ❤️
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    function changeImage(src, dot) {
        document.getElementById('mainImage').src = src;
        document.querySelectorAll('.dot').forEach(d => d.classList.remove('active'));
        dot.classList.add('active');
    }

    function adoptAnimal(animalId) {
        if (confirm('🐾 Voulez-vous vraiment demander l\'adoption de <?php echo addslashes($animal['nom']); ?> ?')) {
            window.location.href = 'demander-adoption.php?id=' + animalId;
        }
    }

    <?php if ($animal['errant'] && !empty($animal['latitude']) && !empty($animal['longitude'])): ?>
    // Initialize map for stray animal
    var map = L.map('map').setView([<?php echo $animal['latitude']; ?>, <?php echo $animal['longitude']; ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);
    L.marker([<?php echo $animal['latitude']; ?>, <?php echo $animal['longitude']; ?>])
        .addTo(map)
        .bindPopup("📍 <?php echo e($animal['nom']); ?> a été signalé ici");
    <?php endif; ?>
</script>
</body>
</html>