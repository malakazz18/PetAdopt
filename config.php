<?php
$host = 'localhost';
$dbname = 'petadopt';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur de connexion: " . $e->getMessage());
}

session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']) || isset($_SESSION['vet_id']);
}

function isVet() {
    return isset($_SESSION['is_vet']) && $_SESSION['is_vet'] === true;
}

function isValidatedVet() {
    return isset($_SESSION['vet_status']) && $_SESSION['vet_status'] === 'VALIDE';
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['vet_id'] ?? null;
}

// Get all villes
function getVilles($pdo) {
    return $pdo->query("SELECT * FROM villes ORDER BY id")->fetchAll();
}

// Get ville name by ID
function getVilleName($pdo, $id) {
    $stmt = $pdo->prepare("SELECT nom FROM villes WHERE id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    return $result ? $result['nom'] : 'Inconnue';
}
?>