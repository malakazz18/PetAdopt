<?php
require_once 'config.php';

echo "<h1>Debug Info</h1>";
echo "<h2>Session Data:</h2><pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Your User ID: " . getCurrentUserId() . "</h2>";

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    echo "<h2>Your Actual User Record:</h2><pre>";
    print_r($user);
    echo "</pre>";
}

echo "<h2>Recent Animals Added:</h2>";
$stmt = $pdo->query("SELECT a.*, u.prenom, u.nom as nom_user, u.email 
                     FROM animaux a 
                     JOIN utilisateurs u ON a.id_proprietaire = u.id 
                     ORDER BY a.date_creation DESC LIMIT 5");
echo "<table border='1'><tr><th>Animal</th><th>Owner</th><th>Owner Email</th><th>Owner ID</th></tr>";
while ($row = $stmt->fetch()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['nom']) . "</td>";
    echo "<td>" . htmlspecialchars($row['prenom'] . ' ' . $row['nom_user']) . "</td>";
    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
    echo "<td>" . $row['id_proprietaire'] . "</td>";
    echo "</tr>";
}
echo "</table>";
