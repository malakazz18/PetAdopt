<?php
function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header("Location: connexion.php");
        exit;
    }
}

function requireRole(array $roles) {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles)) {
        http_response_code(403);
        die("Accès refusé.");
    }
}

function clean($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
?>
