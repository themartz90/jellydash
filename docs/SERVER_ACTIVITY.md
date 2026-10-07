# Server Activity

Open **Server Activity** in the sidebar to see your Jellyfin server details,
scheduled tasks and recent activity. It uses the existing `JELLYFIN_URL` and
`JELLYFIN_API_TOKEN` configuration. No extra service or background collector
is needed.

With Jellydash login enabled, owners and admins can open the page. Access is
checked again on each request, including after an account's role changes.
When login is disabled, the page is available to anyone who can reach your
dashboard, like Settings.

Jellyfin must allow the configured credential to read system information,
scheduled tasks and the activity log. If a section is denied or unavailable,
the page explains which section could not load. Basic server name and version
may still be available through Jellyfin's public system information endpoint.

## Tasks and server details

Server details include the name, version, package name and pending restart
state when Jellyfin supplies them. **Open Jellyfin** opens the configured
server in another tab.

Running tasks show their reported progress. If Jellyfin does not report a
percentage, the page shows the task's state. Recent results show the latest
execution of each task: successful results for an hour, other results for
24 hours. This is not a full task execution history.

## Activity filters and pages

The default period is the last 24 hours. Choose seven days, 30 days, all
available activity or custom dates, then apply the filters. Custom dates
include the full end date in Jellydash's configured timezone. Event type
and severity filters match exactly. The source filter separates events
with a Jellyfin user ID from system events.

Jellydash reads up to 1,000 events per snapshot, within a five-second request
budget. Released Jellyfin versions do not provide all these filters, so
Jellydash applies them to the events it has read. If more events are available,
a notice explains the limit. Counts and filters then describe the loaded
events; older matches may be missing. A narrow historical date range can
still be incomplete when newer events fill the snapshot. Use Jellyfin's own
activity log for the full record.

Pages contain up to 25 events. The first page refreshes while the tab is
visible. Older pages keep the same snapshot so new events do not move the
rows while you browse. Snapshots expire after five minutes, or sooner if
the local cache is unavailable or evicted. **Refresh** starts a new snapshot
and returns to the first page. Tasks and server details refresh separately.

During a temporary connection failure, recently cached information may be
shown for up to five minutes and is labelled as last known. A credential
denial invalidates cached protected information. The page receives event names
and basic metadata. Raw event detail fields, task error fields and system
information path fields are omitted. Event names may contain usernames and
media titles.

The page only reads Jellyfin. Start or stop tasks and inspect full event
details in Jellyfin itself.
