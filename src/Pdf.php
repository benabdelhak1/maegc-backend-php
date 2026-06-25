<?php

declare(strict_types=1);

namespace Maegc;

final class Pdf
{
    private const PAGE_WIDTH = 1414.0;
    private const PAGE_HEIGHT = 2000.0;

    public static function playerContract(array $player, array $team, array $contract): string
    {
        $templatePath = __DIR__ . '/assets/pdf/contract-template-v2.jpg';
        $template = self::jpegFromFile($templatePath);
        if ($template === null) {
            return self::simpleContract($player, $team, $contract);
        }

        $teamLogo = self::teamLogo((string) ($team['logo'] ?? ''));
        $content = self::contractContent($player, $contract, $teamLogo);

        $logoObjectId = $teamLogo !== null ? 6 : null;
        $contentObjectId = $teamLogo !== null ? 7 : 6;
        $xObjects = '/Background 5 0 R';
        if ($logoObjectId !== null) {
            $xObjects .= ' /TeamLogo ' . $logoObjectId . ' 0 R';
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] '
                . '/Resources << /Font << /F1 4 0 R >> /XObject << %s >> >> '
                . '/Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $xObjects,
                $contentObjectId
            ),
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => self::jpegObject($template),
        ];

        if ($teamLogo !== null) {
            $objects[$logoObjectId] = self::jpegObject($teamLogo);
        }
        $objects[$contentObjectId] = self::streamObject($content);

        return self::build($objects);
    }

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

    private static function contractContent(array $player, array $contract, ?array $teamLogo): string
    {
        $commands = [
            'q',
            sprintf('%.0F 0 0 %.0F 0 0 cm', self::PAGE_WIDTH, self::PAGE_HEIGHT),
            '/Background Do',
            'Q',
        ];

        if ($teamLogo !== null) {
            $commands[] = self::teamLogoCommands($teamLogo);
        }

        $fields = [
            ['value' => $player['fullName'] ?? '', 'left' => 763, 'top' => 540, 'width' => 387, 'height' => 55],
            ['value' => $player['efootballId'] ?? '', 'left' => 763, 'top' => 604, 'width' => 387, 'height' => 55],
            ['value' => $player['age'] ?? '', 'left' => 763, 'top' => 668, 'width' => 387, 'height' => 55],
            ['value' => self::dateText($contract['startDate'] ?? null), 'left' => 763, 'top' => 738, 'width' => 387, 'height' => 55],
            ['value' => $player['phoneSerie'] ?? '', 'left' => 119, 'top' => 540, 'width' => 410, 'height' => 55],
            ['value' => $player['phoneId'] ?? '', 'left' => 119, 'top' => 604, 'width' => 410, 'height' => 55],
            ['value' => self::durationText($contract['endDate'] ?? null), 'left' => 119, 'top' => 667, 'width' => 410, 'height' => 55],
            ['value' => self::dateText($contract['endDate'] ?? null), 'left' => 119, 'top' => 738, 'width' => 410, 'height' => 55],
        ];

        foreach ($fields as $field) {
            $commands[] = self::rightAlignedText(
                (string) $field['value'],
                (float) $field['left'],
                (float) $field['top'],
                (float) $field['width'],
                (float) $field['height']
            );
        }

        return implode("\n", $commands) . "\n";
    }

    private static function rightAlignedText(
        string $value,
        float $left,
        float $top,
        float $width,
        float $height
    ): string {
        $fontSize = 22.0;
        $horizontalPadding = 22.0;
        $encoded = self::encodeText($value);
        $maxWidth = $width - ($horizontalPadding * 2.0);
        $estimatedWidth = self::estimatedTextWidth($encoded, $fontSize);

        while ($fontSize > 12.0 && $estimatedWidth > $maxWidth) {
            $fontSize -= 1.0;
            $estimatedWidth = self::estimatedTextWidth($encoded, $fontSize);
        }

        $x = max(
            $left + $horizontalPadding,
            $left + $width - $horizontalPadding - $estimatedWidth
        );
        $baselineFromTop = $top + ($height / 2.0) + ($fontSize * 0.48);
        $y = self::PAGE_HEIGHT - $baselineFromTop;

        return sprintf(
            "BT\n/F1 %.2F Tf\n0 g\n1 0 0 1 %.2F %.2F Tm\n(%s) Tj\nET",
            $fontSize,
            $x,
            $y,
            self::escapeText($encoded)
        );
    }

    private static function teamLogoCommands(array $logo): string
    {
        $left = 1181.0;
        $top = 89.0;
        $size = 183.0;
        $bottom = self::PAGE_HEIGHT - $top - $size;
        $radius = $size / 2.0;
        $cx = $left + $radius;
        $cy = $bottom + $radius;
        $k = $radius * 0.5522847498;

        $scale = max($size / $logo['width'], $size / $logo['height']);
        $drawWidth = $logo['width'] * $scale;
        $drawHeight = $logo['height'] * $scale;
        $drawX = $left + (($size - $drawWidth) / 2.0);
        $drawY = $bottom + (($size - $drawHeight) / 2.0);

        return sprintf(
            "q\n"
            . "%.3F %.3F m\n"
            . "%.3F %.3F %.3F %.3F %.3F %.3F c\n"
            . "%.3F %.3F %.3F %.3F %.3F %.3F c\n"
            . "%.3F %.3F %.3F %.3F %.3F %.3F c\n"
            . "%.3F %.3F %.3F %.3F %.3F %.3F c\n"
            . "W n\n"
            . "%.3F 0 0 %.3F %.3F %.3F cm\n"
            . "/TeamLogo Do\nQ",
            $cx + $radius,
            $cy,
            $cx + $radius,
            $cy + $k,
            $cx + $k,
            $cy + $radius,
            $cx,
            $cy + $radius,
            $cx - $k,
            $cy + $radius,
            $cx - $radius,
            $cy + $k,
            $cx - $radius,
            $cy,
            $cx - $radius,
            $cy - $k,
            $cx - $k,
            $cy - $radius,
            $cx,
            $cy - $radius,
            $cx + $k,
            $cy - $radius,
            $cx + $radius,
            $cy - $k,
            $cx + $radius,
            $cy,
            $drawWidth,
            $drawHeight,
            $drawX,
            $drawY
        );
    }

    private static function dateText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $matches)) {
            return $matches[0];
        }
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? '' : gmdate('Y-m-d', $timestamp);
    }

    private static function durationText(mixed $endDate): string
    {
        $date = self::dateText($endDate);
        if ($date === '') {
            return '';
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        $days = (int) $today->diff($end)->format('%r%a');
        if ($days <= 0) {
            return 'expired';
        }

        $years = intdiv($days, 365);
        if ($years >= 1) {
            return $years === 1 ? '1 year' : $years . ' years';
        }

        $months = intdiv($days, 30);
        if ($months >= 1) {
            return $months === 1 ? '1 month' : $months . ' months';
        }

        return $days === 1 ? '1 day' : $days . ' days';
    }

    private static function teamLogo(string $url): ?array
    {
        if ($url === '' || !preg_match('/^https?:\/\//i', $url) || !function_exists('curl_init')) {
            return null;
        }

        if (str_contains($url, 'res.cloudinary.com') && str_contains($url, '/image/upload/')) {
            $url = str_replace('/image/upload/', '/image/upload/b_white,f_jpg,q_auto/', $url);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MAEGC-PDF/1.0',
        ]);
        $data = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($data) || $status < 200 || $status >= 300 || strlen($data) > 5 * 1024 * 1024) {
            return null;
        }

        return self::jpegFromString($data);
    }

    private static function jpegFromFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = file_get_contents($path);
        return is_string($data) ? self::jpegFromString($data) : null;
    }

    private static function jpegFromString(string $data): ?array
    {
        $info = @getimagesizefromstring($data);
        if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            return null;
        }

        $channels = (int) ($info['channels'] ?? 3);
        $colorSpace = match ($channels) {
            1 => '/DeviceGray',
            4 => '/DeviceCMYK',
            default => '/DeviceRGB',
        };

        return [
            'data' => $data,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'bits' => (int) ($info['bits'] ?? 8),
            'colorSpace' => $colorSpace,
        ];
    }

    private static function jpegObject(array $image): string
    {
        return sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
            . "/ColorSpace %s /BitsPerComponent %d /Filter /DCTDecode /Length %d >>\n"
            . "stream\n%s\nendstream",
            $image['width'],
            $image['height'],
            $image['colorSpace'],
            $image['bits'],
            strlen($image['data']),
            $image['data']
        );
    }

    private static function streamObject(string $content): string
    {
        return "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    }

    private static function build(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($number = 1; $number <= $maxObject; $number++) {
            $pdf .= isset($offsets[$number])
                ? sprintf("%010d 00000 n \n", $offsets[$number])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private static function encodeText(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        return is_string($encoded) ? $encoded : '';
    }

    private static function escapeText(string $value): string
    {
        return str_replace(["\\", "(", ")", "\r", "\n"], ["\\\\", "\\(", "\\)", '', ' '], $value);
    }

    private static function estimatedTextWidth(string $value, float $fontSize): float
    {
        return strlen($value) * $fontSize * 0.56;
    }
}
