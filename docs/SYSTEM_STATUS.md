# System status

Open **System status** beside Settings to check Jellydash's background work.
The same link is in the mobile menu. It stays available when the sidebar's
CPU and RAM card is hidden.

The panel shows Jellyfin collection, Jellyseerr sync, library refreshes, and
notification workers. Each row includes its last successful check. Collection
can be healthy when nobody is watching: a successful check with no active
sessions still counts.

## Reading the checks

- **Healthy:** the background worker completed a recent check.
- **Checking:** a worker started a check and has not finished yet.
- **Delayed:** a check is overdue, a delivery lease expired, or notifications
  are waiting for another delivery attempt.
- **Failed:** a worker or delivery attempt failed. The row gives a short reason.
- **Disabled:** the service or notifications are not configured or enabled.
- **Unknown:** there is no recorded check yet, clock readings disagree, or Web Push
  has no subscribed devices.

An overdue check means more than three configured intervals have passed,
with a minimum allowance of 90 seconds. The intervals come from
`POLL_INTERVAL` (30 seconds by default), `SEERR_POLL_INTERVAL` (120 seconds),
and `LIBRARIES_CACHE_TTL` (300 seconds). A worker that starts but does not
finish uses the same allowance.

The panel reads stored results every 30 seconds while the page is visible.
Opening the dashboard does not mark a background worker healthy. The checks
come from `history:poll`, `seerr:poll`, and `libraries:warm` in
`bin/console.php`. Docker starts these workers by default. If you schedule
them yourself, keep the interval settings consistent with that schedule.
`POLLER_ENABLED=false` disables the built-in Docker loops; recorded checks
from an external schedule can still appear.

Notification health shows worker freshness, pending retries, expired delivery
leases, and the last attempted delivery batch. A failed delivery remains
visible through idle runs until a later batch succeeds. Success means at
least one configured channel accepted each notification in that batch.
It does not confirm delivery through every channel or that a person read it.
Older delivery failures from before this feature are not reconstructed.

If a check fails, start with the reason shown in the panel and the worker
logs. Access failures usually need a token or permission check. A timeout
needs a connection check. A delayed worker needs its schedule checked.
The separate CPU and RAM card describes the machine running Jellydash.

## Copy diagnostics

**Copy diagnostics** copies Jellydash and PHP versions, database type,
timezone, check states, timestamps, intervals, and retry counts. It excludes
credentials, service URLs, user names, viewing titles, and raw logs. If the
browser cannot copy automatically, a text box lets you select and copy it.

The endpoint follows the same optional login rules as Settings. Nothing is
sent to another service by opening the panel or copying diagnostics.
