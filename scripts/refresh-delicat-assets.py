#!/usr/bin/env python3
"""Rebuild the content-addressed asset layer of delicat-builder-v9.

Run after editing any CSS/JS in the plugin:

    python3 scripts/refresh-delicat-assets.py [delicat-builder-v9]

It (1) writes one `<name>.<12-hex-sha256>.<ext>` copy per source stylesheet/script
under assets/, pro/assets/ and modules/**/assets/ (removing stale hashed copies),
(2) regenerates asset-versions.php, and (3) regenerates integrity-manifest.json
with the plugin version read from the main plugin header.
"""
import datetime
import hashlib
import json
import os
import re
import sys

ROOT = sys.argv[1] if len(sys.argv) > 1 else "delicat-builder-v9"
HASHED = re.compile(r"^(.*)\.([0-9a-f]{12})\.(css|js)$")


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def rel(path):
    return os.path.relpath(path, ROOT).replace(os.sep, "/")


def asset_dir(relpath):
    return relpath.startswith("assets/") or relpath.startswith("pro/assets/") or (
        relpath.startswith("modules/") and "/assets/" in relpath
    )


# 1. hashed copies -------------------------------------------------------------
sources, hashed = [], []
for dp, _, fns in os.walk(ROOT):
    for fn in fns:
        p = os.path.join(dp, fn)
        r = rel(p)
        if not asset_dir(r) or not r.endswith((".css", ".js")):
            continue
        (hashed if HASHED.match(fn) else sources).append(r)

wanted = {}
for r in sorted(sources):
    digest = sha256(os.path.join(ROOT, r))[:12]
    base, ext = r.rsplit(".", 1)
    target = "%s.%s.%s" % (base, digest, ext)
    wanted[r] = target
    tpath = os.path.join(ROOT, target)
    if not os.path.exists(tpath):
        with open(os.path.join(ROOT, r), "rb") as src, open(tpath, "wb") as dst:
            dst.write(src.read())
        print("created  %s" % target)

for r in hashed:
    if r not in wanted.values():
        os.remove(os.path.join(ROOT, r))
        print("removed  %s (stale)" % r)

# 2. asset-versions.php --------------------------------------------------------
lines = ["<?php", "// Generated content-addressed assets. Original paths retained for compatibility.", "return array("]
for r in sorted(wanted):
    lines.append(" '%s' => '%s'," % (r, wanted[r]))
lines.append(");")
with open(os.path.join(ROOT, "asset-versions.php"), "w", encoding="utf-8") as fh:
    fh.write("\n".join(lines) + "\n")
print("wrote    asset-versions.php (%d entries)" % len(wanted))

# 3. integrity-manifest.json ---------------------------------------------------
main = open(os.path.join(ROOT, "delicat-builder-v9.php"), encoding="utf-8").read()
m = re.search(r"^\s*\*\s*Version:\s*(\S+)", main, re.M)
version = m.group(1) if m else "unknown"
files, total = {}, 0
for dp, _, fns in os.walk(ROOT):
    for fn in fns:
        p = os.path.join(dp, fn)
        r = rel(p)
        if r == "integrity-manifest.json":
            continue
        size = os.path.getsize(p)
        total += size
        files[r] = {"bytes": size, "sha256": sha256(p)}
manifest = {
    "algorithm": "sha256",
    "built": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "version": version,
    "bytes": total,
    "count": len(files),
    "files": {k: files[k] for k in sorted(files)},
}
with open(os.path.join(ROOT, "integrity-manifest.json"), "w", encoding="utf-8") as fh:
    json.dump(manifest, fh, indent=2)
    fh.write("\n")
print("wrote    integrity-manifest.json (%d files, version %s)" % (len(files), version))
