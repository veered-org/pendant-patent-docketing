#!/usr/bin/env python3
"""Losslessly shrink downloaded USPTO PDFs in place (called by oa_fetch.php).

Reuses ~/Projects/pdf-shrink: CCITT G4 scans -> JBIG2 generic region (never
symbol mode), plus a pikepdf restructure (object streams, re-deflate). A file
is replaced only if the result is smaller AND renders pixel-identical at a
fixed 300 dpi; otherwise the original is left untouched.

Prints one line per file: "<size_in> <size_out> <STATUS> <name>".
"""
import os, shutil, sys, tempfile

sys.path.insert(0, os.path.expanduser("~/Projects/pdf-shrink"))
from shrink import jbig2_repack, verify_identical  # noqa: E402


def optimize(path):
    size_in = os.path.getsize(path)
    with tempfile.TemporaryDirectory() as td:
        cand = os.path.join(td, "out.pdf")
        try:
            jbig2_repack(path, cand)
        except Exception as e:
            return size_in, size_in, f"SKIP({type(e).__name__})"
        if not os.path.exists(cand) or os.path.getsize(cand) >= size_in:
            return size_in, size_in, "SKIP(not smaller)"
        ok, why = verify_identical(path, cand, 300, scale_mode=False)
        if not ok:
            return size_in, size_in, f"SKIP({why})"
        st = os.stat(path)
        shutil.copyfile(cand, path + ".opt")
        os.utime(path + ".opt", (st.st_atime, st.st_mtime))
        os.replace(path + ".opt", path)
        return size_in, os.path.getsize(path), "OK"


if __name__ == "__main__":
    for p in sys.argv[1:]:
        a, b, s = optimize(p)
        print(a, b, s, os.path.basename(p), flush=True)
