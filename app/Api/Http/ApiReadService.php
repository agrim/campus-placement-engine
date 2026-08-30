<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Api\Security\ApiKeyring;
use App\Core\Institution\InstitutionRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Exact, institution-bound public read projections. */
final class ApiReadService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ApiKeyring $keyring,
    ) {
    }

    /** @return array{name: string, version: string, resources: list<string>} */
    public function service(): array
    {
        return [
            'name' => 'Campus Placement Engine API',
            'version' => 'v1',
            'resources' => ['opportunities', 'applications'],
        ];
    }

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   page: array{next_cursor: ?string, has_more: bool}
     * }
     */
    public function collection(
        string $resource,
        string $route,
        int $limit,
        ?string $updatedAfter,
        ?string $cursor,
    ): array {
        try {
            $definition = $this->definition($resource);
            $institution = (new InstitutionRepository($this->pdo))->current();
            $codec = new ApiCursorCodec($this->keyring);
            $snapshot = null;
            $last = null;
            if ($cursor !== null) {
                $decoded = $codec->decode($cursor, $institution->publicId(), $route, $resource);
                $updatedAfter = $decoded['updated_after'];
                $snapshot = $decoded['snapshot'];
                $last = $decoded['last'];
            }
            $updatedAfterDatabase = $updatedAfter !== null
                ? self::apiTimestampToDatabase($updatedAfter)
                : null;
            if ($snapshot === null) {
                $snapshot = $this->snapshot($definition, $institution->id(), $updatedAfterDatabase);
            }
            if ($snapshot === null) {
                return ['data' => [], 'page' => ['next_cursor' => null, 'has_more' => false]];
            }
            $rows = $this->pageRows(
                $definition,
                $institution->id(),
                $updatedAfterDatabase,
                $snapshot,
                $last,
                $limit + 1,
            );
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            $data = [];
            $lastReturned = null;
            foreach ($rows as $row) {
                $lastReturned = [
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'id' => (string) ($row['public_id'] ?? ''),
                ];
                $data[] = $this->mapRow($resource, $row);
            }
            $nextCursor = $hasMore && is_array($lastReturned)
                ? $codec->encode(
                    $institution->publicId(),
                    $route,
                    $resource,
                    $updatedAfter,
                    $snapshot,
                    $lastReturned,
                )
                : null;
            return [
                'data' => $data,
                'page' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore],
            ];
        } catch (ApiHttpException $failure) {
            throw $failure;
        } catch (\App\Api\Security\ApiAuthenticationUnavailable $failure) {
            throw $failure;
        } catch (ApiStorageUnavailable $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new ApiStorageUnavailable('API collection storage is unavailable.', $failure);
        }
    }

    /** @return null|array<string, mixed> */
    public function item(string $resource, string $publicId): ?array
    {
        $prefix = $resource === 'opportunities' ? 'opportunity' : 'application';
        if (preg_match('/\A' . $prefix . '_[a-f0-9]{32}\z/D', $publicId) !== 1) {
            return null;
        }
        try {
            $definition = $this->definition($resource);
            $institution = (new InstitutionRepository($this->pdo))->current();
            $sql = $definition['select'] . ' ' . $definition['from'] . ' '
                . $definition['where'] . ' AND ' . $definition['alias'] . '.public_id = ? LIMIT 1';
            $bindings = array_fill(0, $definition['institution_bind_count'], $institution->id());
            $bindings[] = $publicId;
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bindings);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->mapRow($resource, $row) : null;
        } catch (Throwable $failure) {
            throw new ApiStorageUnavailable('API item storage is unavailable.', $failure);
        }
    }

    public static function normalizeUpdatedAfter(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new ApiHttpException(
                400,
                'invalid_updated_after',
                'updated_after must be an RFC 3339 UTC timestamp.',
                'UPDATED_AFTER_INVALID',
            );
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function definition(string $resource): array
    {
        return match ($resource) {
            'opportunities' => [
                'alias' => 'opportunity',
                'select' => 'SELECT opportunity.public_id, cycle.public_id AS cycle_public_id,
                                    organization.public_id AS organization_public_id,
                                    organization.code AS organization_code,
                                    organization.name AS organization_name,
                                    opportunity.opportunity_key, opportunity.title, opportunity.status,
                                    opportunity.created_at, opportunity.updated_at',
                'from' => 'FROM placement_opportunities opportunity
                           JOIN placement_cycles cycle ON cycle.id = opportunity.cycle_id
                           JOIN organizations organization ON organization.id = opportunity.organization_id
                               AND organization.institution_id = cycle.institution_id',
                'where' => 'WHERE cycle.institution_id = ? AND organization.institution_id = ?',
                'institution_bind_count' => 2,
            ],
            'applications' => [
                'alias' => 'application',
                'select' => 'SELECT application.public_id,
                                    participant.public_id AS participant_public_id,
                                    opportunity.public_id AS opportunity_public_id,
                                    application.current_status, application.aggregate_version,
                                    application.created_at, application.updated_at',
                'from' => 'FROM applications application
                           JOIN placement_cycle_participants participant ON participant.id = application.participant_id
                           JOIN placement_cycles participant_cycle ON participant_cycle.id = participant.cycle_id
                           JOIN student_profiles participant_profile ON participant_profile.id = participant.student_profile_id
                               AND participant_profile.institution_id = participant_cycle.institution_id
                           JOIN placement_opportunities opportunity ON opportunity.id = application.opportunity_id
                               AND opportunity.cycle_id = participant.cycle_id
                           JOIN placement_cycles opportunity_cycle ON opportunity_cycle.id = opportunity.cycle_id
                           JOIN organizations opportunity_organization ON opportunity_organization.id = opportunity.organization_id
                               AND opportunity_organization.institution_id = opportunity_cycle.institution_id',
                'where' => 'WHERE participant_cycle.institution_id = ?
                            AND participant_profile.institution_id = ?
                            AND opportunity_cycle.institution_id = ?
                            AND opportunity_organization.institution_id = ?',
                'institution_bind_count' => 4,
            ],
            default => throw new \RuntimeException('Unsupported API read resource.'),
        };
    }

    /**
     * @param array<string, mixed> $definition
     * @return null|array{updated_at: string, id: string}
     */
    private function snapshot(array $definition, int $institutionId, ?string $updatedAfter): ?array
    {
        $alias = (string) $definition['alias'];
        $sql = 'SELECT ' . $alias . '.updated_at, ' . $alias . '.public_id '
            . $definition['from'] . ' ' . $definition['where'];
        $bindings = array_fill(0, (int) $definition['institution_bind_count'], $institutionId);
        if ($updatedAfter !== null) {
            $sql .= ' AND ' . $alias . '.updated_at > ?';
            $bindings[] = $updatedAfter;
        }
        $sql .= ' ORDER BY ' . $alias . '.updated_at DESC, ' . $alias . '.public_id DESC LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['updated_at' => (string) $row['updated_at'], 'id' => (string) $row['public_id']]
            : null;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array{updated_at: string, id: string} $snapshot
     * @param null|array{updated_at: string, id: string} $last
     * @return list<array<string, mixed>>
     */
    private function pageRows(
        array $definition,
        int $institutionId,
        ?string $updatedAfter,
        array $snapshot,
        ?array $last,
        int $limit,
    ): array {
        $alias = (string) $definition['alias'];
        $sql = $definition['select'] . ' ' . $definition['from'] . ' ' . $definition['where'];
        $bindings = array_fill(0, (int) $definition['institution_bind_count'], $institutionId);
        if ($updatedAfter !== null) {
            $sql .= ' AND ' . $alias . '.updated_at > ?';
            $bindings[] = $updatedAfter;
        }
        $sql .= ' AND (' . $alias . '.updated_at < ? OR (' . $alias . '.updated_at = ? AND '
            . $alias . '.public_id <= ?))';
        array_push($bindings, $snapshot['updated_at'], $snapshot['updated_at'], $snapshot['id']);
        if ($last !== null) {
            $sql .= ' AND (' . $alias . '.updated_at > ? OR (' . $alias . '.updated_at = ? AND '
                . $alias . '.public_id > ?))';
            array_push($bindings, $last['updated_at'], $last['updated_at'], $last['id']);
        }
        $sql .= ' ORDER BY ' . $alias . '.updated_at ASC, ' . $alias . '.public_id ASC LIMIT ?';
        $statement = $this->pdo->prepare($sql);
        foreach ($bindings as $index => $binding) {
            $statement->bindValue($index + 1, $binding, is_int($binding) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(count($bindings) + 1, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapRow(string $resource, array $row): array
    {
        return $resource === 'opportunities'
            ? $this->mapOpportunity($row)
            : $this->mapApplication($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapOpportunity(array $row): array
    {
        $mapped = [
            'id' => (string) ($row['public_id'] ?? ''),
            'cycle_id' => (string) ($row['cycle_public_id'] ?? ''),
            'organization_id' => (string) ($row['organization_public_id'] ?? ''),
            'organization_code' => (string) ($row['organization_code'] ?? ''),
            'organization_name' => (string) ($row['organization_name'] ?? ''),
            'opportunity_key' => (string) ($row['opportunity_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => $this->publicTimestamp((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->publicTimestamp((string) ($row['updated_at'] ?? '')),
        ];
        if (preg_match('/\Aopportunity_[a-f0-9]{32}\z/D', $mapped['id']) !== 1
            || preg_match('/\Acycle_[a-f0-9]{32}\z/D', $mapped['cycle_id']) !== 1
            || preg_match('/\Aorganization_[a-f0-9]{32}\z/D', $mapped['organization_id']) !== 1) {
            throw new ApiStorageUnavailable('Opportunity public identity is unavailable.');
        }
        return $mapped;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapApplication(array $row): array
    {
        $version = filter_var($row['aggregate_version'] ?? null, FILTER_VALIDATE_INT);
        $mapped = [
            'id' => (string) ($row['public_id'] ?? ''),
            'participant_id' => (string) ($row['participant_public_id'] ?? ''),
            'opportunity_id' => (string) ($row['opportunity_public_id'] ?? ''),
            'status' => (string) ($row['current_status'] ?? ''),
            'aggregate_version' => $version === false ? 0 : (int) $version,
            'created_at' => $this->publicTimestamp((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->publicTimestamp((string) ($row['updated_at'] ?? '')),
        ];
        if (preg_match('/\Aapplication_[a-f0-9]{32}\z/D', $mapped['id']) !== 1
            || preg_match('/\Aparticipant_[a-f0-9]{32}\z/D', $mapped['participant_id']) !== 1
            || preg_match('/\Aopportunity_[a-f0-9]{32}\z/D', $mapped['opportunity_id']) !== 1
            || $mapped['aggregate_version'] < 1) {
            throw new ApiStorageUnavailable('Application public identity is unavailable.');
        }
        return $mapped;
    }

    private static function apiTimestampToDatabase(string $value): string
    {
        return substr($value, 0, 10) . ' ' . substr($value, 11, 8);
    }

    private function publicTimestamp(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ApiStorageUnavailable('API timestamp storage is unavailable.');
        }
        return $date->format('Y-m-d\TH:i:s\Z');
    }
}
