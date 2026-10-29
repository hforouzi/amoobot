#!/usr/bin/env sh
# Builds the installable Winapay plugin ZIP (plugin.json, README.md and src/ at the ZIP root).
# Usage: docs/plugins/winapay-gateway/build.sh [output.zip]   (default: $TMPDIR/winapay.zip)
# Uses PHP's ZipArchive, so it works wherever Amoobot runs (no `zip` binary needed).
set -eu

cd "$(dirname "$0")"
out="${1:-${TMPDIR:-/tmp}/winapay.zip}"

php -r '
$out = $argv[1];
@unlink($out);
$zip = new ZipArchive();
if (true !== $zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}
$zip->addFile("plugin.json", "plugin.json");
$zip->addFile("README.md", "README.md");
foreach (glob("src/*.php") as $file) {
    $zip->addFile($file, $file);
}
$zip->close();
' "$out"

echo "$out"
