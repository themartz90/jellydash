# History and viewing time

History keeps viewing time and playback position separately.

- **Recorded time** is the viewing time Jellydash counted between playback
  samples. Paused, stalled and skipped portions do not add their full duration.
  Gaps longer than two minutes do not add viewing time.
- **Position** is the furthest playback position Jellydash received. Seeking
  near the end can fill the bar without recording the movie's full runtime.
- **Estimated time** appears on older entries without measured viewing time.
  Playback Reporting imports supply playback time rather than a media position,
  so their position is shown as unavailable.

A row can show **13m recorded** and **Position 100%** if Jellydash only collected
the final 13 minutes, or if the player reached the end after seeking. The check
uses playback position or the entry's saved completion flag. It does not certify
that Jellydash recorded the full runtime.

## Stops, restarts and pauses

Jellydash starts a separate viewing after it observes the same connection idle,
or playing a different confirmed item. Pausing and resuming keeps the viewing
together. A failed request, missing connection or a long collection gap does not
by itself prove that playback stopped.

Stops must be observed. If playback stops and restarts between checks, Jellydash
may keep the samples in the same viewing. Offline playback that never appears
in Jellyfin's live sessions cannot be captured by the live collector.

The first observation after upgrading to connection tracking starts a new scoped
viewing. A play already running during the upgrade may therefore have two rows.
Its existing row and recorded time remain intact. Previously merged viewings are
not split retrospectively, and missing observations are not filled from runtime
or final position.

## Collection and backups

The background worker records activity even when the dashboard is closed. Check
**Settings > System status** if recent plays are missing. A healthy status now
does not establish that collection was running during an earlier viewing.

Keep the full database in backups. Current connection state and the receipts
used to reject duplicate observations are application data. Pending samples and
capture ordering live in `var/cache/history-samples` by default, or the configured
`HISTORY_SAMPLE_QUEUE_DIR`. Preserve that directory when moving a running install.
Deleting it can lose observations that have not yet reached the database and
can split an ongoing viewing when tracking resumes.
