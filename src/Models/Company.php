<?php

class Company {

    public static function findBySymbol(string $symbol): ?array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare('SELECT * FROM companies WHERE nse_symbol = ?');
            $stmt->execute([strtoupper($symbol)]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function findOrCreate(string $symbol, int $screenerId, string $name = '', string $sector = ''): array {
        $db = Database::get();

        // Always upsert — keeps sector + company_name up to date on every analysis
        $db->prepare("INSERT INTO companies (nse_symbol, screener_id, company_name, sector) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                          screener_id  = VALUES(screener_id),
                          company_name = IF(VALUES(company_name) != '' AND VALUES(company_name) != nse_symbol,
                                           VALUES(company_name), company_name),
                          sector       = IF(VALUES(sector) != '' AND VALUES(sector) IS NOT NULL,
                                           VALUES(sector), sector)")
           ->execute([strtoupper($symbol), $screenerId, $name ?: $symbol, $sector]);

        return self::findBySymbol($symbol);
    }

    public static function updateMeta(int $id, array $data): void {
        try {
            $db      = Database::get();
            $allowed = ['company_name', 'sector', 'screener_id', 'face_value'];
            $data    = array_intersect_key($data, array_flip($allowed));
            if (empty($data)) return;
            $sets = implode(', ', array_map(function($k) { return "$k=:$k"; }, array_keys($data)));
            $data['id'] = $id;
            $db->prepare("UPDATE companies SET $sets WHERE id=:id")->execute($data);
        } catch (Throwable $e) {
            logError('Company::updateMeta', $e);
        }
    }
}
