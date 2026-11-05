import os, sys, zipfile, time
out, src = sys.argv[1], sys.argv[2]
if os.path.exists(out): os.remove(out)
now = time.localtime()[:6]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for root, dirs, files in os.walk(src):
        dirs[:] = sorted(d for d in dirs if d not in ('.ruff_cache', '__pycache__', 'node_modules', '.git'))
        rel_root = os.path.relpath(root, src)
        arc_root = 'delicat-builder-v9' if rel_root == '.' else 'delicat-builder-v9/' + rel_root.replace(os.sep, '/')
        zi = zipfile.ZipInfo(arc_root + '/', date_time=now); zi.external_attr = 0o40755 << 16; z.writestr(zi, '')
        for f in sorted(files):
            if f in ('.DS_Store',) or f.endswith('~'): continue
            p = os.path.join(root, f)
            zi = zipfile.ZipInfo(arc_root + '/' + f, date_time=now)
            zi.compress_type = zipfile.ZIP_DEFLATED
            zi.external_attr = 0o100644 << 16
            with open(p, 'rb') as fh: z.writestr(zi, fh.read(), compresslevel=9)
print(out, os.path.getsize(out))
