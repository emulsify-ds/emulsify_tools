# Emulsify Tools module

This module provides Emulsify Twig extensions, theme-defined Twig namespaces, child theme generation Drush commands, and deployment commands for Emulsify Drupal favicon packages.

## Compatibility

This module targets Drupal `11.3+`, includes Drupal 12 forward compatibility, and requires PHP `8.4+`. Drupal core development branch coverage is experimental until Drupal 12 beta or stable releases are available.

The bundled Drush commands follow the Drush 13+ autowiring pattern, and the
codebase now uses PHP 8.4-only syntax where it improves readability.

### Companion theme pairing

- `emulsify_tools` `^2.0` is intended to pair with Emulsify Drupal `^7.0`.
- The Twig helpers and child theme generator remain broadly useful on their own,
  but the favicon migration and admin-theme favicon features expect the
  Emulsify 7.x companion theme APIs to be present.

## Usage

---

### Drush

Child theme generation:

`drush emulsify_tools:bake [theme_name]`

`drush emulsify [theme_name]`

Generated favicon deployment:

`drush emulsify_tools:favicon-generate [theme_name]`

`drush emulsify_tools:favicon-status [theme_name]`

`drush emulsify_tools:favicon-reset [theme_name]`

Child theme source repair:

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

The favicon commands delegate generation, status, and reset behavior to the
Emulsify Drupal favicon manager instead of duplicating package logic in this
module.

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

Twig function that inserts static classes into Pattern Lab and adds them to the Attributes object in Drupal

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

Twig function that merges with template level attributes in Drupal and prevents them from trickling down into includes.

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

## Updating 6.x to 7.x

Upgrading from Emulsify 6.x to 7.x introduces a new generated favicon workflow.
Instead of relying only on legacy theme-level favicon settings, Emulsify 7.x
stores a portable SVG source and generated package metadata in theme settings so
favicon packages can be regenerated consistently across environments.

### What changes

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

### Child Theme Source Repair

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

- [PHP 8.4+](https://www.php.net/)
- [Composer 2](https://getcomposer.org/)
- [Node.js 20.11+](https://nodejs.org/)

### Initial Setup

1. Run `composer install` to install the PHPUnit and Drupal development stack.
2. Run `npm install` to install the lightweight release and commit tooling.

### Validation

- `npm run lint`
- `composer test:unit`
- `bash .github/scripts/favicon-command-smoke.sh /path/to/drupal-site [theme_name]`
  for a prepared integration fixture with Emulsify Drupal 7.x, Emulsify Tools
  2.x, and favicon source config.

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
  will calculate the next version from the merged commit messages, create the
  GitHub release, and push the new tag to Drupal.org.
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
