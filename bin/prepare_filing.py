#!/usr/bin/env python3
"""Prepare filing (2026-10-11): the papers for a new US patent application, before it is filed.
  prepare_filing.py title SPEC.docx            -> the title of the invention (the paragraph after "TITLE" / "TITLE OF THE INVENTION")
  prepare_filing.py build JOBS.json            -> builds every job, prints JSON [{out, ok, note}]
  prepare_filing.py finalize SRC OUT spec|fig TOOLS -> the -final copy, cleaned for Patent Center (uspto-docx / uspto-pdf in TOOLS)
JOBS: {forms: DIR (blank USPTO forms), font: TTF, jobs: [{kind, out, ...}]}
  declaration  {title, inventor}                                         PTO/AIA/01, page 1, flat
  poa82b       {applicant, forms_total, assignee?, signer?, signer_title?} PTO/AIA/82B, flat (inventor-applicant)
  poa80        {assignee, address_lines[], signer, signer_title}         PTO/AIA/80, flat (assignee-applicant)
  micro        {title, first_inventor, signer, joint}                    PTO/SB/15A, flat
  s373c        {first_inventor, title, assignee, assignee_type, signer, signer_title}  PTO/AIA/96 (3.73(c) statement), 2 pages, flat
  assignment   {template, inventors[], title, assignee, address_lines[], pdf?, font}  Patent Assignment.docx (+ PDF for drawn signatures:
               "sign": {boxes: [{page, sig_box, date}] one per assignor})
(The IDS is not built here: lib/prepfiling.php runs Sienna's ids_gen.py, the Patent Tools IDS generator.)
Each form result carries "sign": {sig: [x, y, size], date: [x, y, size]} for the e-signature page (/private/?d=..., sign_pdf.py).
Filled forms are flat (values stamped as page content, widgets removed, checkbox squares redrawn) so they work with the
e-signature page and Patent Center alike; fonts embedded with Ghostscript when it is there."""
import sys, re, json, io, os, copy, subprocess, shutil

CUSTOMER_NO = os.environ.get('PENDANT_CUSTOMER_NO', '')   # lib/firm.php: the firm's first customer number
SIGN_FIELDS = {'declaration': ('Text4', 'Date Optional'), 'poa82b': ('Signature of the applicant for patent', 'Date Optional_2'),
               'poa80': ('Signature', 'Date'), 'micro': ('Signature', 'Date'), 's373c': ('Text13', 'Date')}
FORM_FILES = {'declaration': ('aia0001.pdf', [0]), 'poa82b': ('aia0082.pdf', [1]), 'poa80': ('aia0080.pdf', [0]), 'micro': ('sb0015a.pdf', [0]), 's373c': ('aia0096.pdf', [0, 1])}


# ---------- title ----------
def spec_title(path):
    import docx
    paras = [re.sub(r'\s+', ' ', p.text).strip() for p in docx.Document(path).paragraphs]
    paras = [p for p in paras if p]
    for i, p in enumerate(paras):
        if re.fullmatch(r'title( of (the )?invention)?:?', p, re.I) and i + 1 < len(paras):
            return paras[i + 1]
        m = re.fullmatch(r'title( of (the )?invention)?:\s*(.+)', p, re.I)
        if m: return m.group(3).strip()
    return ''


# ---------- flat USPTO forms ----------
def widgets(page):
    out = {}
    for a in page.get('/Annots', []) or []:
        a = a.get_object()
        if a.get('/Subtype') != '/Widget': continue
        par = a.get('/Parent'); par = par.get_object() if par else {}
        name = str(a.get('/T') or par.get('/T') or ''); ft = str(a.get('/FT') or par.get('/FT') or '')
        out.setdefault(name, []).append({'rect': [float(x) for x in a['/Rect']], 'ft': ft})
    return out

def fit(c, text, font, w, size):
    from reportlab.pdfbase.pdfmetrics import stringWidth
    while size > 6 and stringWidth(text, font, size) > w: size -= 0.5
    return size

def stamp_form(forms, font_path, kind, values, checks, out):
    """values: {field name: text}; checks: [field names to tick]. Every field name must exist in the blank (KeyError otherwise).
    Returns the e-signature placement {page, sig, date} (page = index in the OUTPUT file)."""
    from pypdf import PdfReader, PdfWriter
    from pypdf.generic import NameObject
    from reportlab.pdfgen import canvas
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    pdfmetrics.registerFont(TTFont('Lexend', font_path))
    fname, pnos = FORM_FILES[kind]
    r = PdfReader(os.path.join(forms, fname)); pages = [r.pages[i] for i in pnos]; wds = [widgets(p) for p in pages]
    where = lambda name: next(i for i, wd in enumerate(wds) if name in wd)
    sf, df = SIGN_FIELDS[kind]; sp = where(sf); sr, dr = wds[sp][sf][0]['rect'], wds[where(df)][df][0]['rect']
    sign = {'page': sp, 'sig': [round(sr[0] + 6), round(sr[1] + 4), 12], 'date': [round(dr[0] + 6), round(dr[1] + 4), 11]}
    w = PdfWriter()
    for pi, (page, wd) in enumerate(zip(pages, wds)):
        W, H = float(page.mediabox.width), float(page.mediabox.height)
        buf = io.BytesIO(); c = canvas.Canvas(buf, pagesize=(W, H)); c.setFont('Lexend', 10)
        for name, ws in wd.items():   # flattening drops the widgets; the checkbox squares live there, so redraw them all
            for x in ws:
                x0, y0, x1, y1 = x['rect']
                if x['ft'] == '/Btn':   # (AIA/80's boxes are text fields over squares printed in the page itself)
                    c.setLineWidth(0.8); c.rect(x0 + 1, y0 + 1, x1 - x0 - 2, y1 - y0 - 2, stroke=1, fill=0)
        for name in checks:
            if where(name) != pi: continue
            x0, y0, x1, y1 = wd[name][0]['rect']; sz = min(x1 - x0, y1 - y0) - 2
            c.setFont('Lexend', sz); c.drawCentredString((x0 + x1) / 2, y0 + (y1 - y0 - sz * 0.72) / 2, 'X')
        for name, text in values.items():
            if where(name) != pi: continue
            x0, y0, x1, y1 = wd[name][0]['rect']; lines = str(text).split('\n')
            size = min(11.0, (y1 - y0 - 2) / len(lines) / 1.15)
            size = min(fit(c, l, 'Lexend', x1 - x0 - 6, size) for l in lines)
            lead = size * 1.15; top = y0 + (y1 - y0 + lead * len(lines)) / 2 - size
            for i, l in enumerate(lines):
                c.setFont('Lexend', size); c.drawString(x0 + 3, top - i * lead, l)
        c.save()
        if '/Annots' in page: del page[NameObject('/Annots')]
        page.merge_page(PdfReader(io.BytesIO(buf.getvalue())).pages[0]); w.add_page(page)
    tmp = out + '.tmp.pdf'
    with open(tmp, 'wb') as f: w.write(f)
    gs = shutil.which('gs')
    if gs and subprocess.run([gs, '-q', '-o', out, '-sDEVICE=pdfwrite', '-dEmbedAllFonts=true', '-dSubsetFonts=true', '-dPDFSETTINGS=/prepress', tmp], capture_output=True).returncode == 0 and os.path.getsize(out) > 1000:
        os.unlink(tmp)
    else: os.replace(tmp, out)
    return sign

def build_form(J, job):
    k = job['kind']; v = {}; ck = []
    if k == 'declaration':
        v = {'Title of Invention': job['title'], 'Inventor': job['inventor']}; ck = ['This declaration']
    elif k == 'poa82b':
        ck = ['Appoint attorneys listed under customer number', 'The address with the above mentioned customer number', 'Inventor or Joint inventor']
        v = {'Customer Number': CUSTOMER_NO, 'Name of the Signer': job['applicant']}
        if int(job.get('forms_total') or 1) > 1: ck.append('Total number of forms are submitted'); v['Number of Forms'] = str(job['forms_total'])
    elif k == 'poa80':
        ck = ['Checkbox to indicate the appointed practitioners are associated with a customer number',
              'Checkbox to indicate that the correspondence address for the application should be the address associated with the identified customer number']
        v = {'Customer number': CUSTOMER_NO, 'Customer number associated with the desired correspondence address': CUSTOMER_NO,
             'Assignee name and address': '\n'.join([job['assignee'], ', '.join(job.get('address_lines') or [])]).strip(),
             'Name': job.get('signer', ''), 'Title': job.get('signer_title', '')}
    elif k == 's373c':   # option 1 (entire right, title and interest), evidenced by option A, assignment submitted for recordation
        v = {'FirstNamedInventortPatent Owner': job['first_inventor'], 'Application NoPatent No': 'Filed herewith', 'Titled 1': job['title'],
             'Assignee': job['assignee'], 'AssigneeType': job.get('assignee_type', ''), 'Printed or Typed Name': job.get('signer', ''),
             'Title or Registration Number': job.get('signer_title', '')}
        ck = ['Check Box1', 'Check Box9', 'Check Box12']   # 1 entire interest; A assignment from the inventors; p.2 recorded concurrently under 3.11 (box numbers do not follow the page order)
    elif k == 'micro':
        v = {'First Named Inventor': job['first_inventor'], 'Title of Invention': job['title'], 'Name': job['signer']}
        if job.get('joint'): ck = [' The required additional certification form(s) signed by the other joint inventor(s) are included with this form']
    v = {a: b for a, b in v.items() if str(b).strip()}
    return stamp_form(J['forms'], J['font'], k, v, ck, job['out'])


# ---------- assignment ----------
def set_cell(cell, text):
    """Replace a cell's text, keeping the first run's formatting; newlines become separate paragraphs."""
    W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
    ps = cell.paragraphs; first = ps[0]
    rpr = next((copy.deepcopy(r._r.find(W + 'rPr')) for r in first.runs if r._r.find(W + 'rPr') is not None), None)
    for p in ps[1:]: p._p.getparent().remove(p._p)
    for r in list(first._p):
        if r.tag != W + 'pPr': first._p.remove(r)
    para = first
    for i, line in enumerate(str(text).split('\n')):
        if i:
            new = copy.deepcopy(first._p)
            for r in list(new):
                if r.tag != W + 'pPr': new.remove(r)
            para._p.addnext(new); para = next(p for p in cell.paragraphs if p._p is new)
        run = para.add_run(line)
        if rpr is not None: run._r.insert(0, copy.deepcopy(rpr))

def value_cells(row):   # the template's rows are "label | label | value | value" (two merged pairs): write the value pair once
    seen = []; out = []
    for c in row.cells:
        if any(c._tc is s for s in seen): continue
        seen.append(c._tc); out.append(c)
    return out

def build_assignment(job):
    import docx
    d = docx.Document(job['template']); t = d.tables[0]
    def row_with(label):
        for r in t.rows:
            if r.cells[0].text.strip().lower().startswith(label): return r
        raise KeyError(label)
    inv = job['inventors']
    set_cell(value_cells(row_with('assignor'))[-1], '\n'.join(inv))
    set_cell(value_cells(row_with('owns an invention'))[-1], job['title'])
    set_cell(value_cells(row_with('assignee'))[-1], '\n'.join([job['assignee']] + list(job.get('address_lines') or [])))
    # signature rows: the "Signature | Printed Name | Signature | Date" header, then one row per assignor
    hdr = next(i for i, r in enumerate(t.rows) if [c.text.strip() for c in r.cells][:2] == ['Signature', 'Printed Name'])
    sig = t.rows[hdr + 1]
    for i, name in enumerate(inv):
        row = sig if i == 0 else None
        if row is None:
            new = copy.deepcopy(sig._tr); t.rows[hdr + i]._tr.addnext(new); row = t.rows[hdr + 1 + i]
        cells = value_cells(row)
        if len(cells) > 1: set_cell(cells[1], name)
    d.save(job['out'])
    if job.get('pdf'): return assignment_pdf(d, job['pdf'], inv, job['font'])


def assignment_pdf(d, out, inv, font_path):
    """The assignment as a Letter PDF for the draw-your-signature page (the server has no Word converter): the text of the filled
    template, then a signing row per assignor. Returns {'boxes': [{page, sig_box: [x, y, w, h], date: [x, y, size]} per assignor]}."""
    from reportlab.pdfgen import canvas
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.platypus import Paragraph
    from reportlab.lib.styles import ParagraphStyle
    from xml.sax.saxutils import escape
    bold = os.path.join(os.path.dirname(font_path), 'Lexend-SemiBold.ttf')
    pdfmetrics.registerFont(TTFont('Lexend', font_path)); pdfmetrics.registerFont(TTFont('LexendB', bold if os.path.isfile(bold) else font_path))
    W, H = 612, 792; L, R = 72, 540; y = H - 72
    body = ParagraphStyle('b', fontName='Lexend', fontSize=10.5, leading=14.5, alignment=4, spaceAfter=0)
    lab = ParagraphStyle('l', parent=body, fontName='LexendB', alignment=0)
    c = canvas.Canvas(out, pagesize=(W, H)); c.setTitle('Patent Application Assignment Agreement')
    def para(text, st, x, w):
        nonlocal y
        for chunk in text.split('\n\n'):
            p = Paragraph(escape(chunk.strip()).replace('\n', '<br/>'), st); _, h = p.wrap(w, H)
            if y - h < 72: c.showPage(); y = H - 72
            p.drawOn(c, x, y - h); y -= h + 8
    c.setFont('LexendB', 15); c.drawCentredString(W / 2, y - 6, 'Patent Application Assignment Agreement'); y -= 40
    t = d.tables[0]
    for r in t.rows:
        cells = value_cells(r); txt = [x.text.strip() for x in cells]
        if txt and txt[0] in ('Signature',) or (txt and txt[0] == '' and len(txt) > 1 and 'behalf' in ' '.join(txt)): break
        if not any(txt): continue
        if len(cells) >= 2 and txt[-1] and txt[0] != txt[-1] and len(txt[0]) < 60:   # "label | value" row
            top = y; para(txt[0], lab, L, 150); yl = y; y = top; para(txt[-1], body, L + 160, R - L - 160); y = min(y, yl)
        else: para(txt[0], body, L, R - L)
    y -= 10
    boxes = []
    for name in inv:
        if y - 70 < 72: c.showPage(); y = H - 72
        c.setFont('Lexend', 10.5); c.setLineWidth(0.6)
        c.line(L, y - 46, L + 230, y - 46); c.line(L + 300, y - 46, R, y - 46)
        c.setFont('Lexend', 9); c.drawString(L, y - 58, 'Signature of Assignor: ' + name); c.drawString(L + 300, y - 58, 'Date')
        boxes.append({'page': c.getPageNumber() - 1, 'sig_box': [L, y - 44, 220, 40], 'date': [L + 304, y - 42, 11]})
        y -= 80
    c.save()
    return {'boxes': boxes}


# ---------- final specification / figures: what Patent Center accepts ----------
def run_tool(tools, name, args):
    r = subprocess.run([sys.executable, os.path.join(tools, name)] + args, capture_output=True, text=True)
    return r.returncode, (r.stdout + r.stderr)

def spec_structure(path):
    """Patent Center splits the DOCX into specification / claims / abstract by their headings; report what would stop it."""
    import docx
    paras = [re.sub(r'\s+', ' ', p.text).strip() for p in docx.Document(path).paragraphs]; paras = [p for p in paras if p]
    notes = []
    ci = next((i for i, p in enumerate(paras) if re.fullmatch(r'(claims|what is claimed is:?|(i|we) claim:?|claims:)', p, re.I)), None)
    ai = next((i for i, p in enumerate(paras) if re.fullmatch(r'abstract( of the (disclosure|invention))?:?', p, re.I)), None)
    if ci is None: notes.append('Specification: no CLAIMS heading (Patent Center needs it to find the claims)')
    if ai is None: notes.append('Specification: no ABSTRACT heading (Patent Center needs it to find the abstract)')
    if ai is not None:
        words = len(' '.join(paras[ai + 1:]).split())
        if words > 150: notes.append(f'Specification: the abstract is {words} words (37 CFR 1.72(b): at most 150)')
        if ci is not None and ci > ai: notes.append('Specification: the ABSTRACT comes before the CLAIMS (it must be on its own page after them)')
    return notes

def finalize(src, out, what, tools):
    """Copy SRC to OUT made acceptable to Patent Center: .docx through uspto-docx (fonts, language tags, tracked changes, comments,
    protection), .pdf through uspto-pdf (Letter, 37 CFR 1.84(g) margins, annotations flattened) + Ghostscript when fonts are not
    embedded; anything else (.pptx) copied as is. Returns {ok, notes}."""
    ext = os.path.splitext(src)[1].lower(); notes = []
    if ext == '.docx':
        rc, o = run_tool(tools, 'uspto-docx', ['-o', out, src])
        if rc != 0 or not os.path.isfile(out): return {'ok': False, 'notes': ['DOCX could not be cleaned: ' + o.strip()[-300:]]}
        fixed = [l.split('[BAD]', 1)[1].strip() for l in o.splitlines() if '[BAD]' in l and 'above' not in l]
        if fixed: notes.append(os.path.basename(out) + ' fixed for Patent Center: ' + '; '.join(fixed))
        notes += [os.path.basename(out) + ': ' + l.split('NOTE', 1)[1].strip() for l in o.splitlines() if 'NOTE' in l]
        rc, o = run_tool(tools, 'uspto-docx', ['--check', out])
        if rc != 0: notes.append(os.path.basename(out) + ' STILL FAILS the Patent Center DOCX check: ' + ' '.join(l.strip() for l in o.splitlines() if '[BAD]' in l))
        if what == 'spec': notes += spec_structure(out)
        return {'ok': True, 'notes': notes}
    if ext == '.pdf':
        rc, o = run_tool(tools, 'uspto-pdf', ['-o', out, src])
        if rc != 0 or not os.path.isfile(out): return {'ok': False, 'notes': ['PDF could not be fixed: ' + o.strip()[-300:]]}
        before = [l.split('[BAD]', 1)[1].strip() for l in run_tool(tools, 'uspto-pdf', ['--check', src])[1].splitlines() if '[BAD]' in l]
        rc, o = run_tool(tools, 'uspto-pdf', ['--check', out])
        if 'not embedded' in o and shutil.which('gs'):
            tmp = out + '.gs.pdf'
            subprocess.run(['gs', '-q', '-o', tmp, '-sDEVICE=pdfwrite', '-dEmbedAllFonts=true', '-dSubsetFonts=true', '-dPDFSETTINGS=/prepress', out], capture_output=True)
            if os.path.isfile(tmp) and os.path.getsize(tmp) > 1000: os.replace(tmp, out)
            rc, o = run_tool(tools, 'uspto-pdf', ['--check', out])
        if before: notes.append(os.path.basename(out) + ' fixed for Patent Center: ' + '; '.join(before))
        if rc != 0: notes.append(os.path.basename(out) + ' STILL FAILS the Patent Center check: ' + ' '.join(l.strip() for l in o.splitlines() if '[BAD]' in l))
        return {'ok': True, 'notes': notes}
    shutil.copyfile(src, out)
    return {'ok': True, 'notes': []}


def main():
    if sys.argv[1] == 'title':
        print(spec_title(sys.argv[2])); return
    if sys.argv[1] == 'finalize':   # finalize SRC OUT spec|fig TOOLS_DIR
        print(json.dumps(finalize(*sys.argv[2:6]))); return
    J = json.load(open(sys.argv[2])); res = []
    for job in J['jobs']:
        try:
            sign = None
            if job['kind'] in FORM_FILES: sign = build_form(J, job)
            elif job['kind'] == 'assignment': sign = build_assignment(job)
            res.append({'out': job['out'], 'ok': os.path.isfile(job['out']), 'sign': sign, 'notes': []})
        except Exception as e:
            res.append({'out': job['out'], 'ok': False, 'notes': [f'{type(e).__name__}: {e}']})
    print(json.dumps(res))

if __name__ == '__main__':
    main()
