<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
    header('Location: accueil.php?view=veterinaires');
    exit();
}

$vetId = $_GET['id'];

// Get veterinarian details
$stmt = $pdo->prepare("
    SELECT v.*, u.id as user_id
    FROM veterinaires v
    JOIN utilisateurs u ON v.email = u.email
    WHERE v.id = ? AND v.statut_validation = 'VALIDE'
");
$stmt->execute([$vetId]);
$vet = $stmt->fetch();

if (!$vet) {
    header('Location: accueil.php?view=veterinaires');
    exit();
}

// Get animals from this vet (animals posted by the vet's user account)
$stmt = $pdo->prepare("
    SELECT a.*, 
           u.prenom, u.nom as nom_user
    FROM animaux a 
    JOIN utilisateurs u ON a.id_proprietaire = u.id
    WHERE u.email = ? AND a.statut_adoption IN ('DISPONIBLE', 'EN_COURS')
    ORDER BY a.date_creation DESC
");
$stmt->execute([$vet['email']]);
$vetAnimals = $stmt->fetchAll();

// Region names mapping
$regions = [
        'tunis' => ['name' => 'Tunis', 'icon' => '🌆'],
        'sfax' => ['name' => 'Sfax', 'icon' => '🏛️'],
        'sousse' => ['name' => 'Sousse', 'icon' => '🌊'],
        'bizerte' => ['name' => 'Bizerte', 'icon' => '⛵'],
        'nabeul' => ['name' => 'Nabeul', 'icon' => '🍊']
];

$regionName = $regions[$vet['region']]['name'] ?? 'Tunisie';
$regionIcon = $regions[$vet['region']]['icon'] ?? '📍';

// Default coordinates for all 24 Tunisian governorates
$defaultCoords = [
        'tunis'      => [36.8065, 10.1815],
        'sfax'       => [34.7406, 10.7603],
        'sousse'     => [35.8254, 10.6370],
        'bizerte'    => [37.2744,  9.8739],
        'nabeul'     => [36.4561, 10.7375],
        'ariana'     => [36.8625, 10.1956],
        'ben_arous'  => [36.7533, 10.2281],
        'manouba'    => [36.8100, 10.0972],
        'zaghouan'   => [36.4029, 10.1427],
        'beja'       => [36.7256,  9.1817],
        'jendouba'   => [36.5011,  8.7757],
        'kef'        => [36.1822,  8.7147],
        'siliana'    => [36.0853,  9.3708],
        'kairouan'   => [35.6781, 10.0963],
        'kasserine'  => [35.1676,  8.8365],
        'sidi_bouzid'=> [35.0382,  9.4858],
        'gafsa'      => [34.4250,  8.7842],
        'tozeur'     => [33.9197,  8.1335],
        'kebili'     => [33.7042,  8.9694],
        'gabes'      => [33.8881, 10.0975],
        'medenine'   => [33.3549, 10.5055],
        'tataouine'  => [32.9211, 10.4509],
        'mahdia'     => [35.5047, 11.0622],
        'monastir'   => [35.7643, 10.8113],
];

$vetRegion = $vet['region'] ?? null;
$vetLat    = !empty($vet['latitude'])  ? $vet['latitude']  : null;
$vetLng    = !empty($vet['longitude']) ? $vet['longitude'] : null;

// Use vet's GPS if set, otherwise fall back to city centre, otherwise Tunisia centre
$latitude  = $vetLat ?? ($defaultCoords[$vetRegion][0] ?? 34.0);
$longitude = $vetLng ?? ($defaultCoords[$vetRegion][1] ?? 9.0);
$hasCoordinates = true;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dr. <?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?> - PetAdoption</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #faf7f2; min-height: 100vh; display: flex; flex-direction: column; }

        .header { background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); position: fixed; width: 100%; top: 0; z-index: 1000; }
        .nav { max-width: 1400px; margin: 0 auto; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .logo-text span { color: #8b6946; }
        .nav-links { display: flex; gap: 2rem; list-style: none; }
        .nav-links a { text-decoration: none; color: #5a5a5a; font-weight: 500; }
        .nav-links a:hover { color: #2c5e2a; }

        .container { max-width: 1200px; margin: 100px auto 40px; padding: 0 2rem; flex: 1; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #8b6946; text-decoration: none; margin-bottom: 1.5rem; font-weight: 500; }
        .back-link:hover { color: #2c5e2a; }

        .vet-header {
            background: linear-gradient(135deg, #2c5e2a 0%, #1e461c 100%);
            border-radius: 25px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .vet-header h1 { font-size: 1.8rem; margin-bottom: 0.5rem; }
        .vet-header .badge { background: #ffd700; color: #2c5e2a; padding: 0.5rem 1rem; border-radius: 30px; font-weight: bold; display: inline-block; margin-top: 0.5rem; }
        .vet-stats { display: flex; gap: 2rem; flex-wrap: wrap; }
        .vet-stat { text-align: center; background: rgba(255,255,255,0.2); padding: 0.5rem 1rem; border-radius: 15px; }
        .vet-stat .number { font-size: 1.5rem; font-weight: bold; }
        .vet-stat .label { font-size: 0.8rem; opacity: 0.9; }

        .grid-2cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #f0e8df;
        }
        .info-card h2 {
            color: #2c5e2a;
            margin-bottom: 1.5rem;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 2px solid #f0e8df;
            padding-bottom: 0.8rem;
        }

        .info-row {
            display: flex;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f0e8df;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label {
            width: 120px;
            font-weight: 600;
            color: #8b6946;
        }
        .info-value {
            flex: 1;
            color: #4a4a4a;
        }

        #map {
            height: 300px;
            border-radius: 15px;
            margin-top: 0.5rem;
            overflow: hidden;
            border: 1px solid #f0e8df;
        }

        .section-title {
            color: #2c5e2a;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .animal-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: all 0.3s;
            border: 1px solid #f0e8df;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .animal-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .animal-card-img { height: 180px; overflow: hidden; }
        .animal-card-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .animal-card:hover .animal-card-img img { transform: scale(1.05); }
        .animal-card-content { padding: 1rem; }
        .animal-card h3 { color: #2c5e2a; margin-bottom: 0.3rem; font-size: 1.1rem; }
        .animal-card p { color: #8b8b8b; font-size: 0.85rem; margin-bottom: 0.5rem; }
        .animal-tags { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .animal-tag { background: #f5f0e8; color: #8b6946; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.7rem; }
        .vet-badge-small { background: #ffd700; color: #333; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.7rem; font-weight: bold; display: inline-block; margin-left: 0.5rem; }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 20px;
            color: #8b6946;
        }

        .footer {
            background: white;
            color: #9b9b9b;
            text-align: center;
            padding: 2rem;
            border-top: 1px solid #f0e8df;
            margin-top: auto;
        }

        @media (max-width: 768px) {
            .grid-2cols { grid-template-columns: 1fr; }
            .container { margin-top: 140px; }
        }
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
            <li><a href="accueil.php?view=veterinaires">Vétérinaires</a></li>
            <?php if (isLoggedIn()): ?>
                <li><a href="mon-espace.php">Mon Espace</a></li>
            <?php endif; ?>
        </ul>
        <div class="user-info" style="display: flex; align-items: center; gap: 1rem;">
            <?php if (isLoggedIn()): ?>
                <span style="color: #4a4a4a;"><?php echo $_SESSION['user_name']; ?></span>
                <button onclick="window.location.href='logout.php'" style="background: #f0e8df; border: none; padding: 0.3rem 1rem; border-radius: 20px; cursor: pointer;">Déconnexion</button>
            <?php else: ?>
                <button onclick="window.location.href='connexion.php'" style="background: #2c5e2a; color: white; border: none; padding: 0.3rem 1rem; border-radius: 20px; cursor: pointer;">Connexion</button>
            <?php endif; ?>
        </div>
    </nav>
</header>

<div class="container">
    <a href="accueil.php?view=veterinaires&region=<?php echo $vet['region']; ?>" class="back-link">← Retour aux vétérinaires</a>

    <!-- Vet Header -->
    <div class="vet-header">
        <div>
            <h1>🩺 Dr. <?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?></h1>
            <div class="badge">⭐ Vétérinaire Diplômé et Vérifié</div>
            <p style="margin-top: 0.8rem; opacity: 0.9;">Membre depuis <?php echo date('F Y', strtotime($vet['date_inscription'])); ?></p>
        </div>
        <div class="vet-stats">
            <div class="vet-stat">
                <div class="number"><?php echo count($vetAnimals); ?></div>
                <div class="label">animaux à adopter</div>
            </div>
            <div class="vet-stat">
                <div class="number">⭐</div>
                <div class="label">Vérifié</div>
            </div>
        </div>
    </div>

    <!-- 2 Columns Layout -->
    <div class="grid-2cols">
        <!-- Vet Personal Info -->
        <div class="info-card">
            <h2>👨‍⚕️ Informations du Vétérinaire</h2>
            <div class="info-row">
                <div class="info-label">Nom complet</div>
                <div class="info-value">Dr. <?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Email professionnel</div>
                <div class="info-value"><?php echo htmlspecialchars($vet['email']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Téléphone</div>
                <div class="info-value"><?php echo htmlspecialchars($vet['telephone'] ?? 'Non renseigné'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Région </div>
                <div class="info-value"><?php echo $regionIcon . ' ' . $regionName; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Statut</div>
                <div class="info-value"><span style="background: #2c5e2a; color: white; padding: 0.2rem 0.8rem; border-radius: 15px; font-size: 0.8rem;">✓ Vérifié</span></div>
            </div>
        </div>

        <!-- Clinic Info -->
        <div class="info-card">
            <h2>🏥 Cabinet Vétérinaire</h2>
            <div class="info-row">
                <div class="info-label">Nom du cabinet</div>
                <div class="info-value"><?php echo htmlspecialchars($vet['nom_cabinet'] ?? 'Cabinet Vétérinaire ' . $regionName); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Adresse</div>
                <div class="info-value"><?php echo htmlspecialchars($vet['adresse_cabinet'] ?? 'Adresse non renseignée'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Téléphone cabinet</div>
                <div class="info-value"><?php echo htmlspecialchars($vet['telephone_cabinet'] ?? $vet['telephone'] ?? 'Non renseigné'); ?></div>
            </div>
            <?php if (!empty($vet['horaires'])): ?>
                <div class="info-row">
                    <div class="info-label">Horaires</div>
                    <div class="info-value" style="white-space: pre-line;"><?php echo nl2br(htmlspecialchars($vet['horaires'])); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Map Section -->
    <div class="info-card" style="margin-bottom: 2rem;">
        <h2>🗺️ Localisation du cabinet</h2>
        <div id="map"></div>
        <p style="margin-top: 0.8rem; color: #8b6946; font-size: 0.85rem; text-align: center;">
            📍 <?php echo htmlspecialchars($vet['adresse_cabinet'] ?? $regionName . ', Tunisie'); ?>
        </p>
    </div>

    <!-- Animals Section -->
    <div class="section-title">
        <span>🐾</span>
        <span> <h2>Animaux à adopter dans ce cabinet</h2></span>
        <span style="font-size: 0.9rem; background: #f5f0e8; padding: 0.2rem 0.8rem; border-radius: 20px; color: #8b6946;"><?php echo count($vetAnimals); ?> animal(aux)</span>
    </div>

    <?php if (empty($vetAnimals)): ?>
        <div class="empty-state">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🐾</div>
            <p>Aucun animal n'est actuellement disponible à l'adoption dans ce cabinet.</p>
            <p style="margin-top: 0.5rem; font-size: 0.9rem;">Revenez bientôt !</p>
        </div>
    <?php else: ?>
        <div class="cards">
            <?php foreach ($vetAnimals as $animal):
                $image = !empty($animal['photos']) ? explode(',', $animal['photos'])[0] : 'https://via.placeholder.com/300x200?text=Pas+d%27image';
                ?>
                <a href="animal-details.php?id=<?php echo $animal['id']; ?>" class="animal-card">
                    <div class="animal-card-img">
                        <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($animal['nom']); ?>">
                    </div>
                    <div class="animal-card-content">
                        <h3><?php echo htmlspecialchars($animal['nom']); ?> <span class="vet-badge-small">⭐ Vet</span></h3>
                        <p><?php echo $animal['espece']; ?> • <?php echo $animal['race'] ?? 'Race inconnue'; ?></p>
                        <p style="color: #8b6946;">📍 <?php echo $regionName; ?></p>
                        <div class="animal-tags">
                            <?php if ($animal['sterilise']): ?><span class="animal-tag">Stérilisé</span><?php endif; ?>
                            <?php if ($animal['vaccine']): ?><span class="animal-tag">Vacciné</span><?php endif; ?>
                            <?php if ($animal['age']): ?><span class="animal-tag">🎂 <?php echo $animal['age']; ?> an(s)</span><?php endif; ?>
                            <?php if ($animal['poids']): ?><span class="animal-tag">⚖️ <?php echo $animal['poids']; ?> kg</span><?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<footer class="footer">
    <p>🐾 PetAdoption - Refuge pour animaux en Tunisie</p>
    <p>📍 Tunisie | 📞 20 123 456 | ✉️ petadoption@gmail.com</p>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // Initialize map
    var map = L.map('map').setView([<?php echo $latitude; ?>, <?php echo $longitude; ?>], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Add marker
    var marker = L.marker([<?php echo $latitude; ?>, <?php echo $longitude; ?>]).addTo(map);

    var clinicName = "<?php echo htmlspecialchars($vet['nom_cabinet'] ?? 'Cabinet Vétérinaire'); ?>";
    var vetName = "Dr. <?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?>";
    var address = "<?php echo htmlspecialchars($vet['adresse_cabinet'] ?? $regionName . ', Tunisie'); ?>";

    marker.bindPopup("<b>" + clinicName + "</b><br>" + vetName + "<br>" + address).openPopup();

    // Fix map display issues
    setTimeout(function() {
        map.invalidateSize();
    }, 100);
</script>
</body>
</html>