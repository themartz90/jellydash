<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/banner-dark.png">
    <img src="docs/assets/banner-light.png" alt="Jellydash" width="440">
  </picture>
</p>

<p align="center">
  A self-hosted dashboard for your Jellyfin server. See who is watching, keep full play history, dig into statistics and get push notifications on your phone.
</p>

<p align="center">
  <a href="https://jellydash.madebymartz.com">jellydash.madebymartz.com</a>
</p>

---

## What is Jellydash?

Jellydash is a monitoring dashboard for [Jellyfin](https://jellyfin.org). If you know Tautulli from the Plex world, this is that idea, built for Jellyfin. It runs in Docker next to your server and answers the questions you actually care about:

- Who is watching right now, and is it transcoding?
- What did people watch this week?
- Which shows and movies are the most popular on my server?
- Did someone just request something new in Jellyseerr?

It's supposed to be lightweight, without too much bloat and (hopefully) nice looking!

It also works as a PWA, so you can install it on your phone like a real app. With notifications turned on, your phone buzzes the moment someone hits play. Alerts can go through Telegram, Pushover, Discord or Web Push, whatever you already use.
I may work on open-source Android app in the future.

The project is very young and in very active development.

## Screenshots

| Now Playing, actively streaming | Now Playing, all quiet |
| --- | --- |
| ![Now Playing with two active streams](docs/assets/now-playing-active.png) | ![Now Playing with no active streams](docs/assets/now-playing-idle.png) |

| History | Jellyseerr requests |
| --- | --- |
| ![History](docs/assets/history.png) | ![Jellyseerr](docs/assets/jellyseerr.jpg) |

| Trending and Most Watched | Statistics |
| --- | --- |
| ![Trending](docs/assets/statistics-trending.png) | ![Statistics](docs/assets/statistics-overview.png) |

<p align="center">
  <img src="docs/assets/mobile-idle.png" width="270" alt="Jellydash idle view on Android">
  &nbsp;&nbsp;
  <img src="docs/assets/mobile-playing.png" width="270" alt="Jellydash active stream view on Android">
</p>
<p align="center">
  <sub>Installed as an app on Android, actively streaming or all quiet</sub>
</p>

<details>
<summary>More screenshots</summary>

![Devices and users](docs/assets/statistics-devices.png)

</details>

## Features

- **Now Playing.** Live cards for every active stream: artwork, user, quality, progress and the playback method (Direct Play, Remux or Transcode, including the reason why). Live TV channels from tuners like Tunarr show up too, with real program progress and a red on-air badge.

- **History.** Every play gets recorded by a background poller, so history is complete even when nobody has the dashboard open. Search it, filter by user or library, export the matching plays to CSV, and enjoy the poster art. Existing Jellyfin or Emby Playback Reporting backups can be imported from Settings.

- **Statistics.** Watch time trends, top users, device activity, clients, codecs and transcode reasons. There is a Trending strip for what is hot right now, and all-time Most Watched charts for both shows and movies.

- **Libraries.** An overview of all your libraries with item counts and type breakdowns. New libraries are picked up automatically.

- **System status.** Check background collection, library refreshes, optional request sync, and notification retries from Settings. Copy a diagnostic summary without service URLs, credentials, or viewing details. See [System status](docs/SYSTEM_STATUS.md) for what the checks mean.

- **Monitoring exclusions.** Hide selected Jellyfin users from Now Playing, History, Statistics, and history exports, and stop collecting new activity for them. Existing rows are kept. See [Monitoring exclusions](docs/MONITORING_EXCLUSIONS.md) before enabling this setting.

- **Jellyseerr requests** (optional). The latest requests with their current status, plus a push notification when a new request comes in. The page only appears once you connect your Jellyseerr instance.

- **Notifications** (optional). "Anna started watching The Office" straight to your phone or desktop, even with the app closed. Delivered through Telegram, Pushover, a Discord webhook, Web Push, or any combination of them.

- **Optional login.** Off by default, because on a trusted home network it just gets in the way. One env var turns it on. Recommended if you expose Jellydash to the internet. I recommend using Tailscale for exposing.

- **Modules.** Jellydash can load drop-in modules that add whole new pages to the dashboard. See [docs/MODULES.md](docs/MODULES.md) if you want to build your own.

## Quick start

You need Docker with the Compose plugin. Pick the database setup you want, grab two files, and you are ready to go.

### MariaDB (default)

The normal setup runs Jellydash and MariaDB together. Choose this if you want the default setup or already use MariaDB.

```bash
mkdir jellydash && cd jellydash
curl -LO https://raw.githubusercontent.com/themartz90/jellydash/main/docker-compose.yml
curl -L -o .env https://raw.githubusercontent.com/themartz90/jellydash/main/.env.example
# edit .env, at minimum: JELLYFIN_URL, JELLYFIN_API_TOKEN, DB_PASS
docker compose up -d
```

### SQLite (optional, lighter setup)

The lighter setup runs only Jellydash and keeps its database in `./sqlite-data/jellydash.sqlite`. Choose this for a small install where you do not want a separate database container.

```bash
mkdir jellydash && cd jellydash
curl -L -o docker-compose.yml https://raw.githubusercontent.com/themartz90/jellydash/main/docker-compose.sqlite.yml
curl -L -o .env https://raw.githubusercontent.com/themartz90/jellydash/main/.env.example
# edit .env, at minimum: JELLYFIN_URL and JELLYFIN_API_TOKEN
docker compose up -d
```

Open `http://your-host:8080` and you are done. Both setups create everything they need automatically, there is nothing to import.

Set `APP_TIMEZONE` in `.env` to your local IANA timezone so History and Statistics use the right date boundaries. Custom `docker run` setups may pass the standard `TZ` variable instead. `APP_TIMEZONE` takes priority when both are present.

Whichever database you choose, the active setup is saved as `docker-compose.yml`. Normal commands, aliases and update scripts work the same way for both.

If you want to use your own MariaDB server or mount modules, copy [docker-compose.override.example.yml](docker-compose.override.example.yml) to `docker-compose.override.yml` and adjust it there.

**For setting up notifications, check the section down below.**

### Updating

For both MariaDB and SQLite:

```bash
docker compose pull && docker compose up -d
```

If you installed SQLite using the first v1.2.0 instructions, you may still have a file named `docker-compose.sqlite.yml`. If there is no `docker-compose.yml` beside it, make SQLite the default once:

```bash
cp docker-compose.sqlite.yml docker-compose.yml
```

If `docker-compose.yml` is your old MariaDB setup, preserve it first:

```bash
cp -n docker-compose.yml docker-compose.mariadb.yml
cmp -s docker-compose.yml docker-compose.mariadb.yml && cp docker-compose.sqlite.yml docker-compose.yml && echo "SQLite is now the default Compose setup"
```

These commands only copy the Compose files. They do not change either database. Afterward, the normal update command above manages SQLite.

### Moving from MariaDB to SQLite

You do not need to migrate. Existing MariaDB installations continue working exactly as before. Follow this only if you choose to switch to SQLite.

Stop Jellydash first so no plays or settings change during the copy. The migration checks every copied value and leaves the original MariaDB data untouched.

First preserve your current MariaDB Compose file for rollback. This will not overwrite an existing backup. Continue only when it prints `MariaDB Compose backup verified`:

```bash
cp -n docker-compose.yml docker-compose.mariadb.yml
cmp -s docker-compose.yml docker-compose.mariadb.yml && echo "MariaDB Compose backup verified"
```

Then run:

```bash
curl -LO https://raw.githubusercontent.com/themartz90/jellydash/main/docker-compose.sqlite.yml
docker compose pull app
docker compose stop app
mkdir -p sqlite-data
docker compose run --rm --no-deps -v "$(pwd)/sqlite-data:/export" app php bin/console.php database:migrate-to-sqlite /export/jellydash.sqlite --confirm-stopped
docker compose down
cp docker-compose.sqlite.yml docker-compose.yml
docker compose up -d
```

From then on, the normal `docker compose` commands manage SQLite. Keep the old MariaDB volume until you have checked the SQLite install and made a backup.

To go back to MariaDB:

```bash
docker compose down
cp docker-compose.mariadb.yml docker-compose.yml
docker compose up -d
```

### Building from source

Prefer building the image yourself instead of pulling it from GHCR?

```bash
git clone https://github.com/themartz90/jellydash.git
cd jellydash
cp .env.example .env
# edit .env as above
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

Updating then means `git pull` and running the same command again. For a source-built SQLite install, replace `docker-compose.yml` with `docker-compose.sqlite.yml`.

## Notifications

Jellydash can ping you when someone starts playing and when a new Jellyseerr request comes in. There are four ways to get the alerts, pick whatever you already use. Every configured channel gets every alert, and a channel is on as soon as its values are filled in `.env`.

Two things that apply to all channels:

- Set `APP_URL=https://your-dashboard.example.com` if you want alerts to link back to your dashboard.
- You can exclude users from triggering alerts (usually yourself) in the Settings page inside the app.

Test your setup any time, it reports each channel separately:

```bash
docker compose exec app php bin/console.php push:test
```

### Telegram

1. Message [@BotFather](https://t.me/BotFather), send `/newbot` and answer its two questions. It gives you a bot token.
2. Open a chat with your new bot and send it any message (bots cannot message you first).
3. Visit `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates` in a browser and find `"chat":{"id":...}` in the response. That number is your chat id! Or you can also use IDbot for your id.

```bash
TELEGRAM_BOT_TOKEN=123456789:your-token
TELEGRAM_CHAT_ID=your-chat-id
```

### Pushover

Your User Key is on the [pushover.net](https://pushover.net) dashboard. Then create an application there (call it Jellydash) to get an API token.

```bash
PUSHOVER_APP_TOKEN=your-app-token
PUSHOVER_USER_KEY=your-user-key
```

### Discord

Server Settings > Integrations > Webhooks > New Webhook, pick a channel, copy the webhook URL. No bot needed.

```bash
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...
```

### Web Push (browser and the installed app)

The no-third-party option: notifications go straight to your browser or the installed PWA. It needs the dashboard served over HTTPS. Generate a keypair once:

```bash
docker compose exec app php bin/console.php push:vapid
```

Paste the two keys into `.env`, restart, then tap the bell in the app and allow notifications. You get a test notification right away so you know it works.

## Optional login

Set this in `.env`:

```bash
AUTH_ENABLED=true
AUTH_ADMIN_USER=admin
AUTH_ADMIN_PASSWORD=pick-a-strong-one
```

The password needs at least 8 characters. The admin user is created automatically on the next start. More users can be added with `docker compose exec app php bin/console.php user:add`.

On the login page, **Keep me signed in** lets that browser restore your login for up to 90 days. The remembered login is renewed when you return and removed when you sign out or change your password.

## Exclusions in Settings or the environment

Open **Settings > Exclusions** to manage these options:

| Option | What it excludes | Environment fallback |
| --- | --- | --- |
| Monitoring | Selected users from Now Playing, History, Statistics, library playback summaries and CSV exports. New plays and history imports for those users are skipped. | `IGNORE_USERS` |
| Notifications | Playback alerts for selected users. Their activity is still recorded unless they are also excluded from monitoring. | `PUSH_IGNORE_USERS` |
| Statistics | Selected libraries from Trending and Most Watched. Other statistics and History remain visible. | `TRENDING_EXCLUDE_LIBRARIES` |

Environment values are comma-separated names, for example `IGNORE_USERS=Admin,Test`. Saved Settings values take priority over the environment, including an empty selection. To change a saved exclusion, use Settings. For Docker environment changes, recreate the app container so it receives the new values.

Monitoring exclusions preserve existing database rows. Removing an exclusion makes that earlier activity visible again; Jellydash cannot reconstruct activity it skipped while the user was excluded. Username matching is exact and case-insensitive. If you rename an account, update its exclusion. See [Monitoring exclusions](docs/MONITORING_EXCLUSIONS.md) for details.

## Exporting History

Use **Export CSV** on the History page to choose a search, user, library and time period before downloading. Jellydash shows the exact number of matching plays, and the export is never limited to the page you are viewing. Its versioned format keeps the playback fields Jellydash needs for a native round-trip import. See [docs/HISTORY_CSV.md](docs/HISTORY_CSV.md) for the format and compatibility details.

To restore that file, open **Settings → Import play history** and choose **Jellydash CSV**. Jellydash previews new and already-present plays before asking you to confirm. Imports are transactional, skip duplicates and never trigger playback notifications.

## Importing Playback Reporting history

Jellydash only records plays from the moment it starts. If you already used Playback Reporting on [Jellyfin](https://github.com/jellyfin/jellyfin-plugin-playbackreporting) or [Emby](https://github.com/faush01/playback_reporting), you can import that history.

The plugin backup is a TSV file with no header row. You can also provide `playback_reporting.db`, or import directly while the plugin API is available.

Use **Import history** on the History page, or open the importer directly from Settings. Drop a TSV backup or `playback_reporting.db` (20 MB max) there. The file type is detected automatically. Jellydash counts the plays first, then asks you to confirm before writing anything. If the plugin is still installed, **Import from server plugin** appears too.

User names are resolved through the connected server's `/Users` API. Media runtime is looked up through `/Items` (`RunTimeTicks`) so the completion bar matches live history; plays are marked finished at 95% of that runtime, same as the poller. If an item no longer exists, runtime stays empty and the play is left unfinished. `PlayDuration` is elapsed session time, not playback position. When an Emby backup includes `PauseDuration`, Jellydash excludes that paused time from the watched total. Dates are kept as the plugin recorded them in the server's local time. Each play is attached to the library that currently owns the item from its file path; if the item is gone, the type is used as a fallback (Movie → Movies, Episode → TV Shows). Imported plays never trigger notifications. Re-importing skips duplicates, but will fill in a missing runtime and replace a generic library label if the server is reachable the second time.

This compatibility only covers Playback Reporting imports from Emby. Jellydash is still built and tested for Jellyfin, so Emby is not a fully supported server yet.

## Good to know

- The CPU and RAM numbers in the sidebar come from the host the container runs on.
- iOS only supports Web Push for apps installed to the home screen, and only on iOS 16.4 or newer. That is an Apple rule, not mine 👀. Telegram and Pushover alerts work on any iPhone.
- Brave blocks Web Push by default. It works after enabling "Use Google services for push messaging" in Brave's privacy settings.
- Edge hides Web Push permission prompts behind a small bell icon in the address bar ("quiet notification requests"). If enabling notifications keeps snapping back to off, allow notifications for the site there, and check that Windows itself allows notifications from Edge.
- Trending and Most Watched can exclude libraries you pick (Settings page). Useful for libraries full of temporary stuff.
- Monitoring exclusions match complete Jellyfin usernames without caring about letter case. They do not change notification exclusions.

## Support Jellydash

Jellydash is free and always will be. If you enjoy it and want to support its development, you can buy me a coffee.

[![Buy me a coffee](https://img.shields.io/badge/Buy_me_a_coffee-FFDD00?logo=buy-me-a-coffee&logoColor=000000)](https://www.buymeacoffee.com/themartz90)

## Credits

The Jellydash mascot is based on a [jellyfish icon](https://www.flaticon.com/free-icon/jellyfish_2977310) by Magnific from [Flaticon](https://www.flaticon.com), modified for this project.

Interface icons come from [Tabler Icons](https://tabler.io/icons), used under its [MIT license](THIRD_PARTY_NOTICES.md).

## License

The code is licensed under [MIT](LICENSE). The mascot original icon is covered by the Flaticon license above, not MIT. Third-party license notices are collected in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
