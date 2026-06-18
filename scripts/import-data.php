<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Maegc\Database;
use Maegc\Support;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$file = $argv[1] ?? null;
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/import-data.php path/to/maegc-export.json\n");
    exit(1);
}

$data = json_decode(file_get_contents($file) ?: '', true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON export.\n");
    exit(1);
}

$pdo = Database::connect($config);
$pdo->beginTransaction();

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $map = [
        'teams' => ['table' => 'teams', 'columns' => ['id', 'name', 'fullName', 'logo', 'createdAt', 'updatedAt']],
        'users' => ['table' => 'users', 'columns' => ['id', 'email', 'password', 'role', 'teamId', 'coachName', 'coachAge', 'createdAt', 'updatedAt']],
        'competitions' => ['table' => 'competitions', 'columns' => ['id', 'name', 'type', 'logo', 'rules', 'rulesPdfUrl', 'createdAt']],
        'settings' => ['table' => 'settings', 'columns' => ['id', 'editMode', 'mercatoOpen', 'playerCreateOpen', 'generalRulesPdfUrl']],
        'players' => ['table' => 'players', 'columns' => ['id', 'fullName', 'photo', 'age', 'position', 'phoneId', 'efootballId', 'phoneSerie', 'cin', 'phone', 'address', 'salary', 'notes', 'teamId', 'banned', 'createdAt', 'updatedAt']],
        'bannedPlayers' => ['table' => 'banned_players', 'columns' => ['id', 'phoneId', 'phoneSerie', 'efootballId', 'reason', 'dateAdded']],
        'matches' => ['table' => 'matches', 'columns' => ['id', 'competitionId', 'homeTeamId', 'awayTeamId', 'round', 'matchDate', 'referee', 'homeScore', 'awayScore', 'createdAt', 'updatedAt']],
        'contracts' => ['table' => 'contracts', 'columns' => ['id', 'playerId', 'startDate', 'endDate', 'createdAt']],
        'transferHistory' => ['table' => 'transfer_history', 'columns' => ['id', 'playerId', 'fromTeamId', 'toTeamId', 'date']],
        'calendarEvents' => ['table' => 'calendar_events', 'columns' => ['id', 'title', 'details', 'startDate', 'endDate', 'createdAt', 'userId']],
        'news' => ['table' => 'news', 'columns' => ['id', 'title', 'text', 'image', 'date', 'createdAt', 'updatedAt']],
    ];

    foreach ($map as $key => $definition) {
        $rows = $data[$key] ?? [];
        if (!$rows) {
            continue;
        }

        $columns = $definition['columns'];
        $table = $definition['table'];
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $quoted = implode(', ', array_map(fn ($column) => "`$column`", $columns));
        $updates = implode(', ', array_map(fn ($column) => "`$column` = VALUES(`$column`)", array_filter($columns, fn ($c) => $c !== 'id')));
        $stmt = $pdo->prepare("INSERT INTO $table ($quoted) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates");

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (in_array($column, ['createdAt', 'updatedAt', 'startDate', 'endDate', 'matchDate', 'dateAdded', 'date'], true)) {
                    $value = Support::mysqlDate($value);
                }
                if (in_array($column, ['editMode', 'mercatoOpen', 'playerCreateOpen', 'banned'], true)) {
                    $value = $value ? 1 : 0;
                }
                $values[] = $value;
            }
            $stmt->execute($values);
        }

        echo "Imported " . count($rows) . " rows into $table\n";
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();
    echo "Import complete.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed: {$e->getMessage()}\n");
    exit(1);
}
