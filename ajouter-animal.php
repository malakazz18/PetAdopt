<?php
global $pdo;
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
        verifyCsrf();

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
        $latitude = $errant ? sanitizeFloat($_POST['latitude'] ?? null, -90, 90) : null;
        $longitude = $errant ? sanitizeFloat($_POST['longitude'] ?? null, -180, 180) : null;
        $statutSante = in_array($_POST['statut_sante'] ?? '', ['STABLE', 'URGENT', 'CRITIQUE']) ? $_POST['statut_sante'] : 'STABLE';
        $descriptionMaladie = $statutSante !== 'STABLE' ? sanitizeString($_POST['description_maladie'] ?? '', 1000) : null;

        if (empty($nom) || empty($espece)) {
            throw new Exception("Nom et espèce sont obligatoires");
        }

        // ===================================================================
        // CORRECTION CRITIQUE : Récupération de l'ID utilisateur valide
        // ===================================================================
        $userId = null;

        if (isVet() && isset($_SESSION['vet_id'])) {
            $vetId = $_SESSION['vet_id'];

            // 1. Vérifier si le vétérinaire a déjà un compte utilisateur lié
            $stmt = $pdo->prepare("SELECT id_utilisateur, email, nom, prenom, telephone, region, mot_de_passe FROM veterinaires WHERE id = ?");
            $stmt->execute([$vetId]);
            $vet = $stmt->fetch();

            if ($vet) {
                if (!empty($vet['id_utilisateur'])) {
                    $userId = $vet['id_utilisateur'];
                } else {
                    // 2. Chercher un utilisateur existant avec cet email
                    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                    $stmt->execute([$vet['email']]);
                    $existingUser = $stmt->fetch();

                    if ($existingUser) {
                        $userId = $existingUser['id'];
                        // Mettre à jour le lien
                        $pdo->prepare("UPDATE veterinaires SET id_utilisateur = ? WHERE id = ?")->execute([$userId, $vetId]);
                    } else {
                        // 3. Créer un compte utilisateur pour ce vétérinaire
                        $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, region, statut, date_inscription) VALUES (?, ?, ?, ?, ?, ?, 'ACTIF', NOW())");
                        $stmt->execute([
                                $vet['nom'],
                                $vet['prenom'],
                                $vet['email'],
                                $vet['mot_de_passe'],
                                $vet['telephone'] ?? '',
                                $vet['region'] ?? 'tunis'
                        ]);
                        $userId = $pdo->lastInsertId();

                        // Lier le vétérinaire à ce nouvel utilisateur
                        $pdo->prepare("UPDATE veterinaires SET id_utilisateur = ? WHERE id = ?")->execute([$userId, $vetId]);
                    }
                }
            }
        } else {
            // Utilisateur normal
            $userId = getCurrentUserId();
        }

        // Vérification finale obligatoire
        if (empty($userId)) {
            throw new Exception("Erreur d'authentification. Veuillez vous reconnecter.");
        }

        // Vérifier que l'ID existe vraiment dans utilisateurs
        $check = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = ?");
        $check->execute([$userId]);
        if (!$check->fetch()) {
            throw new Exception("Compte utilisateur invalide. Contactez l'administrateur.");
        }
        // ===================================================================

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
                    $path = secureImageUpload($file, 'uploads/animaux/', 5242880);
                    if ($path) $photos[] = $path;
                }
            }
        }

        $photoStr = implode(',', $photos);

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
        .error { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; border: 1px solid #f5c6cb; }
        .footer { background: white; color: #9b9b9b; text-align: center; padding: 2rem; border-top: 1px solid #f0e8df; margin-top: auto; }
        #gpsSection { display: none; background: #fff3cd; border: 1px solid #ffeaa7; padding: 1.5rem; border-radius: 12px; margin-top: 1rem; }
        #gpsSection.visible { display: block; }
        #map { height: 300px; border-radius: 12px; margin-top: 1rem; border: 2px solid #2c5e2a; }
        .health-section { background: #f8f9fa; padding: 1.5rem; border-radius: 12px; margin-top: 1rem; border: 1px solid #e0d5c8; }
        .health-STABLE { background: #d4edda; color: #155724; padding: 0.3rem 0.8rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem; }
        .health-URGENT { background: #fff3cd; color: #856404; padding: 0.3rem 0.8rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem; }
        .health-CRITIQUE { background: #f8d7da; color: #721c24; padding: 0.3rem 0.8rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem; }
        @media (max-width: 768px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <nav class="nav">
        <a href="accueil.php" style="text-decoration:none;">
            <div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div>
        </a>
        <a href="accueil.php" style="text-decoration: none; color: #5a5a5a; font-weight: 500;">← Retour</a>
    </nav>
</header>

<div class="container">
    <div class="form-card">
        <h1>🐾 Ajouter un animal</h1>
        <p class="subtitle">Remplissez les informations</p>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>

            <div class="row">
                <div class="form-group">
                    <label>Nom <span style="color:red">*</span></label>
                    <input type="text" name="nom" required maxlength="100">
                </div>
                <div class="form-group">
                    <label>Région <span style="color:red">*</span></label>
                    <select name="region" required>
                        <?php foreach ($regions as $key => $info): ?>
                            <option value="<?php echo $key; ?>"><?php echo $info['icon'] . ' ' . $info['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Espèce <span style="color:red">*</span></label>
                    <select name="espece" required>
                        <option value="CHIEN">🐕 Chien</option>
                        <option value="CHAT">🐈 Chat</option>
                        <option value="LAPIN">🐰 Lapin</option>
                        <option value="OISEAU">🐦 Oiseau</option>
                        <option value="AUTRE">🐾 Autre</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Race</label>
                    <input type="text" name="race" placeholder="Ex: Berger Allemand">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Âge (années)</label>
                    <input type="number" name="age" min="0" max="100" step="0.5">
                </div>
                <div class="form-group">
                    <label>Poids (kg)</label>
                    <input type="number" name="poids" min="0" max="500" step="0.1">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Sexe</label>
                    <select name="sexe">
                        <option value="">Non spécifié</option>
                        <option value="MALE">Mâle</option>
                        <option value="FEMELLE">Femelle</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Photos</label>
                    <input type="file" name="photos[]" multiple accept="image/*">
                </div>
            </div>

            <div class="form-group">
                <label>Options</label>
                <div class="checkbox-group">
                    <label class="checkbox-item"><input type="checkbox" name="sterilise"> Stérilisé</label>
                    <label class="checkbox-item"><input type="checkbox" name="vaccine"> Vacciné</label>
                    <label class="checkbox-item"><input type="checkbox" name="errant" id="errantCheck" onchange="toggleGPS()"> Animal errant</label>
                </div>
            </div>

            <div id="gpsSection">
                <div class="row">
                    <div class="form-group">
                        <label>Latitude</label>
                        <input type="number" name="latitude" id="latitude" step="any">
                    </div>
                    <div class="form-group">
                        <label>Longitude</label>
                        <input type="number" name="longitude" id="longitude" step="any">
                    </div>
                </div>
                <div id="map"></div>
            </div>

            <div class="health-section">
                <label>État de santé <span style="color:red">*</span></label>
                <div style="margin-top:0.5rem">
                    <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem">
                        <input type="radio" name="statut_sante" value="STABLE" checked onchange="toggleMaladie()">
                        <span class="health-STABLE">Stable</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem">
                        <input type="radio" name="statut_sante" value="URGENT" onchange="toggleMaladie()">
                        <span class="health-URGENT">Urgent</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:0.5rem">
                        <input type="radio" name="statut_sante" value="CRITIQUE" onchange="toggleMaladie()">
                        <span class="health-CRITIQUE">Critique</span>
                    </label>
                </div>
                <div id="maladieSection" style="display:none; margin-top:1rem">
                    <label>Description santé</label>
                    <textarea name="description_maladie" rows="3"></textarea>
                </div>
            </div>

            <div class="form-group" style="margin-top:1.5rem">
                <label>Description</label>
                <textarea name="description" rows="4"></textarea>
            </div>

            <button type="submit" class="submit-btn">Ajouter l'animal</button>
        </form>
    </div>
</div>

<footer class="footer">
    <p>🐾 PetAdoption</p>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let map, marker;
    function toggleGPS() {
        const checked = document.getElementById('errantCheck').checked;
        document.getElementById('gpsSection').className = checked ? 'visible' : '';
        if (checked && !map) {
            map = L.map('map').setView([36.8065, 10.1815], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
            marker = L.marker([36.8065, 10.1815], {draggable:true}).addTo(map);
            marker.on('dragend', function(e) {
                document.getElementById('latitude').value = marker.getLatLng().lat.toFixed(6);
                document.getElementById('longitude').value = marker.getLatLng().lng.toFixed(6);
            });
        }
    }
    function toggleMaladie() {
        document.getElementById('maladieSection').style.display =
            document.querySelector('input[name="statut_sante"]:checked').value === 'STABLE' ? 'none' : 'block';
    }
</script>
</body>
</html>