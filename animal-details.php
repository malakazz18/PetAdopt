<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
    header('Location: accueil.php');
    exit();
}

$animalId = $_GET['id'];

$stmt = $pdo->prepare("
    SELECT a.*, u.prenom, u.nom as nom_user, u.email, u.telephone,
           v.statut_validation as vet_status, v.nom_cabinet
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

// Get location
$locationName = 'Tunisie';
if (preg_match('/\[Ville:\s*(\w+)\]/i', $animal['description'], $matches)) {
    $villeKey = strtolower($matches[1]);
    $villeNames = ['tunis' => 'Tunis', 'sfax' => 'Sfax', 'sousse' => 'Sousse', 'bizerte' => 'Bizerte', 'nabeul' => 'Nabeul'];
    $locationName = $villeNames[$villeKey] ?? 'Tunisie';
}

$cleanDesc = preg_replace('/\[Ville:\s*\w+\]\s*/i', '', $animal['description']);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($animal['nom']); ?> - PetAdoption</title>
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
        .nav-links a:hover { color: #2c5e2a; }
        .container { max-width: 1200px; margin: 100px auto 40px; padding: 0 2rem; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #8b6946; text-decoration: none; margin-bottom: 1.5rem; font-weight: 500; }
        .back-link:hover { color: #2c5e2a; }
        .pet-container { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .gallery { position: relative; background: #f5f0e8; min-height: 500px; }
        .main-image { width: 100%; height: 500px; object-fit: cover; }
        .image-nav { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; border: 2px solid white; }
        .dot.active { background: #2c5e2a; }
        .info-section { padding: 2rem; }
        .pet-header { margin-bottom: 1.5rem; }
        .pet-name { font-size: 2rem; color: #2c5e2a; margin-bottom: 0.5rem; }
        .pet-breed { color: #8b6946; font-size: 1.1rem; }
        .badges { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .badge { padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .badge-primary { background: #2c5e2a; color: white; }
        .badge-secondary { background: #f5f0e8; color: #8b6946; }
        .badge-vet { background: #ffd700; color: #333; }
        .quick-facts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; padding: 1rem; background: #faf7f2; border-radius: 12px; }
        .fact { text-align: center; }
        .fact-icon { font-size: 1.5rem; margin-bottom: 0.3rem; }
        .fact-label { font-size: 0.75rem; color: #9b9b9b; text-transform: uppercase; }
        .fact-value { font-weight: 600; color: #4a4a4a; }
        .section { margin-bottom: 2rem; }
        .section-title { font-size: 1.1rem; color: #2c5e2a; margin-bottom: 0.8rem; font-weight: 600; }
        .section-content { color: #5a5a5a; line-height: 1.6; }
        .owner-card { background: #f5f0e8; padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; }
        .owner-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .owner-avatar { width: 50px; height: 50px; background: #8b6946; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; }
        .owner-info h4 { color: #2c5e2a; margin-bottom: 0.2rem; }
        .owner-info p { color: #8b6946; font-size: 0.9rem; }
        .adopt-btn { width: 100%; padding: 1rem; background: #2c5e2a; color: white; border: none; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .adopt-btn:hover { background: #1e461c; transform: scale(1.02); }
        .adopt-btn:disabled { background: #9b9b9b; cursor: not-allowed; transform: none; }
        .login-notice { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; text-align: center; }
        .login-notice a { color: #2c5e2a; font-weight: bold; }
        .owner-notice { background: #e8f0e5; border: 1px solid #2c5e2a; color: #2c5e2a; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .requested-notice { background: #e8f0e5; border: 1px solid #2c5e2a; color: #2c5e2a; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        @media (max-width: 968px) { .pet-container { grid-template-columns: 1fr; } .main-image { height: 300px; } }
    </style>
</head>
<body>
    <header class="header">
        <nav class="nav">
            <div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div>
            <ul class="nav-links">
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
                <img src="<?php echo htmlspecialchars($photos[0]); ?>" class="main-image" id="mainImage">
                <?php if (count($photos) > 1): ?>
                <div class="image-nav">
                    <?php foreach ($photos as $index => $photo): ?>
                    <div class="dot <?php echo $index === 0 ? 'active' : ''; ?>" onclick="changeImage('<?php echo htmlspecialchars($photo); ?>', this)"></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="info-section">
                <div class="pet-header">
                    <h1 class="pet-name"><?php echo htmlspecialchars($animal['nom']); ?></h1>
                    <p class="pet-breed"><?php echo htmlspecialchars($animal['race'] ?? 'Race inconnue'); ?> • <?php echo $animal['espece']; ?></p>
                </div>

                <div class="badges">
                    <span class="badge badge-primary"><?php echo $animal['sexe'] === 'MALE' ? '♂️ Mâle' : ($animal['sexe'] === 'FEMELLE' ? '♀️ Femelle' : 'Sexe inconnu'); ?></span>
                    <span class="badge badge-secondary">📍 <?php echo $locationName; ?></span>
                    <span class="badge badge-secondary">⚖️ <?php echo $animal['poids']; ?> kg</span>
                    <?php if ($animal['vet_status'] === 'VALIDE'): ?>
                    <span class="badge badge-vet">⭐ Vétérinaire vérifié</span>
                    <?php endif; ?>
                </div>

                <div class="quick-facts">
                    <div class="fact">
                        <div class="fact-icon">🎂</div>
                        <div class="fact-label">Âge</div>
                        <div class="fact-value"><?php echo $animal['age']; ?> an(s)</div>
                    </div>
                    <div class="fact">
                        <div class="fact-icon">📏</div>
                        <div class="fact-label">Taille</div>
                        <div class="fact-value"><?php echo $animal['poids'] < 10 ? 'Petit' : ($animal['poids'] > 25 ? 'Grand' : 'Moyen'); ?></div>
                    </div>
                    <div class="fact">
                        <div class="fact-icon">❤️</div>
                        <div class="fact-label">Santé</div>
                        <div class="fact-value">Vacciné</div>
                    </div>
                </div>

                <div class="section">
                    <h3 class="section-title">À propos</h3>
                    <p class="section-content"><?php echo nl2br(htmlspecialchars($cleanDesc)); ?></p>
                </div>

                <div class="section">
                    <h3 class="section-title">Caractéristiques</h3>
                    <p class="section-content">
                        ✅ Stérilisé<br>
                        ✅ Vacciné à jour<br>
                        ✅ Identifié (puce électronique)<br>
                        <?php if ($animal['vet_status'] === 'VALIDE'): ?>
                        ✅ Suivi vétérinaire assuré
                        <?php endif; ?>
                    </p>
                </div>

                <div class="owner-card">
                    <div class="owner-header">
                        <div class="owner-avatar">👤</div>
                        <div class="owner-info">
                            <h4><?php echo htmlspecialchars($animal['prenom'] . ' ' . $animal['nom_user']); ?> <?php if ($animal['vet_status'] === 'VALIDE') echo '⭐'; ?></h4>
                            <p><?php echo $animal['vet_status'] === 'VALIDE' ? 'Vétérinaire agréé' : 'Propriétaire particulier'; ?></p>
                        </div>
                    </div>
                    <?php if ($animal['vet_status'] === 'VALIDE' && $animal['nom_cabinet']): ?>
                    <p style="color: #8b6946; font-size: 0.9rem; margin-bottom: 0.5rem;">🏥 <?php echo htmlspecialchars($animal['nom_cabinet']); ?></p>
                    <?php endif; ?>
                    <p style="color: #5a5a5a; font-size: 0.9rem;">📞 <?php echo htmlspecialchars($animal['telephone'] ?? 'Contact via le site'); ?></p>
                </div>

                <?php if ($isOwner): ?>
                <div class="owner-notice">
                    🏠 C'est votre animal. <a href="mon-espace.php">Gérer dans Mon Espace</a>
                </div>
                <button class="adopt-btn" disabled>Votre animal</button>
                <?php elseif (!isLoggedIn()): ?>
                <div class="login-notice">
                    🔒 <a href="connexion.php">Connectez-vous</a> pour demander l'adoption de <?php echo htmlspecialchars($animal['nom']); ?>
                </div>
                <button class="adopt-btn" disabled>Demander l'adoption</button>
                <?php elseif ($alreadyRequested): ?>
                <div class="requested-notice">
                    ✅ Vous avez déjà fait une demande. <a href="mon-espace.php">Voir dans Mon Espace</a>
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

    <script>
        function changeImage(src, dot) {
            document.getElementById('mainImage').src = src;
            document.querySelectorAll('.dot').forEach(d => d.classList.remove('active'));
            dot.classList.add('active');
        }

        function adoptAnimal(animalId) {
            if (confirm('🐾 Voulez-vous vraiment demander l\'adoption de <?php echo addslashes($animal['nom']); ?> ?\n\nLe propriétaire sera notifié et vous contactera sous peu.')) {
                window.location.href = 'demander-adoption.php?id=' + animalId;
            }
        }
    </script>
</body>
</html>