<?php
// api/actions.php

// 1. Load the core engine (Session, DB, Helpers, Security Headers)
require_once 'config.php';

// 2. Load the logic handlers (Only needed for this specific endpoint)
require_once 'action_handlers.php';

// 3. Authentication Gatekeeper
if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}

// 4. Capture Session/Context Variables
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$specific_id = $_SESSION['specific_id'];
$action = $_GET['action'] ?? '';

// 5. Request Validation & Security
requirePostMethod();      // Ensure it's a POST request
$data = getJsonInput();   // Sanitize and decode JSON input
requireCsrf();            // Validate the X-XSRF-TOKEN header

// 6. Execute the Action
dispatchAction($pdo, $role, $user_id, $specific_id, $action, $data);