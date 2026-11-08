<?php
/**
 * SmartBulk — translation coverage (no PrestaShop needed).
 *
 * Checks that every string the module translates — $this->l() / plainL() in
 * smartbulk.php, $l() and Lang::t() in src/ — has a Polish entry in
 * translations/pl.php under the key PrestaShop looks up, with the same
 * {placeholders}; and that every t('key') used by the React app exists in
 * the SPA dictionary (smartbulk.php getI18nDictionary).
 *
 * Run: php tests/unit/TranslationsTest.php
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__, 2);
$failures = 0;
$count = 0;

function check(string $label, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        printf("  \033[31m✗\033[0m %s\n", $label);
    }
}

/** Same key as PrestaShop's Translate::getModuleTranslation (apostrophes escaped before md5). */
function psKey(string $source): string
{
    return '<{smartbulk}prestashop>smartbulk_' . md5((string) preg_replace("/\\\\*'/", "\\'", $source));
}

/** @return string[] sorted {placeholders} in $s ({plural} is English-only: Polish words it differently) */
function placeholders(string $s): array
{
    preg_match_all('/\{[a-z_]+\}/', $s, $m);
    $p = array_values(array_diff(array_unique($m[0]), ['{plural}']));
    sort($p);
    return $p;
}

/**
 * String literals passed as first argument to the given calls in a PHP file.
 *
 * @param string[] $calls e.g. ['->l', '::t', '$l']
 * @return array<int,array{0:string,1:string}> [source, call]
 */
function translatedLiterals(string $file, array $calls): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $out = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        $text = is_array($tok) ? $tok[1] : $tok;
        $prev = $i > 0 ? (is_array($tokens[$i - 1]) ? $tokens[$i - 1][1] : $tokens[$i - 1]) : '';
        $call = null;
        if ($text === '$l') {
            $call = '$l';
        } elseif (is_array($tok) && $tok[0] === T_STRING && in_array($prev, ['->', '::'], true)) {
            $call = $prev . $text;
        }
        if ($call === null || !in_array($call, $calls, true)) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (($tokens[$j] ?? null) !== '(') continue;
        $j++;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING && $tokens[$j][1][0] === "'") {
            $out[] = [str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tokens[$j][1], 1, -1)), $call];
        }
    }
    return $out;
}

global $_MODULE;
require $root . '/translations/pl.php';
$pl = $_MODULE;

// ---- PHP sources ----
$sources = [];
foreach (translatedLiterals($root . '/smartbulk.php', ['->l', '->plainL']) as $s) $sources[] = $s;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    foreach (translatedLiterals($f->getPathname(), ['::t', '$l', '->l']) as $s) $sources[] = $s;
}
require $root . '/vendor/autoload.php';
foreach ((new ReflectionClass(SmartBulk\Service\Ai\AIResponse::class))->getConstants() as $name => $value) {
    if (str_starts_with($name, 'MSG_')) $sources[] = [$value, 'AIResponse'];
}

$checked = [];
foreach ($sources as [$src, $call]) {
    if (isset($checked[$src])) continue;
    $checked[$src] = true;
    $tr = $pl[psKey($src)] ?? null;
    check("pl.php has: {$src}", is_string($tr) && $tr !== '');
    if (is_string($tr)) {
        check("placeholders match: {$src}", placeholders($tr) === placeholders($src));
    }
    if ($call === '::t' || $call === 'AIResponse') {
        check("no apostrophe in Lang::t source: {$src}", !str_contains($src, "'"));
    }
}
printf("PHP strings checked: %d\n", count($checked));

// ---- SPA keys ----
preg_match_all("/^\s*'([a-z0-9_.\-]+)' => \\\$this->l\(/m", (string) file_get_contents($root . '/smartbulk.php'), $m);
$dictKeys = array_flip($m[1]);
$used = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/views/js/app/src'));
foreach ($it as $f) {
    if (!preg_match('/\.tsx?$/', $f->getFilename())) continue;
    preg_match_all("/\bt2?\('([a-z0-9_.\-]+)'/", (string) file_get_contents($f->getPathname()), $mm);
    foreach ($mm[1] as $key) {
        if (str_ends_with($key, '.')) continue; // dynamic prefix, e.g. t('status.' + s)
        $used++;
        check("SPA key in dictionary: {$key} (" . basename($f->getPathname()) . ')', isset($dictKeys[$key]));
    }
}
printf("SPA key uses checked: %d\n", $used);

printf("\n%d checks, %d failed\n", $count, $failures);
exit($failures === 0 ? 0 : 1);
