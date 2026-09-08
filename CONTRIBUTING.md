# Contributing

Thanks for wanting to help with Jellydash 🙂👀

For a larger feature, please open an issue first so we can agree on the shape of it before either of us spends too much time. Draft pull requests are welcome when an idea already has working code.

Please keep each pull request focused on one change. Jellydash has public installations now, so updates need to preserve existing data, environment variables and Docker setups. Optional integrations must stay optional, and the core app must keep working with no modules installed.

MariaDB and SQLite are both supported. Any database query, schema change, background write, command or migration needs to work with both, including fresh installs and existing populated databases.

Before opening a pull request, run:

```bash
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/phpunit --no-coverage
```

The test suite uses its own temporary SQLite database unless you explicitly set both `DB_DRIVER` and `DB_NAME`. It does not use the database or external service URLs from your `.env` file.

GitHub Actions will run the test suite against MariaDB and SQLite, then build and boot the Docker image with SQLite. Please include a short explanation of what changed and how you tested it.

Do not include API tokens, `.env` files, or other private files in a pull request.
