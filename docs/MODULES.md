# Modules

Jellydash can be extended with drop-in modules: self-contained folders under
`modules/` that add pages, nav entries, API endpoints, and assets without
touching the core. With no modules present the app runs core-only.

In Docker, mount a module into the container:

```yaml
services:
  app:
    volumes:
      - ./my-modules/downloads:/var/www/html/modules/downloads:ro
```

## Layout

```
modules/<name>/
  module.php        required: the manifest (below)
  src/              PHP classes (PSR-4, autoloaded per the manifest)
  templates/        Twig templates, addressed as @<name>/file.twig
  assets/           js / css / images, served via /api/module-asset.php
  api/              endpoint handler(s) run via /api/module.php?m=<name>
```

The folder name must match the manifest `name` and use only `a-z 0-9 -`.

## Manifest (`module.php`)

A PHP file returning an array:

```php
return [
    'name' => 'downloads',                 // must equal the folder name
    'label' => 'Downloads',
    'nav' => [                             // optional sidebar entry
        'label' => 'Downloads',
        'route' => 'downloads',
        'order' => 20,                     // lower = higher in the list
        'icon' => '<path d="..."/>',       // inline SVG inner markup (24x24 viewBox)
    ],
    'routes' => [                          // page route => controller class
        'downloads' => \Vendor\Module\DownloadsController::class,
    ],
    'autoload' => [                        // PSR-4 prefix => dir inside the module
        'Vendor\\Module\\' => 'src/',
    ],
    'api' => 'api/downloads.php',          // handler for /api/module.php?m=downloads
    'styles' => ['downloads.css'],         // loaded globally in the shell <head>
    'scripts' => ['indicator.js'],         // loaded globally before </body>
];
```

Every key except `name` is optional.

## How the pieces behave

- **Controllers** extend `Mk\Framework\Controller` and render namespaced
  templates: `$this->render('@downloads/index', [...])`. Module templates can
  `{% extends "_shell.twig" %}` to get the full dashboard chrome.
- **The API handler** is a plain PHP file. When it runs, the standard bootstrap
  has already happened: env loaded, session handled, and the optional-auth
  guard applied (401 before your code when auth is on and the caller has no
  session). Echo your own response and set your own headers. The shared guard
  proves authentication only. A handler that changes global state must also
  require `Authorization::CAPABILITY_MANAGE_GLOBAL`; account-owned mutations
  must enforce their own ownership boundary.
- **Assets** are streamed by `/api/module-asset.php?m=<name>&f=<file>` with an
  extension allowlist (js, css, svg, png, jpg, webp, woff2). Build URLs with
  `Mk\Framework\Modules::assetUrl()` or hardcode the pattern.
- **Global scripts** load on every page, so return early unless the page you
  care about is present (see the downloads indicator for the pattern).
- **Configuration** comes from environment variables, documented by your
  module. In Docker, pass them through in your compose override.

## Rules of thumb

- Modules are trusted code: they run in-process with the core. There is no
  sandbox and no stability-guaranteed plugin API; pin your module to the core
  version you tested against.
- Keep module CSS namespaced (prefix selectors with your module name) so it
  can be loaded globally without collisions.
