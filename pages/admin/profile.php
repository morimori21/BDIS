<?php
// Wrapper to reuse resident profile for Admin portal
require_once '../../includes/config.php';
require_once __DIR__ . '/../../includes/email_config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('admin');

// Allow including resident profile without resident-only guard
if (!defined('PROFILE_ALLOW_ALL_RESIDENT_LIKE')) {
    define('PROFILE_ALLOW_ALL_RESIDENT_LIKE', true);
}
// Ensure the resident profile uses the admin header
if (!defined('PROFILE_HEADER_PATH')) {
    define('PROFILE_HEADER_PATH', __DIR__ . '/header.php');
}

// Include resident implementation using admin header
include __DIR__ . '/../resident/profile.php';
