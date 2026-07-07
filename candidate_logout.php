<?php
require_once 'includes/config.php';
// Destroy only candidate session keys, preserve HR session if any
unset($_SESSION['candidate_id'], $_SESSION['candidate_name'], $_SESSION['candidate_email']);
// If no HR session either, destroy everything
if (empty($_SESSION['user_id'])) {
    session_destroy();
}
header('Location: candidate_login.php');
exit;
