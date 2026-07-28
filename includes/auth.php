<?php
// ============================================
// includes/auth.php — Role-based Access Control
// ============================================

/**
 * Pastikan user sudah login dan memiliki role yang diizinkan.
 * Jika tidak, redirect ke halaman yang sesuai.
 *
 * @param array $roles  Contoh: ['admin'], atau ['admin','bendahara']
 */
function requireRole(array $roles): void {
    if (!isset($_SESSION['admin_id'])) {
        // Hitung root path agar redirect benar dari subfolder
        $dir = basename(dirname($_SERVER['PHP_SELF']));
        $root = ($dir !== 'Project PHP' && $dir !== 'htdocs') ? '../' : '';
        header('Location: ' . $root . 'login.php');
        exit;
    }

    $currentRole = $_SESSION['admin_role'] ?? 'admin';
    if (!in_array($currentRole, $roles, true)) {
        // Redirect ke halaman default sesuai role
        $dir  = basename(dirname($_SERVER['PHP_SELF']));
        $root = ($dir !== 'Project PHP' && $dir !== 'htdocs') ? '../' : '';

        if ($currentRole === 'kasir') {
            header('Location: ' . $root . 'tabungan/masuk.php');
        } elseif ($currentRole === 'bendahara') {
            header('Location: ' . $root . 'laporan/index.php');
        } else {
            header('Location: ' . $root . 'dashboard.php');
        }
        exit;
    }
}

/**
 * Cek apakah user yang sedang login memiliki role tertentu.
 */
function isRole(string $role): bool {
    return ($_SESSION['admin_role'] ?? '') === $role;
}

/**
 * Cek apakah user memiliki salah satu dari beberapa role.
 */
function hasRole(array $roles): bool {
    return in_array($_SESSION['admin_role'] ?? '', $roles, true);
}
