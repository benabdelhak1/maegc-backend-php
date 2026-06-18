<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Maegc\Database;
use Maegc\Support;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$pdo = Database::connect($config);
$dir = dirname(__DIR__) . '/public/uploads/rules';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

function download_pdf(string $url): ?string
{
    if (!preg_match('/^https?:\/\//i', $url)) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $status >= 200 && $status < 300) ? (string) $data : null;
}

$base = Support::publicApiBase($config);

$settings = $pdo->query('SELECT id, generalRulesPdfUrl FROM settings WHERE generalRulesPdfUrl IS NOT NULL AND generalRulesPdfUrl <> ""')->fetchAll(PDO::FETCH_ASSOC);
foreach ($settings as $row) {
    $data = download_pdf($row['generalRulesPdfUrl']);
    if ($data === null) {
        echo "Skipped settings PDF: {$row['generalRulesPdfUrl']}\n";
        continue;
    }
    $filename = Support::safeFilename('general-rules.pdf', 'general-rules', 'pdf');
    file_put_contents("$dir/$filename", $data);
    $url = "$base/uploads/rules/" . rawurlencode($filename);
    $stmt = $pdo->prepare('UPDATE settings SET generalRulesPdfUrl = ? WHERE id = ?');
    $stmt->execute([$url, $row['id']]);
    echo "Mirrored general rules PDF\n";
}

$competitions = $pdo->query('SELECT id, name, rulesPdfUrl FROM competitions WHERE rulesPdfUrl IS NOT NULL AND rulesPdfUrl <> ""')->fetchAll(PDO::FETCH_ASSOC);
foreach ($competitions as $row) {
    $data = download_pdf($row['rulesPdfUrl']);
    if ($data === null) {
        echo "Skipped competition PDF {$row['id']}: {$row['rulesPdfUrl']}\n";
        continue;
    }
    $filename = Support::safeFilename(($row['name'] ?: 'competition') . '-rules.pdf', 'competition-rules', 'pdf');
    file_put_contents("$dir/$filename", $data);
    $url = "$base/uploads/rules/" . rawurlencode($filename);
    $stmt = $pdo->prepare('UPDATE competitions SET rulesPdfUrl = ? WHERE id = ?');
    $stmt->execute([$url, $row['id']]);
    echo "Mirrored competition PDF {$row['id']}\n";
}

echo "PDF mirror complete.\n";
