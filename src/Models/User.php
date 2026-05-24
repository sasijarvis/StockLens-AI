<?php

class User {

    public static function findByEmail(string $email): ?array {
        try {
            $stmt = Database::get()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([strtolower(trim($email))]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            logError('User::findByEmail', $e);
            return null;
        }
    }

    public static function findById(int $id): ?array {
        try {
            $stmt = Database::get()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            logError('User::findById', $e);
            return null;
        }
    }

    /**
     * Create a new user. Returns the new user's ID or null on failure.
     */
    public static function create(string $email, string $password, string $name = ''): ?int {
        try {
            $db   = Database::get();
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)');
            $stmt->execute([strtolower(trim($email)), $hash, trim($name)]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            logError('User::create', $e);
            return null;
        }
    }

    public static function emailExists(string $email): bool {
        try {
            $stmt = Database::get()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([strtolower(trim($email))]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
}
