"""Cache only the existing public font/icon assets for isolated browser evidence.

No production asset is changed. This ignored cache allows the browser to keep all
external requests blocked while rendering the application's actual CDN typography.
"""
from pathlib import Path
import hashlib
import json
import re
import urllib.parse
import urllib.request

CACHE = Path(__file__).resolve().parent / "v10-batch1075-results/remediation/cdn-cache"
HOSTS = {"cdnjs.cloudflare.com", "fonts.googleapis.com", "fonts.gstatic.com"}
SOURCES = ["https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css",
           "https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap",
           "https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap"]


def cache_assets():
    CACHE.mkdir(parents=True, exist_ok=True)
    assets, pending = {}, list(SOURCES)
    while pending:
        url = pending.pop(0)
        if url in assets:
            continue
        parsed = urllib.parse.urlsplit(url)
        assert parsed.scheme == "https" and parsed.hostname in HOSTS and not parsed.username
        request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 Chrome/130.0.0.0 Safari/537.36"})
        with urllib.request.urlopen(request, timeout=30) as response:
            assert urllib.parse.urlsplit(response.url).hostname in HOSTS
            content = response.read(4 * 1024 * 1024 + 1)
            assert len(content) <= 4 * 1024 * 1024
            mime = response.headers.get_content_type()
        filename = hashlib.sha256(url.encode()).hexdigest() + ".bin"
        (CACHE / filename).write_bytes(content)
        assets[url] = {"file": filename, "mime": mime, "sha256": hashlib.sha256(content).hexdigest()}
        if mime == "text/css":
            pending.extend(urllib.parse.urljoin(url, link.strip("\"' "))
                           for link in re.findall(r"url\(([^)]+)\)", content.decode()))
    (CACHE / "assets.json").write_text(json.dumps(assets, indent=2), encoding="utf-8")
    print(f"Cached {len(assets)} existing public font/icon resources; no production files changed.")


if __name__ == "__main__":
    cache_assets()
