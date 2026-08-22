<?php

declare(strict_types=1);

namespace App\Core\Institution;

use App\Core\Settings\SettingRepository;
use PDO;
use RuntimeException;

final class InstitutionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function current(): InstitutionContext
    {
        try {
            $row = $this->pdo->query(
                "SELECT id, public_id, slug, name, timezone
                 FROM institutions
                 WHERE slug = 'default'
                 LIMIT 1"
            )->fetch();
        } catch (\Throwable) {
            $row = false;
        }
        if (!$row) {
            $settings = new SettingRepository($this->pdo);
            return new InstitutionContext(
                0,
                'legacy-local',
                'default',
                trim($settings->get('college_name', 'Demo College')) ?: 'Demo College',
                trim($settings->get('timezone', 'Asia/Kolkata')) ?: 'Asia/Kolkata',
            );
        }
        return new InstitutionContext(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['timezone'],
        );
    }

    public function synchronizeFromSettings(): InstitutionContext
    {
        $settings = new SettingRepository($this->pdo);
        $name = trim($settings->get('college_name', 'Demo College')) ?: 'Demo College';
        $timezone = trim($settings->get('timezone', 'Asia/Kolkata')) ?: 'Asia/Kolkata';
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE institutions
             SET name = ?, timezone = ?, updated_at = ?
             WHERE slug = 'default'"
        );
        $stmt->execute([$name, $timezone, $now]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('The installation has no institution row to synchronize.');
        }
        return $this->current();
    }
}
