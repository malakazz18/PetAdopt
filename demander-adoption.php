<?php
require_once 'config.php';

if (!isLoggedIn() || !isset($_GET['id'])) {
    header('Location: connexion.php');
    exit();
}

$animalId = $_GET['id'];
$userId = getCurrentUserId();

// Get animal info
$stmt = $pdo->prepare("SELECT * FROM animaux WHERE id = ?");
$stmt->execute([$animalId]);
$animal = $stmt->fetch();

if (!$animal) {
    die("Animal non trouvé");
}

// Can't adopt own pet
if ($animal['id_proprietaire'] == $userId) {
    echo "<script>alert('Vous ne pouvez pas adopter votre propre animal'); window.location.href='animal-details.php?id=$animalId';</script>";
    exit();
}

// Get or create announcement
$stmt = $pdo->prepare("SELECT id FROM annonces WHERE id_animal = ?");
$stmt->execute([$animalId]);
$annonce = $stmt->fetch();

if (!$annonce) {
    $stmt = $pdo->prepare("INSERT INTO annonces (id_animal, id_proprietaire, titre, description_annonce) VALUES (?, ?, ?, ?)");
    $stmt->execute([$animalId, $animal['id_proprietaire'], $animal['nom'] . ' à adopter', $animal['description']]);
    $annonceId = $pdo->lastInsertId();
} else {
    $annonceId = $annonce['id'];
}

// Check if already requested
$stmt = $pdo->prepare("SELECT * FROM demandes_adoption WHERE id_annonce = ? AND id_adoptant = ?");
$stmt->execute([$annonceId, $userId]);
if ($stmt->fetch()) {
    echo "<script>alert('Vous avez déjà fait une demande pour cet animal'); window.location.href='mon-espace.php';</script>";
    exit();
}

// Create request
$stmt = $pdo->prepare("INSERT INTO demandes_adoption (id_annonce, id_adoptant) VALUES (?, ?)");
$stmt->execute([$annonceId, $userId]);

echo "<script>alert('🐾 Demande d\\'adoption envoyée avec succès ! Le propriétaire vous contactera bientôt.'); window.location.href='mon-espace.php';</script>";
?>