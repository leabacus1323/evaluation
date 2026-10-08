<?php
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? setting('site_title', 'Event Evaluation and Feedback Management System');
$orgName = setting('organization_name', 'Your Institution');
$primaryColor = setting('primary_color', '#0b6e8e');
$adminArea = $adminArea ?? false;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?= h($primaryColor) ?>">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="<?= $adminArea ? '../' : '' ?>assets/css/app.css">
<style>:root{--brand:<?= h($primaryColor) ?>}</style>
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= $adminArea ? '../index.php' : 'index.php' ?>">
      <span class="brand-mark">EF</span>
      <span><strong><?= h(setting('site_title', 'Event Evaluation and Feedback Management System')) ?></strong><small><?= h($orgName) ?></small></span>
    </a>
    <button class="menu-toggle" type="button" aria-label="Toggle menu" onclick="document.querySelector('.main-nav').classList.toggle('open')">☰</button>
    <nav class="main-nav">
      <?php if ($adminArea): ?>
        <a href="dashboard.php">Dashboard</a>
        <a href="events.php">Events</a>
        <a href="event-create.php">Create Event</a>
        <a href="forms.php">Evaluation Forms</a>
        <a href="google-forms.php">Google Forms</a>
        <a href="responses.php">Responses</a>
        <a href="analytics.php">Analytics</a>
        <a href="reports.php">Reports</a>
        <a href="settings.php">Settings</a>
        <a href="../logout.php">Logout</a>
      <?php else: ?>
        <a href="index.php#open-evaluations">Open Evaluations</a>
        <a href="login.php">Admin Login</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="page-shell">
<div class="container">
<?php $flash = get_flash(); if ($flash['message']): ?>
<div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>
