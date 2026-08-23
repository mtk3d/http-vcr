#!/usr/bin/env bash
#
# Stamps absolute canonical/og:url links into the built book and writes a
# sitemap. Run it after `mdbook build docs`; it is idempotent, so re-running
# over an already-stamped book leaves it as it was.
#
# This lives outside the book template because Handlebars only sees the source
# path of a chapter (`getting-started/quick-start.md`) and has no way to turn
# that into the published URL. The built tree does know, so we read it here.
#
# There is deliberately no robots.txt: crawlers only honour one at the domain
# root, and this book is published under a path on a shared github.io host.
# Pages that must stay out of the index carry a noindex meta instead.

set -euo pipefail

BASE_URL="${DOCS_BASE_URL:-https://mtk3d.github.io/http-vcr}"
BASE_URL="${BASE_URL%/}"
BOOK_DIR="$(cd "$(dirname "$0")" && pwd)/book"

if [ ! -d "$BOOK_DIR" ]; then
    echo "seo.sh: no build at $BOOK_DIR — run 'mdbook build docs' first" >&2
    exit 1
fi

# The whole-book print page, the sidebar fragment and the 404. mdBook already
# marks the first two noindex itself; the 404 it does not, so we add it there.
is_indexable() {
    case "$1" in
        print.html | toc.html | 404.html) return 1 ;;
        *) return 0 ;;
    esac
}

# `foo/index.html` is served as `foo/`; linking the bare directory keeps the
# canonical and the URL a reader actually sees identical.
to_url() {
    case "$1" in
        index.html) printf '%s/' "$BASE_URL" ;;
        */index.html) printf '%s/%s' "$BASE_URL" "${1%index.html}" ;;
        *) printf '%s/%s' "$BASE_URL" "$1" ;;
    esac
}

# mdBook publishes the first chapter twice: once at its own path, and once
# copied to index.html as the site root. Identical bytes under two URLs, which
# is the one duplicate a crawler will hold against the book.
first_chapter_html() {
    local summary
    summary="$(dirname "$BOOK_DIR")/src/SUMMARY.md"
    [ -f "$summary" ] || return 0
    sed -n 's/.*](\([^)]*\.md\)).*/\1/p' "$summary" | head -1 | sed 's/\.md$/.html/'
}

inject() {
    local file="$1"
    # Insert ahead of the first </head>; anything later is body content. The
    # tags travel through the environment rather than -v, which mangles both
    # newlines and backslashes on the way in.
    SEO_TAGS="$2" awk '
        !done && /<\/head>/ { printf "%s", ENVIRON["SEO_TAGS"]; done = 1 }
        { print }
    ' "$file" > "$file.seo" && mv "$file.seo" "$file"
}

stamped=0
skipped=0
urls=""
index_dup="$(first_chapter_html)"

while IFS= read -r file; do
    rel="${file#"$BOOK_DIR"/}"

    already=false
    if grep -q 'rel="canonical"\|name="robots"' "$file"; then
        already=true
    fi

    if ! is_indexable "$rel"; then
        if [ "$already" = true ]; then
            skipped=$((skipped + 1))
        else
            inject "$file" '        <meta name="robots" content="noindex, follow">
'
            stamped=$((stamped + 1))
        fi
        continue
    fi

    url="$(to_url "$rel")"

    # The copy of the first chapter points at the root it duplicates, and stays
    # out of the sitemap. Every other page is its own canonical.
    if [ -n "$index_dup" ] && [ "$rel" = "$index_dup" ]; then
        url="$BASE_URL/"
    else
        urls="$urls$url
"
    fi

    if [ "$already" = true ]; then
        skipped=$((skipped + 1))
        continue
    fi

    inject "$file" "        <link rel=\"canonical\" href=\"$url\">
        <meta property=\"og:url\" content=\"$url\">
"
    stamped=$((stamped + 1))
done < <(find "$BOOK_DIR" -type f -name '*.html' | sort)

{
    echo '<?xml version="1.0" encoding="UTF-8"?>'
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    # No <lastmod>: every build would stamp today's date on every page, telling
    # crawlers the whole book changed when a single paragraph did.
    printf '%s' "$urls" | while IFS= read -r url; do
        [ -n "$url" ] && echo "    <url><loc>$url</loc></url>"
    done
    echo '</urlset>'
} > "$BOOK_DIR/sitemap.xml"

echo "seo.sh: stamped $stamped, left $skipped already-stamped pages alone"
echo "seo.sh: sitemap lists $(grep -c '<url>' "$BOOK_DIR/sitemap.xml") URLs"
