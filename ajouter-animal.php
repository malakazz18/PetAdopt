<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: connexion.php');
    exit();
}

$regions = getRegions();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf(); // CSRF Protection

        $nom = sanitizeString($_POST['nom'] ?? '', 100);
        $espece = sanitizeString($_POST['espece'] ?? '', 50);
        $race = sanitizeString($_POST['race'] ?? '', 100);
        $age = sanitizeFloat($_POST['age'] ?? 0, 0, 100);
        $sexe = in_array($_POST['sexe'] ?? '', ['MALE', 'FEMELLE']) ? $_POST['sexe'] : null;
        $poids = sanitizeFloat($_POST['poids'] ?? 0, 0, 500);
        $description = sanitizeString($_POST['description'] ?? '', 2000);
        $region = array_key_exists($_POST['region'] ?? '', $regions) ? $_POST['region'] : 'tunis';

        $sterilise = isset($_POST['sterilise']) ? 1 : 0;
        $vaccine = isset($_POST['vaccine']) ? 1 : 0;
        $errant = isset($_POST['errant']) ? 1 : 0;

        // GPS coordinates (only if stray)
        $latitude = $errant ? sanitizeFloat($_POST['latitude'] ?? null, -90, 90) : null;
        $longitude = $errant ? sanitizeFloat($_POST['longitude'] ?? null, -180, 180) : null;

        // Health status
        $statutSante = in_array($_POST['statut_sante'] ?? '', ['STABLE', 'URGENT', 'CRITIQUE']) ? $_POST['statut_sante'] : 'STABLE';
        $descriptionMaladie = $statutSante !== 'STABLE' ? sanitizeString($_POST['description_maladie'] ?? '', 1000) : null;

        if (empty($nom) || empty($espece)) {
            throw new Exception("Nom et espèce sont obligatoires");
        }

        $photos = [];
        if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
            foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['photos']['error'][$key] === 0) {
                    $file = [
                            'tmp_name' => $tmp_name,
                            'name' => $_FILES['photos']['name'][$key],
                            'error' => $_FILES['photos']['error'][$key],
                            'size' => $_FILES['photos']['size'][$key]
                    ];
                    $path = secureImageUpload($file, 'uploads/animaux/', 5242880); // 5MB max
                    if ($path) $photos[] = $path;
                }
            }
        }

        $photoStr = implode(',', $photos);
        $userId = getCurrentUserId();

        $stmt = $pdo->prepare("
            INSERT INTO animaux (
                nom, espece, race, age, sexe, poids, description, photos, 
                id_proprietaire, region, sterilise, vaccine, errant,
                latitude, longitude, statut_sante, description_maladie
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
                $nom, $espece, $race, $age, $sexe, $poids, $description, $photoStr,
                $userId, $region, $sterilise, $vaccine, $errant,
                $latitude, $longitude, $statutSante, $descriptionMaladie
        ]);

        $animalId = $pdo->lastInsertId();
        $titre = $nom . ' - ' . $espece . ' à adopter';

        $stmt = $pdo->prepare("INSERT INTO annonces (id_animal, id_proprietaire, titre, description_annonce) VALUES (?, ?, ?, ?)");
        $stmt->execute([$animalId, $userId, $titre, $description]);

        header('Location: mon-espace.php?success=1');
        exit();

    } catch(Exception $e) {
        $error = $e->getMessage();
    }
}

$healthOptions = getHealthStatusOptions();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajouter un animal - PetAdoption</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #faf7f2; min-height: 100vh; padding-top: 80px; display: flex; flex-direction: column; }
        .header { background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); position: fixed; width: 100%; top: 0; z-index: 1000; }
        .nav { max-width: 1400px; margin: 0 auto; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .container { max-width: 800px; margin: 40px auto; padding: 0 2rem; flex: 1; width: 100%; }
        .form-card { background: white; border-radius: 20px; padding: 2.5rem; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border: 1px solid #f0e8df; }
        h1 { color: #2c5e2a; margin-bottom: 0.5rem; }
        .subtitle { color: #8b6946; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; color: #4a4a4a; font-weight: 500; }
        label .required { color: #dc3545; }
        input, select, textarea { width: 100%; padding: 0.9rem 1rem; border: 1px solid #e0d5c8; border-radius: 12px; font-size: 0.95rem; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #2c5e2a; box-shadow: 0 0 0 3px rgba(44, 94, 42, 0.1); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .checkbox-group { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .checkbox-item { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.5rem; border-radius: 8px; transition: background 0.3s; }
        .checkbox-item:hover { background: #f5f0e8; }
        .checkbox-item input[type="checkbox"] { width: 20px; height: 20px; accent-color: #2c5e2a; }
        .submit-btn { width: 100%; padding: 1rem; background: #2c5e2a; color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .submit-btn:hover { background: #1e461c; transform: translateY(-2px); }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #8b6946; text-decoration: none; margin-bottom: 1.5rem; }
        .success { background: #d4edda; color: #155724; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; border: 1px solid #f5c6cb; }
        .footer { background: white; color: #9b9b9b; text-align: center; padding: 2rem; border-top: 1px solid #f0e8df; margin-top: auto; }

        /* GPS Section */
        #gpsSection { display: none; background: #fff3cd; border: 1px solid #ffeaa7; padding: 1.5rem; border-radius: 12px; margin-top: 1rem; }
        #gpsSection.visible { display: block; }
        #map { height: 300px; border-radius: 12px; margin-top: 1rem; border: 2px solid #2c5e2a; }
        .gps-info { background: #e8f0e5; padding: 0.8rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; color: #2c5e2a; }

        /* Health Section */
        .health-section { background: #f8f9fa; padding: 1.5rem; border-radius: 12px; margin-top: 1rem; border: 1px solid #e0d5c8; }
        .health-option { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        .health-option input { width: auto; }
        .health-label { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0.8rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem; }
        .health-STABLE { background: #d4edda; color: #155724; }
        .health-URGENT { background: #fff3cd; color: #856404; }
        .health-CRITIQUE { background: #f8d7da; color: #721c24; }

        #maladieSection { display: none; margin-top: 1rem; }
        #maladieSection.visible { display: block; }
        .alert-warning { background: #fff3cd; color: #856404; padding: 0.8rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }

        @media (max-width: 768px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <nav class="nav">
        <a href="accueil.php" style="text-decoration:none;"><div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div></a>
        <a href="accueil.php" style="text-decoration: none; color: #5a5a5a; font-weight: 500;">← Retour à l'accueil</a>
    </nav>
</header>

<div class="container">
    <div class="form-card">
        <h1>🐾 Ajouter un animal à l'adoption</h1>
        <p class="subtitle">Remplissez toutes les informations sur l'animal</p>

        <?php if ($success): ?>
            <div class="success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" autocomplete="off">
            <?php echo csrfField(); ?>

            <div class="row">
                <div class="form-group">
                    <label>Nom de l'animal <span class="required">*</span></label>
                    <input type="text" name="nom" required placeholder="Ex: Max, Luna..." maxlength="100">
                </div>
                <div class="form-group">
                    <label>Région <span class="required">*</span></label>
                    <select name="region" required>
                        <?php foreach ($regions as $key => $info): ?>
                            <option value="<?php echo e($key); ?>">
                                <?php echo $info['icon'] . ' ' . e($info['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Espèce <span class="required">*</span></label>
                    <select name="espece" required>
                        <option value="">Choisir...</option>
                        <option value="CHIEN">🐕 Chien</option>
                        <option value="CHAT">🐈 Chat</option>
                        <option value="LAPIN">🐰 Lapin</option>
                        <option value="OISEAU">🐦 Oiseau</option>
                        <option value="RONGEUR">🐹 Rongeur</option>
                        <option value="REPTILE">🦎 Reptile</option>
                        <option value="AUTRE">🐾 Autre</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Race</label>
                    <input type="text" name="race" placeholder="Ex: Berger Allemand" maxlength="100">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Âge (années)</label>
                    <input type="number" name="age" min="0" max="100" step="0.5" placeholder="Ex: 2">
                </div>
                <div class="form-group">
                    <label>Poids (kg)</label>
                    <input type="number" name="poids" min="0" max="500" step="0.1" placeholder="Ex: 12.5">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Sexe</label>
                    <select name="sexe">
                        <option value="">Non spécifié</option>
                        <option value="MALE">Mâle ♂️</option>
                        <option value="FEMELLE">Femelle ♀️</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Photos (max 5MB chaque)</label>
                    <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp">
                    <small style="color: #8b6946;">Formats acceptés: JPG, PNG, WebP</small>
                </div>
            </div>

            <div class="form-group">
                <label>Informations sur l'animal</label>
                <div class="checkbox-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="sterilise">
                        <span>✂️ Stérilisé</span>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="vaccine">
                        <span>💉 Vacciné</span>
                    </label>
                    <label class="checkbox-item" style="background: #fff3cd;" onclick="toggleGPS()">
                        <input type="checkbox" name="errant" id="errantCheck" onchange="toggleGPS()">
                        <span>🐾 Animal errant (sans propriétaire)</span>
                    </label>
                </div>
            </div>

            <!-- GPS Section for Strays -->
            <div id="gpsSection">
                <div class="gps-info">
                    📍 <strong>Localisation GPS</strong> - Cette information permet aux sauveteurs de trouver l'animal
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Latitude</label>
                        <input type="number" name="latitude" id="latitude" step="any" min="-90" max="90" placeholder="36.8065">
                    </div>
                    <div class="form-group">
                        <label>Longitude</label>
                        <input type="number" name="longitude" id="longitude" step="any" min="-180" max="180" placeholder="10.1815">
                    </div>
                </div>
                <div id="map"></div>
                <button type="button" onclick="getCurrentLocation()" style="margin-top: 0.5rem; background: #2c5e2a; color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer;">
                    📍 Ma position actuelle
                </button>
            </div>

            <!-- Health Status Section -->
            <div class="health-section">
                <label style="margin-bottom: 1rem; display: block;">État de santé <span class="required">*</span></label>

                <div class="health-option">
                    <input type="radio" name="statut_sante" value="STABLE" id="sante_stable" checked onchange="toggleMaladie()">
                    <label for="sante_stable" class="health-label health-STABLE">
                        <?php echo $healthOptions['STABLE']['icon']; ?> <?php echo $healthOptions['STABLE']['label']; ?>
                    </label>
                    <span style="color: #666; font-size: 0.9rem; margin-left: 0.5rem;">- En bonne santé</span>
                </div>

                <div class="health-option">
                    <input type="radio" name="statut_sante" value="URGENT" id="sante_urgent" onchange="toggleMaladie()">
                    <label for="sante_urgent" class="health-label health-URGENT">
                        <?php echo $healthOptions['URGENT']['icon']; ?> <?php echo $healthOptions['URGENT']['label']; ?>
                    </label>
                    <span style="color: #666; font-size: 0.9rem; margin-left: 0.5rem;">- Nécessite des soins dans les 24-48h</span>
                </div>

                <div class="health-option">
                    <input type="radio" name="statut_sante" value="CRITIQUE" id="sante_critique" onchange="toggleMaladie()">
                    <label for="sante_critique" class="health-label health-CRITIQUE">
                        <?php echo $healthOptions['CRITIQUE']['icon']; ?> <?php echo $healthOptions['CRITIQUE']['label']; ?>
                    </label>
                    <span style="color: #666; font-size: 0.9rem; margin-left: 0.5rem;">- Danger de mort immédiat</span>
                </div>

                <div id="maladieSection">
                    <div class="alert-warning">
                        ⚠️ Veuillez décrire les symptômes ou blessures observées pour aider les vétérinaires et sauveteurs.
                    </div>
                    <div class="form-group" style="margin-top: 1rem;">
                        <label>Description de l'état de santé / blessures</label>
                        <textarea name="description_maladie" rows="4" placeholder="Décrivez les symptômes, blessures, comportement anormal..."><?php echo e($_POST['description_maladie'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-top: 1.5rem;">
                <label>Description générale</label>
                <textarea name="description" rows="4" placeholder="Décrivez l'animal : caractère, habitudes, histoire..." maxlength="2000"></textarea>
            </div>

            <button type="submit" class="submit-btn">✨ Ajouter l'animal</button>
        </form>
    </div>
</div>

<footer class="footer">
    <p>🐾 PetAdoption - Refuge pour animaux en Tunisie</p>
    <p>📍 Tunisie | 📞 20 123 456 | ✉️ petadoption@gmail.com</p>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let map;
    let marker;

    function toggleGPS() {
        const checkbox = document.getElementById('errantCheck');
        const section = document.getElementById('gpsSection');

        if (checkbox.checked) {
            section.classList.add('visible');
            if (!map) initMap();
        } else {
            section.classList.remove('visible');
        }
    }

    function initMap() {
        // Default to Tunis
        const defaultLat = 36.8065;
        const defaultLng = 10.1815;

        map = L.map('map').setView([defaultLat, defaultLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        marker = L.marker([defaultLat, defaultLng], {draggable: true}).addTo(map);

        marker.on('dragend', function(e) {
            const pos = marker.getLatLng();
            document.getElementById('latitude').value = pos.lat.toFixed(6);
            document.getElementById('longitude').value = pos.lng.toFixed(6);
        });

        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            document.getElementById('latitude').value = e.latlng.lat.toFixed(6);
            document.getElementById('longitude').value = e.latlng.lng.toFixed(6);
        });
    }

    function getCurrentLocation() {
        if (!navigator.geolocation) {
            alert('La géolocalisation n\'est pas supportée par votre navigateur');
            return;
        }

        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            document.getElementById('latitude').value = lat.toFixed(6);
            document.getElementById('longitude').value = lng.toFixed(6);

            if (map) {
                map.setView([lat, lng], 16);
                marker.setLatLng([lat, lng]);
            }
        }, function() {
            alert('Impossible d\'obtenir votre position');
        });
    }

    function toggleMaladie() {
        const isStable = document.getElementById('sante_stable').checked;
        const section = document.getElementById('maladieSection');

        if (!isStable) {
            section.classList.add('visible');
        } else {
            section.classList.remove('visible');
        }
    }
</script>
</body>
</html>