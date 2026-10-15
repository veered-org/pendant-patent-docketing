#!/usr/bin/env python3
"""Lossless PDF shrink for the pair host (2026-10-01: "add lossless PDF shrink to pair"), the server twin of
~/Projects/pdf-shrink/shrink.py + bin/pdf_optimize.py on the laptop.

  pdf_shrink.py FILE [FILE ...]      rewrites each FILE in place, or leaves it untouched

CCITT G4 scans (what the USPTO serves) are re-encoded as JBIG2 generic region (jbig2enc -p; NEVER symbol mode, which can swap
look-alike glyphs). Differences from the laptop tool, because the host has neither pikepdf nor a working poppler:
  * pypdf rewrites the file; the G4 stream is handed to jbig2enc wrapped in a TIFF header, so nothing decodes it in Python.
  * the proof is Ghostscript (pair's mutool cannot draw JBIG2): every page of the original and of the candidate is rendered to grayscale at the same resolution and
    the two bitmaps must be byte-identical. Any difference, error or timeout = the original stays.
A file is replaced only if the result is smaller AND pixel-identical on every page.
Prints one line per file: "<size_in> <size_out> <STATUS> <name>" (STATUS OK, or SKIP(reason)).
"""
import hashlib, os, shutil, struct, subprocess, sys, tempfile

JB = os.environ.get("JBIG2", os.path.expanduser("~/opt/jbig2/bin/jbig2"))
GS = shutil.which("gs") or "/usr/local/bin/gs"   # the proof renderer: pair's mutool cannot draw JBIG2 (libmupdf lacks jbig2dec)
DPI = int(os.environ.get("SHRINK_DPI", "200"))
MAX_PAGES = 400


def tiff_g4(data, width, height, black_is_1):
    """A minimal single-strip TIFF around a raw CCITT G4 stream (Compression 4)."""
    entries = [
        (256, 4, 1, width), (257, 4, 1, height), (258, 3, 1, 1), (259, 3, 1, 4),
        (262, 3, 1, 1 if black_is_1 else 0),   # measured 2026-10-01: PDF BlackIs1 false (default) <-> TIFF WhiteIsZero; the other way round inverted every page
        (266, 3, 1, 1), (273, 4, 1, 0), (277, 3, 1, 1), (278, 4, 1, height), (279, 4, 1, len(data)), (293, 4, 1, 0),
    ]
    ifd_off = 8; n = len(entries); data_off = ifd_off + 2 + n * 12 + 4
    out = bytearray(b"II*\x00" + struct.pack("<I", ifd_off) + struct.pack("<H", n))
    for tag, typ, cnt, val in entries:
        if tag == 273: val = data_off
        out += struct.pack("<HHI", tag, typ, cnt) + (struct.pack("<HH", val, 0) if typ == 3 else struct.pack("<I", val))
    out += struct.pack("<I", 0) + data
    return bytes(out)


def repack(src, dst, tmp):
    from pypdf import PdfWriter
    from pypdf.generic import NameObject
    w = PdfWriter(clone_from=src)
    changed = 0
    for page in w.pages:
        res = page.get("/Resources")
        res = res.get_object() if res is not None else None
        xo = res.get("/XObject") if res is not None else None
        if xo is None:
            continue
        xo = xo.get_object()
        for name in list(xo.keys()):
            obj = xo[name].get_object()
            try:
                if obj.get("/Subtype") != "/Image" or int(obj.get("/BitsPerComponent", 1 if obj.get("/ImageMask") else 8)) != 1:
                    continue
                filt = obj.get("/Filter"); fl = [str(f) for f in filt] if isinstance(filt, list) else [str(filt)]
                if fl != ["/CCITTFaxDecode"]:
                    continue
                dp = obj.get("/DecodeParms") or {}
                if isinstance(dp, list):
                    dp = dp[0] or {}
                dp = dp.get_object() if hasattr(dp, "get_object") else dp
                if int(dp.get("/K", 0)) >= 0 or dp.get("/EncodedByteAlign"):
                    continue   # G4 only; byte-aligned rows have no TIFF equivalent
                width = int(dp.get("/Columns", 1728)); height = int(obj.get("/Height")); rows = int(dp.get("/Rows", height))
                if width != int(obj.get("/Width")) or rows != height:
                    continue
                raw = obj._data
                p = os.path.join(tmp, "i.tif")
                with open(p, "wb") as fh:
                    fh.write(tiff_g4(raw, width, height, bool(dp.get("/BlackIs1", False))))
                r = subprocess.run([JB, "-d", "-p", p], capture_output=True, timeout=300)
                if r.returncode != 0 or not r.stdout or len(r.stdout) >= len(raw):
                    continue
                obj._data = r.stdout
                obj[NameObject("/Filter")] = NameObject("/JBIG2Decode")
                if "/DecodeParms" in obj:
                    del obj["/DecodeParms"]
                # /Decode stays: the bitmap went to the encoder undecoded, so the PDF still has to apply it.
                changed += 1
            except Exception:
                continue
    if changed:
        with open(dst, "wb") as fh:
            w.write(fh)
    return changed


def page_count(path):
    from pypdf import PdfReader
    return len(PdfReader(path).pages)


def digest(path, page, tmp):
    out = os.path.join(tmp, "r.pgm")
    try:
        r = subprocess.run([GS, "-q", "-dSAFER", "-dNOPAUSE", "-dBATCH", "-sDEVICE=pgmraw", "-r" + str(DPI), "-dFirstPage=%d" % page, "-dLastPage=%d" % page, "-o", out, path], capture_output=True, timeout=600)
    except subprocess.TimeoutExpired:
        return None
    if r.returncode != 0 or not os.path.exists(out):
        return None
    h = hashlib.sha256()
    with open(out, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    os.unlink(out)
    return h.hexdigest()


def shrink(path):
    size_in = os.path.getsize(path)
    if not os.path.exists(JB):
        return size_in, size_in, "SKIP(no jbig2enc)"
    tmp = tempfile.mkdtemp(prefix="shrink-")
    try:
        cand = os.path.join(tmp, "out.pdf")
        try:
            n = repack(path, cand, tmp)
        except Exception as e:
            return size_in, size_in, f"SKIP({type(e).__name__})"
        if not n or not os.path.exists(cand):
            return size_in, size_in, "SKIP(no G4 images)"
        size_out = os.path.getsize(cand)
        if size_out >= size_in:
            return size_in, size_in, "SKIP(not smaller)"
        try:
            pa, pb = page_count(path), page_count(cand)
        except Exception as e:
            return size_in, size_in, f"SKIP(count {type(e).__name__})"
        if pa != pb or pa == 0:
            return size_in, size_in, "SKIP(page count)"
        if pa > MAX_PAGES:
            return size_in, size_in, "SKIP(too many pages to verify)"
        for i in range(1, pa + 1):
            a = digest(path, i, tmp); b = digest(cand, i, tmp)
            if a is None or b is None:
                return size_in, size_in, f"SKIP(render p{i})"
            if a != b:
                return size_in, size_in, f"SKIP(pixels differ p{i})"
        st = os.stat(path)
        shutil.copyfile(cand, path + ".shrink-tmp")
        os.chmod(path + ".shrink-tmp", st.st_mode & 0o777)
        os.replace(path + ".shrink-tmp", path)
        return size_in, size_out, "OK"
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    try:
        os.nice(15)
    except Exception:
        pass
    for f in sys.argv[1:]:
        a, b, s = shrink(f)
        print(f"{a} {b} {s} {os.path.basename(f)}", flush=True)
