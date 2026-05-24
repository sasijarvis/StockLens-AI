<?php
// api/auth.php — login / register / logout / me

$action = $_POST['action'] ?? $_GET['action'] ?? '';

match ($action) {
    'login'    => handleLogin(),
    'register' => handleRegister(),
    'logout'   => handleLogout(),
    'me'       => handleMe(),
    default    => jsonResponse(['error' => 'Invalid action'], 400),
};

// ── Handlers ──────────────────────────────────────────────────────────────────

function handleLogin(): never {
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email and password are required'], 400);
    }

    $user = User::findByEmail($email);
    if (!$user || !User::verifyPassword($password, $user['password_hash'])) {
        jsonResponse(['error' => 'Invalid email or password'], 401);
    }

    Auth::login($user);
    jsonResponse(['ok' => true, 'name' => $user['name'], 'email' => $user['email']]);
}

function handleRegister(): never {
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = trim($_POST['password'] ?? '');
    $name     = trim($_POST['name']     ?? '');

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email and password are required'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email address'], 400);
    }
    if (strlen($password) < 8) {
        jsonResponse(['error' => 'Password must be at least 8 characters'], 400);
    }
    if (User::emailExists($email)) {
        jsonResponse(['error' => 'An account with this email already exists'], 409);
    }

    $userId = User::create($email, $password, $name);
    if (!$userId) {
        jsonResponse(['error' => 'Registration failed. Please try again.'], 500);
    }

    $user = User::findById($userId);
    Auth::login($user);
    jsonResponse(['ok' => true, 'name' => $user['name'], 'email' => $user['email']]);
}

function handleLogout(): never {
    Auth::logout();
    jsonResponse(['ok' => true]);
}

function handleMe(): never {
    if (!Auth::check()) {
        jsonResponse(['authenticated' => false]);
    }
    $u = Auth::user();
    jsonResponse([
        'authenticated' => true,
        'id'            => $u['id'],
        'name'          => $u['name'],
        'email'         => $u['email'],
    ]);
}
