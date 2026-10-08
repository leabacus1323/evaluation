<?php
require_once __DIR__ . '/functions.php';

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!admin_logged_in()) {
        flash('Please log in to continue.', 'error');
        redirect('../login.php');
    }
}

function current_admin(): ?array
{
    if (!admin_logged_in()) return null;
    $stmt = db()->prepare('SELECT id,username,display_name FROM admins WHERE id=?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}
