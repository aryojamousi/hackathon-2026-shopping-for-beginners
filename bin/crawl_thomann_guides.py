#!/usr/bin/env python3
"""Crawl the Thomann "Online Expert" guide index and store the guides in SQLite.

Source page: https://www.thomann.de/de/onlineexpert.html

The page lists Thomann's buying/advice guides ("Ratgeber"). For each guide we
capture its title, absolute link, a short teaser, a preview image and a derived
instrument/topic category so the shopping journey can reference relevant advice.

Stdlib only (urllib + sqlite3) so it runs without extra dependencies:

    python bin/crawl_thomann_guides.py
"""

from __future__ import annotations

import html
import re
import sqlite3
import urllib.request
from pathlib import Path

INDEX_URL = "https://www.thomann.de/de/onlineexpert.html"
BASE_URL = "https://www.thomann.de/de/"
DB_PATH = Path(__file__).resolve().parent.parent / "data" / "thomann_guides.sqlite"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
)

# Instrument / topic focus per guide slug. Keeps the crawler deterministic and
# lets the app group guides by the journey step they belong to.
CATEGORY_BY_SLUG = {
    "lautsprecher": "PA & Live Sound",
    "gitarrensetups": "Guitars",
    "pa_anlagen": "PA & Live Sound",
    "podcasting": "Recording & Studio",
    "instrumente_fuer_einsteiger": "Beginners",
    "e_gitarren": "Guitars",
    "home_recording": "Recording & Studio",
    "e_gitarren_recording": "Recording & Studio",
    "monitoring": "PA & Live Sound",
    "digitalmixer": "Mixing",
    "synthesizer": "Keys & Synths",
    "masterkeyboards": "Keys & Synths",
    "e_drums": "Drums & Percussion",
    "digitalpianos": "Keys & Pianos",
    "kleinmixer": "Mixing",
    "kabel": "Cables & Accessories",
    "stage_pianos": "Keys & Pianos",
    "einsteiger_drumsets": "Drums & Percussion",
    "audiointerfaces": "Recording & Studio",
    "gitarrenverstaerker": "Guitars",
    "led_beleuchtung": "Lighting & Stage",
    "klaviere": "Keys & Pianos",
    "amp_modeling": "Guitars",
    "studiomonitore": "Recording & Studio",
}

ANCHOR_RE = re.compile(
    r'<a\b[^>]*href="([^"]*onlineexpert_topic_[a-z0-9_]+\.html)"[^>]*>(.*?)</a>',
    re.S | re.I,
)
TITLE_RE = re.compile(r'body__title">\s*(.*?)\s*</p>', re.S)
TEXT_RE = re.compile(r'body__text">\s*(.*?)\s*</p>', re.S)
IMG_RE = re.compile(r'data-srcset="([^"]+)"')


def clean(text: str) -> str:
    """Strip tags/entities, collapse whitespace, drop the truncation ellipsis."""
    text = re.sub(r"<[^>]+>", " ", text)
    text = html.unescape(text)
    text = "".join(ch for ch in text if ch.isprintable() or ch == " ")
    text = re.sub(r"\s+", " ", text).strip()
    # Teasers on the index are truncated with a trailing "… –" run; drop it.
    return text.rstrip(" .…–—-")


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=30) as resp:
        # The page is UTF-8. A stray invalid byte lives in an unrelated inline
        # script, so replace rather than fail — it never touches the guide data.
        return resp.read().decode("utf-8", errors="replace")


def parse(doc: str) -> list[dict]:
    guides: dict[str, dict] = {}
    for match in ANCHOR_RE.finditer(doc):
        href, inner = match.group(1), match.group(2)
        slug = re.search(r"onlineexpert_topic_([a-z0-9_]+)\.html", href).group(1)
        if slug in guides:
            continue  # index links each guide once; guard against duplicates
        title_m = TITLE_RE.search(inner)
        text_m = TEXT_RE.search(inner)
        img_m = IMG_RE.search(inner)
        guides[slug] = {
            "slug": slug,
            "title": clean(title_m.group(1)) if title_m else slug,
            "url": BASE_URL + href.lstrip("/"),
            "description": clean(text_m.group(1)) if text_m else "",
            "image_url": img_m.group(1) if img_m else "",
            "category": CATEGORY_BY_SLUG.get(slug, "Uncategorized"),
        }
    return list(guides.values())


def write_db(guides: list[dict]) -> None:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    try:
        conn.execute("DROP TABLE IF EXISTS guides")
        conn.execute(
            """
            CREATE TABLE guides (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                slug        TEXT NOT NULL UNIQUE,
                title       TEXT NOT NULL,
                url         TEXT NOT NULL,
                category    TEXT NOT NULL,
                description TEXT,
                image_url   TEXT,
                source_url  TEXT NOT NULL
            )
            """
        )
        conn.executemany(
            """
            INSERT INTO guides
                (slug, title, url, category, description, image_url, source_url)
            VALUES
                (:slug, :title, :url, :category, :description, :image_url, :source_url)
            """,
            [{**g, "source_url": INDEX_URL} for g in guides],
        )
        conn.execute("CREATE INDEX idx_guides_category ON guides(category)")
        conn.commit()
    finally:
        conn.close()


def main() -> None:
    print(f"Fetching {INDEX_URL} ...")
    doc = fetch(INDEX_URL)
    guides = parse(doc)
    guides.sort(key=lambda g: (g["category"], g["title"]))
    write_db(guides)
    print(f"Stored {len(guides)} guides in {DB_PATH}")
    for g in guides:
        print(f"  [{g['category']:>20}] {g['title']}")


if __name__ == "__main__":
    main()
