#!/usr/bin/env python3
"""Layout text of PDF pages, like `pdftotext -layout -f FIRST -l LAST file -` (pdftotext is broken on the pair host:
libpoppler wants a library the host does not have). Uses pypdf's layout mode. Prints nothing for an image-only page.
    pdftext.py FILE [FIRST [LAST]]      pages are 1-based; LAST 0 = to the end
"""
import sys
from pypdf import PdfReader

def main():
    path = sys.argv[1]; first = int(sys.argv[2]) if len(sys.argv) > 2 else 1; last = int(sys.argv[3]) if len(sys.argv) > 3 else 0
    try:
        r = PdfReader(path)
    except Exception:
        return
    n = len(r.pages); last = n if last <= 0 or last > n else last
    out = []
    for i in range(max(1, first) - 1, last):
        try:
            out.append(r.pages[i].extract_text(extraction_mode="layout") or "")
        except Exception:
            try:
                out.append(r.pages[i].extract_text() or "")
            except Exception:
                out.append("")
    sys.stdout.write("\f".join(out))

if __name__ == "__main__":
    main()
