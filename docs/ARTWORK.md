# Saved posters

Jellydash can keep small copies of Jellyfin's selected posters for History,
Statistics and Monthly recap. This includes custom posters and shows without
external provider IDs. Jellyfin artwork keeps priority. Online recovery can fill
missing posters from TMDB when no Jellyfin copy is available.

Once a poster has been downloaded, checked and saved, it stays available if an
episode, series or movie is deleted from Jellyfin. A deleted episode can still
use its saved series identity. Removing a poster in Jellyfin keeps the last good
copy in Jellydash. Choosing a different poster refreshes the saved copy after a
successful check.

Artwork deleted before the first successful save cannot be guaranteed. Saving
a play does not prove that its poster has been saved yet. Background workers,
connection failures and storage limits can affect coverage.

## Background checks

The Docker image runs `artwork:poll` separately from History collection, normally
every 30 seconds. A batch does a limited amount of work. Nobody needs to have the
dashboard open. Retained posters are checked again after 24 hours, with new
observations able to request an earlier check. Large queues or failed requests
can delay a check.

If you run PHP directly, enable GD for poster validation and schedule:

```sh
php bin/console.php artwork:poll
```

Running it once a minute is sufficient to make progress. Missing GD stops new
poster saves and appears in Settings; it does not prevent recording History.
The Docker image includes GD. Setting `POLLER_ENABLED=false` disables the network
polling loops, including artwork collection. Local external expiry stays active.

Only Primary posters are retained. Backgrounds, avatars, logos and episode
stills keep their existing behavior. Supported animated images are saved as a
decoded still. Unsupported or invalid images leave an existing good copy intact.

## Repair existing History

Open **Settings > Saved posters** as a manager. With login disabled, this section
is available to anyone who can access Jellydash.

1. If older History belongs to the configured Jellyfin server, choose **Check
   existing History**. Older exports do not contain verified server identity,
   so Jellydash asks for this confirmation before checking those entries.
2. Existing items with matching identity and recorded metadata can recover their
   current Jellyfin poster. Entries that cannot be verified appear under **Find
   missing posters**.
3. Search your current Jellyfin library, check the title, year and poster, then
   choose **Use this poster**. The choice applies to the listed episodes or
   movies. Large groups are handled in batches of up to 100 items.

Matches affect artwork only. Every original play, its item ID and its statistics
stay intact. For movies that were deleted and re-added, [Movie replacements](MOVIE_REPLACEMENTS.md)
also reconnects their Statistics entries and History drilldowns.

Jellydash does not automatically identify a deleted show by its name. Remakes,
anime editions and custom shows can share titles. If the original custom image
is gone and no current Jellyfin item has it, Jellydash cannot reconstruct it.

Expand **Saved posters** to review retained copies. **Remove match** removes a
manual artwork association so you can choose again. **Clear saved poster**
removes that stored image and pauses saving it until you choose **Resume saving**.
A current image can still appear through Jellyfin while the item exists; clearing
the saved copy does not delete or change anything in Jellyfin.

**Refresh** reloads the local status. **Retry checks** schedules another background
attempt. Neither action replaces good images with a failed download.

## Online recovery

Online recovery is enabled by default. Turn it off in **Settings > Saved posters >
Online poster recovery**, or set `ARTWORK_EXTERNAL_RECOVERY=false` as the initial
default. An explicit Settings choice takes priority. Turning it off stops new
online requests. Saved external copies remain available until cleared or expired.

Automatic recovery uses only movie or series TMDB IDs captured independently from
Jellyfin. It checks the exact returned ID and media kind. IMDb or TVDB IDs alone
do not authorize a match. Deleted items can recover automatically when reliable
identity evidence survives; older History without it needs a manager's choice.
When that evidence is missing, Jellydash searches the saved movie or series name
and prepares suggestions. It does not choose a title match automatically. Episodes
use their saved series name; an episode title alone cannot identify a deleted show.
Native names are supported without converting them to Latin characters.

Managers see a small History/Statistics notice and Settings badge linking straight
to the review area. Choose the affected History items and press **Review suggestions**
when suggestions are ready. You can also edit the title and use **Search online**.
Check the title, year and poster before selecting a candidate. No candidate
starts selected. The match stays pending until validated image bytes are saved.
Failed downloads keep the choice for a retry. Removing a match returns it to
manual review. Groups are handled in batches of up to 100 items.

**Skip for 7 days** hides the notice for all managers while keeping the entry in
Settings. **Keep placeholder** pauses external recovery for those items until
resumed, including after unlinking or toggling recovery. A real Jellyfin poster
can still return. **Clear image everywhere** confirms the affected count, deletes
that shared external image and pauses its linked items, including pending saves.

Searches use Jellyfin's TMDB remote-search provider without applying metadata to
Jellyfin. Deleted items use server defaults rather than former library options.
Exact-ID checks send the ID. Background title searches send the saved movie or
series name; manual searches send your entered title.
Images come from TMDB's image host without Jellyfin credentials. Candidate previews
load when you review suggestions or run a manual search. Opening Settings alone
does not make a provider request or download previews. Missing or unusable images leave a good saved copy
intact, or a local placeholder labelled with the title.

Background title discovery makes at most one new query per minute for each
configured source. Matching saved names can share suggestions, but each historical
selection stays separate. Suggestions are cached for seven days. Empty or failed
lookups retry after at least 15 minutes, or longer when the provider requests it.
An empty result can also mean a provider problem. You can edit the name and search
again. Recorded movie or series years appear as a check when available; episode
air dates are not used as a show's release year.

Useful external copies are renewed by exact ID after 150 days. Under
[TMDB's API terms](https://www.themoviedb.org/api-terms-of-use), cached images and
provider metadata expire after 180 days if renewal cannot complete. Reuse, viewing
and restoring a backup do not extend the deadline. Unknown age is treated as
expired. Expired manual choices need manager selection again. Automatic matches
can use independently captured IDs for a fresh check. Retained Jellyfin custom
posters have no such expiry.

Docker runs local expiry maintenance every minute even with polling or recovery
disabled, including disconnected and quarantined source scopes. Direct PHP
installs should schedule `php bin/console.php artwork:expire` once a minute.
This makes no network requests. Reads reject expired external content immediately,
including after a restore. Physical cleanup is bounded and may need several runs.
Jellydash cannot erase offline backups; keep backup retention and restored data
consistent with the provider's terms. External posters are an expiring cache.

The TMDB logo follows its [branding guidance](https://www.themoviedb.org/about/logos-attribution).
This application uses TMDB and the TMDB APIs but is not endorsed, certified, or
otherwise approved by TMDB.

## Storage and backups

Saved posters and their associations live in the Jellydash database. They are
application data, separate from disposable caches. Ordinary cache clearing and
container recreation do not remove them when the database is persisted.

Each retained image is limited to 256 KiB. Jellydash requests a rendition within
480 by 720 pixels, validates its format and dimensions, and decodes it before
publication. Keep the full database in your backups, including its binary fields.
The checked MariaDB-to-SQLite migration preserves poster bytes and associations.
History CSV contains plays, not poster images or artwork matches.

The default image-body budget is 512 MiB per configured Jellyfin address. You can
set a different budget in MiB:

```dotenv
ARTWORK_STORAGE_LIMIT_MB=512
```

Valid values are 1 through 65536. The limit counts Jellyfin and external image bytes, not total
database or backup size. When a save would exceed the budget, it waits and Settings
reports the capacity problem. Existing images are kept. Increasing the limit or
explicitly clearing unwanted copies makes room for later checks. Database files
may remain larger than the retained image bodies.

Keep the configured Jellyfin URL stable. Changing it creates a separate artwork
scope; existing copies stay in the database and Settings reports the other scope.
They are not automatically reassigned. If a different Jellyfin server identity
appears at the same address, saved artwork is protected by pausing serving and
updates. Automatic reassociation after a server identity change is not supported.
