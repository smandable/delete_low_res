# delete_low_res

A PHP CLI tool that finds low-resolution video files in the current directory and deletes them. Uses `ffprobe` to check the actual video width of each file and removes anything at or below 576px wide.

## Usage

```bash
cd /path/to/your/videos
php /path/to/delete_low_res.php
```

### Flags

| Flag | Description |
|---|---|
| `--dry-run` | Show what would be deleted without actually deleting |
| `--only-deleted` | Only show the deleted files in the output (hides KEEP/SKIP groups) |

Flags can be combined:

```bash
php delete_low_res.php --dry-run --only-deleted
```

## Output

Files are grouped by status and sorted alphabetically within each group:

- **DELETED** (or **DELETE\*** in dry-run mode) — files with width <= 576px
- **KEEP** — files above the resolution threshold
- **SKIP** — files where ffprobe couldn't determine resolution
- **ERROR** — files that failed to delete

A summary at the end shows counts and total disk space recovered.

## Supported Formats

`mp4`, `m4v`, `mkv`, `avi`, `mov`, `wmv`, `flv`, `webm`, `mpg`, `mpeg`, `ts`, `m2ts`, `vob`

## Requirements

- PHP 8.0+ (uses `str_starts_with`)
- [FFmpeg](https://ffmpeg.org/) (`ffprobe` must be on your PATH)
