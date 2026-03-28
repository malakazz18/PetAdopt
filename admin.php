<?php
require_once 'config.php';

if (!isAdmin()) {
    header('Location: connexion.php');
    exit();
}

// Handle vet validation
if (isset($_POST['action']) && isset($_POST['vet_id'])) {
    $vetId = $_POST['vet_id'];
    $action = $_POST['action']; // VALIDE or REFUSE
    
    $pdo->prepare("UPDATE veterinaires SET statut_validation = ? WHERE id = ?")
        ->execute([$action, $vetId]);
    
    header('Location: admin.php?section=vets');
    exit();
}

// Get stats
$totalUsers = $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
$totalVets = $pdo->query("SELECT COUNT(*) FROM veterinaires WHERE statut_validation = 'VALIDE'")->fetchColumn();
$totalAnimals = $pdo->query("SELECT COUNT(*) FROM animaux")->fetchColumn();
$pendingVets = $pdo->query("SELECT COUNT(*) FROM veterinaires WHERE statut_validation = 'EN_ATTENTE'")->fetchColumn();

// Get data
$users = $pdo->query("SELECT * FROM utilisateurs ORDER BY date_inscription DESC")->fetchAll();
$vets = $pdo->query("SELECT * FROM veterinaires ORDER BY statut_validation = 'EN_ATTENTE' DESC, date_inscription DESC")->fetchAll();
$animals = $pdo->query("SELECT a.*, u.prenom, u.nom as nom_user FROM animaux a JOIN utilisateurs u ON a.id_proprietaire = u.id ORDER BY a.date_creation DESC")->fetchAll();

$section = $_GET['section'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>PetAdoption - Admin</title>
<style>
body { margin:0; font-family: 'Segoe UI', sans-serif; background:#f5f3ef; }
.navbar { background:#fff; padding:12px 20px; display:flex; justify-content:space-between; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.container { display:flex; }
.sidebar { width:220px; background:#2f5d3a; color:white; min-height:100vh; padding:20px; }
.sidebar li { list-style:none; padding:12px; margin:8px 0; background:#3d7a4c; border-radius:8px; cursor:pointer; transition: all 0.3s; }
.sidebar li:hover { background: #4a9060; transform: translateX(5px); }
.sidebar li.active { background: #ffd700; color: #2f5d3a; font-weight: bold; }
.main { flex:1; padding:20px; }
.cards { display:flex; gap:20px; margin-bottom: 30px; }
.card { flex:1; background:white; padding:20px; border-radius:15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); text-align:center; }
.card h3 { color: #2f5d3a; margin-bottom: 10px; font-size: 0.9rem; text-transform: uppercase; }
.card p { font-size: 2rem; font-weight: bold; color: #8b6946; margin: 0; }
table { width:100%; background:white; margin-top:20px; border-collapse:collapse; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
th, td { padding:12px; border-bottom:1px solid #eee; text-align: left; }
th { background: #f5f0e8; color: #2f5d3a; font-weight: 600; }
tr:hover { background: #faf7f2; }
button { margin-right:5px; padding:6px 12px; border:none; border-radius:6px; cursor:pointer; color:white; font-size: 0.85rem; }
.edit { background:#4CAF50; }
.delete { background:#f44336; }
.validate { background:#2c5e2a; }
.reject { background:#c96b4a; }
.view { background:#8b6946; }
.status { padding:5px 12px; border-radius:20px; color:white; font-size: 0.8rem; font-weight: 500; }
.accepted { background:#2c5e2a; }
.pending { background:#ffa500; }
.rejected { background:#c96b4a; }
.star-badge { color: #ffd700; font-size: 1.2rem; }
.section { display: none; }
.section.active { display: block; }
.diploma-link { color: #2c5e2a; text-decoration: underline; cursor: pointer; }
</style>
</head>

<body>

<div class="navbar">
  <h2>🐾 <span style="color:black;">Pet</span><span style="color:#8b6946;">Adoption</span> - Administration</h2>
  <div style="display:flex; align-items:center; gap:1rem;">
    <span>👨‍💼 Admin</span>
    <a href="logout.php" style="color:#c96b4a; text-decoration:none;">Déconnexion</a>
  </div>
</div>

<div class="container">

  <div class="sidebar">
    <ul>
      <li class="<?php echo $section === 'dashboard' ? 'active' : ''; ?>" onclick="window.location.href='?section=dashboard'">📊 Dashboard</li>
      <li class="<?php echo $section === 'users' ? 'active' : ''; ?>" onclick="window.location.href='?section=users'">👥 Utilisateurs</li>
      <li class="<?php echo $section === 'animals' ? 'active' : ''; ?>" onclick="window.location.href='?section=animals'">🐾 Animaux</li>
      <li class="<?php echo $section === 'vets' ? 'active' : ''; ?>" onclick="window.location.href='?section=vets'">
        🩺 Vétérinaires <?php if($pendingVets > 0) echo "($pendingVets)"; ?>
      </li>
    </ul>
  </div>

  <div class="main">

    <!-- Dashboard -->
    <div id="dashboard" class="section <?php echo $section === 'dashboard' ? 'active' : ''; ?>">
      <h1>📊 Tableau de bord</h1>
      <div class="cards">
        <div class="card">
          <h3>Utilisateurs</h3>
          <p><?php echo $totalUsers; ?></p>
        </div>
        <div class="card">
          <h3>Vétérinaires Validés</h3>
          <p><?php echo $totalVets; ?></p>
        </div>
        <div class="card">
          <h3>Animaux</h3>
          <p><?php echo $totalAnimals; ?></p>
        </div>
        <div class="card" style="<?php echo $pendingVets > 0 ? 'background: #fff3cd;' : ''; ?>">
          <h3>En attente validation</h3>
          <p style="<?php echo $pendingVets > 0 ? 'color: #c96b4a;' : ''; ?>"><?php echo $pendingVets; ?></p>
        </div>
      </div>
      
      <?php if ($pendingVets > 0): ?>
      <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 1rem; border-radius: 10px; margin-top: 20px;">
        <strong>⚠️ Action requise:</strong> <?php echo $pendingVets; ?> vétérinaire(s) en attente de validation. 
        <a href="?section=vets" style="color: #2c5e2a; font-weight: bold;">Voir les demandes →</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Users -->
    <div id="users" class="section <?php echo $section === 'users' ? 'active' : ''; ?>">
      <h1>👥 Utilisateurs</h1>
      <table>
        <tr>
          <th>Nom</th>
          <th>Email</th>
          <th>Téléphone</th>
          <th>Inscription</th>
          <th>Statut</th>
        </tr>
        <?php foreach ($users as $user): ?>
        <tr>
          <td><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></td>
          <td><?php echo htmlspecialchars($user['email']); ?></td>
          <td><?php echo htmlspecialchars($user['telephone'] ?? 'N/A'); ?></td>
          <td><?php echo date('d/m/Y', strtotime($user['date_inscription'])); ?></td>
          <td><span class="status <?php echo strtolower($user['statut']); ?>"><?php echo $user['statut']; ?></span></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <!-- Animals -->
    <div id="animals" class="section <?php echo $section === 'animals' ? 'active' : ''; ?>">
      <h1>🐾 Animaux</h1>
      <table>
        <tr>
          <th>Photo</th>
          <th>Nom</th>
          <th>Espèce</th>
          <th>Propriétaire</th>
          <th>Statut</th>
        </tr>
        <?php foreach ($animals as $animal): ?>
        <tr>
          <td>
            <?php 
            $img = !empty($animal['photos']) ? explode(',', $animal['photos'])[0] : 'https://via.placeholder.com/50';
            ?>
            <img src="<?php echo $img; ?>" style="width:50px; height:50px; object-fit:cover; border-radius:5px;">
          </td>
          <td><?php echo htmlspecialchars($animal['nom']); ?></td>
          <td><?php echo $animal['espece']; ?></td>
          <td><?php echo htmlspecialchars($animal['prenom'] . ' ' . $animal['nom_user']); ?></td>
          <td><span class="status <?php echo strtolower(str_replace('_', '', $animal['statut_adoption'])); ?>"><?php echo $animal['statut_adoption']; ?></span></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <!-- Vets -->
    <div id="vets" class="section <?php echo $section === 'vets' ? 'active' : ''; ?>">
      <h1>🩺 Demandes Vétérinaires</h1>
      <p style="margin-bottom: 20px; color: #666;">
        ⭐ = Diplôme vérifié par l'administrateur | ⏳ = En attente de validation
      </p>
      <table>
        <tr>
          <th>Nom</th>
          <th>Email</th>
          <th>Téléphone</th>
          <th>Diplôme</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
        <?php foreach ($vets as $vet): ?>
        <tr>
          <td><?php echo htmlspecialchars($vet['prenom'] . ' ' . $vet['nom']); ?></td>
          <td><?php echo htmlspecialchars($vet['email']); ?></td>
          <td><?php echo htmlspecialchars($vet['telephone'] ?? 'N/A'); ?></td>
          <td>
            <?php if ($vet['photo_diplome']): ?>
              <a href="<?php echo $vet['photo_diplome']; ?>" target="_blank" class="diploma-link">📄 Voir le diplôme</a>
            <?php else: ?>
              <span style="color: #999;">Non fourni</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($vet['statut_validation'] === 'VALIDE'): ?>
              <span class="status accepted">⭐ Validé</span>
            <?php elseif ($vet['statut_validation'] === 'REFUSE'): ?>
              <span class="status rejected">❌ Refusé</span>
            <?php else: ?>
              <span class="status pending">⏳ En attente</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($vet['statut_validation'] === 'EN_ATTENTE'): ?>
              <form method="POST" style="display: inline;">
                <input type="hidden" name="vet_id" value="<?php echo $vet['id']; ?>">
                <button type="submit" name="action" value="VALIDE" class="validate">✓ Valider</button>
                <button type="submit" name="action" value="REFUSE" class="reject">✕ Refuser</button>
              </form>
            <?php else: ?>
              <span style="color: #999; font-size: 0.85rem;">Traité</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

  </div>
</div>

</body>
</html>