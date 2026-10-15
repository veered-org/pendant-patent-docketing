#!/usr/bin/env python3
"""Compare the independent claims of an issued patent (the USPTO eGrant PDF) with the claims as last filed (the ODP "CLM" XML).
Usage: claims_compare.py EGRANT.pdf CLM.xml [NOA.xml]   -> JSON {ok, granted_count, granted_independent, filed_count, diffs, examiner_amendment}
A granted independent claim that differs from the last-filed claim still passes when the examiner wrote that exact claim out in full
in an examiner's amendment in the Notice of Allowance (XYZ001). Amendments given as edit instructions (XYZ010) are left to a person.
2026-10-02: the issuance letter says "the independent claim language ... has been checked for accuracy"; Pendant checks it."""
import sys, re, json, difflib, unicodedata
from pypdf import PdfReader

def norm(t):
    t = re.sub(r'(\w)-\s+(\w)', r'\1\2', t)            # hyphenation across lines ("trian- gular")
    t = re.sub(r'\s+', ' ', t).strip().lower()
    t = re.sub(r'\s+([,;:.])', r'\1', t)
    return t

HEAD = r'(?:what\s+is\s+claimed\s+is|the\s+invention\s+claimed\s+is|(?:i|we)\s+claim|claimed\s+is)\s*:?'   # "What is claimed is:", "We claim:", "I claim:"

def split_columns(page_text):
    """Two-column page (layout text) -> left column text + right column text. The gutter is the character position, in the middle
    of the line, that is blank on the most lines; the line numbers printed in it (5, 10, ... 65) are dropped."""
    lines = page_text.split('\n'); width = max((len(l) for l in lines), default=0)
    if width < 40: return page_text
    best = min(range(int(width * 0.35), int(width * 0.65)), key=lambda p: sum(1 for l in lines if len(l) > p and l[p] != ' '))
    L, R = [], []
    for l in lines:
        left, right = l[:best], l[best:]
        left = re.sub(r'\s+\d{1,2}\s*$', '', left) if re.search(r'\s{3,}\d{1,2}\s*$', left) else left
        right = re.sub(r'^\s*\d{1,2}\s{3,}', '', right)
        L.append(left.strip()); R.append(right.strip())
    return '\n'.join(L) + '\n' + '\n'.join(R)

def egrant_claims(path):
    """Claims of the eGrant (two-column print), read column by column."""
    r = PdfReader(path); cols = []; started = False
    for pg in r.pages:
        t = pg.extract_text(extraction_mode='layout')
        if not started and not re.search(HEAD, re.sub(r'\s+', ' ', t), re.I): continue
        started = True; cols.append(split_columns(t))
    txt = unicodedata.normalize('NFKC', '\n'.join(cols))
    ks = [k for k in re.finditer(HEAD, txt, re.I) if re.match(r'\s*1\.\s', txt[k.end():])]; txt = txt[ks[-1].end():] if ks else txt
    txt = re.sub(r'US\s+\d{1,2},\d{3},\d{3}(\s+[AB]\d)?', ' ', txt); txt = re.sub(r'(?m)^\s*(\d{1,2}|[AB]\d)\s*$', '', txt)   # page header / page numbers
    txt = re.sub(r'[∗*]', ' ', txt)
    parts = re.split(r'(?:^|(?<=[\s.;]))(\d{1,3})\.\s+(?=[A-Z])', txt)
    claims = {}; want = 1
    for i in range(1, len(parts) - 1, 2):
        n = int(parts[i])
        if n == want: claims[n] = parts[i + 1].strip(); want += 1      # claims run 1, 2, 3 ...; anything else is a number inside a claim
        elif claims: claims[want - 1] += ' ' + parts[i] + '. ' + parts[i + 1]
    return claims

def filed_claims(path):
    x = open(path, encoding='utf8').read(); out = []
    for c in re.findall(r'<uspat:Claim\b.*?</uspat:Claim>', x, re.S):
        st = re.search(r'<uspat:ClaimStatusCategory>(.*?)</', c); st = (st.group(1) if st else '').lower()
        if 'cancel' in st or 'withdrawn' in st: continue
        num = re.search(r'<pat:ClaimNumber>(\d+)</', c)
        body = re.sub(r'<com:Del>.*?</com:Del>', '', c, flags=re.S)
        body = re.sub(r'<uspat:ClaimStatusCategory>.*?</uspat:ClaimStatusCategory>|<pat:ClaimNumber>.*?</pat:ClaimNumber>', ' ', body, flags=re.S)
        body = re.sub(r'<[^>]+>', ' ', body)
        body = re.sub(r'\s+', ' ', re.sub(r'&amp;', '&', body)).strip()
        body = re.sub(r'^\d+\s*\.\s*(\([^)]*\)\s*)?', '', body)   # "2. (Original) ..." / "1. (Currently Amended) ..."
        body = unicodedata.normalize('NFKC', body)
        out.append((int(num.group(1)) if num else 0, body))
    return out

def independent(t): return not re.search(r'\b(of|to|in|with|by)\s+claims?\s*\d+|according\s+to\s+claims?\s*\d+|as\s+claimed\s+in\s+claims?', re.sub(r'\s+', ' ', t), re.I)   # "claim1," (no space) too

g = egrant_claims(sys.argv[1]); f = filed_claims(sys.argv[2])
alnum = lambda t: re.sub(r'[^a-z0-9]', '', norm(t))
noa = ''
if len(sys.argv) > 3:
    try: noa = unicodedata.normalize('NFKC', re.sub(r'<[^>]+>', ' ', open(sys.argv[3], encoding='utf8').read()))
    except OSError: noa = ''
ea = re.search(r"examiner.{0,3}s\s+amendment", noa, re.I)
noa_alnum = alnum(noa[ea.start():]) if ea else ''
# Claims are renumbered at grant (cancelled ones drop out): the k-th surviving filed claim is granted claim k.
diffs = []; gl = [g[n] for n in sorted(g)]
for k, gt in enumerate(gl):
    if not independent(gt): continue
    ga = alnum(gt)
    if noa_alnum and len(ga) > 40 and ga in noa_alnum: continue     # written out in full in the examiner's amendment
    if k >= len(f): diffs.append({'granted': k + 1, 'note': 'no filed claim to compare'}); continue
    na, nb = norm(gt), norm(f[k][1])
    # Letters and digits only: OCR spacing in the filed XML ("ad j acent"), hyphenation and punctuation are not claim language.
    if ga != alnum(f[k][1]):
        d = [w for w in difflib.ndiff(nb.split(), na.split()) if w[:1] in '+-']
        diffs.append({'granted': k + 1, 'filed': f[k][0], 'ratio': round(difflib.SequenceMatcher(None, na, nb).ratio(), 4), 'changes': ' '.join(d)[:600]})
if len(gl) != len(f) and not ea: diffs.append({'note': 'claim count differs: %d filed (not cancelled), %d in the patent' % (len(f), len(gl))})
print(json.dumps({'ok': bool(gl) and not diffs, 'granted_count': len(gl), 'granted_independent': [k + 1 for k, t in enumerate(gl) if independent(t)], 'filed_count': len(f), 'examiner_amendment': bool(ea), 'diffs': diffs}, indent=1))
