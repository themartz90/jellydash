# History CSV format

Jellydash can export every play matching the filters chosen in the History
export dialog. The dialog starts with the filters currently used on the History
page, shows the exact number of matching plays and can reset to all History.
The download is not limited to the current page.

The file uses UTF-8 with a byte order mark, comma separators, RFC 4180 quoting
and one play per row. Dates use `YYYY-MM-DD HH:MM:SS`. The source installation's
IANA timezone is stored with every row. Version 2 also includes Unix timestamps
when the original moment is known, so repeated clock times during a daylight
saving change can be restored correctly. The first column identifies the
format version. Version 2 uses this exact column order:

```text
jellydash_history_version
jellydash_timezone
session_key
started_at
updated_at
ended_at
user_id
user_name
item_id
item_type
series_name
item_name
season_ep
library
library_resolved_at
play_method
play_method_detail
client
device
source_video_codec
source_audio_codec
source_container
target_video_codec
target_audio_codec
target_container
is_video_direct
is_audio_direct
transcode_reasons
watched_sec
runtime_sec
is_finished
watch_duration_sec
started_at_epoch
updated_at_epoch
ended_at_epoch
library_resolved_at_epoch
```

`jellydash_history_version` is `2` for every exported play and
`jellydash_timezone` contains an IANA name such as `Europe/Prague`. Optional
values are empty. Boolean values are `1`, `0`, or empty when Jellyfin did not
report them.
Transcode reasons remain JSON so a future Jellydash importer can restore the
original list without guessing where one reason ends and another starts.

`watched_sec` keeps the existing value used for progress calculations. New live
plays store sampled viewing time separately in `watch_duration_sec`. Playback
Reporting imports use the session duration reported by that plugin. An empty
duration means the row predates this distinction, so its watch-time contribution
is an estimate based on `watched_sec`. Exporting older rows does not invent a
duration or a Unix timestamp.

Live duration starts at the first observed sample. It counts advancing playback
between samples at most two minutes apart, allows for playback speed, and skips
paused, stalled, backward-seek and longer-gap intervals. It cannot reconstruct
viewing that happened before collection started or while polling was offline.

## Spreadsheet safety

Media titles, user names and other text can begin with characters spreadsheet
apps interpret as formulas. Jellydash prefixes those cells with an apostrophe.
A literal value that already begins with an apostrophe receives a second one.
This keeps the export safe to open and makes the transformation reversible for
the native importer.

The database row ID and notification flag are intentionally excluded. They are
local implementation details and must not be reused when moving plays between
Jellydash installations.

## Importing

Open **Settings → Import play history** and choose **Jellydash CSV**. Jellydash
validates the complete file and previews how many plays are new or already
present before enabling the import. The write is transactional, so a failure
cannot leave half of a backup in History. Existing plays are skipped and every
restored play is marked as already notified.

Version 1 backups are still accepted with their original header, which ends at
`is_finished`. They restore with empty duration and Unix timestamp fields.
If an older row has an ambiguous clock time during a daylight saving change,
restore it using the source timezone. Converting that row to another timezone
is rejected because the file cannot identify which occurrence was intended.
Version 2 rows with Unix timestamps can distinguish those occurrences.

Only the exact documented headers and supported format versions are accepted.
This keeps restores predictable and lets future versions reject files they
cannot reproduce safely.
