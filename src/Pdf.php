<?php

declare(strict_types=1);

namespace Maegc;

final class Pdf
{
    public static function simpleContract(array $player, array $team, array $contract): string
    {
        $lines = [
            'MAEGC Player Contract',
            '',
            'Player: ' . ($player['fullName'] ?? ''),
            'Team: ' . ($team['name'] ?? ''),
            'eFootball ID: ' . ($player['efootballId'] ?? ''),
            'Phone Serial: ' . ($player['phoneSerie'] ?? ''),
            'Phone ID: ' . ($player['phoneId'] ?? ''),
            'Age: ' . ($player['age'] ?? ''),
            'Start date: ' . substr((string) ($contract['startDate'] ?? ''), 0, 10),
            'End date: ' . substr((string) ($contract['endDate'] ?? ''), 0, 10),
        ];

        $content = "BT\n/F1 18 Tf\n72 760 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -28 Td\n";
            }
            $content .= '(' . Support::escapePdfText($line) . ") Tj\n";
        }
        $content .= "ET\n";

        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $num = $i + 1;
            $pdf .= "$num 0 obj\n$obj\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n$xref\n%%EOF";

        return $pdf;
    }
}
