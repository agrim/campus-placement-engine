<?php

declare(strict_types=1);

// No app installer: run this isolated driver contract on both secure and older
// comparison runtimes without lowering the application's PHP security floor.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE statement_lifecycle (value INTEGER NOT NULL)');
$pdo->exec("CREATE TRIGGER reject_negative BEFORE INSERT ON statement_lifecycle
    WHEN NEW.value < 0 BEGIN SELECT RAISE(ABORT, 'negative value rejected'); END");
$statement = $pdo->prepare('INSERT INTO statement_lifecycle (value) VALUES (?)');
foreach ([1, -1, -2, 2] as $value) {
    $failure = null;
    try {
        $statement->execute([$value]);
    } catch (PDOException $caught) {
        $failure = $caught;
    } finally {
        $statement->closeCursor();
    }
    if ($value < 0) {
        if (!$failure || (string) $failure->getCode() !== '23000'
            || !str_contains($failure->getMessage(), 'negative value rejected')) {
            throw new RuntimeException('Expected the trigger constraint, never a statement misuse error.', 0, $failure);
        }
    } elseif ($failure) {
        throw new RuntimeException('Valid execution after rejected executions must still succeed.', 0, $failure);
    }
}
$values = $pdo->query('SELECT value FROM statement_lifecycle ORDER BY value')->fetchAll(PDO::FETCH_COLUMN);
if (array_map('intval', $values) !== [1, 2]) {
    throw new RuntimeException('Rejected executions changed rows or successful writes were lost.');
}
echo "PASS SQLite cursor cleanup preserves rejection diagnostics and statement reuse\n";
