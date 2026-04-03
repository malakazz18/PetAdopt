<?php
global $pdo;
require_once 'config.php';

if (!isLoggedIn() || !isVet()) {
    header('Location: connexion.php');
    exit();
}

$userId = getCurrentUserId();
$vetEmail = $_SESSION['user_email'] ?? '';

// Get vet info
$stmt = $pdo->prepare("SELECT * FROM veterinaires WHERE id = ?");
$stmt->execute([$userId]);
$vet = $stmt->fetch();

if (!$vet) {
    die("Vétérinaire non trouvé");
}

// Get animals assigned to this vet (through utilisateurs email link)
$stmt = $pdo->prepare("
    SELECT a.*, 
           COUNT(da.id) as nb_demandes
    FROM animaux a 
    JOIN utilisateurs u ON a.id_proprietaire = u.id
    LEFT JOIN annonces an ON a.id = an.id_animal
    LEFT JOIN demandes_adoption da ON an.id = da.id_annonce AND da.statut = 'EN_ATTENTE'
    WHERE u.email = ?
    GROUP BY a.id
    ORDER BY a.date_creation DESC
");
$stmt->execute([$vet['email']]);
$vetAnimals = $stmt->fetchAll();

// Handle profile update
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $nomCabinet = $_POST['nom_cabinet'] ?? '';
        $adresse = $_POST['adresse_cabinet'] ?? '';
        $telCabinet = $_POST['telephone_cabinet'] ?? '';
        $horaires = $_POST['horaires'] ?? '';
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;

        try {
            $stmt = $pdo->prepare("
                UPDATE veterinaires 
                SET nom_cabinet = ?, adresse_cabinet = ?, telephone_cabinet = ?, 
                    horaires = ?, latitude = ?, longitude = ?
                WHERE id = ?
            ");
            $stmt->execute([$nomCabinet, $adresse, $telCabinet, $horaires, $latitude, $longitude, $userId]);
            $success = "Profil mis à jour avec succès !";

            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM veterinaires WHERE id = ?");
            $stmt->execute([$userId]);
            $vet = $stmt->fetch();
        } catch(PDOException $e) {
            $error = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace Vétérinaire - PetAdoption</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #faf7f2;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            background: white;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }
        .nav {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .logo-text span { color: #8b6946; }
        .nav-links { display: flex; gap: 2rem; list-style: none; }
        .nav-links a {
            text-decoration: none;
            color: #5a5a5a;
            font-weight: 500;
            transition: color 0.3s;
        }
        .nav-links a:hover, .nav-links a.active { color: #2c5e2a; }
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-secondary { background: #f0e8df; color: #8b6946; }

        .container {
            max-width: 1200px;
            margin: 100px auto 40px;
            padding: 0 2rem;
            flex: 1;
            width: 100%;
        }

        .page-header {
            background: linear-gradient(135deg, #e8f0e5 0%, #f5efe8 100%);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .page-header h1 {
            color: #2c5e2a;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .badge-vet {
            display: inline-block;
            background: #ffd700;
            color: #333;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #f0e8df;
        }

        .card h2 {
            color: #2c5e2a;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4a4a4a;
            font-weight: 500;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.9rem 1rem;
            border: 1px solid #e0d5c8;
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2c5e2a;
            box-shadow: 0 0 0 3px rgba(44, 94, 42, 0.1);
        }

        .btn-primary {
            background: #2c5e2a;
            color: white;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #1e461c;
        }

        #map {
            height: 300px;
            border-radius: 12px;
            margin-top: 1rem;
            border: 1px solid #e0d5c8;
        }

        .animal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .animal-card {
            background: #f5f0e8;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }

        .animal-card img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #e8f0e5;
            color: #2c5e2a;
            border: 1px solid #2c5e2a;
        }

        .alert-error {
            background: #fce8e6;
            color: #c96b4a;
            border: 1px solid #c96b4a;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f0e8df;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .footer {
            background: white;
            color: #9b9b9b;
            text-align: center;
            padding: 2rem;
            border-top: 1px solid #f0e8df;
            margin-top: auto;
        }

        @media (max-width: 968px) {
            .grid {
                grid-template-columns: 1fr;
            }
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
            <li><a href="accueil.php">Accueil</a></li>
            <li><a href="espace-veterinaire.php" class="active">Mon Cabinet</a></li>
            <li><a href="ajouter-animal.php">Ajouter un animal</a></li>
            <li><a href="mon-espace.php">Mon Espace</a></li>
        </ul>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span style="color: #4a4a4a;"><?php echo $_SESSION['user_name']; ?> ⭐</span>
            <button class="btn btn-secondary" onclick="window.location.href='logout.php'">Déconnexion</button>
        </div>
    </nav>
</header>

<div class="container">
    <div class="page-header">
        <span class="badge-vet">⭐ Vétérinaire Vérifié</span>
        <h1>Dr. <?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?></h1>
        <p style="color: #6b5a4a; margin-top: 0.5rem;">Gérez votre cabinet et vos animaux disponibles</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="grid">
        <!-- Clinic Info -->
        <div class="card">
            <h2>🏥 Informations du Cabinet</h2>
            <form method="POST">
                <input type="hidden" name="update_profile" value="1">

                <div class="form-group">
                    <label>Nom du Cabinet</label>
                    <input type="text" name="nom_cabinet" value="<?php echo htmlspecialchars($vet['nom_cabinet'] ?? ''); ?>" placeholder="Ex: Cabinet Vétérinaire du Centre">
                </div>

                <div class="form-group">
                    <label>Adresse</label>
                    <textarea name="adresse_cabinet" rows="2" placeholder="Adresse complète..."><?php echo htmlspecialchars($vet['adresse_cabinet'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Téléphone du Cabinet</label>
                    <input type="tel" name="telephone_cabinet" value="<?php echo htmlspecialchars($vet['telephone_cabinet'] ?? ''); ?>" placeholder="20 123 456">
                </div>

                <div class="form-group">
                    <label>Horaires d'ouverture</label>
                    <textarea name="horaires" rows="3" placeholder="Lun-Ven: 9h-18h&#10;Sam: 9h-12h&#10;Dim: Fermé"><?php echo htmlspecialchars($vet['horaires'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Coordonnées GPS (pour la carte)</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <input type="text" name="latitude" value="<?php echo htmlspecialchars($vet['latitude'] ?? ''); ?>" placeholder="Latitude (ex: 36.8065)">
                        <input type="text" name="longitude" value="<?php echo htmlspecialchars($vet['longitude'] ?? ''); ?>" placeholder="Longitude (ex: 10.1815)">
                    </div>
                    <small style="color: #8b6946;">Laissez vide pour masquer la carte</small>
                </div>

                <button type="submit" class="btn-primary">💾 Enregistrer les modifications</button>
            </form>
        </div>

        <!-- Map Preview -->
        <div class="card">
            <h2>📍 Localisation</h2>
            <?php if (!empty($vet['latitude']) && !empty($vet['longitude'])): ?>
                <div id="map"></div>
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                <script>
                    var map = L.map('map').setView([<?php echo $vet['latitude']; ?>, <?php echo $vet['longitude']; ?>], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
                    L.marker([<?php echo $vet['latitude']; ?>, <?php echo $vet['longitude']; ?>])
                        .addTo(map)
                        .bindPopup("<?php echo htmlspecialchars($vet['nom_cabinet'] ?? 'Cabinet'); ?>");
                </script>
            <?php else: ?>
                <div style="background: #f5f0e8; border-radius: 12px; height: 300px; display: flex; align-items: center; justify-content: center; color: #8b6946;">
                    <div style="text-align: center;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">🗺️</div>
                        <p>Ajoutez vos coordonnées GPS<br>pour afficher la carte</p>
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 1.5rem;">
                <div class="info-row">
                    <span>📍 Adresse</span>
                    <strong><?php echo htmlspecialchars($vet['adresse_cabinet'] ?? 'Non renseignée'); ?></strong>
                </div>
                <div class="info-row">
                    <span>📞 Téléphone</span>
                    <strong><?php echo htmlspecialchars($vet['telephone_cabinet'] ?? 'Non renseigné'); ?></strong>
                </div>
                <div class="info-row">
                    <span>⏰ Horaires</span>
                    <strong style="white-space: pre-line;"><?php echo htmlspecialchars($vet['horaires'] ?? 'Non renseignés'); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Animals Section -->
    <div class="card" style="margin-top: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2>🐾 Animaux à adopter dans votre cabinet</h2>
            <a href="ajouter-animal.php" class="btn-primary">➕ Ajouter un animal</a>
        </div>

        <?php if (empty($vetAnimals)): ?>
            <div style="text-align: center; padding: 3rem; background: #faf7f2; border-radius: 12px;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🐾</div>
                <p style="color: #8b6946;">Vous n'avez pas encore d'animaux en adoption</p>
                <a href="ajouter-animal.php" class="btn-primary" style="display: inline-block; margin-top: 1rem;">Ajouter votre premier animal</a>
            </div>
        <?php else: ?>
            <div class="animal-grid">
                <?php foreach ($vetAnimals as $animal):
                    $photo = !empty($animal['photos']) ? explode(',', $animal['photos'])[0] : 'https://via.placeholder.com/200x150?text=Pas+d%27image';
                    ?>
                    <div class="animal-card">
                        <img src="<?php echo htmlspecialchars($photo); ?>" alt="<?php echo htmlspecialchars($animal['nom']); ?>">
                        <h4 style="color: #2c5e2a; margin-bottom: 0.3rem;"><?php echo htmlspecialchars($animal['nom']); ?></h4>
                        <p style="color: #8b6946; font-size: 0.85rem; margin-bottom: 0.5rem;"><?php echo $animal['espece']; ?></p>
                        <?php if ($animal['nb_demandes'] > 0): ?>
                            <span style="background: #ffd700; color: #333; padding: 0.2rem 0.6rem; border-radius: 10px; font-size: 0.75rem; font-weight: bold;">
                                <?php echo $animal['nb_demandes']; ?> demande(s)
                            </span>
                        <?php endif; ?>
                        <a href="animal-details.php?id=<?php echo $animal['id']; ?>" style="display: block; margin-top: 0.5rem; color: #2c5e2a; text-decoration: none; font-size: 0.85rem;">Voir →</a>
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
</body>
</html>