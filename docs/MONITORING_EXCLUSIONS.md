# Monitoring exclusions

Monitoring exclusions let you hide a Jellyfin user's activity from Jellydash and stop recording new activity for that user.

An excluded user is hidden from:

- Now Playing
- History
- Statistics
- History CSV exports

Jellydash also skips new background collection and history imports for that user. Rows recorded before the exclusion stay in the database. If you remove the exclusion later, that earlier activity appears again. Activity skipped while the exclusion was active cannot be reconstructed by Jellydash.

Open **Settings > Exclusions > Monitoring** to select users that Jellydash has already seen. You can enter additional usernames in the comma-separated field. Saving an empty selection overrides an `IGNORE_USERS` value from the environment, so the Settings page remains the active source after you save it.

For an initial environment-based setup, use a comma-separated list:

```env
IGNORE_USERS=Alice,Bob
```

Matching uses the complete username and ignores letter case. For example, `Alice` matches `alice`, but it does not match `Alice TV`. User IDs and partial-name matching are not supported.

Exclusions follow usernames, not accounts. If a Jellyfin account is renamed, update its exclusion to the new username.

Monitoring exclusions and notification exclusions are separate settings. `IGNORE_USERS` controls collection and visibility. `PUSH_IGNORE_USERS` only prevents playback alerts and leaves monitoring unchanged.
