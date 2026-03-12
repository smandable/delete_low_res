#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 *
 * Finds video files in the CURRENT directory with width <= 576px and deletes them.
 *
 * Output:
 * - Grouped by status: PENDING (or DELETE* in dry-run), KEEP, SKIP (and ERROR)
 * - Alphabetical within each group
 * - Shows file, resolution, size, and total recovered
 *
 * Flags:
 *   --dry-run       Don't delete; show what would be deleted (DELETE*)
 *   --only-deleted  Print only the DELETED group (and DELETE* in dry-run) + summary
 *
 * Usage:
 *   php delete_low_res_videos.php
 *   php delete_low_res_videos.php --dry-run
 *   php delete_low_res_videos.php --only-deleted
 *   php delete_low_res_videos.php --dry-run --only-deleted
 */

const MAX_WIDTH = 576;

$opts = getopt('', ['dry-run', 'only-deleted']);
$dryRun = array_key_exists('dry-run', $opts);
$onlyDeleted = array_key_exists('only-deleted', $opts);

function commandExists(string $cmd): bool {
    $check = (stripos(PHP_OS_FAMILY, 'Windows') !== false)
        ? "where " . escapeshellarg($cmd)
        : "command -v " . escapeshellarg($cmd) . " 2>/dev/null";
    $out = [];
    $code = 0;
    @exec($check, $out, $code);
    return $code === 0 && !empty($out);
}

function bytesToHuman(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $size = (float)$bytes;
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return ($i === 0) ? sprintf('%d %s', (int)$size, $units[$i]) : sprintf('%.2f %s', $size, $units[$i]);
}

function probeResolution(string $path): array {
    // Returns: ['ok'=>bool, 'width'=>?int, 'height'=>?int, 'error'=>?string]
    $cmd = 'ffprobe -hide_banner -loglevel error -select_streams v:0 -show_entries stream=width,height -of json '
         . escapeshellarg($path)
         . ' 2>/dev/null';

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    if ($code !== 0 || empty($out)) {
        return ['ok' => false, 'width' => null, 'height' => null, 'error' => 'ffprobe failed or no output'];
    }

    $json = implode("\n", $out);
    $data = json_decode($json, true);

    if (!is_array($data) || empty($data['streams'][0])) {
        return ['ok' => false, 'width' => null, 'height' => null, 'error' => 'no video stream'];
    }

    $stream = $data['streams'][0];
    $w = isset($stream['width']) ? (int)$stream['width'] : null;
    $h = isset($stream['height']) ? (int)$stream['height'] : null;

    if (!$w || !$h) {
        return ['ok' => false, 'width' => $w, 'height' => $h, 'error' => 'missing width/height'];
    }

    return ['ok' => true, 'width' => $w, 'height' => $h, 'error' => null];
}

if (!commandExists('ffprobe')) {
    fwrite(STDERR, "ERROR: ffprobe not found. Install FFmpeg and ensure ffprobe is on your PATH.\n");
    exit(1);
}

$videoExts = [
    'mp4','m4v','mkv','avi','mov','wmv','flv','webm','mpg','mpeg','ts','m2ts','vob'
];

$cwd = getcwd();
if ($cwd === false) {
    fwrite(STDERR, "ERROR: Unable to determine current directory.\n");
    exit(1);
}

/**
 * Collect candidate files first, then sort alphabetically.
 */
$candidates = [];
$it = new DirectoryIterator($cwd);
foreach ($it as $fileinfo) {
    if ($fileinfo->isDot()) continue;
    if (!$fileinfo->isFile()) continue;

    $name = $fileinfo->getFilename();
    if (str_starts_with($name, '.')) continue;

    $ext = strtolower($fileinfo->getExtension());
    if (!in_array($ext, $videoExts, true)) continue;

    $candidates[] = [
        'name' => $name,
        'path' => $fileinfo->getPathname(),
        'size' => (int)$fileinfo->getSize(),
    ];
}

usort($candidates, static function(array $a, array $b): int {
    $cmp = strcasecmp($a['name'], $b['name']);
    return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
});

$rows = [];
$totalRecovered = 0;
$deletedCount = 0;
$keptCount = 0;
$skippedCount = 0;

foreach ($candidates as $c) {
    $name = $c['name'];
    $path = $c['path'];
    $size = $c['size'];

    $probe = probeResolution($path);
    if (!$probe['ok']) {
        $rows[] = [
            'status' => 'SKIP',
            'file' => $name,
            'res' => 'unknown',
            'size' => $size,
            'note' => $probe['error'] ?? 'unknown error',
        ];
        $skippedCount++;
        continue;
    }

    $w = $probe['width'];
    $h = $probe['height'];
    $res = "{$w}x{$h}";

    if ($w <= MAX_WIDTH) {
        $rows[] = [
            'status' => $dryRun ? 'DELETE*' : 'PENDING',
            'file' => $name,
            'path' => $path,
            'res' => $res,
            'size' => $size,
            'note' => $dryRun ? 'dry-run' : '',
        ];
        $totalRecovered += $size;
        $deletedCount++;
    } else {
        $rows[] = [
            'status' => 'KEEP',
            'file' => $name,
            'res' => $res,
            'size' => $size,
            'note' => '',
        ];
        $keptCount++;
    }
}

/**
 * Group for display: DELETED/DELETE* -> KEEP -> SKIP -> ERROR
 * Alphabetical within each group.
 */
$groups = [
    'DELETED' => [],
    'KEEP'    => [],
    'SKIP'    => [],
    'ERROR'   => [],
];

foreach ($rows as $r) {
    $status = $r['status'];
    if ($status === 'DELETED' || $status === 'DELETE*' || $status === 'PENDING') {
        $groups['DELETED'][] = $r; // keep original status for printing
    } elseif ($status === 'KEEP') {
        $groups['KEEP'][] = $r;
    } elseif ($status === 'SKIP') {
        $groups['SKIP'][] = $r;
    } else { // ERROR or anything unexpected
        $groups['ERROR'][] = $r;
    }
}

$sortByFile = static function(array &$arr): void {
    usort($arr, static function(array $a, array $b): int {
        $cmp = strcasecmp($a['file'], $b['file']);
        return $cmp !== 0 ? $cmp : strcmp($a['file'], $b['file']);
    });
};

foreach (array_keys($groups) as $k) {
    $sortByFile($groups[$k]);
}

// Pretty print
$col1 = 9;   // STATUS
$col2 = 54;  // FILE
$col3 = 12;  // RES
$col4 = 12;  // SIZE

$line = str_repeat('-', $col1 + 1 + $col2 + 1 + $col3 + 1 + $col4 + 1 + 20);

echo $dryRun ? "Mode: DRY-RUN (no files deleted)\n" : "Mode: DELETE\n";
echo "Rule: delete if width <= " . MAX_WIDTH . "px\n";
echo "Display: grouped by status, alphabetical within each group\n";
if ($onlyDeleted) {
    echo "Filter: ONLY DELETED group\n";
}
echo "\n";

printf("%s\n", $line);
printf("%-{$col1}s %-{$col2}s %-{$col3}s %-{$col4}s %s\n", "STATUS", "FILE", "RES", "SIZE", "NOTE");
printf("%s\n", $line);

$printGroup = function(string $title, array $items) use ($col1, $col2, $col3, $col4): void {
    if (count($items) === 0) return;
    echo "\n=== {$title} (" . count($items) . ") ===\n";
    foreach ($items as $r) {
        printf(
            "%-{$col1}s %-{$col2}s %-{$col3}s %-{$col4}s %s\n",
            $r['status'],
            mb_strimwidth($r['file'], 0, $col2 - 1, '…'),
            $r['res'],
            bytesToHuman((int)$r['size']),
            $r['note'] ?? ''
        );
    }
};

$printGroup('DELETED', $groups['DELETED']);

if (!$onlyDeleted) {
    $printGroup('KEEP',  $groups['KEEP']);
    $printGroup('SKIP',  $groups['SKIP']);
    $printGroup('ERROR', $groups['ERROR']);
}

echo "\n{$line}\n\n";
echo "Deleted:   {$deletedCount}\n";
echo "Kept:      {$keptCount}\n";
echo "Skipped:   {$skippedCount}\n";
echo "Recovered: " . bytesToHuman($totalRecovered) . " (" . number_format($totalRecovered) . " bytes)\n";

// Confirmation prompt (skip in dry-run mode)
if (!$dryRun && $deletedCount > 0) {
    echo "\nDelete {$deletedCount} file(s)? Y/n: ";
    system("stty -icanon");
    $handle = fopen("php://stdin", "r");
    $char = fgetc($handle);
    fclose($handle);
    system("stty sane");
    echo "\n";

    if (strtolower(trim($char)) !== 'y') {
        echo "Operation aborted.\n";
        exit;
    }

    $actualDeleted = 0;
    foreach ($rows as &$r) {
        if ($r['status'] !== 'PENDING') continue;
        $ok = @unlink($r['path']);
        if ($ok) {
            $r['status'] = 'DELETED';
            $actualDeleted++;
        } else {
            $r['status'] = 'ERROR';
            $r['note'] = 'failed to delete (permissions?)';
        }
    }
    unset($r);
    echo "Deleted {$actualDeleted} file(s).\n";
}

