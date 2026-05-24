<?php

class Company {

    public static function findBySymbol(string $symbol): ?array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare('SELECT * FROM companies WHERE nse_symbol = ?');
            $stmt->execute([strtoupper($symbol)]);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function findOrCreate(string $symbol, int $screenerId, string $name = '', string $sector = ''): array {
        $existing = self::findBySymbol($symbol);
        if ($existing) return $existing;

        $db = Database::get();
        $db->prepare("INSERT INTO companies (nse_symbol, screener_id, company_name, sector) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE screener_id=VALUES(screener_id)")
           ->execute([strtoupper($symbol), $screenerId, $name ?: $symbol, $sector]);

        return self::findBySymbol($symbol);
    }

    public static function updateMeta(int $id, array $data): void {
        try {
            $db = Database::get();
            $sets = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($data)));
            $data['id'] = $id;
            $db->prepare("UPDATE companies SET $sets WHERE id=:id")->execute($data);
        } catch (Throwable $e) {
            logError('Company::updateMeta', $e);
        }
    }
}
