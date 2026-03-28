<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: connexion.php');
    exit();
}

$villes = getVilles($pdo);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = $_POST['nom'];
    $espece = $_POST['espece'];
    $race = $_POST['race'] ?? '';
    $age = $_POST['age'] ?? 0;
    $sexe = $_POST['sexe'] ?? '';
    $poids = $_POST['poids'] ?? 0;
    $description = $_POST['description'] ?? '';
    $idVille = $_POST['id_ville'] ?? $_SESSION['id_ville'] ?? 1;
    $sterilise = isset($_POST['sterilise']) ? 1 : 0;
    $vaccine = isset($_POST['vaccine']) ? 1 : 0;
    $errant = isset($_POST['errant']) ? 1 : 0;
    
    $photos = [];
    if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
        $uploadDir = 'uploads/animaux/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['photos']['error'][$key] === 0) {
                $fileName = time() . '_' . $key . '_' . basename($_FILES['photos']['name'][$key]);
                $destination = $uploadDir . $fileName;
                if (move_uploaded_file($tmp_name, $destination)) {
                    $photos[] = $destination;
                }
            }
        }
    }
    
    $photoStr = implode(',', $photos);
    $userId = getCurrentUserId();
    
    try {
        $stmt = $pdo->prepare("INSERT INTO animaux (nom, espece, race, age, sexe, poids, description, photos, id_proprietaire, id_ville, sterilise, vaccine, errant) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nom, $espece, $race, $age, $sexe, $poids, $description, $photoStr, $userId, $idVille, $sterilise, $vaccine, $errant]);
        
        $animalId = $pdo->lastInsertId();
        $titre = $nom . ' - ' . $espece . ' à adopter';
        
        $stmt = $pdo->prepare("INSERT INTO annonces (id_animal, id_proprietaire, titre, description_annonce) VALUES (?, ?, ?, ?)");
        $stmt->execute([$animalId, $userId, $titre, $description]);
        
        $success = "Animal ajouté avec succès !";
    } catch(PDOException $e) {
        $error = "Erreur lors de l'ajout: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajouter un animal - PetAdoption</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #faf7f2; min-height: 100vh; padding-top: 80px; }
        .header { background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); position: fixed; width: 100%; top: 0; z-index: 1000; }
        .nav { max-width: 1400px; margin: 0 auto; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .container { max-width: 800px; margin: 40px auto; padding: 0 2rem; }
        .form-card { background: white; border-radius: 20px; padding: 2.5rem; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border: 1px solid #f0e8df; }
        h1 { color: #2c5e2a; margin-bottom: 0.5rem; }
        .subtitle { color: #8b6946; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; color: #4a4a4a; font-weight: 500; }
        input, select, textarea { width: 100%; padding: 0.9rem 1rem; border: 1px solid #e0d5c8; border-radius: 12px; font-size: 0.95rem; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #2c5e2a; box-shadow: 0 0 0 3px rgba(44, 94, 42, 0.1); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .checkbox-group { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .checkbox-item { display: flex; align-items: center; gap: 0.5rem; }
        .checkbox-item input[type="checkbox"] { width: 20px; height: 20px; accent-color: #2c5e2a; }
        .submit-btn { width: 100%; padding: 1rem; background: #2c5e2a; color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .submit-btn:hover { background: #1e461c; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #8b6946; text-decoration: none; margin-bottom: 1.5rem; }
        .success { background: #e8f0e5; color: #2c5e2a; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; }
        .error { background: #fce8e6; color: #c96b4a; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; }
        @media (max-width: 768px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header class="header">
        <nav class="nav">
            <div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div>
            <a href="accueil.php" style="text-decoration: none; color: #5a5a5a; font-weight: 500;">← Retour à l'accueil</a>
        </nav>
    </header>

    <div class="container">
        <div class="form-card">
            <h1>🐾 Ajouter un animal à l'adoption</h1>
            <p class="subtitle">Remplissez toutes les informations sur l'animal</p>

            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="row">
                    <div class="form-group">
                        <label>Nom de l'animal *</label>
                        <input type="text" name="nom" required placeholder="Ex: Max, Luna...">
                    </div>
                    <div class="form-group">
                        <label>Ville / Région *</label>
                        <select name="id_ville" required>
                            <?php foreach ($villes as $ville): ?>
                            <option value="<?php echo $ville['id']; ?>" <?php echo (isset($_SESSION['id_ville']) && $_SESSION['id_ville'] == $ville['id']) ? 'selected' : ''; ?>>
                                <?php echo $ville['icon'] . ' ' . $ville['nom']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="form-group">
                        <label>Espèce *</label>
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
                        <input type="text" name="race" placeholder="Ex: Berger Allemand">
                    </div>
                </div>

                <div class="row">
                    <div class="form-group">
                        <label>Âge (années)</label>
                        <input type="number" name="age" min="0" step="0.5" placeholder="Ex: 2">
                    </div>
                    <div class="form-group">
                        <label>Poids (kg)</label>
                        <input type="number" name="poids" min="0" step="0.1" placeholder="Ex: 12.5">
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
                        <label>Photos</label>
                        <input type="file" name="photos[]" multiple accept="image/*">
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
                        <label class="checkbox-item">
                            <input type="checkbox" name="errant">
                            <span>🐾 Animal errant</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" placeholder="Décrivez l'animal : caractère, habitudes, histoire..."></textarea>
                </div>

                <button type="submit" class="submit-btn">✨ Ajouter l'animal</button>
            </form>
        </div>
    </div>
</body>
</html>