<?php
/**
 * Drift check for skills/content-egg/SKILL.md against a Content Egg checkout.
 *
 *   php scripts/check-skill.php <path-to-content-egg> [path-to-SKILL.md]
 *
 * Fails (exit 1) and lists:
 *   - every `content-egg/<name>` token in the skill that is neither an ability
 *     name() found in <ce>/application/abilities/*Ability.php nor on the
 *     allowlist of routes and live block types; and
 *   - every topic named in the skill's topic table (a table row whose first
 *     cell is a single backticked name) that has no file in
 *     <ce>/application/abilities/guide/topics/<name>.md.
 *
 * Exit 0 when nothing drifted, 2 when the inputs cannot be read.
 *
 * The allowlist mirrors the plugin's own drift test
 * (tests/abilities/test_guide_topics.php); keep the two in step.
 */

const NON_ABILITY_TOKENS = array(
    'content-egg/v1',
    'content-egg/mcp',
    'content-egg/products',
    'content-egg/coupons',
    'content-egg/images',
    'content-egg/videos',
);

function fail_usage($message)
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

if ($argc < 2 || $argc > 3)
{
    fail_usage('usage: php scripts/check-skill.php <path-to-content-egg> [path-to-SKILL.md]');
}

$ce = rtrim($argv[1], '/');
$skillFile = isset($argv[2]) ? $argv[2] : dirname(__DIR__) . '/skills/content-egg/SKILL.md';

$abilitiesDir = $ce . '/application/abilities';
$topicsDir = $abilitiesDir . '/guide/topics';

if (!is_dir($abilitiesDir))
{
    fail_usage('not a Content Egg checkout (no application/abilities): ' . $ce);
}
if (!is_dir($topicsDir))
{
    fail_usage('no guide topics directory: ' . $topicsDir);
}
$skill = is_file($skillFile) ? file_get_contents($skillFile) : false;
if ($skill === false)
{
    fail_usage('cannot read ' . $skillFile);
}

// Ability names, exactly as the plugin registers them.
$abilities = array();
foreach (glob($abilitiesDir . '/*Ability.php') as $file)
{
    $source = file_get_contents($file);
    if (preg_match('/function\s+name\s*\(\s*\)\s*(?::\s*string\s*)?\{\s*return\s+[\'"](content-egg\/[a-z0-9-]+)[\'"]\s*;/', $source, $m))
    {
        $abilities[] = $m[1];
    }
}
if (!$abilities)
{
    fail_usage('found no ability names under ' . $abilitiesDir . ' - has the name() convention changed?');
}

$problems = array();

// 1. Every content-egg/<name> token must be a real ability or an allowed route/block.
preg_match_all('#content-egg/[a-z0-9-]+#', $skill, $m);
$referenced = array_values(array_unique($m[0]));
foreach ($referenced as $token)
{
    if (!in_array($token, $abilities, true) && !in_array($token, NON_ABILITY_TOKENS, true))
    {
        $problems[] = 'unknown ability or route in the skill: ' . $token;
    }
}

// 2. Every topic in the skill's topic table must have a file.
$topics = array();
foreach (preg_split('/\R/', $skill) as $line)
{
    if (preg_match('/^\|\s*`([a-z0-9-]+)`\s*\|/', $line, $m))
    {
        $topics[] = $m[1];
    }
}
$topics = array_values(array_unique($topics));
foreach ($topics as $topic)
{
    if (!is_file($topicsDir . '/' . $topic . '.md'))
    {
        $problems[] = 'topic in the skill has no file in guide/topics: ' . $topic;
    }
}

if ($problems)
{
    echo "FAIL: " . basename($skillFile) . " has drifted from " . $ce . "\n";
    foreach ($problems as $problem)
    {
        echo '  - ' . $problem . "\n";
    }
    exit(1);
}

printf(
    "OK: %d content-egg/* reference(s) and %d topic(s) in %s match %s\n",
    count($referenced),
    count($topics),
    $skillFile,
    $ce
);
exit(0);
