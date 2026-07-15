#!/usr/bin/env python3
"""Crawl the Thomann blog "Learn" section and store the articles in SQLite.

Source: https://www.thomann.de/blog/en/learn/  (WordPress category "Learn")

The blog runs on WordPress, so instead of scraping paginated HTML we read the
public WP REST API, which returns clean, structured metadata per article:
title, permalink, excerpt, publish date, reading time, categories, post tags
(the targeted instrument/topic metadata) and the featured image.

Articles are written to an `articles` table in the SAME database used by the
guide crawler, so the shopping journey can surface both alongside each other.

Stdlib only (urllib + sqlite3):

    python bin/crawl_thomann_learn.py
"""

from __future__ import annotations

import html
import json
import re
import sqlite3
import urllib.request
from pathlib import Path

SECTION = "Learn"
SECTION_URL = "https://www.thomann.de/blog/en/learn/"
API_BASE = "https://www.thomann.de/blog/en/wp-json/wp/v2"
LEARN_CATEGORY_ID = 10720  # category "Learn" (from /wp/v2/categories)
PER_PAGE = 100

DB_PATH = Path(__file__).resolve().parent.parent / "data" / "thomann_guides.sqlite"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
)

# Coarse instrument/topic focus, kept aligned with the guide crawler's taxonomy
# so guides and articles can be joined on `category`. Rules are matched in order
# against the article's tags + title; first hit wins. Post tags remain the
# authoritative, fine-grained metadata (stored verbatim in the `tags` column).
CATEGORY_RULES = [
    ("Guitars", ("guitar", "gitarre", "bass", "amp", "pedal", "distortion",
                 "humbucker", "pickup", "fretboard", "strings for", "capo")),
    ("Drums & Percussion", ("drum", "percussion", "cajon", "stick", "cymbal",
                            "snare", "conga")),
    ("Keys & Synths", ("synth", "modular", "sequencer", "oscillator", "eurorack")),
    ("Keys & Pianos", ("piano", "keyboard", "keys", "organ", "midi controller")),
    ("Recording & Studio", ("recording", "studio", "microphone", "mic ", "podcast",
                            "mastering", "audio interface", "daw", "plugin", "vst")),
    ("Mixing", ("mixer", "mixing", "mix ", "console")),
    ("PA & Live Sound", ("pa ", "live sound", "loudspeaker", "speaker", "monitor",
                         "in-ear", "stage sound")),
    ("DJ", ("dj", "turntable", "vinyl", "scratch")),
    ("Vocals", ("vocal", "singing", "singer", "voice", "choir")),
    ("Wind & Brass", ("saxophone", "trumpet", "flute", "clarinet", "trombone",
                      "harmonica")),
    ("Orchestral Strings", ("violin", "cello", "viola", "double bass", "ukulele")),
    ("Lighting & Stage", ("light", "led", "laser", "fog", "stage effect")),
    ("Cables & Accessories", ("cable", "connector", "stand", "case", "strap",
                              "accessor")),
    ("Music Theory & Practice", ("theory", "scale", "chord", "practice", "metronome",
                                 "rhythm", "notation", "lesson", "ear training")),
]


def http_get(url: str):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read(), resp.headers  # headers: case-insensitive Message


def clean_text(raw_html: str) -> str:
    text = re.sub(r"<[^>]+>", " ", raw_html)
    text = html.unescape(text)
    return re.sub(r"\s+", " ", text).strip()


def derive_category(tags: list[str], title: str) -> str:
    haystack = (" " + " ".join(tags) + " " + title + " ").lower()
    for category, keywords in CATEGORY_RULES:
        if any(kw in haystack for kw in keywords):
            return category
    return "General"


def fetch_articles() -> list[dict]:
    articles: list[dict] = []
    page = 1
    while True:
        url = (
            f"{API_BASE}/posts?categories={LEARN_CATEGORY_ID}"
            f"&per_page={PER_PAGE}&page={page}"
            f"&_embed=wp:term,wp:featuredmedia"
            f"&_fields=id,slug,link,date,title,excerpt,reading_time,categories,_links,_embedded"
        )
        body, headers = http_get(url)
        batch = json.loads(body.decode("utf-8"))
        if not batch:
            break
        total_pages = int(headers.get("X-WP-TotalPages", "0") or 0)
        for post in batch:
            articles.append(parse_post(post))
        print(f"  page {page}/{total_pages or '?'}: {len(batch)} posts")
        # Stop on the last page: either the header says so, or WP returned a
        # short (final) batch. Guarding on batch size avoids a 400 for page N+1.
        if len(batch) < PER_PAGE or (total_pages and page >= total_pages):
            break
        page += 1
    return articles


def parse_post(post: dict) -> dict:
    embedded = post.get("_embedded", {})
    categories: list[str] = []
    tags: list[str] = []
    for group in embedded.get("wp:term", []):
        for term in group:
            if not isinstance(term, dict):
                continue
            if term.get("taxonomy") == "category":
                categories.append(term.get("name", ""))
            elif term.get("taxonomy") == "post_tag":
                tags.append(term.get("name", ""))

    media = embedded.get("wp:featuredmedia", [])
    image_url = ""
    if media and isinstance(media[0], dict):
        image_url = media[0].get("source_url", "") or ""

    title = html.unescape(post["title"]["rendered"]).strip()
    tags = [t for t in dict.fromkeys(tags) if t]  # dedupe, keep order

    reading_time = post.get("reading_time")
    if isinstance(reading_time, dict):
        reading_time = reading_time.get("minutes")

    return {
        "post_id": post["id"],
        "title": title,
        "slug": post.get("slug", ""),
        "url": post["link"],
        "excerpt": clean_text(post.get("excerpt", {}).get("rendered", "")),
        "category": derive_category(tags, title),
        "tags": ", ".join(tags),
        "wp_categories": ", ".join(c for c in dict.fromkeys(categories) if c),
        "image_url": image_url,
        "published_at": post.get("date", ""),
        "reading_time": reading_time,
    }


def write_db(articles: list[dict]) -> None:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    try:
        conn.execute("DROP TABLE IF EXISTS articles")
        conn.execute(
            """
            CREATE TABLE articles (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id       INTEGER NOT NULL UNIQUE,
                title         TEXT NOT NULL,
                slug          TEXT NOT NULL,
                url           TEXT NOT NULL,
                excerpt       TEXT,
                category      TEXT NOT NULL,
                tags          TEXT,
                wp_categories TEXT,
                image_url     TEXT,
                published_at  TEXT,
                reading_time  INTEGER,
                section       TEXT NOT NULL,
                source_url    TEXT NOT NULL
            )
            """
        )
        conn.executemany(
            """
            INSERT INTO articles
                (post_id, title, slug, url, excerpt, category, tags, wp_categories,
                 image_url, published_at, reading_time, section, source_url)
            VALUES
                (:post_id, :title, :slug, :url, :excerpt, :category, :tags,
                 :wp_categories, :image_url, :published_at, :reading_time,
                 :section, :source_url)
            """,
            [{**a, "section": SECTION, "source_url": SECTION_URL} for a in articles],
        )
        conn.execute("CREATE INDEX idx_articles_category ON articles(category)")
        conn.commit()
    finally:
        conn.close()


def main() -> None:
    print(f"Fetching Learn articles from {API_BASE} ...")
    articles = fetch_articles()
    articles.sort(key=lambda a: (a["category"], a["title"].lower()))
    write_db(articles)
    print(f"\nStored {len(articles)} articles in {DB_PATH}")
    counts: dict[str, int] = {}
    for a in articles:
        counts[a["category"]] = counts.get(a["category"], 0) + 1
    for cat in sorted(counts):
        print(f"  {cat:>24}: {counts[cat]}")


if __name__ == "__main__":
    main()
