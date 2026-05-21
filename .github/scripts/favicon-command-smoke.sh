#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 /path/to/drupal-site [theme_name]" >&2
  exit 2
fi

site_dir="$1"
theme_name="${2:-}"
drush_bin="${site_dir}/vendor/bin/drush"

if [[ ! -x "$drush_bin" ]]; then
  drush_bin="drush"
fi

cd "$site_dir"

theme_args=()
if [[ "$theme_name" != "" ]]; then
  theme_args=("$theme_name")
fi

"$drush_bin" emulsify_tools:favicon-status "${theme_args[@]}"
"$drush_bin" emulsify_tools:favicon-generate "${theme_args[@]}"
"$drush_bin" emulsify_tools:favicon-status "${theme_args[@]}"
"$drush_bin" emulsify_tools:favicon-reset "${theme_args[@]}"
"$drush_bin" emulsify_tools:favicon-status "${theme_args[@]}"
