#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"
[[ -f tests/requirements-security-bootstrap.txt ]] || {
    echo 'Missing tests/requirements-security-bootstrap.txt. Publish the reviewed local security prerequisites listed in .codex/cloud/README.md before Cloud setup.' >&2
    exit 1
}
command -v php >/dev/null || { echo 'Provision the README-supported PHP runtime and SQLite/mbstring extensions first.' >&2; exit 1; }
php -r '
require "app/Install/SystemRequirements.php";
if (!method_exists(App\Install\SystemRequirements::class, "minimumSecurePhp")) {
    fwrite(STDERR, "Missing branch-specific PHP security policy. Publish the reviewed SystemRequirements.php change before Cloud setup.\n"); exit(1);
}
foreach ((new App\Install\SystemRequirements())->runtimeChecks() as $check) {
    if (!$check["ok"]) { fwrite(STDERR, $check["label"].": ".$check["value"]."\n"); exit(1); }
}
foreach (["pdo_sqlite", "sqlite3"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension."\n"); exit(1); }
}
'
command -v python3 >/dev/null || { echo 'Provision Python 3.10+ with venv support first.' >&2; exit 1; }
python3 -c 'import sys; assert sys.version_info >= (3, 10), "Python 3.10+ required"'
python3 -m venv .codex/cloud/.venv
schema_python="$repo_root/.codex/cloud/.venv/bin/python"
"$schema_python" -m pip install --disable-pip-version-check --only-binary=:all: --require-hashes -r tests/requirements-security-bootstrap.txt
"$schema_python" -m pip install --disable-pip-version-check -r tests/requirements-public-event-schema.txt
"$schema_python" tests/validate_public_event_schemas.py
"$schema_python" tests/validate_public_api_contracts.py
printf '%s\n' 'Engine schema tooling is ready. Use .codex/cloud/.venv/bin/python as CPE_TEST_SCHEMA_PYTHON in task shells.'
