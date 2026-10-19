<?php

/*
 * Release guard, run by .github/workflows/release.yml on a tag push.
 *
 *   php .github/scripts/check-release.php v1.2.3 [notes-file]
 *
 * Fails unless the tag is semver (`vX.Y.Z` or `vX.Y.Z-pre.N`), equals Sfere\Support\Library::VERSION
 * (which every event reports in `context.library.version`), and CHANGELOG.md has a dated, non-empty
 * `## [X.Y.Z] - YYYY-MM-DD` section. Writes that section to notes-file for the GitHub Release.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, "Release check failed: {$message}\n");
    exit(1);
};

$tag = $argv[1] ?? '';
if (preg_match('/^v(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $tag, $match) !== 1) {
    $fail("tag \"{$tag}\" is not vX.Y.Z or vX.Y.Z-prerelease.");
}
$version = $match[1];
$root = dirname(__DIR__, 2);

$library = (string) file_get_contents($root.'/src/Support/Library.php');
if (preg_match("/const VERSION = '([^']+)';/", $library, $match) !== 1) {
    $fail('Library::VERSION not found in src/Support/Library.php.');
}
if ($match[1] !== $version) {
    $fail("tag {$tag} does not match Library::VERSION '{$match[1]}'. Bump src/Support/Library.php before tagging.");
}

$changelog = (string) file_get_contents($root.'/CHANGELOG.md');
$pattern = '/^## \['.preg_quote($version, '/').'\] - (\d{4}-\d{2}-\d{2})[ \t]*\R(.*?)(?=^## \[|\z)/ms';
if (preg_match($pattern, $changelog, $match) !== 1) {
    $fail("CHANGELOG.md has no \"## [{$version}] - YYYY-MM-DD\" section.");
}
$notes = trim($match[2]);
if ($notes === '') {
    $fail("the CHANGELOG.md section for {$version} is empty.");
}

if (isset($argv[2])) {
    file_put_contents($argv[2], $notes."\n");
}

echo "Release {$version} ({$match[1]}): tag, Library::VERSION and CHANGELOG.md agree.\n";
