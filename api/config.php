<?php
// api/config.php

// --- 1. SESSION CONFIGURATION ---
ini_set('session.gc_maxlifetime', 3600); // 1 hour idle timeout
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true, // FIXED: Protects session ID from XSS theft
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 2. GLOBAL APP SETTINGS ---
// Thresholds for student risk levels
define('RISK_THRESHOLD_GPA', 2.0);
define('RISK_THRESHOLD_ATTENDANCE', 80);
define('WARNING_THRESHOLD_GPA', 3.0);
define('WARNING_THRESHOLD_ATTENDANCE', 90);

// Global registration settings
define('MENTOR_INVITE_CODE', 'MENTOR-INVITE-2026');

// --- 3. SECURITY & HEADERS ---
// Activates centralized security protections
require_once 'security_headers.php';
sendSecurityHeaders(); //

// --- 4. CORE INCLUDES ---
// These are now available to any file that requires config.php
require_once 'db.php';
require_once 'helpers.php';