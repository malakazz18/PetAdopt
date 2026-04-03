<?php
require_once 'config.php';

if (!isAdmin()) {
    header('Location: connexion.php');
    exit();
}

// ── CRUD HANDLERS ──────────────────────────────────────────────

// Vet validation
if (isset($_POST['action']) && isset($_POST['vet_id']) && in_array($_POST['action'], ['VALIDE','REFUSE'])) {
    $pdo->prepare("UPDATE veterinaires SET statut_validation = ? WHERE id = ?")
            ->execute([$_POST['action'], $_POST['vet_id']]);
    header("Location: admin.php?section=vets"); exit();
}

// DELETE USER
if (isset($_POST['action']) && $_POST['action'] === 'delete_user' && isset($_POST['user_id'])) {
    $uid = (int)$_POST['user_id'];
    $pdo->prepare("DELETE FROM demandes_adoption WHERE id_adoptant = ?")->execute([$uid]);
    $pdo->prepare("DELETE FROM annonces WHERE id_proprietaire = ?")->execute([$uid]);
    $pdo->prepare("DELETE FROM animaux WHERE id_proprietaire = ?")->execute([$uid]);
    $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$uid]);
    header("Location: admin.php?section=users"); exit();
}

// EDIT USER
if (isset($_POST['action']) && $_POST['action'] === 'edit_user' && isset($_POST['user_id'])) {
    $pdo->prepare("UPDATE utilisateurs SET prenom=?, nom=?, email=?, telephone=?, statut=? WHERE id=?")
            ->execute([$_POST['prenom'], $_POST['nom'], $_POST['email'], $_POST['telephone'], $_POST['statut'], $_POST['user_id']]);
    header("Location: admin.php?section=users"); exit();
}

// DELETE VET
if (isset($_POST['action']) && $_POST['action'] === 'delete_vet' && isset($_POST['vet_id'])) {
    $pdo->prepare("DELETE FROM veterinaires WHERE id = ?")->execute([$_POST['vet_id']]);
    header("Location: admin.php?section=vets"); exit();
}

// EDIT VET
if (isset($_POST['action']) && $_POST['action'] === 'edit_vet' && isset($_POST['vet_id'])) {
    $pdo->prepare("UPDATE veterinaires SET prenom=?, nom=?, email=?, telephone=?, statut_validation=?, region=?, adresse_cabinet=?, nom_cabinet=?, telephone_cabinet=? WHERE id=?")
            ->execute([$_POST['prenom'], $_POST['nom'], $_POST['email'], $_POST['telephone'],
                    $_POST['statut_validation'], $_POST['region'], $_POST['adresse_cabinet'],
                    $_POST['nom_cabinet'], $_POST['telephone_cabinet'], $_POST['vet_id']]);
    header("Location: admin.php?section=vets"); exit();
}

// DELETE ANIMAL
if (isset($_POST['action']) && $_POST['action'] === 'delete_animal' && isset($_POST['animal_id'])) {
    $aid = (int)$_POST['animal_id'];
    $pdo->prepare("DELETE FROM demandes_adoption WHERE id_annonce IN (SELECT id FROM annonces WHERE id_animal=?)")->execute([$aid]);
    $pdo->prepare("DELETE FROM annonces WHERE id_animal=?")->execute([$aid]);
    $pdo->prepare("DELETE FROM animaux WHERE id=?")->execute([$aid]);
    header("Location: admin.php?section=animals"); exit();
}

// EDIT ANIMAL
if (isset($_POST['action']) && $_POST['action'] === 'edit_animal' && isset($_POST['animal_id'])) {
    $pdo->prepare("UPDATE animaux SET nom=?, espece=?, race=?, age=?, sexe=?, poids=?, statut_adoption=?, sterilise=?, vaccine=?, errant=? WHERE id=?")
            ->execute([$_POST['nom'], $_POST['espece'], $_POST['race'], $_POST['age'], $_POST['sexe'],
                    $_POST['poids'], $_POST['statut_adoption'], isset($_POST['sterilise'])?1:0,
                    isset($_POST['vaccine'])?1:0, isset($_POST['errant'])?1:0, $_POST['animal_id']]);
    header("Location: admin.php?section=animals"); exit();
}
// ───────────────────────────────────────────────────────────────

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

        /* ── Modals ── */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:#fff; border-radius:16px; padding:28px; width:480px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,0.2); }
        .modal h2 { color:#2f5d3a; margin-bottom:20px; font-size:1.2rem; }
        .modal label { display:block; font-size:0.85rem; color:#555; margin-bottom:4px; margin-top:12px; font-weight:600; }
        .modal input, .modal select { width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:8px; font-size:0.9rem; box-sizing:border-box; }
        .modal input:focus, .modal select:focus { outline:none; border-color:#2f5d3a; }
        .modal-footer { display:flex; gap:10px; margin-top:20px; justify-content:flex-end; }
        .btn-save { background:#2c5e2a; color:white; border:none; padding:9px 20px; border-radius:8px; cursor:pointer; font-weight:600; }
        .btn-cancel { background:#e0d5c8; color:#555; border:none; padding:9px 16px; border-radius:8px; cursor:pointer; }
        .checkbox-row { display:flex; gap:16px; margin-top:8px; flex-wrap:wrap; }
        .checkbox-row label { display:flex; align-items:center; gap:6px; font-weight:normal; margin-top:0; }
        .checkbox-row input { width:auto; }
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
                    <th>Actions</th>
                </tr>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo htmlspecialchars($user['telephone'] ?? 'N/A'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($user['date_inscription'])); ?></td>
                        <td><span class="status <?php echo strtolower($user['statut']); ?>"><?php echo $user['statut']; ?></span></td>
                        <td>
                            <button class="edit" onclick="openEditUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">✏️ Modifier</button>
                            <button class="delete" onclick="confirmDelete('user', <?php echo $user['id']; ?>, '<?php echo addslashes($user['prenom'].' '.$user['nom']); ?>')">🗑️ Supprimer</button>
                        </td>
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
                    <th>Actions</th>
                </tr>
                <?php foreach ($animals as $animal): ?>
                    <tr>
                        <td>
                            <?php $img = !empty($animal['photos']) ? explode(',', $animal['photos'])[0] : 'https://via.placeholder.com/50'; ?>
                            <img src="<?php echo $img; ?>" style="width:50px; height:50px; object-fit:cover; border-radius:5px;">
                        </td>
                        <td><?php echo htmlspecialchars($animal['nom']); ?></td>
                        <td><?php echo $animal['espece']; ?></td>
                        <td><?php echo htmlspecialchars($animal['prenom'] . ' ' . $animal['nom_user']); ?></td>
                        <td><span class="status <?php echo strtolower(str_replace('_', '', $animal['statut_adoption'])); ?>"><?php echo $animal['statut_adoption']; ?></span></td>
                        <td>
                            <button class="edit" onclick="openEditAnimal(<?php echo htmlspecialchars(json_encode($animal)); ?>)">✏️ Modifier</button>
                            <button class="delete" onclick="confirmDelete('animal', <?php echo $animal['id']; ?>, '<?php echo addslashes($animal['nom']); ?>')">🗑️ Supprimer</button>
                        </td>
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
                            <button class="edit" style="margin-top:4px;" onclick="openEditVet(<?php echo htmlspecialchars(json_encode($vet)); ?>)">✏️ Modifier</button>
                            <button class="delete" style="margin-top:4px;" onclick="confirmDelete('vet', <?php echo $vet['id']; ?>, '<?php echo addslashes($vet['prenom'].' '.$vet['nom']); ?>')">🗑️ Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div class="modal-overlay" id="modalUser">
    <div class="modal">
        <h2>✏️ Modifier l'utilisateur</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id">
            <label>Prénom</label><input type="text" name="prenom" id="edit_user_prenom" required>
            <label>Nom</label><input type="text" name="nom" id="edit_user_nom" required>
            <label>Email</label><input type="email" name="email" id="edit_user_email" required>
            <label>Téléphone</label><input type="text" name="telephone" id="edit_user_telephone">
            <label>Statut</label>
            <select name="statut" id="edit_user_statut">
                <option value="ACTIF">ACTIF</option>
                <option value="INACTIF">INACTIF</option>
                <option value="BANNI">BANNI</option>
            </select>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalUser')">Annuler</button>
                <button type="submit" class="btn-save">💾 Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-overlay" id="modalDelete">
    <div class="modal" style="width:380px;">
        <h2>🗑️ Confirmer la suppression</h2>
        <p id="deleteMessage" style="color:#555; margin-bottom:10px;"></p>
        <p style="color:#c96b4a; font-size:0.85rem;">⚠️ Cette action est irréversible.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" id="delete_action">
            <input type="hidden" name="user_id" id="delete_user_id">
            <input type="hidden" name="vet_id" id="delete_vet_id">
            <input type="hidden" name="animal_id" id="delete_animal_id">
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalDelete')">Annuler</button>
                <button type="submit" class="delete" style="padding:9px 18px; border-radius:8px;">🗑️ Supprimer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT VET MODAL ── -->
<div class="modal-overlay" id="modalVet">
    <div class="modal">
        <h2>✏️ Modifier le vétérinaire</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit_vet">
            <input type="hidden" name="vet_id" id="edit_vet_id">
            <label>Prénom</label><input type="text" name="prenom" id="edit_vet_prenom" required>
            <label>Nom</label><input type="text" name="nom" id="edit_vet_nom">
            <label>Email</label><input type="email" name="email" id="edit_vet_email" required>
            <label>Téléphone</label><input type="text" name="telephone" id="edit_vet_telephone">
            <label>Statut validation</label>
            <select name="statut_validation" id="edit_vet_statut">
                <option value="EN_ATTENTE">EN_ATTENTE</option>
                <option value="VALIDE">VALIDE</option>
                <option value="REFUSE">REFUSE</option>
            </select>
            <label>Région</label>
            <select name="region" id="edit_vet_region">
                <option value="tunis">Tunis</option>
                <option value="sfax">Sfax</option>
                <option value="sousse">Sousse</option>
                <option value="bizerte">Bizerte</option>
                <option value="nabeul">Nabeul</option>
            </select>
            <label>Nom du cabinet</label><input type="text" name="nom_cabinet" id="edit_vet_cabinet">
            <label>Adresse du cabinet</label><input type="text" name="adresse_cabinet" id="edit_vet_adresse">
            <label>Téléphone cabinet</label><input type="text" name="telephone_cabinet" id="edit_vet_tel_cabinet">
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalVet')">Annuler</button>
                <button type="submit" class="btn-save">💾 Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT ANIMAL MODAL ── -->
<div class="modal-overlay" id="modalAnimal">
    <div class="modal">
        <h2>✏️ Modifier l'animal</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit_animal">
            <input type="hidden" name="animal_id" id="edit_animal_id">
            <label>Nom</label><input type="text" name="nom" id="edit_animal_nom" required>
            <label>Espèce</label>
            <select name="espece" id="edit_animal_espece">
                <option value="CHIEN">Chien</option>
                <option value="CHAT">Chat</option>
                <option value="LAPIN">Lapin</option>
                <option value="OISEAU">Oiseau</option>
                <option value="RONGEUR">Rongeur</option>
                <option value="REPTILE">Reptile</option>
                <option value="AUTRE">Autre</option>
            </select>
            <label>Race</label><input type="text" name="race" id="edit_animal_race">
            <label>Âge (années)</label><input type="number" name="age" id="edit_animal_age" min="0" step="0.5">
            <label>Sexe</label>
            <select name="sexe" id="edit_animal_sexe">
                <option value="">Non spécifié</option>
                <option value="MALE">Mâle</option>
                <option value="FEMELLE">Femelle</option>
            </select>
            <label>Poids (kg)</label><input type="number" name="poids" id="edit_animal_poids" min="0" step="0.1">
            <label>Statut adoption</label>
            <select name="statut_adoption" id="edit_animal_statut">
                <option value="DISPONIBLE">DISPONIBLE</option>
                <option value="EN_COURS">EN_COURS</option>
                <option value="ADOPTE">ADOPTE</option>
            </select>
            <label style="margin-top:12px;">Caractéristiques</label>
            <div class="checkbox-row">
                <label><input type="checkbox" name="sterilise" id="edit_animal_sterilise"> Stérilisé</label>
                <label><input type="checkbox" name="vaccine" id="edit_animal_vaccine"> Vacciné</label>
                <label><input type="checkbox" name="errant" id="edit_animal_errant"> Errant</label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalAnimal')">Annuler</button>
                <button type="submit" class="btn-save">💾 Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    // Close on overlay click
    document.querySelectorAll('.modal-overlay').forEach(el => {
        el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
    });

    function openEditUser(u) {
        document.getElementById('edit_user_id').value = u.id;
        document.getElementById('edit_user_prenom').value = u.prenom || '';
        document.getElementById('edit_user_nom').value = u.nom || '';
        document.getElementById('edit_user_email').value = u.email || '';
        document.getElementById('edit_user_telephone').value = u.telephone || '';
        document.getElementById('edit_user_statut').value = u.statut || 'ACTIF';
        document.getElementById('modalUser').classList.add('open');
    }

    function openEditVet(v) {
        document.getElementById('edit_vet_id').value = v.id;
        document.getElementById('edit_vet_prenom').value = v.prenom || '';
        document.getElementById('edit_vet_nom').value = v.nom || '';
        document.getElementById('edit_vet_email').value = v.email || '';
        document.getElementById('edit_vet_telephone').value = v.telephone || '';
        document.getElementById('edit_vet_statut').value = v.statut_validation || 'EN_ATTENTE';
        document.getElementById('edit_vet_region').value = v.region || 'tunis';
        document.getElementById('edit_vet_cabinet').value = v.nom_cabinet || '';
        document.getElementById('edit_vet_adresse').value = v.adresse_cabinet || '';
        document.getElementById('edit_vet_tel_cabinet').value = v.telephone_cabinet || '';
        document.getElementById('modalVet').classList.add('open');
    }

    function openEditAnimal(a) {
        document.getElementById('edit_animal_id').value = a.id;
        document.getElementById('edit_animal_nom').value = a.nom || '';
        document.getElementById('edit_animal_espece').value = a.espece || 'CHIEN';
        document.getElementById('edit_animal_race').value = a.race || '';
        document.getElementById('edit_animal_age').value = a.age || 0;
        document.getElementById('edit_animal_sexe').value = a.sexe || '';
        document.getElementById('edit_animal_poids').value = a.poids || 0;
        document.getElementById('edit_animal_statut').value = a.statut_adoption || 'DISPONIBLE';
        document.getElementById('edit_animal_sterilise').checked = a.sterilise == 1;
        document.getElementById('edit_animal_vaccine').checked = a.vaccine == 1;
        document.getElementById('edit_animal_errant').checked = a.errant == 1;
        document.getElementById('modalAnimal').classList.add('open');
    }

    function confirmDelete(type, id, name) {
        document.getElementById('deleteMessage').textContent = 'Voulez-vous vraiment supprimer "' + name + '" ?';
        document.getElementById('delete_action').value = 'delete_' + type;
        document.getElementById('delete_user_id').value   = type === 'user'   ? id : '';
        document.getElementById('delete_vet_id').value    = type === 'vet'    ? id : '';
        document.getElementById('delete_animal_id').value = type === 'animal' ? id : '';
        document.getElementById('modalDelete').classList.add('open');
    }
</script>
</body>
</html>