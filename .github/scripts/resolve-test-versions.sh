#!/usr/bin/env bash
#
# Resolves the versions tested by the PHPUnit matrix and appends them to $GITHUB_OUTPUT:
#   sylius-2-1, sylius-2-2, sylius-2-3  latest stable sylius/sylius release of each minor (Packagist)
#   sylius-latest                        latest stable sylius/sylius release allowed by composer.json
#   php-latest                           latest PHP minor released on php.net allowed by composer.json
#   symfony-latest                       latest stable Symfony minor (symfony/framework-bundle on Packagist)
#                                        allowed by every Symfony framework constraint of composer.json
#
# The constraints read from composer.json must be caret ranges joined by "||", such as "^8.2" or
# "^6.4|| ^7.2". Any other shape, an unreachable source or an empty result stops the script with an
# error annotation.
#
# Symfony framework constraints are the symfony/* entries of require and require-dev, except:
#   - packages versioned apart from the framework: symfony/polyfill-*, symfony/ux-*, symfony/flex,
#     symfony/runtime, symfony/thanks, symfony/maker-bundle, symfony/apache-pack, symfony/monolog-bundle,
#     symfony/webpack-encore-bundle and symfony/stimulus-bundle;
#   - "*" constraints;
#   - constraints none of whose caret majors is a symfony/framework-bundle major on Packagist.
#
# Usage: resolve-test-versions.sh [path/to/composer.json]

set -euo pipefail

composer_json=${1:-composer.json}
work=${RUNNER_TEMP:-$(mktemp -d)}
: "${GITHUB_OUTPUT:?GITHUB_OUTPUT must be set}"

fail() {
    echo "::error::$*"
    exit 1
}

fetch() {
    curl -fsSL --retry 3 --retry-all-errors -o "$2" "$1" || fail "Could not fetch $1"
}

# shellcheck disable=SC2016 # jq program, not shell expansions
jq_lib='
    def caret_ranges:
        . as $constraint
        | [split("|")[] | gsub("^\\s+|\\s+$"; "") | select(. != "")]
        | if length == 0 then error("empty constraint \"\($constraint)\"") else . end
        | map(
            [capture("^\\^(?<major>[1-9][0-9]*)\\.(?<minor>[0-9]+)(\\.[0-9]+)?$")]
            | if length == 1 then .[0] | map_values(tonumber)
              else error("unsupported constraint \"\($constraint)\"") end
        );
    def allowed_by($ranges):
        (split(".") | map(tonumber)) as [$major, $minor]
        | any($ranges[]; .major == $major and $minor >= .minor);
    def stable: map(ltrimstr("v") | select(test("^[0-9]+\\.[0-9]+\\.[0-9]+$")));
    def minor: split(".")[0:2] | join(".");
    def highest: sort_by(split(".") | map(tonumber)) | last // empty;
'

jq -e 'type == "object"' "$composer_json" > /dev/null || fail "Could not read $composer_json"
php_constraint=$(jq -r '.require.php // empty' "$composer_json") \
    || fail "Could not read the php requirement of $composer_json"
sylius_constraint=$(jq -r '.require["sylius/sylius"] // empty' "$composer_json") \
    || fail "Could not read the sylius/sylius requirement of $composer_json"
not_framework='^symfony/(polyfill-.*|ux-.*|flex|runtime|thanks|maker-bundle|apache-pack|monolog-bundle|webpack-encore-bundle|stimulus-bundle)$'
symfony_requirements=$(jq -c --arg not_framework "$not_framework" '
    [((.require // {}), (."require-dev" // {})) | to_entries[]
        | select((.key | startswith("symfony/")) and (.key | test($not_framework) | not) and .value != "*")]
' "$composer_json") || fail "Could not read the symfony/* requirements of $composer_json"
[ -n "$php_constraint" ] || fail "No php requirement in $composer_json"
[ -n "$sylius_constraint" ] || fail "No sylius/sylius requirement in $composer_json"
[ "$symfony_requirements" != '[]' ] || fail "No Symfony framework requirement in $composer_json"

unsupported="(expected caret ranges joined by ||, such as \"^6.4 || ^7.2\")"
php_ranges=$(jq -cn --arg c "$php_constraint" "$jq_lib"'$c | caret_ranges') \
    || fail "Unsupported php constraint \"$php_constraint\" in $composer_json $unsupported"
sylius_ranges=$(jq -cn --arg c "$sylius_constraint" "$jq_lib"'$c | caret_ranges') \
    || fail "Unsupported sylius/sylius constraint \"$sylius_constraint\" in $composer_json $unsupported"
symfony_parsed=$(jq -cn --argjson requirements "$symfony_requirements" "$jq_lib"'
    $requirements | map(.ranges = (.value | caret_ranges))
') || fail "Unsupported symfony/* constraint among $(jq -c 'from_entries' <<< "$symfony_requirements") in $composer_json $unsupported"

sylius_metadata="$work/sylius.json"
symfony_metadata="$work/framework-bundle.json"
fetch https://repo.packagist.org/p2/sylius/sylius.json "$sylius_metadata"
fetch https://repo.packagist.org/p2/symfony/framework-bundle.json "$symfony_metadata"
php_releases=()
for major in $(jq -r 'map(.major) | unique[]' <<< "$php_ranges"); do
    fetch "https://www.php.net/releases/index.php?json&version=$major&max=1000" "$work/php-$major.json"
    php_releases+=("$work/php-$major.json")
done

resolved=()
output() {
    [ -n "$2" ] || fail "$3"
    [[ "$2" =~ $4 ]] || fail "Resolved $1 \"$2\" is not a version"
    echo "$1=$2" >> "$GITHUB_OUTPUT"
    resolved+=("$1=$2")
}
patch_re='^[0-9]+\.[0-9]+\.[0-9]+$'
minor_re='^[0-9]+\.[0-9]+$'

for minor_version in 2.1 2.2 2.3; do
    latest=$(jq -r --arg minor "$minor_version" "$jq_lib"'
        [.packages["sylius/sylius"][].version] | stable | map(select(minor == $minor)) | highest
    ' "$sylius_metadata") || fail "Could not read the sylius/sylius versions from Packagist"
    output "sylius-${minor_version/./-}" "$latest" "No stable sylius/sylius $minor_version.x release found on Packagist" "$patch_re"
done

sylius_latest=$(jq -r --argjson ranges "$sylius_ranges" "$jq_lib"'
    [.packages["sylius/sylius"][].version] | stable | map(select(allowed_by($ranges))) | highest
' "$sylius_metadata") || fail "Could not read the sylius/sylius versions from Packagist"
output sylius-latest "$sylius_latest" "No stable sylius/sylius release matches \"$sylius_constraint\"" "$patch_re"

php_latest=$(jq -rs --argjson ranges "$php_ranges" "$jq_lib"'
    [.[] | keys[]] | stable | map(minor) | unique | map(select(allowed_by($ranges))) | highest
' "${php_releases[@]}") || fail "Could not read the PHP releases from php.net"
output php-latest "$php_latest" "No PHP release on php.net matches \"$php_constraint\"" "$minor_re"

symfony_framework=$(jq -c --argjson requirements "$symfony_parsed" "$jq_lib"'
    ([.packages["symfony/framework-bundle"][].version] | stable | map(split(".")[0] | tonumber) | unique) as $majors
    | [$requirements[] | select(any(.ranges[]; .major as $major | any($majors[]; . == $major)))]
' "$symfony_metadata") || fail "Could not read the symfony/framework-bundle versions from Packagist"
symfony_constraints=$(jq -c 'map({key, value}) | from_entries' <<< "$symfony_framework")
[ "$symfony_framework" != '[]' ] \
    || fail "No symfony/* constraint of $composer_json targets a Symfony framework major"

symfony_latest=$(jq -r --argjson framework "$symfony_framework" "$jq_lib"'
    [.packages["symfony/framework-bundle"][].version] | stable | map(minor) | unique
    | map(. as $version | select(all($framework[]; .ranges as $ranges | $version | allowed_by($ranges))))
    | highest
' "$symfony_metadata") || fail "Could not read the symfony/framework-bundle versions from Packagist"
output symfony-latest "$symfony_latest" "No stable Symfony minor matches every Symfony framework constraint $symfony_constraints" "$minor_re"

echo "composer.json: php \"$php_constraint\", sylius/sylius \"$sylius_constraint\", Symfony framework $symfony_constraints"
printf '%s\n' "${resolved[@]}"
