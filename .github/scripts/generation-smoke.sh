#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
FIXTURE_DIR="${FIXTURE_DIR:-${TMPDIR:-/tmp}/emulsify-tools-generation-smoke}"
DRUPAL_VERSION="${DRUPAL_VERSION:-11.3.*}"
EMULSIFY_VERSION="${EMULSIFY_VERSION:-^7}"
TOOLS_VERSION="${TOOLS_VERSION:-2.2.x-dev}"
EXPECTED_TOOLS_DEPENDENCY="${EXPECTED_TOOLS_DEPENDENCY:-}"
DRUSH_VERSION="${DRUSH_VERSION:-^13}"
THEME_NAME="${THEME_NAME:-watson}"
THEME_LABEL="${THEME_LABEL:-Watson Theme}"
THEME_DESCRIPTION="${THEME_DESCRIPTION:-Project theme: Starterkit and Drush parity.}"
LEGACY_DEPRECATION_TEXT="legacy Emulsify Drupal 6.x generation path is deprecated"
MISSING_STARTERKIT_TEXT="The Emulsify Whisk Starterkit was not found. Install a compatible Emulsify Drupal 7.x release before generating a child theme."
MISSING_STARTERKIT_CONFIG_TEXT="The Emulsify Whisk Starterkit metadata file whisk.starterkit.yml was not found. Install a compatible Emulsify Drupal 7.x release before generating a child theme."
DB_URL="${DB_URL:-sqlite://sites/default/files/.ht.sqlite}"
KEEP_FIXTURE="${KEEP_FIXTURE:-0}"
LOCAL_PACKAGE_DIR="${FIXTURE_DIR}/local/emulsify_tools"
MANIFEST_DIR="${FIXTURE_DIR}/tree-manifests"
WHISK_SOURCE_PATH=""
WHISK_SOURCE_BACKUP=""

cleanup_fixture() {
  if [[ -d "$FIXTURE_DIR" ]]; then
    chmod -R u+w "$FIXTURE_DIR" 2>/dev/null || true
    rm -rf "$FIXTURE_DIR"
  fi
}

restore_whisk_source() {
  if [[ -z "$WHISK_SOURCE_BACKUP" ]]; then
    return 0
  fi

  if [[ -e "$WHISK_SOURCE_BACKUP" || -L "$WHISK_SOURCE_BACKUP" ]]; then
    if ! mv -- "$WHISK_SOURCE_BACKUP" "$WHISK_SOURCE_PATH"; then
      printf '\nERROR: Unable to restore Whisk source path: %s\n' "$WHISK_SOURCE_PATH" >&2
      return 1
    fi
  elif [[ ! -e "$WHISK_SOURCE_PATH" && ! -L "$WHISK_SOURCE_PATH" ]]; then
    printf '\nERROR: Whisk source and backup are both missing: %s\n' "$WHISK_SOURCE_PATH" >&2
    return 1
  fi

  WHISK_SOURCE_PATH=""
  WHISK_SOURCE_BACKUP=""
}

finish() {
  local status=$?

  trap - EXIT
  restore_whisk_source || status=1
  if [[ "$KEEP_FIXTURE" != "1" ]]; then
    cleanup_fixture || status=1
  fi
  exit "$status"
}

log() {
  printf '\n==> %s\n' "$*"
}

fail() {
  printf '\nERROR: %s\n' "$*" >&2
  exit 1
}

assert_dir() {
  local dir="$1"

  [[ -d "$dir" ]] || fail "Expected directory missing: ${dir}"
}

assert_file() {
  local file="$1"

  [[ -f "$file" ]] || fail "Expected file missing: ${file}"
}

assert_not_exists() {
  local path="$1"

  [[ ! -e "$path" && ! -L "$path" ]] || fail "Unexpected path exists: ${path}"
}

assert_command_fails_with() {
  local expected="$1"
  shift
  local output
  local status

  set +e
  output="$("$@" 2>&1)"
  status=$?
  set -e

  [[ "$status" -ne 0 ]] || fail "Expected command to fail: $*"
  grep -Fq "$expected" <<<"$output" || fail "Expected failed command output to contain: ${expected}
Command output:
${output}"
}

write_tree_manifest() {
  local root="$1"
  local output="$2"

  assert_dir "$root"
  mkdir -p "$(dirname "$output")"

  # Dollar signs below are PHP variables.
  # shellcheck disable=SC2016
  php -r '
$root = realpath($argv[1]);
if ($root === false) {
  fwrite(STDERR, "Unable to resolve manifest root: {$argv[1]}\n");
  exit(1);
}

$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
  RecursiveIteratorIterator::SELF_FIRST,
);
$entries = [];
foreach ($iterator as $item) {
  $path = $item->getPathname();
  $entry = [
    "path" => str_replace(DIRECTORY_SEPARATOR, "/", substr($path, strlen($root) + 1)),
  ];

  if ($item->isLink()) {
    $target = readlink($path);
    if ($target === false) {
      fwrite(STDERR, "Unable to read symlink target: {$path}\n");
      exit(1);
    }
    $entry["type"] = "symlink";
    $entry["target"] = $target;
  }
  elseif ($item->isDir()) {
    $entry["type"] = "directory";
  }
  elseif ($item->isFile()) {
    $hash = hash_file("sha256", $path);
    if ($hash === false) {
      fwrite(STDERR, "Unable to hash file: {$path}\n");
      exit(1);
    }
    $entry["type"] = "file";
    $entry["sha256"] = $hash;
    $entry["executable_mode"] = sprintf("%03o", $item->getPerms() & 0111);
  }
  else {
    $entry["type"] = filetype($path) ?: "unknown";
  }

  $entries[] = $entry;
}

usort($entries, static fn (array $left, array $right): int => $left["path"] <=> $right["path"]);
$lines = array_map(
  static fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
  $entries,
);
if (file_put_contents($argv[2], implode(PHP_EOL, $lines) . PHP_EOL) === false) {
  fwrite(STDERR, "Unable to write manifest: {$argv[2]}\n");
  exit(1);
}
' "$root" "$output" || fail "Unable to create tree manifest for ${root}"
}

assert_manifests_equal() {
  local expected="$1"
  local actual="$2"
  local description="$3"

  if ! diff -u "$expected" "$actual"; then
    fail "Tree manifest mismatch: ${description}"
  fi
}

assert_generated_metadata() {
  local info_file="$1"
  local expected_tools_dependency="$EXPECTED_TOOLS_DEPENDENCY"

  if [[ -z "$expected_tools_dependency" ]]; then
    if [[ "$TOOLS_VERSION" =~ ^v?([0-9]+)\.([0-9]+)(\.|$) ]]; then
      expected_tools_dependency="drupal:emulsify_tools (^${BASH_REMATCH[1]}.${BASH_REMATCH[2]})"
    else
      fail "Unable to derive an expected dependency from TOOLS_VERSION=${TOOLS_VERSION}. Set EXPECTED_TOOLS_DEPENDENCY explicitly."
    fi
  fi

  # Dollar signs below are PHP variables.
  # shellcheck disable=SC2016
  php -r '
require $argv[1];

try {
  $info = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($argv[2]));
}
catch (Throwable $exception) {
  fwrite(STDERR, "Unable to parse {$argv[2]}: {$exception->getMessage()}\n");
  exit(1);
}

if (!is_array($info)) {
  fwrite(STDERR, "Generated info metadata is not a YAML mapping.\n");
  exit(1);
}

$expected = [
  "name" => $argv[3],
  "description" => $argv[4],
  "base theme" => "emulsify",
];
foreach ($expected as $key => $value) {
  $actual = $info[$key] ?? null;
  if ($actual !== $value) {
    fwrite(STDERR, sprintf(
      "Metadata mismatch for %s: expected %s, got %s\n",
      $key,
      var_export($value, true),
      var_export($actual, true),
    ));
    exit(1);
  }
}

$dependencies = $info["dependencies"] ?? null;
if (!is_array($dependencies) || !in_array($argv[5], $dependencies, true)) {
  fwrite(STDERR, sprintf(
    "Generated dependencies do not contain %s: %s\n",
    var_export($argv[5], true),
    var_export($dependencies, true),
  ));
  exit(1);
}
' \
    "${FIXTURE_DIR}/vendor/autoload.php" \
    "$info_file" \
    "$THEME_LABEL" \
    "$THEME_DESCRIPTION" \
    "$expected_tools_dependency" || fail "Generated theme metadata validation failed."
}

command -v composer >/dev/null || fail "composer is required."
command -v php >/dev/null || fail "php is required."
if [[ "$DB_URL" == sqlite://* ]]; then
  php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' || fail "The pdo_sqlite PHP extension is required for DB_URL=${DB_URL}."
fi

if [[ -x "$REPO_ROOT/vendor/bin/yaml-lint" ]]; then
  log "Linting module YAML files"
  "$REPO_ROOT/vendor/bin/yaml-lint" --parse-tags \
    "$REPO_ROOT/emulsify_tools.info.yml" \
    "$REPO_ROOT/emulsify_tools.services.yml"
fi

trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

log "Creating disposable Drupal fixture at ${FIXTURE_DIR}"
cleanup_fixture
composer create-project "drupal/recommended-project:${DRUPAL_VERSION}" "$FIXTURE_DIR" \
  --no-dev \
  --no-interaction \
  --no-progress

log "Copying this checkout as Drupal.org package drupal/emulsify_tools ${TOOLS_VERSION}"
mkdir -p "$LOCAL_PACKAGE_DIR"
tar \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='.DS_Store' \
  -C "$REPO_ROOT" \
  -cf - . | tar -C "$LOCAL_PACKAGE_DIR" -xf -

# Dollar signs below are PHP variables.
# shellcheck disable=SC2016
php -r '
$file = $argv[1];
$json = json_decode(file_get_contents($file), true);
$json["name"] = "drupal/emulsify_tools";
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
' "$LOCAL_PACKAGE_DIR/composer.json"

cd "$FIXTURE_DIR"

# Dollar signs below are PHP variables.
# shellcheck disable=SC2016
repository_json="$(php -r '
echo json_encode([
  "type" => "path",
  "url" => $argv[1],
  "options" => [
    "symlink" => false,
    "versions" => [
      "drupal/emulsify_tools" => $argv[2],
    ],
  ],
], JSON_UNESCAPED_SLASHES);
' "$LOCAL_PACKAGE_DIR" "$TOOLS_VERSION")"

composer config --json repositories.local_emulsify_tools "$repository_json"
composer config prefer-stable true
# This disposable compatibility fixture should test generation, not audit policy.
composer config audit.block-insecure false

log "Installing Emulsify Drupal ${EMULSIFY_VERSION}, Emulsify Tools ${TOOLS_VERSION}, and Drush"
composer require \
  "drupal/emulsify:${EMULSIFY_VERSION}" \
  "drupal/emulsify_tools:${TOOLS_VERSION}" \
  "drush/drush:${DRUSH_VERSION}" \
  --with-all-dependencies \
  --update-no-dev \
  --no-interaction \
  --no-progress

log "Installing Drupal fixture"
mkdir -p web/sites/default/files
vendor/bin/drush site:install minimal \
  --db-url="$DB_URL" \
  --site-name='Emulsify Tools generation smoke' \
  --account-name=admin \
  --account-pass=admin \
  -y

log "Enabling Emulsify Tools and the Emulsify parent theme"
vendor/bin/drush pm:enable emulsify_tools -y
vendor/bin/drush theme:enable emulsify -y
vendor/bin/drush cr -y

log "Checking that the public Drush commands are discoverable"
vendor/bin/drush list --raw | grep -Fq 'emulsify_tools:bake' || fail "Drush command emulsify_tools:bake was not discovered."
vendor/bin/drush list --raw | grep -Fq 'emulsify_tools:repair-favicon-config' || fail "Drush command emulsify_tools:repair-favicon-config was not discovered."
vendor/bin/drush help emulsify >/dev/null || fail "Drush help for emulsify failed."
vendor/bin/drush help emulsify_tools:bake >/dev/null || fail "Drush help for emulsify_tools:bake failed."
vendor/bin/drush help emulsify_tools:generate-theme >/dev/null || fail "Drush help for emulsify_tools:generate-theme failed."
vendor/bin/drush help emulsify_tools:repair-favicon-config >/dev/null || fail "Drush help for emulsify_tools:repair-favicon-config failed."

if [[ -x vendor/bin/dr ]]; then
  drupal_cli=(vendor/bin/dr)
else
  drupal_cli=(php web/core/scripts/drupal)
fi

log "Generating ${THEME_NAME} with Drupal core Starterkit"
"${drupal_cli[@]}" generate-theme "$THEME_NAME" \
  --name="$THEME_LABEL" \
  --description="$THEME_DESCRIPTION" \
  --starterkit=whisk \
  --path=themes/custom \
  --no-interaction

theme_dir="web/themes/custom/${THEME_NAME}"
info_file="${theme_dir}/${THEME_NAME}.info.yml"
core_theme_dir="${FIXTURE_DIR}/core-generated/${THEME_NAME}"
mkdir -p "$(dirname "$core_theme_dir")"
mv "$theme_dir" "$core_theme_dir"

log "Generating ${THEME_NAME} with drush emulsify"
if ! drush_generation_output="$(
  vendor/bin/drush emulsify "$THEME_NAME" \
    --name="$THEME_LABEL" \
    --description="$THEME_DESCRIPTION" 2>&1
)"; then
  printf '%s\n' "$drush_generation_output"
  fail "Drush child-theme generation failed."
fi
printf '%s\n' "$drush_generation_output"
if grep -Fq "$LEGACY_DEPRECATION_TEXT" <<<"$drush_generation_output"; then
  fail "Emulsify Drupal 7.x generation unexpectedly used the deprecated legacy workflow."
fi

log "Comparing Drupal core and Drush generated-tree manifests"
core_manifest="${MANIFEST_DIR}/core-generated.jsonl"
drush_manifest="${MANIFEST_DIR}/drush-generated.jsonl"
write_tree_manifest "$core_theme_dir" "$core_manifest"
write_tree_manifest "$theme_dir" "$drush_manifest"
assert_manifests_equal "$core_manifest" "$drush_manifest" "Drupal core and Drush generated different child themes."

log "Validating generated child theme files"
assert_dir "$theme_dir"
assert_file "$info_file"
assert_generated_metadata "$info_file"
assert_file "${theme_dir}/config/install/${THEME_NAME}.settings.yml"
assert_file "${theme_dir}/config/schema/${THEME_NAME}.schema.yml"
assert_file "${theme_dir}/project.emulsify.json"
assert_not_exists "${theme_dir}/whisk.info.emulsify.yml"
assert_not_exists "${theme_dir}/whisk.starterkit.yml"
assert_not_exists "${theme_dir}/src/StarterKit.php"
assert_not_exists "${theme_dir}/config/install/whisk.settings.yml"
assert_not_exists "${theme_dir}/config/schema/whisk.schema.yml"
if grep -R -Fq '%%EMULSIFY_' "$theme_dir"; then
  fail "Generated child theme contains unresolved documentation placeholders."
fi

log "Confirming a human-readable positional theme name is normalized"
human_theme_label="Crème Brûlée Theme"
human_theme_name="creme_brulee_theme"
if [[ "$THEME_NAME" == "$human_theme_name" ]]; then
  human_theme_label="Another Crème Brûlée Theme"
  human_theme_name="another_creme_brulee_theme"
fi
vendor/bin/drush emulsify_tools:generate-theme "$human_theme_label"
assert_dir "web/themes/custom/${human_theme_name}"
assert_file "web/themes/custom/${human_theme_name}/${human_theme_name}.info.yml"

log "Confirming existing destination fails safely through drush emulsify_tools:bake"
guard_manifest_before="${MANIFEST_DIR}/existing-destination-before.jsonl"
guard_manifest_after="${MANIFEST_DIR}/existing-destination-after.jsonl"
write_tree_manifest "$theme_dir" "$guard_manifest_before"
assert_command_fails_with \
  "Theme could not be generated because the destination directory" \
  vendor/bin/drush emulsify_tools:bake "$THEME_NAME"
write_tree_manifest "$theme_dir" "$guard_manifest_after"
assert_manifests_equal "$guard_manifest_before" "$guard_manifest_after" "Existing destination was modified."

log "Confirming missing Whisk source fails clearly"
emulsify_theme_path="$(vendor/bin/drush php:eval 'echo DRUPAL_ROOT . "/" . \Drupal::service("extension.list.theme")->getPath("emulsify");')"
whisk_dir="${emulsify_theme_path}/whisk"
assert_dir "$whisk_dir"
WHISK_SOURCE_PATH="$whisk_dir"
WHISK_SOURCE_BACKUP="${WHISK_SOURCE_PATH}.generation-smoke-missing"
mv -- "$WHISK_SOURCE_PATH" "$WHISK_SOURCE_BACKUP"
missing_source_theme="missing_source_theme"
if [[ "$THEME_NAME" == "$missing_source_theme" ]]; then
  missing_source_theme="missing_whisk_source_theme"
fi
assert_command_fails_with \
  "$MISSING_STARTERKIT_TEXT" \
  vendor/bin/drush emulsify_tools:bake "$missing_source_theme"
restore_whisk_source || fail "Unable to restore the Whisk source after the missing-source check."
assert_not_exists "web/themes/custom/${missing_source_theme}"

log "Confirming missing Whisk Starterkit metadata fails clearly"
WHISK_SOURCE_PATH="${whisk_dir}/whisk.starterkit.yml"
WHISK_SOURCE_BACKUP="${WHISK_SOURCE_PATH}.generation-smoke-missing"
mv -- "$WHISK_SOURCE_PATH" "$WHISK_SOURCE_BACKUP"
missing_metadata_theme="missing_starterkit_metadata_theme"
if [[ "$THEME_NAME" == "$missing_metadata_theme" ]]; then
  missing_metadata_theme="missing_whisk_metadata_theme"
fi
assert_command_fails_with \
  "$MISSING_STARTERKIT_CONFIG_TEXT" \
  vendor/bin/drush emulsify_tools:bake "$missing_metadata_theme"
restore_whisk_source || fail "Unable to restore the Whisk source after the missing-metadata check."
assert_not_exists "web/themes/custom/${missing_metadata_theme}"

log "Enabling generated child theme"
vendor/bin/drush theme:enable "$THEME_NAME" -y
vendor/bin/drush config:set system.theme default "$THEME_NAME" -y
vendor/bin/drush cr -y

log "Emulsify Tools generation smoke test passed"
