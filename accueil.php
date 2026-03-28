<?php
require_once 'config.php';

$view = $_GET['view'] ?? 'animaux';
$region = $_GET['region'] ?? 'all';

$regions = [
    'all' => ['name' => 'Toute la Tunisie', 'icon' => '🏠'],
    'tunis' => ['name' => 'Tunis', 'icon' => '🌆'],
    'sfax' => ['name' => 'Sfax', 'icon' => '🏛️'],
    'sousse' => ['name' => 'Sousse', 'icon' => '🌊'],
    'bizerte' => ['name' => 'Bizerte', 'icon' => '⛵'],
    'nabeul' => ['name' => 'Nabeul', 'icon' => '🍊'],
];

// Get animals with filters
$whereClause = "WHERE a.statut_adoption IN ('DISPONIBLE', 'EN_COURS')";
$params = [];

if ($region !== 'all') {
    $whereClause .= " AND (u.region = ? OR v.region = ?)";
    $params[] = $region;
    $params[] = $region;
}

// Apply filters
if (isset($_GET['espece']) && $_GET['espece']) {
    $whereClause .= " AND a.espece = ?";
    $params[] = $_GET['espece'];
}

if (isset($_GET['poids_min']) && $_GET['poids_min'] !== '') {
    $whereClause .= " AND a.poids >= ?";
    $params[] = $_GET['poids_min'];
}

if (isset($_GET['poids_max']) && $_GET['poids_max'] !== '') {
    $whereClause .= " AND a.poids <= ?";
    $params[] = $_GET['poids_max'];
}

if (isset($_GET['age_min']) && $_GET['age_min'] !== '') {
    $whereClause .= " AND a.age >= ?";
    $params[] = $_GET['age_min'];
}

if (isset($_GET['age_max']) && $_GET['age_max'] !== '') {
    $whereClause .= " AND a.age <= ?";
    $params[] = $_GET['age_max'];
}

if (isset($_GET['sterilise']) && $_GET['sterilise'] !== '') {
    $whereClause .= " AND a.stérilisé = ?";
    $params[] = $_GET['sterilise'];
}

if (isset($_GET['vaccine']) && $_GET['vaccine'] !== '') {
    $whereClause .= " AND a.vacciné = ?";
    $params[] = $_GET['vaccine'];
}

if (isset($_GET['errant']) && $_GET['errant'] !== '') {
    $whereClause .= " AND a.errant = ?";
    $params[] = $_GET['errant'];
}

$stmt = $pdo->prepare("
    SELECT a.*, u.prenom, u.nom as nom_user, u.region as user_region,
           v.statut_validation as vet_status, v.region as vet_region
    FROM animaux a 
    JOIN utilisateurs u ON a.id_proprietaire = u.id 
    LEFT JOIN veterinaires v ON u.email = v.email
    $whereClause
    ORDER BY a.date_creation DESC
");
$stmt->execute($params);
$animals = $stmt->fetchAll();

// Get vets
$vetWhere = "WHERE v.statut_validation = 'VALIDE'";
$vetParams = [];

if ($region !== 'all') {
    $vetWhere .= " AND v.region = ?";
    $vetParams[] = $region;
}

$stmt = $pdo->prepare("
    SELECT v.*, 
           COUNT(a.id) as nb_animaux
    FROM veterinaires v
    LEFT JOIN animaux a ON a.id_proprietaire = v.id AND a.statut_adoption = 'DISPONIBLE'
    $vetWhere
    GROUP BY v.id
    ORDER BY v.prenom, v.nom
");
$stmt->execute($vetParams);
$vets = $stmt->fetchAll();

// Count for sidebar
$animalCounts = ['all' => 0, 'tunis' => 0, 'sfax' => 0, 'sousse' => 0, 'bizerte' => 0, 'nabeul' => 0];
$vetCounts = ['all' => 0, 'tunis' => 0, 'sfax' => 0, 'sousse' => 0, 'bizerte' => 0, 'nabeul' => 0];

foreach ($animals as $a) {
    $animalCounts['all']++;
    $r = $a['vet_region'] ?? $a['user_region'] ?? 'tunis';
    if (isset($animalCounts[$r])) $animalCounts[$r]++;
}

foreach ($vets as $v) {
    $vetCounts['all']++;
    $r = $v['region'] ?? 'tunis';
    if (isset($vetCounts[$r])) $vetCounts[$r]++;
}

// Stats
$totalAnimals = $pdo->query("SELECT COUNT(*) FROM animaux WHERE statut_adoption IN ('DISPONIBLE', 'EN_COURS')")->fetchColumn();
$totalAdoptions = $pdo->query("SELECT COUNT(*) FROM demandes_adoption WHERE statut = 'ACCEPTEE'")->fetchColumn();
$totalVets = $pdo->query("SELECT COUNT(*) FROM veterinaires WHERE statut_validation = 'VALIDE'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PetAdoption - Adoption d'animaux en Tunisie</title>
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
        .nav-links a { text-decoration: none; color: #5a5a5a; font-weight: 500; transition: color 0.3s; }
        .nav-links a:hover { color: #2c5e2a; }
        .user-info { display: flex; align-items: center; gap: 1rem; background: #f5f0e8; padding: 0.4rem 1rem; border-radius: 50px; }
        .user-avatar { background: #8b6946; border-radius: 50%; padding: 0.2rem 0.5rem; font-size: 1rem; color: white; }
        .user-name { color: #4a4a4a; font-size: 0.9rem; }
        .logout-btn { background: #f0e8df; color: #8b6946; border: none; padding: 0.3rem 1rem; border-radius: 20px; cursor: pointer; transition: all 0.3s; font-size: 0.8rem; }
        .logout-btn:hover { background: #e0d5c8; }
        
        /* Main Tabs */
        .main-tabs { background: white; border-bottom: 2px solid #f0e8df; position: sticky; top: 70px; z-index: 100; }
        .main-tabs-container { max-width: 1400px; margin: 0 auto; display: flex; padding: 0 2rem; }
        .main-tab { padding: 1rem 2rem; background: none; border: none; color: #8b6946; font-weight: 500; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.3s; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .main-tab:hover { color: #2c5e2a; }
        .main-tab.active { color: #2c5e2a; border-bottom-color: #2c5e2a; }
        
        .main-container { max-width: 1400px; margin: 140px auto 2rem; padding: 0 2rem; display: flex; gap: 2rem; }
        .sidebar { width: 320px; background: white; border-radius: 20px; padding: 1.5rem; height: fit-content; position: sticky; top: 140px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border: 1px solid #f0e8df; }
        .sidebar-title { font-size: 1.2rem; color: #2c5e2a; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #f0e8df; display: flex; align-items: center; gap: 0.5rem; }
        
        .locations-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .location-item { display: flex; align-items: center; gap: 0.8rem; padding: 0.7rem; border-radius: 12px; cursor: pointer; transition: all 0.3s; background: #fefcf9; border: 1px solid #f0e8df; text-decoration: none; color: inherit; }
        .location-item:hover { background: #f5f0e8; transform: translateX(3px); }
        .location-item.active { background: #2c5e2a; color: white; border-color: #2c5e2a; }
        .location-item.active .location-name, .location-item.active .location-count { color: white; }
        .location-icon { font-size: 1.3rem; }
        .location-info { flex: 1; }
        .location-name { font-weight: 600; color: #4a4a4a; font-size: 0.9rem; }
        .location-count { font-size: 0.75rem; color: #9b9b9b; }
        
        .filter-section { margin-bottom: 1.5rem; }
        .filter-section h3 { color: #8b6946; margin-bottom: 0.8rem; font-size: 0.95rem; font-weight: 600; }
        .filter-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; }
        .filter-input { width: 100%; padding: 0.5rem; border: 1px solid #e0d5c8; border-radius: 8px; font-size: 0.85rem; }
        .filter-select { width: 100%; padding: 0.5rem; border: 1px solid #e0d5c8; border-radius: 8px; font-size: 0.85rem; background: white; }
        .filter-option { margin-bottom: 0.5rem; }
        .filter-option label { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: #5a5a5a; font-size: 0.85rem; padding: 0.3rem; border-radius: 6px; }
        .filter-option label:hover { background: #f5f0e8; }
        
        .reset-btn { width: 100%; padding: 0.7rem; background: #f0e8df; color: #8b6946; border: none; border-radius: 12px; cursor: pointer; font-weight: 500; margin-top: 0.5rem; transition: all 0.3s; }
        .reset-btn:hover { background: #e0d5c8; }
        .add-btn { width: 100%; padding: 0.7rem; background: #8b6946; color: white; border: none; border-radius: 12px; cursor: pointer; font-weight: 500; margin-top: 0.5rem; transition: all 0.3s; }
        .add-btn:hover { background: #6b5336; }
        
        .content-section { flex: 1; }
        .hero { background: linear-gradient(135deg, #e8f0e5 0%, #f5efe8 100%); border-radius: 25px; padding: 2.5rem; text-align: center; margin-bottom: 2rem; }
        .hero h1 { font-size: 2.2rem; color: #2c5e2a; margin-bottom: 0.8rem; }
        .hero p { font-size: 1.1rem; color: #6b5a4a; margin-bottom: 1.5rem; }
        .stats { display: flex; justify-content: center; gap: 2.5rem; margin-top: 1rem; }
        .stat { text-align: center; }
        .stat-number { font-size: 1.8rem; font-weight: bold; color: #8b6946; }
        .stat-label { color: #9b9b9b; font-size: 0.85rem; }
        
        .section-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .section-title h2 { color: #2c5e2a; font-size: 1.5rem; }
        .results-count { color: #8b6946; background: #f5f0e8; padding: 0.4rem 1rem; border-radius: 20px; font-size: 0.9rem; }
        
        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.05); transition: all 0.3s; border: 1px solid #f0e8df; text-decoration: none; color: inherit; display: block; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .card-img { position: relative; height: 200px; overflow: hidden; }
        .card-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .card:hover .card-img img { transform: scale(1.05); }
        .card-badge { position: absolute; top: 12px; left: 12px; background: white; color: #8b6946; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 500; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .card-badges { position: absolute; bottom: 12px; left: 12px; right: 12px; display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .mini-badge { background: rgba(44, 94, 42, 0.9); color: white; padding: 0.2rem 0.6rem; border-radius: 15px; font-size: 0.7rem; }
        .vet-badge { position: absolute; top: 12px; right: 12px; background: #ffd700; color: #333; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: bold; }
        .status-badge { position: absolute; top: 12px; right: 12px; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 500; color: white; }
        .status-DISPONIBLE { background: #2c5e2a; }
        .status-EN_COURS { background: #ffa500; }
        .card-content { padding: 1.2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .card h3 { font-size: 1.2rem; color: #2c5e2a; }
        .card-meta { color: #8b8b8b; font-size: 0.85rem; margin-bottom: 0.5rem; }
        .card-tags { display: flex; gap: 0.5rem; margin: 0.5rem 0; flex-wrap: wrap; }
        .tag { background: #f5f0e8; color: #8b6946; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem; }
        
        /* Vet Card Specific */
        .vet-card { display: flex; align-items: center; gap: 1.5rem; padding: 1.5rem; }
        .vet-avatar { width: 80px; height: 80px; background: #2c5e2a; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: white; flex-shrink: 0; }
        .vet-info { flex: 1; }
        .vet-info h3 { margin-bottom: 0.3rem; display: flex; align-items: center; gap: 0.5rem; }
        .vet-info p { color: #8b6946; font-size: 0.9rem; margin-bottom: 0.3rem; }
        .vet-stats { display: flex; gap: 1rem; margin-top: 0.5rem; }
        .vet-stat { background: #f5f0e8; padding: 0.3rem 0.8rem; border-radius: 15px; font-size: 0.8rem; color: #8b6946; }
        
        .footer { background: white; color: #9b9b9b; text-align: center; padding: 2rem; margin-top: 3rem; border-top: 1px solid #f0e8df; }
        
        @media (max-width: 968px) { 
            .main-container { flex-direction: column; margin-top: 180px; } 
            .sidebar { width: 100%; position: static; } 
            .main-tabs-container { flex-wrap: wrap; }
        }
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
                <li><a href="aider.html">En savoir plus</a></li>
                <li><a href="contact.html">Contact</a></li>
                <?php if (isLoggedIn()): ?>
                <li><a href="mon-espace.php">Mon Espace</a></li>
                <?php endif; ?>
            </ul>
            <div class="user-info" id="userInfo">
                <div class="user-avatar">👤</div>
                <span class="user-name" id="userNameDisplay">
                    <?php 
                    if (isLoggedIn()) {
                        echo $_SESSION['user_name'];
                        if (isVet() && isValidatedVet()) echo ' ⭐';
                    } else {
                        echo 'Invité';
                    }
                    ?>
                </span>
                <?php if (isLoggedIn()): ?>
                    <button class="logout-btn" onclick="window.location.href='logout.php'">Déconnexion</button>
                <?php else: ?>
                    <button class="logout-btn" onclick="window.location.href='connexion.php'" style="background: #2c5e2a; color: white;">Connexion</button>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <!-- Main Navigation Tabs -->
    <div class="main-tabs">
        <div class="main-tabs-container">
            <a href="?view=animaux&region=<?php echo $region; ?>" class="main-tab <?php echo $view === 'animaux' ? 'active' : ''; ?>">
                🐾 Animaux à adopter
            </a>
            <a href="?view=veterinaires&region=<?php echo $region; ?>" class="main-tab <?php echo $view === 'veterinaires' ? 'active' : ''; ?>">
                🩺 Vétérinaires
            </a>
        </div>
    </div>

    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-title">
                <span>📍</span>
                <span>Choisir une région</span>
            </div>
            
            <div class="locations-list">
                <?php foreach ($regions as $key => $info): 
                    $count = $view === 'animaux' ? $animalCounts[$key] : $vetCounts[$key];
                ?>
                <a href="?view=<?php echo $view; ?>&region=<?php echo $key; ?>" class="location-item <?php echo $region === $key ? 'active' : ''; ?>">
                    <div class="location-icon"><?php echo $info['icon']; ?></div>
                    <div class="location-info">
                        <div class="location-name"><?php echo $info['name']; ?></div>
                        <div class="location-count"><?php echo $count; ?> <?php echo $view === 'animaux' ? 'animaux' : 'vétérinaires'; ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($view === 'animaux'): ?>
            <!-- Filters for Animals -->
            <div class="sidebar-title" style="margin-top: 1.5rem;">
                <span>🔍</span>
                <span>Filtrer</span>
            </div>
            
            <form method="GET" id="filterForm">
                <input type="hidden" name="view" value="animaux">
                <input type="hidden" name="region" value="<?php echo $region; ?>">
                
                <div class="filter-section">
                    <h3>Espèce</h3>
                    <select name="espece" class="filter-select" onchange="this.form.submit()">
                        <option value="">Toutes</option>
                        <option value="CHIEN" <?php echo ($_GET['espece'] ?? '') === 'CHIEN' ? 'selected' : ''; ?>>🐕 Chien</option>
                        <option value="CHAT" <?php echo ($_GET['espece'] ?? '') === 'CHAT' ? 'selected' : ''; ?>>🐈 Chat</option>
                        <option value="LAPIN" <?php echo ($_GET['espece'] ?? '') === 'LAPIN' ? 'selected' : ''; ?>>🐰 Lapin</option>
                        <option value="OISEAU" <?php echo ($_GET['espece'] ?? '') === 'OISEAU' ? 'selected' : ''; ?>>🐦 Oiseau</option>
                        <option value="RONGEUR" <?php echo ($_GET['espece'] ?? '') === 'RONGEUR' ? 'selected' : ''; ?>>🐹 Rongeur</option>
                        <option value="REPTILE" <?php echo ($_GET['espece'] ?? '') === 'REPTILE' ? 'selected' : ''; ?>>🦎 Reptile</option>
                        <option value="AUTRE" <?php echo ($_GET['espece'] ?? '') === 'AUTRE' ? 'selected' : ''; ?>>🐾 Autre</option>
                    </select>
                </div>
                
                <div class="filter-section">
                    <h3>Poids (kg)</h3>
                    <div class="filter-row">
                        <input type="number" name="poids_min" class="filter-input" placeholder="Min" value="<?php echo $_GET['poids_min'] ?? ''; ?>" step="0.1">
                        <input type="number" name="poids_max" class="filter-input" placeholder="Max" value="<?php echo $_GET['poids_max'] ?? ''; ?>" step="0.1">
                    </div>
                </div>
                
                <div class="filter-section">
                    <h3>Âge (années)</h3>
                    <div class="filter-row">
                        <input type="number" name="age_min" class="filter-input" placeholder="Min" value="<?php echo $_GET['age_min'] ?? ''; ?>" step="0.5">
                        <input type="number" name="age_max" class="filter-input" placeholder="Max" value="<?php echo $_GET['age_max'] ?? ''; ?>" step="0.5">
                    </div>
                </div>
                
                <div class="filter-section">
                    <h3>Santé</h3>
                    <div class="filter-option">
                        <label>
                            <input type="checkbox" name="sterilise" value="1" <?php echo ($_GET['sterilise'] ?? '') === '1' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Stérilisé
                        </label>
                    </div>
                    <div class="filter-option">
                        <label>
                            <input type="checkbox" name="vaccine" value="1" <?php echo ($_GET['vaccine'] ?? '') === '1' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Vacciné
                        </label>
                    </div>
                    <div class="filter-option">
                        <label>
                            <input type="checkbox" name="errant" value="1" <?php echo ($_GET['errant'] ?? '') === '1' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Animal errant
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="reset-btn">Appliquer les filtres</button>
                <a href="?view=animaux&region=<?php echo $region; ?>" class="reset-btn" style="display: block; text-align: center; text-decoration: none; background: #e0d5c8;">Réinitialiser</a>
            </form>
            <?php endif; ?>
            
            <?php if (isLoggedIn()): ?>
            <button class="add-btn" onclick="window.location.href='ajouter-animal.php'">
                ➕ <?php echo $view === 'animaux' ? 'Ajouter un animal' : 'Mon profil vétérinaire'; ?>
            </button>
            <?php endif; ?>
        </aside>

        <!-- Content -->
        <div class="content-section">
            <?php if ($view === 'animaux'): ?>
            <!-- Animals View -->
            <div class="hero">
                <h1>🐾 Donnez-leur une seconde chance en Tunisie</h1>
                <p>Des animaux attendent une famille aimante comme la vôtre</p>
                <div class="stats">
                    <div class="stat">
                        <div class="stat-number"><?php echo $totalAnimals; ?></div>
                        <div class="stat-label">animaux disponibles</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number"><?php echo $totalVets; ?></div>
                        <div class="stat-label">vétérinaires partenaires</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number"><?php echo $totalAdoptions; ?>+</div>
                        <div class="stat-label">adoptions réussies</div>
                    </div>
                </div>
            </div>

            <div class="section-title">
                <h2>🌟 Nos compagnons à adopter</h2>
                <div class="results-count"><?php echo count($animals); ?> animaux</div>
            </div>

            <div class="cards">
                <?php foreach ($animals as $animal): 
                    $image = !empty($animal['photos']) ? explode(',', $animal['photos'])[0] : 'https://via.placeholder.com/500x300?text=Pas+d%27image';
                ?>
                <a href="animal-details.php?id=<?php echo $animal['id']; ?>" class="card">
                    <div class="card-img">
                        <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($animal['nom']); ?>">
                        <div class="card-badge"><?php echo $animal['espece']; ?> • <?php echo $animal['age']; ?> an<?php echo $animal['age'] > 1 ? 's' : ''; ?></div>
                        <?php if ($animal['vet_status'] === 'VALIDE'): ?>
                        <div class="vet-badge">⭐ Vétérinaire</div>
                        <?php endif; ?>
                        <?php if ($animal['statut_adoption'] === 'EN_COURS'): ?>
                        <div class="status-badge status-EN_COURS">En cours</div>
                        <?php endif; ?>
                        <div class="card-badges">
                            <?php if ($animal['stérilisé']): ?><span class="mini-badge">Stérilisé</span><?php endif; ?>
                            <?php if ($animal['vacciné']): ?><span class="mini-badge">Vacciné</span><?php endif; ?>
                            <?php if ($animal['errant']): ?><span class="mini-badge">Errant</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="card-header">
                            <h3><?php echo htmlspecialchars($animal['nom']); ?></h3>
                        </div>
                        <div class="card-meta">
                            <?php echo $animal['race'] ?? 'Race inconnue'; ?> • <?php echo $animal['poids']; ?> kg
                        </div>
                        <div class="card-tags">
                            <span class="tag"><?php echo $animal['sexe'] === 'MALE' ? '♂️ Mâle' : ($animal['sexe'] === 'FEMELLE' ? '♀️ Femelle' : 'Sexe inconnu'); ?></span>
                            <span class="tag">📍 <?php echo $regions[$animal['vet_region'] ?? $animal['user_region'] ?? 'tunis']['name'] ?? 'Tunisie'; ?></span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                
                <?php if (empty($animals)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 3rem; background: white; border-radius: 20px;">
                    <span style="font-size: 3rem;">🐾</span>
                    <h3 style="color: #2c5e2a; margin-top: 1rem;">Aucun animal trouvé</h3>
                    <p style="color: #8b6946;">Essayez d'autres filtres ou régions</p>
                </div>
                <?php endif; ?>
            </div>
            
            <?php else: ?>
            <!-- Vets View -->
            <div class="hero">
                <h1>🩺 Nos vétérinaires partenaires</h1>
                <p>Des professionnels de confiance pour vos compagnons</p>
                <div class="stats">
                    <div class="stat">
                        <div class="stat-number"><?php echo $totalVets; ?></div>
                        <div class="stat-label">vétérinaires</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number"><?php echo array_sum($vetCounts) - $vetCounts['all']; ?></div>
                        <div class="stat-label">cliniques</div>
                    </div>
                </div>
            </div>

            <div class="section-title">
                <h2>🌟 Vétérinaires disponibles</h2>
                <div class="results-count"><?php echo count($vets); ?> vétérinaires</div>
            </div>

            <div class="cards">
                <?php foreach ($vets as $vet): ?>
                <a href="veterinaire-details.php?id=<?php echo $vet['id']; ?>" class="card vet-card">
                    <div class="vet-avatar">🩺</div>
                    <div class="vet-info">
                        <h3>
                            Dr. <?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?>
                            <span style="color: #ffd700; font-size: 1.2rem;">⭐</span>
                        </h3>
                        <p>🏥 <?php echo htmlspecialchars($vet['nom_cabinet'] ?? 'Cabinet vétérinaire'); ?></p>
                        <p>📍 <?php echo $regions[$vet['region'] ?? 'tunis']['name'] ?? 'Tunisie'; ?></p>
                        <p>📞 <?php echo htmlspecialchars($vet['telephone_cabinet'] ?? $vet['telephone'] ?? 'Contact via le site'); ?></p>
                        <div class="vet-stats">
                            <span class="vet-stat">🐾 <?php echo $vet['nb_animaux']; ?> animaux à adopter</span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                
                <?php if (empty($vets)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 3rem; background: white; border-radius: 20px;">
                    <span style="font-size: 3rem;">🩺</span>
                    <h3 style="color: #2c5e2a; margin-top: 1rem;">Aucun vétérinaire dans cette région</h3>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>🐾 PetAdoption - Refuge pour animaux en Tunisie</p>
        <p>📍 Tunisie | 📞 20 123 456 | ✉️ petadoption@gmail.com</p>
    </footer>
</body>
</html>