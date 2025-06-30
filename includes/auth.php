<?php
session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header('Location: dashboard.php');
        exit();
    }
}

function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'department' => $_SESSION['department'] ?? null
    ];
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Auth class wrapper for compatibility with IDE dashboard
class Auth {
    public function requireLogin() {
        requireLogin();
    }
    
    public function requireRole($role) {
        requireRole($role);
    }
    
    public function getCurrentUser() {
        return getCurrentUser();
    }
    
    public function isLoggedIn() {
        return isLoggedIn();
    }
    
    public function hasRole($role) {
        return hasRole($role);
    }
}
?>
