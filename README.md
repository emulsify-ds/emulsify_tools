# Emulsify Tools module

Provides Twig extensions, theme Twig namespaces, child-theme generation, and
favicon deployment commands for Emulsify.

## Compatibility

This module requires Drupal `^11.3 || ^12` and PHP `^8.3`. Drupal 12
compatibility follows Drupal core's PHP requirements and is tested on PHP
`8.5`.

The bundled Drush commands follow the Drush 13+ autowiring pattern, and the
codebase avoids syntax newer than PHP 8.3 so Drupal 11 sites can keep using
their supported PHP 8.3 runtimes.

### Emulsify Tools 2.2 compatibility

| Emulsify Tools | Emulsify Drupal            | Drupal core      | PHP    | Drush | Child-theme generation backend                                               |
| -------------- | -------------------------- | ---------------- | ------ | ----- | ---------------------------------------------------------------------------- |
| `2.2.x`        | `^7.0`                     | `^11.3` or `^12` | `^8.3` | `13+` | Preferred Drupal core Starterkit consuming Emulsify's Whisk Starterkit       |
| `2.2.x`        | `^6.0` (override required) | `^11.3`          | `^8.3` | `13+` | Deprecated legacy Whisk copy-and-customize generator; removed in Tools 3.0.0 |

PHP must also satisfy the installed Drupal core release. The forward-
compatibility CI job tests Drupal 12.x development releases on PHP 8.5 with a
Drush 14.x development release. The Emulsify Drupal 6.x compatibility overlap
is limited to Drupal `^11.3` because that theme line supports Drupal `^10.3` or
`^11`, while Tools 2.2 requires Drupal `^11.3` or `^12`.

Generated favicon deployment, favicon migration, and admin-theme favicon
features require the Emulsify Drupal 7.x companion theme APIs.

Official Emulsify Drupal 6.x releases declare `drupal/emulsify_tools:^1.0` in
both Composer and theme metadata. Using this Tools 2.2 compatibility path
therefore requires an intentional temporary project override or patch for
those constraints. The fallback preserves generation behavior; it does not
relax dependency declarations in the installed Emulsify 6.x parent theme.

### Project scope

- Twig extensions and theme Twig namespaces are provided by this module and do
  not require `drupal/emulsify` as a hard Composer dependency.
- Child-theme generation requires an installed compatible Emulsify Drupal theme
  and consumes the Whisk source supplied by that theme.
- `favicon-generate`, `favicon-status`, and `favicon-reset` require Emulsify
  Drupal 7.x and delegate package operations to its companion favicon APIs.
- `repair-favicon-config` is a separate source-repair workflow. It copies
  missing favicon configuration and schema definitions from Emulsify Drupal
  7.x into existing child-theme source files; it does not deploy packages.

## Usage

### Child-theme generation

Emulsify Tools automatically selects generation behavior from the installed
`whisk` source format. Emulsify Drupal 7.x supplies the Whisk Starterkit,
including `whisk.starterkit.yml`, so generation delegates to Drupal core.
A recognizable legacy `whisk.info.emulsify.yml` source with neither
`whisk.info.yml` nor `whisk.starterkit.yml` uses the Emulsify Drupal 6.x
compatibility workflow instead; no command flag is needed. Incomplete or
missing Starterkit sources fail a focused Emulsify preflight, while malformed
modern metadata is reported by Drupal core. Neither case silently falls back.

For Emulsify Drupal 7.x, these equivalent commands consume the same Whisk
Starterkit and produce the same generated file tree and file contents when
given the same machine name, display name, and description. The parity test
also compares entry types, symlink targets, and regular-file executable
permission bits:

```bash
php web/core/scripts/drupal generate-theme my_theme \
  --name="My Theme" \
  --description="Project theme" \
  --starterkit=whisk \
  --path=themes/custom \
  --no-interaction

drush emulsify_tools:bake my_theme \
  --name="My Theme" \
  --description="Project theme"

drush emulsify my_theme \
  --name="My Theme" \
  --description="Project theme"

drush emulsify_tools:generate-theme my_theme \
  --name="My Theme" \
  --description="Project theme"
```

`emulsify` is an alias of `emulsify_tools:bake`.
`emulsify_tools:generate-theme` is an additional descriptive alias of the same
command. The generated child theme uses `emulsify` as its runtime parent theme
and is created at `web/themes/custom/my_theme` in a standard Composer-based
Drupal project. A human-readable positional value such as
`drush emulsify "My Theme"` remains supported and resolves to `my_theme`.

| Input             | Meaning                                                                      | Default                      |
| ----------------- | ---------------------------------------------------------------------------- | ---------------------------- |
| Positional `name` | Machine name or label used to derive the normalized destination machine name | Required                     |
| `--name`          | Human-readable `name` written to the generated `.info.yml` file              | Original positional value    |
| `--description`   | Human-readable `description` written to the generated `.info.yml` file       | Empty on the Starterkit path |

Positional labels are trimmed, transliterated to ASCII, lowercased, and
normalized with single underscores; for example, `Crème Brûlée Theme` resolves
to `creme_brulee_theme`. `--name` does not override that resolved machine name.

The Emulsify Drupal 6.x compatibility workflow warns that the legacy generation
path is deprecated, will be removed in Emulsify Tools 3.0.0, and should be
replaced by Emulsify Drupal 7.x with Drupal Starterkit generation. Until then,
`--name` is safely applied to legacy generated themes. A nonempty
`--description` replaces the legacy source description; an omitted or empty
description preserves the source default.

#### Missing Whisk troubleshooting

The generation preflight distinguishes these installation problems:

- **Base theme unavailable:** Drupal cannot discover the `emulsify` theme and
  reports `The Emulsify base theme was not found`.
- **Whisk source unavailable:** the discovered base theme does not contain its
  `whisk` directory and reports `The Emulsify Whisk Starterkit was not found`.
- **Starterkit metadata unavailable:** the Whisk directory does not contain
  `whisk.starterkit.yml` and is not a complete Drupal Starterkit source.

Install or update the preferred companion theme and rebuild Drupal's caches:

```bash
composer require "drupal/emulsify:^7"
drush cr
```

Then confirm the installed Emulsify theme contains
`whisk/whisk.starterkit.yml`. Errors from a present but malformed modern Whisk
Starterkit remain Drupal core output and never trigger the deprecated legacy
fallback.

Generated favicon deployment for Emulsify Drupal 7.x companion themes:

`drush emulsify_tools:favicon-generate [theme_name]`

`drush emulsify_tools:favicon-status [theme_name]`

`drush emulsify_tools:favicon-reset [theme_name]`

Child-theme favicon source repair, separate from package deployment:

`drush emulsify_tools:repair-favicon-config`

`drush emulsify_tools:repair-favicon-config [theme_machine_name]`

### Generated Favicon Deployment

Emulsify Drupal 7.x owns favicon theme settings, config defaults and schema,
admin preview UI, frontend head tag attachment, portable SVG source storage, and
the generated asset references stored in `<theme>.settings`.

Emulsify Tools 2.x owns Drush-facing deployment operations for that workflow.
Configure the favicon in the Emulsify Drupal theme settings form, export config,
and run the generate command after config import or deploy so environment-local
package files exist before traffic reaches the site.

Emulsify Drupal page requests do not generate missing favicon package files.
After config import, `emulsify_tools:favicon-generate` is the supported
deployment path for recreating packages from saved portable SVG config.

The favicon deployment commands delegate generation, status, and reset behavior
to the Emulsify Drupal 7.x companion favicon manager APIs instead of duplicating
package logic in this module.

The optional admin-theme favicon toggle in this module only reuses an already
generated Emulsify package on admin routes. It does not replace the Emulsify
Drupal theme settings UI or frontend head-tag attachment.

#### Deploy/config-import workflow

1. Configure and save favicon settings in the Emulsify Drupal theme settings
   form for `emulsify` or an Emulsify child theme.
2. Export and deploy/import configuration as usual.
3. Run `drush emulsify_tools:favicon-generate my_theme` after config import so
   the environment-local generated package exists before page requests need it.
4. Run `drush emulsify_tools:favicon-status my_theme` in deployment diagnostics
   to confirm dependencies, package state, and portable SVG source state.

#### Command examples

```bash
drush emulsify_tools:favicon-generate my_theme
drush emulsify_tools:favicon-status my_theme
drush emulsify_tools:favicon-reset my_theme
```

Omit `my_theme` to target the configured default frontend theme. The target must
be `emulsify` or an Emulsify child theme.

`emulsify_tools:favicon-generate` generates or refreshes the package from the
saved Emulsify Drupal theme settings. Use it in deployment hooks and
post-config-import automation.

`emulsify_tools:favicon-status` reports whether generation is enabled, whether
the package exists, whether GD and Imagick are available, and whether the
portable SVG source is available for regeneration.

`emulsify_tools:favicon-reset` removes generated package metadata and assets and
restores the default theme favicon behavior. Configure and save the Emulsify
Drupal theme settings form again, or rerun `emulsify_tools:favicon-generate`
after config import, to recreate the package.

### Twig Namespaces

Emulsify themes can register Symfony-style Twig namespaces in their `.info.yml`
file using the same `components.namespaces` structure supported by the
Components module:

```yaml
components:
  namespaces:
    atoms: components/01-atoms
    molecules:
      - components/02-molecules
      - src/components/molecules
    vendor_components: /../vendor/acme/components
```

Relative paths are resolved from the theme directory. Paths starting with `/`
are resolved from the Drupal app root. Namespaces are searched in this order:

1. Active theme
2. Active theme base themes
3. Default frontend theme, if the active theme is different

Templates can then be referenced with standard Twig namespace syntax such as
`@atoms/button/button.twig`. Nested component templates are also registered
by basename, so `@atoms/button.twig` will resolve when the file is uniquely
named within the namespace.

### BEM Twig Extension

The `bem()` Twig function builds BEM class names and returns them in a form that can be printed into Drupal template attributes.

#### Simple block name (required argument):

`<h1 {{ bem('title') }}>`

This creates:

`<h1 class="title">`

#### Block with modifiers (optional array allowing multiple modifiers):

`<h1 {{ bem('title', ['small', 'red']) }}>`

This creates:

`<h1 class="title title--small title--red">`

#### Element with modifiers and blockname (optional):

`<h1 {{ bem('title', ['small', 'red'], 'card') }}>`

This creates:

`<h1 class="card__title card__title--small card__title--red">`

#### Element with blockname, but no modifiers (optional):

`<h1 {{ bem('title', '', 'card') }}>`

This creates:

`<h1 class="card__title">`

#### Element with modifiers, blockname and extra classes (optional - in case you need non-BEM classes):

`<h1 {{ bem('title', ['small', 'red'], 'card', ['js-click', 'something-else']) }}>`

This creates:

`<h1 class="card__title card__title--small card__title--red js-click something-else">`

#### Element with extra classes only (optional):

`<h1 {{ bem('title', '', '', ['js-click']) }}>`

This creates:

`<h1 class="title js-click">`

### Add Attributes Twig Extension

The `add_attributes()` Twig function merges additional attributes with Drupal's template-level attributes and prevents those attributes from trickling into child includes.

```
{% set additional_attributes = {
  "class": ["foo", "bar"],
  "baz": ["foobar", "goobar"],
  "foobaz": "goobaz",
} %}

<div {{ add_attributes(additional_attributes) }}></div>
```

Can also be used with the BEM Function:

```
{% set additional_attributes = {
  "class": bem("foo", ["bar", "baz"], "foobar"),
} %}

<div {{ add_attributes(additional_attributes) }}></div>
```

### Switch Case Twig Extension

This adds the ability to do a `switch/case` function from within Twig templates. To use:

```twig
{% switch content.field_name.0 %}
    {% case "text" %}
      <p>This appears if the field name value is set to "text"</p>
    {% case "image" %}
      <p>This appears if the field name value is set to "image"</p>
    {% default %}
      <p>The field text did not match any case.</p>
{% endswitch %}
```

Note that the `switch`, `endswitch`, and `case` tags are required and the `default` is optional.

## Upgrading Emulsify Drupal 6.x to 7.x

### Child-theme generation

Emulsify Tools 2.2 retains the Emulsify Drupal 6.x generator only as a
deprecated compatibility path and emits a warning whenever it is used. Upgrade
the Emulsify parent theme to 7.x before Emulsify Tools 3.0.0 removes that path.
Once the installed `whisk` source contains Starterkit metadata, the same
`drush emulsify` and `drush emulsify_tools:bake` commands and the descriptive
`drush emulsify_tools:generate-theme` alias automatically use Drupal core; no
command configuration change is required.
Existing generated child themes are not rewritten. Generation continues to
protect an existing destination, so use a new machine name unless you have
intentionally removed the old generated directory.

Remove any temporary Emulsify 6.x dependency override after upgrading the
parent theme and return the project to the normal Emulsify 7.x/Tools 2.x
constraints.

### Favicon migration

Upgrading from Emulsify 6.x to 7.x introduces a new generated favicon workflow.
Instead of relying only on legacy theme-level favicon settings, Emulsify 7.x
stores a portable SVG source and generated package metadata in theme settings so
favicon packages can be regenerated consistently across environments.

#### What changes

- Active theme settings gain new favicon keys such as `favicon_source_svg`,
  `favicon_source_filename`, platform-specific color and padding settings, and
  generated package metadata fields like `favicon_package_hash`,
  `favicon_package_path`, and `favicon_package_generated_at`.
- Installed Emulsify-based themes can be migrated in place by running Drupal
  database updates. This module provides a post update that backfills missing
  favicon keys in active `<theme>.settings` config and, when possible, stores a
  sanitized portable SVG source from the existing managed favicon file.
- Older generated child themes may still be missing the source files that define
  those settings for fresh installs and future config exports.

Run `drush updatedb` after upgrading the module so the installed theme settings
receive the new defaults before exporting configuration.

After exporting or importing those settings, use
`drush emulsify_tools:favicon-generate [theme_name]` to recreate generated
package files in each environment. Use
`drush emulsify_tools:favicon-status [theme_name]` for deployment diagnostics
and `drush emulsify_tools:favicon-reset [theme_name]` when you intentionally
want to remove generated package state.

### Child-theme source repair

Run the repair command in the Drupal site root to update older Emulsify-based
child theme codebases:

`drush emulsify_tools:repair-favicon-config`

To target a single child theme:

`drush emulsify_tools:repair-favicon-config my_child_theme`

The command scans Emulsify-based child themes in the current codebase and
backfills missing favicon entries in:

- `config/install/<theme>.settings.yml`
- `config/schema/<theme>.schema.yml`

Existing values are preserved. Only missing or `NULL` favicon keys and schema
definitions are filled in. Review and commit those child theme source-file
changes after running the command.

## Development

---

### Requires

- [PHP 8.3+](https://www.php.net/)
- [Composer 2](https://getcomposer.org/)
- [Node.js 20.11+](https://nodejs.org/)

### Initial Setup

1. Run `composer install` to install the PHPUnit and Drupal development stack.
2. Run `npm install` to install the lightweight release and commit tooling.

### Validation

- `npm run lint`
- `composer test`
- `composer analyse`
- `bash -n .github/scripts/generation-smoke.sh`
- `shellcheck .github/scripts/generation-smoke.sh`
- `bash .github/scripts/favicon-command-smoke.sh /path/to/drupal-site [theme_name]`
  for a prepared integration fixture with Emulsify Drupal 7.x, Emulsify Tools
  2.x, and favicon source config.

### Emulsify Drupal 7.x Generation Smoke Test

The required **Generation Smoke / Real Drupal generation** CI matrix runs this
script with Drupal 11.3, PHP 8.3, and Drush 13, plus the claimed forward
compatibility combination of Drupal 12.x-dev, PHP 8.5, and Drush 14.x-dev.
Both jobs install Emulsify Drupal 7.x and this checkout as a local Composer
package.

Run the same integration test locally from the repository root:

```bash
bash .github/scripts/generation-smoke.sh
```

With PHP 8.5 active, reproduce the forward-compatibility job with:

```bash
DRUPAL_VERSION=dev-main DRUSH_VERSION=14.x-dev@dev \
  bash .github/scripts/generation-smoke.sh
```

The script creates a disposable SQLite site, verifies discovery of
`emulsify_tools:bake`, `emulsify`, `emulsify_tools:generate-theme`, and
`emulsify_tools:repair-favicon-config`, checks `--name` and `--description`
parsing, and verifies that Drupal core and Drush produce the same generated file
tree and file contents. The manifest comparison also records relative paths,
entry types, SHA-256 regular-file hashes, symlink targets, and regular-file
executable permission bits. This is structural and content parity, not complete
filesystem metadata identity: timestamps, ownership, and other permission bits
are not compared.

The smoke test also parses generated YAML to verify the requested name and
description, the Emulsify base theme, and the Emulsify Tools dependency. It
checks human-readable positional-name normalization, full-tree preservation on
an existing-destination failure, missing-source and missing-metadata diagnostics
with restoration, Starterkit-only file removal, unresolved placeholder removal,
and generated theme enablement. It intentionally exercises only the preferred
7.x Starterkit path; legacy 6.x compatibility is covered by the PHPUnit fixtures.

Local requirements are Bash, Composer 2, a compatible PHP CLI with `pdo_sqlite`,
and standard Unix utilities (`tar`, `diff`, and `grep`). Composer
installs Drupal, Drush, and Emulsify in the disposable fixture; no pre-existing
Drupal site or global Drush installation is required. ShellCheck is required
only to run the same script lint used by CI.

Optional environment variables:

```
TMPDIR=/tmp
FIXTURE_DIR=/tmp/emulsify-tools-generation-smoke
DRUPAL_VERSION=11.3.*
EMULSIFY_VERSION=^7
TOOLS_VERSION=2.2.x-dev
DRUSH_VERSION=^13
THEME_NAME=watson
THEME_LABEL="Watson Theme"
THEME_DESCRIPTION="Project theme: Starterkit and Drush parity."
DB_URL=sqlite://sites/default/files/.ht.sqlite
KEEP_FIXTURE=1
```

`FIXTURE_DIR` defaults to a directory beneath `TMPDIR`, or beneath `/tmp` when
`TMPDIR` is unset. Set `KEEP_FIXTURE=1` to retain the disposable Drupal site for
inspection; any temporarily renamed Whisk source path is still restored.

### Committing Changes

This repository uses [Conventional Commits](https://www.conventionalcommits.org/)
so semantic-release can determine the next version automatically.

1. Stage your changes, ensuring they encompass exactly what you wish to change, no more.
2. Commit using a conventional message such as `fix: repair favicon config sync`.
3. Run the validation commands above before opening a pull request.

## Release

---

There's a two-step process to publish a new release to [the project page](https://www.drupal.org/project/emulsify_tools) on Drupal.org.

1. Cut a release on GitHub
2. Select the generated tag for the release on Drupal.org, and set it as the "recommended" release.

### Creating a release on GitHub

- Merge the release-ready changes into `main`.
- The [semantic-release workflow](https://github.com/emulsify-ds/emulsify_tools/actions)
  will calculate the next version from the merged commit messages, update
  `CHANGELOG.md`, create a `[skip ci]` release commit, create the GitHub
  release, and push the release commit and new tag to Drupal.org.
- Release tags use the semantic version only, such as `2.1.1`, with no `v`
  prefix.
- When the workflow completes, confirm the new version appears on the
  [GitHub Releases page](https://github.com/emulsify-ds/emulsify_tools/releases).

### Publishing the release to Drupal.org

- Go to the [Releases tab for the Emulsify Tools project](https://www.drupal.org/node/3094752/edit/releases) on drupal.org. (You'll need to be a maintainer to access this page.)
- Click "Add new release"
- Select the tag for the latest release and click Next
- Copy the release notes from the GitHub releases page, and reformat them according to the wysiwyg options
- Select the appropriate release type(s) (Bug fixes/New features).
- Click Save
- Back on the Releases tab, select the new release as the "Supported" and "Recommended" release. Deselect any others.
- Save, and go to the projects main page to verify that the new release is displayed in the green box so that future builds will pull it by default.
