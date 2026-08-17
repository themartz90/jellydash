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

![Now Playing](docs/assets/now-playing.png)

| History | Jellyseerr requests |
| --- | --- |
| ![History](docs/assets/history.png) | ![Jellyseerr](docs/assets/jellyseerr.png) |

| Trending and Most Watched | Statistics |
| --- | --- |
| ![Trending](docs/assets/statistics-trending.png) | ![Statistics](docs/assets/statistics-overview.png) |

<p align="center">
  <img src="docs/assets/mobile.jpg" width="300" alt="Jellydash installed as a PWA on Android">
</p>
<p align="center">
  <sub>Installed as an app on Android</sub>
</p>

<details>
<summary>More screenshots</summary>

![Clients](docs/assets/statistics-clients.png)

</details>

## Features

- **Now Playing.** Live cards for every active stream: artwork, user, quality, progress and the playback method (Direct Play, Remux or Transcode, including the reason why). Live TV channels from tuners like Tunarr show up too, with real program progress and a red on-air badge.

- **History.** Every play gets recorded by a background poller, so history is complete even when nobody has the dashboard open. Search it, filter by user or library, and enjoy the poster art. Existing Jellyfin Playback Reporting backups can be imported from Settings.

- **Statistics.** Watch time trends, top users, clients, codecs and transcode reasons. There is a Trending strip for what is hot right now, and all-time Most Watched charts for both shows and movies.

- **Libraries.** An overview of all your libraries with item counts and type breakdowns. New libraries are picked up automatically.

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

## Importing Playback Reporting history

Jellydash only records plays from the moment it starts. If you already used the [Playback Reporting](https://github.com/jellyfin/jellyfin-plugin-playbackreporting) plugin, you can import that history.

The plugin backup is a TSV file (no header row): Dashboard → Playback Reporting → Save Backup Data. You can also point at `playback_reporting.db` in the Jellyfin data folder, or pull live from the plugin API.

From the Settings page, drop a TSV backup or `playback_reporting.db` (20 MB max). The file type is detected automatically. Jellydash counts the plays first, then asks you to confirm before writing anything. If the plugin is still installed, **Import from Jellyfin (API)** appears too. The import dialog is loaded on History and Settings only. On History, with an empty play list, Jellydash also offers that import after the What's new dialog.

User names are resolved via the Jellyfin `/Users` API (admin token). Media runtime is looked up from Jellyfin (`RunTimeTicks`) so the completion bar matches live history; plays are marked finished at 95% of that runtime, same as the poller. If an item no longer exists, runtime stays empty and the play is left unfinished. `PlayDuration` in the backup is session length, not playback position: a resume that ran 20 minutes shows 20 minutes watched, not 90% of the film. Dates are kept as the plugin recorded them (Jellyfin server local time). Each play is attached to the Jellyfin library that currently owns the item (from its file path); if the item is gone, the type is used as a fallback (Movie → Movies, Episode → TV Shows). Imported plays never trigger notifications. Re-importing skips duplicates, but will fill in a missing runtime and replace a generic library label if Jellyfin is reachable the second time.

## Good to know

- The CPU and RAM numbers in the sidebar come from the host the container runs on.
- iOS only supports Web Push for apps installed to the home screen, and only on iOS 16.4 or newer. That is an Apple rule, not mine 👀. Telegram and Pushover alerts work on any iPhone.
- Brave blocks Web Push by default. It works after enabling "Use Google services for push messaging" in Brave's privacy settings.
- Edge hides Web Push permission prompts behind a small bell icon in the address bar ("quiet notification requests"). If enabling notifications keeps snapping back to off, allow notifications for the site there, and check that Windows itself allows notifications from Edge.
- Trending and Most Watched can exclude libraries you pick (Settings page). Useful for libraries full of temporary stuff.

## Credits

The Jellydash mascot is based on a [jellyfish icon](https://www.flaticon.com/free-icon/jellyfish_2977310) by Magnific from [Flaticon](https://www.flaticon.com), modified for this project.

## License

The code is licensed under [MIT](LICENSE). The mascot original icon is covered by the Flaticon license above, not MIT.
