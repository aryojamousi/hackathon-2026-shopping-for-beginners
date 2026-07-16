#!/usr/bin/env python3
"""Populate the guides.text column with the full guide article text.

Each Thomann "Online Expert" topic page (onlineexpert_topic_*.html) is split
into section pages (onlineexpert_page_<slug>_<section>.html) linked from its
table of contents. This script, for each target topic URL:

  1. fetches the topic page and reads the TOC section links,
  2. fetches each section page and extracts the prose from the
     `guide-content__content` container,
  3. concatenates the sections into the full guide text, and
  4. writes it to the guides row matched by `url`. If no row matches, a new
     guide row is inserted (title/category/description/image derived from the
     topic page).

Adds the `text` column if it doesn't exist yet. Re-runnable / idempotent.
Stdlib only (urllib + sqlite3 + html.parser):

    python bin/crawl_guide_texts.py
"""

from __future__ import annotations

import html
import re
import sqlite3
import urllib.request
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin

DB_PATH = Path(__file__).resolve().parent.parent / "data" / "thomann_guides.sqlite"
SOURCE_INDEX = "https://www.thomann.de/de/onlineexpert.html"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
)

# Topic URLs to crawl. Text is stored on the guides row whose `url` matches;
# URLs with no matching row are inserted as new guide rows.
TARGET_URLS = [
    "https://www.thomann.de/de/onlineexpert_topic_e_gitarren.html",
    "https://www.thomann.de/de/onlineexpert_topic_bariton_e_gitarren.html",
    "https://www.thomann.de/de/onlineexpert_topic_gitarren_multieffekte.html",
    "https://www.thomann.de/de/onlineexpert_topic_saitenkunde.html",
    "https://www.thomann.de/de/onlineexpert_topic_gitarrenzubehoer.html",
    "https://www.thomann.de/de/onlineexpert_topic_gitarrenverstaerker.html",
    "https://www.thomann.de/de/onlineexpert_topic_gitarrensetups.html",
    "https://www.thomann.de/de/onlineexpert_topic_instrumente_fuer_einsteiger.html",
]

SECTION_HREF_RE = re.compile(r'guide-toc__link[^>]*?href="([^"]+)"', re.S)


class ContentExtractor(HTMLParser):
    """Collect text inside the first `guide-content__content` div."""

    def __init__(self) -> None:
        super().__init__()
        self._depth = 0
        self._inside = False
        self._skip = 0
        self._parts: list[str] = []

    def handle_starttag(self, tag, attrs):
        cls = dict(attrs).get("class", "") or ""
        if not self._inside and tag == "div" and "guide-content__content" in cls:
            self._inside = True
            self._depth = 1
            return
        if self._inside:
            if tag in ("script", "style"):
                self._skip += 1
            if tag == "div":
                self._depth += 1
            if tag in ("p", "li", "h1", "h2", "h3", "h4", "br", "tr"):
                self._parts.append("\n")

    def handle_endtag(self, tag):
        if not self._inside:
            return
        if tag in ("script", "style") and self._skip:
            self._skip -= 1
        if tag == "div":
            self._depth -= 1
            if self._depth == 0:
                self._inside = False

    def handle_data(self, data):
        if self._inside and not self._skip:
            text = data.strip()
            if text:
                self._parts.append(text)

    def text(self) -> str:
        joined = "\n".join(self._parts)
        joined = re.sub(r"[ \t]+", " ", joined)
        return re.sub(r"\n\s*\n+", "\n\n", joined).strip()


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def extract_content(doc: str) -> str:
    parser = ContentExtractor()
    parser.feed(doc)
    return parser.text()


def section_urls(topic_url: str, topic_html: str) -> list[str]:
    """Ordered, de-duplicated section page URLs from the TOC (excluding the
    topic self-link and the product-offer section)."""
    seen: dict[str, None] = {}
    for href in SECTION_HREF_RE.findall(topic_html):
        if "onlineexpert_page_" not in href:
            continue  # topic self-link / locale variants
        if "angebote" in href:
            continue  # "our current offers" = product listing, not prose
        seen.setdefault(urljoin(topic_url, href), None)
    return list(seen)


def collect_text(topic_url: str, topic_html: str) -> str:
    texts: list[str] = []
    for url in section_urls(topic_url, topic_html):
        try:
            text = extract_content(fetch(url))
        except Exception as exc:  # noqa: BLE001 - keep going on a bad section
            print(f"    ! section failed {url}: {exc}")
            continue
        if text:
            texts.append(text)

    # Single-page guide (no section pages) — use the topic page's own content.
    if not texts:
        fallback = extract_content(topic_html)
        if fallback:
            texts.append(fallback)

    return "\n\n".join(texts)


def slug_from_url(url: str) -> str:
    match = re.search(r"onlineexpert_topic_([a-z0-9_]+)\.html", url)
    return match.group(1) if match else url


def extract_title(topic_html: str, slug: str) -> str:
    match = re.search(r"<h1[^>]*>(.*?)</h1>", topic_html, re.S)
    if match:
        title = html.unescape(re.sub(r"<[^>]+>", "", match.group(1))).strip()
        if title:
            return title
    match = re.search(r"<title>(.*?)</title>", topic_html, re.S)
    if match:
        title = html.unescape(match.group(1)).strip()
        title = re.sub(r"^Thomann\s+Online-?Ratgeber\s*", "", title, flags=re.I).strip()
        if title:
            return title
    return slug.replace("_", " ").title()


def extract_image(topic_html: str) -> str:
    block = re.search(r"guide-topic-intro__picture.*?</picture>", topic_html, re.S)
    if block:
        img = re.search(
            r'(?:data-srcset|srcset|data-src|src)="([^"\s]+\.(?:jpg|jpeg|png|webp))',
            block.group(0),
        )
        if img:
            return img.group(1)
    return ""


def make_description(text: str, limit: int = 180) -> str:
    for line in text.split("\n"):
        line = line.strip()
        if len(line) >= 40:  # first substantial prose line (skip short headings)
            if len(line) <= limit:
                return line
            return line[:limit].rsplit(" ", 1)[0].rstrip(" .,;–-") + "…"
    return ""


def infer_category(slug: str, title: str) -> str:
    haystack = (slug + " " + title).lower()
    if any(k in haystack for k in ("gitarr", "guitar", "saiten", "multieffekt", "bariton")):
        return "Guitars"
    return "Uncategorized"


def ensure_text_column(conn: sqlite3.Connection) -> None:
    columns = [row[1] for row in conn.execute("PRAGMA table_info(guides)")]
    if "text" not in columns:
        conn.execute("ALTER TABLE guides ADD COLUMN text TEXT")
        print("Added column guides.text")


def main() -> None:
    conn = sqlite3.connect(DB_PATH)
    try:
        ensure_text_column(conn)

        updated, inserted = [], []
        for url in TARGET_URLS:
            print(f"Crawling {url}")
            topic_html = fetch(url)
            text = collect_text(url, topic_html)

            cursor = conn.execute(
                "UPDATE guides SET text = ? WHERE url = ?", (text, url)
            )
            if cursor.rowcount:
                updated.append((url, len(text)))
                print(f"    updated existing row ({len(text)} chars)")
                continue

            slug = slug_from_url(url)
            title = extract_title(topic_html, slug)
            conn.execute(
                """
                INSERT INTO guides
                    (slug, title, url, category, description, image_url, source_url, text)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    slug,
                    title,
                    url,
                    infer_category(slug, title),
                    make_description(text),
                    extract_image(topic_html),
                    SOURCE_INDEX,
                    text,
                ),
            )
            inserted.append((url, title, len(text)))
            print(f"    inserted new row '{title}' ({len(text)} chars)")
        conn.commit()

        print(f"\nUpdated {len(updated)} existing guide(s):")
        for url, size in updated:
            print(f"  {size:>7} chars  {url}")
        print(f"\nInserted {len(inserted)} new guide(s):")
        for url, title, size in inserted:
            print(f"  {size:>7} chars  {title}  <- {url}")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
