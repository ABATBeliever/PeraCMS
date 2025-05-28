<?php
// Pera CMS by ABATBeliever
// License - Apache 3.0
$SYSTEM_NAME    = 'Pera CMS';
$SYSTEM_VERSION = 'Alpha 3';
$BASE_URL       = 'https://abatbeliever.net/app/PeraCMS/';

$article = $_GET['article'] ?? 'index';
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
        if ($line === '' || str_starts_with($line, ';')) continue;
        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $current = strtolower($m[1]);
            $sections[$current] = '';
        } elseif ($current) {
            $sections[$current] .= $line . "\n";
        }
    }

    foreach ($sections as $key => $value) {
        if ($key !== 'text') {
            $data = [];
            foreach (explode("\n", trim($value)) as $line) {
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
    return preg_replace_callback('/\[(.*?)\]\(@([a-zA-Z0-9_\-]+)\)/', function ($m) {
        return "<a href='?article=" . htmlspecialchars($m[2]) . "'>" . htmlspecialchars($m[1]) . "</a>";
    }, $text);
}

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
            $html .= "<tr>" . implode('', array_map(fn($c) => "<td>" . htmlspecialchars($c) . "</td>", $cols)) . "</tr>";
        } else {
            $html .= "<p>" . htmlspecialchars($line) . "</p>";
        }
    }

    if ($inTable) $html .= "</table>";
    return $html;
}

$sections = parse_sections($content);
$text = convertRelativeLinks($sections['text'], $BASE_URL);

$lines = explode("\n", $text);
$bodyBlocks = [];
$currentSkin = 0;
$currentText = '';

foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match('/^@skin\s+(\d+)/', $line, $m)) {
        if ($currentText !== '') {
            $bodyBlocks[] = "<div class='skin-{$currentSkin}'>" . convert_to_html($currentText) . "</div>";
            $currentText = '';
        }
        $currentSkin = (int)$m[1];
    } else {
        $currentText .= $line . "\n";
    }
}
if ($currentText !== '') {
    $bodyBlocks[] = "<div class='skin-{$currentSkin}'>" . convert_to_html($currentText) . "</div>";
}

$combinedHTML = implode("\n", $bodyBlocks);

$ini = $sections['ini'] ?? [];
$color = $sections['color'] ?? [];
$extend = $sections['extend'] ?? [];

$vars = [
    'title' => $ini['title'] ?? 'no-title',
    'description' => $ini['description'] ?? '',
    'favicon' => $ini['favicon'] ?? 'favicon.ico',
    'robots' => $ini['robots'] ?? 'index, follow',
    'ogp' => isset($ini['ogp']) && !preg_match('#^https?://#', $ini['ogp']) ? $BASE_URL . 'img/' . ltrim($ini['ogp'], '/') : ($ini['ogp'] ?? ''),
    'back-rgb' => $color['back-rgb'] ?? '255,255,255',
    'text-rgb' => $color['text-rgb'] ?? '0,0,0',
    'text' => $combinedHTML,
    'url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
];

foreach (['day', 'update', 'author'] as $k) {
    $vars[$k] = $ini[$k] ?? '';
}
foreach ($extend as $k => $v) {
    $vars[$k] = $v;
}

$skinName = $ini['skin'] ?? 'default';
$skinPath = __DIR__ . "/skins/{$skinName}.skin";
if (!file_exists($skinPath)) {
    http_response_code(500);
    exit("Error in {$SYSTEM_NAME} {$SYSTEM_VERSION} - skin '{$skinName}.skin' not found.");
}

$template = file_get_contents($skinPath);
foreach ($vars as $k => $v) {
    $template = str_replace('{' . $k . '}', $k === 'text' ? $v : htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $template);
}

echo $template;
