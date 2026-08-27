<?php

declare(strict_types=1);

namespace App\Core\Backup;

/** Filesystem locations are deliberately available only to internal callers. */
final class BackupArtifact
{
    public function __construct(
        private readonly string $path,
        private readonly string $checksumPath,
        private readonly string $metadataPath,
        private readonly string $sha256,
        private readonly string $driver,
    ) {
    }

    public function internalPath(): string { return $this->path; }
    public function internalChecksumPath(): string { return $this->checksumPath; }
    public function internalMetadataPath(): string { return $this->metadataPath; }
    public function fileName(): string { return basename($this->path); }
    public function checksumFileName(): string { return basename($this->checksumPath); }
    public function metadataFileName(): string { return basename($this->metadataPath); }
    public function sha256(): string { return $this->sha256; }
    public function driver(): string { return $this->driver; }

    public function reference(): string
    {
        return 'backup_' . substr(hash('sha256', $this->sha256 . "\0" . basename($this->path)), 0, 24);
    }
}
