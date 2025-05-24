<?php
// Pera CMS by ABATBeliever
// License - Apache 2.0
$SYSTEM_NAME    = 'Pera CMS';
$SYSTEM_VERSION = 'Alpha 2';
$BASE_URL       = 'https://abatbeliever.net/app/PeraCMS/';

$article = $_GET['article'] ?? '';
if ($article === 'version') {
    echo "{$SYSTEM_NAME} {$SYSTEM_VERSION}. Thank you.";
    exit;
}

$file = __DIR__ . "/data/{$article}.txt";
if (!file_exists($file)) {
    http_response_code(404);
    exit("Error in {$SYSTEM_NAME} {$SYSTEM_VERSION} - Article '{$article}' is not found or deleted.");
}
$content = file_get_contents($file);

function parse_sections($content) {
    $lines = explode("\n", $content);
    $sections = [];
    $current = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '[EOF]') break;
        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $current = strtolower($m[1]);
            $sections[$current] = '';
        } else {
            if ($current) $sections[$current] .= $line . "\n";
        }
    }

    foreach ($sections as $key => $value) {
        if ($key !== 'text') {
            $lines = explode("\n", trim($value));
            $data = [];
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $data[trim($k)] = trim($v);
                }
            }
            $sections[$key] = $data;
        } else {
            $sections[$key] = trim($value);
        }
    }

    return $sections;
}

function convertRelativeLinks($text, $baseUrl) {
    return preg_replace_callback('/\[(.*?)\]\(@([a-zA-Z0-9_\-]+)\)/', function ($matches) use ($baseUrl) {
        $label = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
        $target = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
        return "<a href='{$baseUrl}{$target}'>{$label}</a>";
    }, $text);
}

$sections = parse_sections($content);

$title       = $sections['ini']['title']       ?? 'no-title';
$color       = $sections['color']              ?? ['back-rgb' => '255,255,255', 'text-rgb' => '0,0,0'];
$favicon     = $sections['ini']['favicon']     ?? 'favicon.ico';
$robots      = $sections['ini']['robots']      ?? 'index, follow';
$description = $sections['ini']['description'] ?? ' ';
$ogp         = $sections['ini']['ogp']         ?? '';

if ($ogp && !preg_match('#^https?://#', $ogp)) {
    $ogp = rtrim($BASE_URL, '/') . '/img/' . ltrim($ogp, '/');
}

$sections['text'] = convertRelativeLinks($sections['text'], $BASE_URL);

function convert_to_html($text) {
    $lines = explode("\n", $text);
    $html = '';
    $inTable = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^# (.+)/', $line, $m)) {
            $html .= "<h2>" . htmlspecialchars($m[1]) . "</h2>";
        } elseif (preg_match('/\[(.+?)\]\((https?:\/\/.+?)\)/', $line, $m)) {
            $html .= "<p><a href=\"" . htmlspecialchars($m[2]) . "\" target=\"_blank\">" . htmlspecialchars($m[1]) . "</a></p>";
        } elseif (preg_match('/^<a href=/', $line)) {
            $html .= "<p>{$line}</p>";
        } elseif (preg_match('/youtube\((.+?)\)/', $line, $m)) {
            $id = htmlspecialchars($m[1]);
            $html .= "<iframe width='560' height='315' src='https://www.youtube.com/embed/{$id}' frameborder='0' allowfullscreen></iframe>";
        } elseif (preg_match('/img\((.+?)\)/', $line, $m)) {
            $img = htmlspecialchars($m[1]);
            $html .= "<img src=\"/img/{$img}\" alt=\"\">";
        } elseif (strpos($line, '|') !== false) {
            if (!$inTable) {
                $html .= "<table border='1'>";
                $inTable = true;
            }
            $cols = array_map('trim', explode('|', trim($line, '|')));
            $html .= "<tr>";
            foreach ($cols as $col) {
                $html .= "<td>" . htmlspecialchars($col) . "</td>";
            }
            $html .= "</tr>";
        } else {
            $html .= "<p>" . htmlspecialchars($line) . "</p>";
        }
    }

    if ($inTable) $html .= "</table>";
    return $html;
}

$bodyBlocks = [];
$lines = explode("\n", $sections['text']);
$currentSkin = 0;
$currentText = '';

foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match('/^@skin\s+(\d+)/', $line, $m)) {
        if ($currentText !== '') {
            $bodyBlocks[] = [
                'skin' => $currentSkin,
                'html' => convert_to_html($currentText)
            ];
            $currentText = '';
        }
        $currentSkin = (int)$m[1];
    } else {
        $currentText .= $line . "\n";
    }
}
if ($currentText !== '') {
    $bodyBlocks[] = [
        'skin' => $currentSkin,
        'html' => convert_to_html($currentText)
    ];
}

$skinName = $sections['ini']['skin'] ?? 'default';
$skinPath = __DIR__ . "/skins/{$skinName}.skin";
if (!file_exists($skinPath)) {
    http_response_code(500);
    exit("Error in {$SYSTEM_NAME} {$SYSTEM_VERSION} - skin file '{$skinName}.skin' is not found / Article:{$article}");
}

include $skinPath;
