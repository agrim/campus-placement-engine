<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use App\Core\Http\UserVisibleException;
use PDO;
use RuntimeException;

final class WorkflowDefinitionFileService
{
    public const SCHEMA = 'career_services.workflow.v1';

    private WorkflowRepository $repository;
    private WorkflowDefinitionValidator $validator;

    public function __construct(private readonly PDO $pdo)
    {
        $this->repository = new WorkflowRepository($pdo);
        $this->validator = new WorkflowDefinitionValidator();
    }

    public function export(int $versionId, string $path): array
    {
        $payload = $this->payloadForVersion($versionId);
        $workflow = $this->repository->workflowForVersion($versionId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create workflow export directory: ' . $dir);
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Could not write workflow export: ' . $path);
        }
        return ['file_reference' => $this->fileReference($path), 'workflow_key' => $workflow['key'], 'version' => $workflow['version_number']];
    }

    public function payloadForVersion(int $versionId): array
    {
        $workflow = $this->repository->workflowForVersion($versionId);
        if ($workflow === null) {
            throw new RuntimeException('Workflow version not found.');
        }
        return [
            'schema' => self::SCHEMA,
            'workflow_key' => $workflow['key'],
            'exported_version' => $workflow['version_number'],
            'checksum' => $workflow['checksum'],
            'definition' => $this->definition($workflow),
        ];
    }

    public function validate(string $path): array
    {
        $payload = $this->read($path);
        $this->validator->assertValid($payload['definition']);
        return [
            'file_reference' => $this->fileReference($path),
            'workflow_key' => $payload['workflow_key'],
            'states' => count($payload['definition']['states']),
            'transitions' => count($payload['definition']['transitions']),
        ];
    }

    public function publish(string $path, ?int $actorId = null, bool $activate = true): int
    {
        $payload = $this->read($path);
        $this->validator->assertValid($payload['definition']);
        return (new WorkflowPublisher($this->pdo))->publish(
            (string) $payload['workflow_key'],
            $payload['definition'],
            'import',
            $actorId,
            $activate
        );
    }

    private function read(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Workflow definition file not found: ' . $path);
        }
        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UserVisibleException(
                'WORKFLOW_DEFINITION_JSON_INVALID',
                'Workflow definition is not valid JSON.',
                $e,
            );
        }
        if (!is_array($payload) || ($payload['schema'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Unsupported workflow definition schema.');
        }
        if (!is_array($payload['definition'] ?? null) || trim((string) ($payload['workflow_key'] ?? '')) === '') {
            throw new RuntimeException('Workflow definition payload is incomplete.');
        }
        return $payload;
    }

    private function fileReference(string $path): string
    {
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        return 'workflow_' . substr(is_string($hash) ? $hash : hash('sha256', basename($path)), 0, 24);
    }

    private function definition(array $workflow): array
    {
        $states = [];
        foreach ($workflow['states'] as $key => $state) {
            $states[$key] = [
                'label' => (string) $state['label'],
                'order' => (int) $state['order'],
                'color' => (string) $state['color'],
                'semantic_category' => (string) $state['semantic_category'],
                'is_terminal' => (bool) $state['is_terminal'],
            ];
        }
        $transitions = [];
        foreach ($workflow['transitions'] as $transition) {
            $transitions[] = [
                'key' => (string) $transition['key'],
                'label' => (string) $transition['label'],
                'from' => (string) $transition['from'],
                'to' => (string) $transition['to'],
                'required_capability' => (string) $transition['required_capability'],
                'roles' => array_values($transition['roles']),
                'guards' => array_values($transition['guards']),
                'effects' => array_values($transition['effects']),
                'order' => (int) $transition['order'],
                'is_correction' => (bool) $transition['is_correction'],
            ];
        }
        return [
            'name' => $workflow['name'],
            'source_template_key' => $workflow['key'],
            'initial_state_key' => $workflow['initial_state_key'],
            'states' => $states,
            'transitions' => $transitions,
        ];
    }
}
