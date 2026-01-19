<?php
// Secretary profile wrapper using shared profile implementation
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/email_config.php';
redirectIfNotLoggedIn();


// Allow non-resident roles to access shared profile
if (!defined('PROFILE_ALLOW_ALL_RESIDENT_LIKE')) {
    define('PROFILE_ALLOW_ALL_RESIDENT_LIKE', true);
}
// Set role-specific header/footer
if (!defined('PROFILE_HEADER_PATH')) {
    define('PROFILE_HEADER_PATH', __DIR__ . '/header.php');
}
if (!defined('PROFILE_FOOTER_PATH')) {
    define('PROFILE_FOOTER_PATH', __DIR__ . '/footer.php');
}

// Load shared profile view at project root
require_once __DIR__ . '/../../profile_shared.php';
