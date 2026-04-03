<?php
global $pdo;
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: accueil.php');
    exit();
}

$error = '';
$success = '';

$regions = [
    'tunis' => 'Tunis 🌆',
    'sfax' => 'Sfax 🏛️',
    'sousse' => 'Sousse 🌊',
    'bizerte' => 'Bizerte ⛵',
    'nabeul' => 'Nabeul 🍊'
];

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loginEmail'])) {
    $email = $_POST['loginEmail'];
    $password = $_POST['loginPassword'];
    
    if ($email === 'admin' && $password === 'group20AGL') {
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_name'] = 'Admin';
        header('Location: admin.php');
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ? AND statut = 'ACTIF'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['mot_de_passe'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
        $_SESSION['is_vet'] = false;
        $_SESSION['user_region'] = $user['region'];
        
        $pdo->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$user['id']]);
        
        header('Location: accueil.php');
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM veterinaires WHERE email = ? AND statut = 'ACTIF'");
    $stmt->execute([$email]);
    $vet = $stmt->fetch();
    
    if ($vet && password_verify($password, $vet['mot_de_passe'])) {
        $_SESSION['vet_id'] = $vet['id'];
        $_SESSION['user_name'] = $vet['prenom'] . ' ' . $vet['nom'];
        $_SESSION['is_vet'] = true;
        $_SESSION['vet_status'] = $vet['statut_validation'];
        $_SESSION['user_region'] = $vet['region'];
        
        $pdo->prepare("UPDATE veterinaires SET derniere_connexion = NOW() WHERE id = ?")->execute([$vet['id']]);
        
        header('Location: accueil.php');
        exit();
    }
    
    $error = 'Email ou mot de passe incorrect';
}

// Handle Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regName'])) {
    $name = $_POST['regName'];
    $email = $_POST['regEmail'];
    $phone = $_POST['regPhone'] ?? '';
    $password = $_POST['regPassword'];
    $confirmPassword = $_POST['regConfirmPassword'];
    $region = $_POST['region'] ?? 'tunis';
    $isVet = isset($_POST['isVet']);
    
    if ($password !== $confirmPassword) {
        $error = 'Les mots de passe ne correspondent pas';
    } elseif (strlen($password) < 6) {
        $error = 'Le mot de passe doit contenir au moins 6 caractères';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $parts = explode(' ', $name, 2);
        $prenom = $parts[0];
        $nom = $parts[1] ?? '';
        
        try {
            if ($isVet) {
                $diplomaPath = null;
                if (isset($_FILES['diplomaFile']) && $_FILES['diplomaFile']['error'] === 0) {
                    $uploadDir = 'uploads/diplomas/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    
                    $fileName = time() . '_' . basename($_FILES['diplomaFile']['name']);
                    $diplomaPath = $uploadDir . $fileName;
                    move_uploaded_file($_FILES['diplomaFile']['tmp_name'], $diplomaPath);
                }
                
                $stmt = $pdo->prepare("INSERT INTO veterinaires (nom, prenom, email, mot_de_passe, telephone, region, photo_diplome, statut_validation, adresse_cabinet, telephone_cabinet) VALUES (?, ?, ?, ?, ?, ?, ?, 'EN_ATTENTE', ?, ?)");
                $stmt->execute([$nom, $prenom, $email, $hashedPassword, $phone, $region, $diplomaPath, $_POST['adresseCabinet'] ?? '', $_POST['telephoneCabinet'] ?? '']);
                
                $success = 'Compte vétérinaire créé ! En attente de validation par l\'administrateur.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, region) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nom, $prenom, $email, $hashedPassword, $phone, $region]);
                
                $success = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
            }
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Cet email est déjà utilisé';
            } else {
                $error = 'Erreur lors de l\'inscription: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PetAdoption - Connexion</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #faf7f2; min-height: 100vh; }
        .navbar { background: white; box-shadow: 0 2px 15px rgba(0,0,0,0.05); position: fixed; top: 0; width: 100%; z-index: 1000; }
        .nav-container { max-width: 1200px; margin: 0 auto; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 0.5rem; }
        .logo-icon { font-size: 1.8rem; }
        .logo-text { font-size: 1.3rem; font-weight: bold; color: #2c5e2a; }
        .logo-text span { color: #8b6946; }
        .container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 100px 2rem 2rem; }
        .cards-wrapper { display: flex; gap: 2rem; justify-content: center; flex-wrap: wrap; max-width: 900px; width: 100%; }
        .auth-card { background: white; border-radius: 24px; padding: 2rem; width: 380px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transition: transform 0.3s, box-shadow 0.3s; border: 1px solid #f0e8df; }
        .auth-card:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }
        .card-header { text-align: center; margin-bottom: 1.8rem; }
        .card-icon { font-size: 2.5rem; background: #f5f0e8; display: inline-block; padding: 0.8rem; border-radius: 60px; margin-bottom: 1rem; }
        .card-header h2 { font-size: 1.5rem; color: #2c5e2a; margin-bottom: 0.3rem; }
        .card-header p { color: #9b9b9b; font-size: 0.85rem; }
        .admin-notice { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem; text-align: center; }
        .form-group { margin-bottom: 1.2rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; color: #4a4a4a; font-weight: 500; font-size: 0.85rem; }
        .form-group input, .form-group select { width: 100%; padding: 0.8rem 1rem; border: 1px solid #e0d5c8; border-radius: 12px; font-size: 0.9rem; transition: all 0.3s; background: white; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2c5e2a; box-shadow: 0 0 0 3px rgba(44, 94, 42, 0.1); }
        .submit-btn { width: 100%; padding: 0.8rem; background: #2c5e2a; color: white; border: none; border-radius: 12px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: all 0.3s; margin-top: 0.5rem; }
        .submit-btn:hover { background: #1e461c; transform: scale(1.02); }
        .error-message { color: #c96b4a; font-size: 0.85rem; margin-top: 0.5rem; text-align: center; padding: 0.5rem; background: #fce8e6; border-radius: 8px; }
        .success-message { color: #2c5e2a; text-align: center; margin-top: 1rem; font-size: 0.85rem; padding: 0.5rem; background: #e8f0e5; border-radius: 8px; }
        .switch-link { text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #f0e8df; }
        .switch-link a { color: #8b6946; text-decoration: none; font-weight: 500; font-size: 0.85rem; cursor: pointer; }
        .switch-link a:hover { text-decoration: underline; color: #2c5e2a; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.95rem; color: #4a4a4a; }
        .checkbox-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: #2c5e2a; }
        .vet-fields { display: none; margin-top: 1rem; padding: 1rem; background: #f5f0e8; border-radius: 12px; }
        .footer { background: white; color: #9b9b9b; text-align: center; padding: 1.5rem; margin-top: 2rem; border-top: 1px solid #f0e8df; font-size: 0.8rem; }
        @media (max-width: 768px) { .auth-card { width: 100%; max-width: 380px; } }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">
                <div class="logo-icon">🐾</div>
                <div class="logo-text">Pet<span>Adoption</span></div>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="cards-wrapper">
            <!-- Login Card -->
            <div class="auth-card" id="loginCard">
                <div class="card-header">
                    <div class="card-icon">🔐</div>
                    <h2>Connexion</h2>
                    <p>Accédez à votre espace personnel</p>
                </div>

                
                <?php if ($error && !isset($_POST['regName'])): ?>
                    <div class="error-message"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="success-message"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>📧 Email (ou 'admin')</label>
                        <input type="text" name="loginEmail" required placeholder="votre@email.com">
                    </div>
                    <div class="form-group">
                        <label>🔒 Mot de passe</label>
                        <input type="password" name="loginPassword" required placeholder="Votre mot de passe">
                    </div>
                    <button type="submit" class="submit-btn">Se connecter</button>
                </form>
                <div class="switch-link">
                    Pas encore de compte ? <a href="#" onclick="showRegister()">Créer un compte</a>
                </div>
            </div>

            <!-- Register Card -->
            <div class="auth-card" id="registerCard" style="display: none;">
                <div class="card-header">
                    <div class="card-icon">✨</div>
                    <h2>Créer un compte</h2>
                    <p>Rejoignez notre communauté</p>
                </div>
                
                <?php if ($error && isset($_POST['regName'])): ?>
                    <div class="error-message"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>👤 Nom complet *</label>
                        <input type="text" name="regName" required placeholder="Jean Dupont">
                    </div>
                    <div class="form-group">
                        <label>📧 Email *</label>
                        <input type="email" name="regEmail" required placeholder="jean@email.com">
                    </div>
                    <div class="form-group">
                        <label>📍 Région *</label>
                        <select name="region" required>
                            <?php foreach ($regions as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>📱 Téléphone</label>
                        <input type="tel" name="regPhone" placeholder="20 123 456">
                    </div>
                    <div class="form-group">
                        <label>🔒 Mot de passe *</label>
                        <input type="password" name="regPassword" required placeholder="Au moins 6 caractères">
                    </div>
                    <div class="form-group">
                        <label>✓ Confirmer *</label>
                        <input type="password" name="regConfirmPassword" required placeholder="Confirmez">
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="isVet" id="isVet" onchange="toggleVetFields()">
                            🩺 Je suis vétérinaire (étoile ⭐ après validation)
                        </label>
                    </div>
                    
                    <div class="vet-fields" id="vetFields">
                        <div class="form-group">
                            <label>📄 Diplôme (PDF ou Image)</label>
                            <input type="file" name="diplomaFile" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="form-group">
                            <label>🏥 Adresse du cabinet</label>
                            <input type="text" name="adresseCabinet" placeholder="123 Rue...">
                        </div>
                        <div class="form-group">
                            <label>📞 Téléphone du cabinet</label>
                            <input type="tel" name="telephoneCabinet" placeholder="20 123 456">
                        </div>
                    </div>
                    
                    <button type="submit" class="submit-btn">Créer mon compte</button>
                </form>
                <div class="switch-link">
                    Déjà un compte ? <a href="#" onclick="showLogin()">Se connecter</a>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>🐾 PetAdoption - Donnez une seconde chance à un compagnon en Tunisie</p>
    </footer>

    <script>
        function showRegister() {
            document.getElementById('loginCard').style.display = 'none';
            document.getElementById('registerCard').style.display = 'block';
        }
        function showLogin() {
            document.getElementById('loginCard').style.display = 'block';
            document.getElementById('registerCard').style.display = 'none';
        }
        function toggleVetFields() {
            const checkbox = document.getElementById('isVet');
            const fields = document.getElementById('vetFields');
            fields.style.display = checkbox.checked ? 'block' : 'none';
        }
    </script>
</body>
</html>