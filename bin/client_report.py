#!/usr/bin/env python3
"""Client report PDF (2026-10-02): every property of one client on a few landscape pages - upcoming deadlines, then each
property with its numbers, dates, status and next deadlines. No narrative, no contact details; the firm logo sits upper right.

    python3 bin/client_report.py data.json out.pdf

data.json is written by lib/clientreport.php (client_report_data): {client, today, firm, matters: [...]}.
Lexend (the firm's document font) is read from templates/; Helvetica is the fallback if the files are missing.
"""
import json, os, sys
from datetime import date
from xml.sax.saxutils import escape
from reportlab.lib import colors
from reportlab.lib.pagesizes import letter, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import inch
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, CondPageBreak, Image

NAVYBAND = colors.HexColor('#0C203B'); NAVY = colors.HexColor('#0c203b'); GOLD = colors.HexColor('#a8841a'); GOLDLINE = colors.HexColor('#c9a227')
GREY = colors.HexColor('#5b6472'); RULE = colors.HexColor('#d9d4c7'); ZEBRA = colors.HexColor('#f7f5ef'); RED = colors.HexColor('#b3261e')
HERE = os.path.dirname(os.path.abspath(__file__)); TPL = os.path.join(os.path.dirname(HERE), 'templates')
REG, BOLD, LIGHT = 'Helvetica', 'Helvetica-Bold', 'Helvetica'
try:
    pdfmetrics.registerFont(TTFont('Lexend', os.path.join(TPL, 'Lexend-Regular.ttf')))
    pdfmetrics.registerFont(TTFont('Lexend-SemiBold', os.path.join(TPL, 'Lexend-SemiBold.ttf')))
    pdfmetrics.registerFont(TTFont('Lexend-Light', os.path.join(TPL, 'Lexend-Light.ttf')))
    REG, BOLD, LIGHT = 'Lexend', 'Lexend-SemiBold', 'Lexend-Light'
except Exception:
    pass

def long_date(iso):
    try:
        d = date.fromisoformat((iso or '')[:10]); return d.strftime('%B ') + str(d.day) + d.strftime(', %Y')
    except Exception:
        return ''

def P(text, style): return Paragraph(text, style)
def esc(s): return escape(str(s or ''))

S = {
    'cell':  ParagraphStyle('cell', fontName=REG, fontSize=8, leading=10.2, textColor=colors.HexColor('#1e2430')),
    'cellb': ParagraphStyle('cellb', fontName=BOLD, fontSize=8, leading=10.2, textColor=NAVY),
    'small': ParagraphStyle('small', fontName=REG, fontSize=7.2, leading=9.2, textColor=GREY),
    'head':  ParagraphStyle('head', fontName=BOLD, fontSize=7.4, leading=9.4, textColor=colors.white),
    'h2':    ParagraphStyle('h2', fontName=BOLD, fontSize=11, leading=14, textColor=NAVY, spaceBefore=10, spaceAfter=4),
    'body':  ParagraphStyle('body', fontName=REG, fontSize=9, leading=12.5, textColor=colors.HexColor('#1e2430')),
    'note':  ParagraphStyle('note', fontName=LIGHT, fontSize=7.6, leading=10, textColor=GREY),
}

class NumberedCanvas(canvas.Canvas):
    """Footer with "Page x of y" (the total is only known at the end)."""
    footer = ''
    def __init__(self, *a, **k): super().__init__(*a, **k); self._pages = []
    def showPage(self): self._pages.append(dict(self.__dict__)); self._startPage()
    def save(self):
        n = len(self._pages)
        for st in self._pages:
            self.__dict__.update(st); w, h = self._pagesize
            self.setStrokeColor(RULE); self.setLineWidth(0.5); self.line(0.5 * inch, 0.47 * inch, w - 0.5 * inch, 0.47 * inch)
            self.setFont(REG, 7.2); self.setFillColor(GREY)
            self.drawString(0.5 * inch, 0.32 * inch, self.footer)
            self.drawRightString(w - 0.5 * inch, 0.32 * inch, 'Page %d of %d' % (self._pageNumber, n))
            super().showPage()
        super().save()

def table(rows, widths, zebra=True):
    t = Table(rows, colWidths=widths, repeatRows=1)
    st = [('BACKGROUND', (0, 0), (-1, 0), NAVY), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
          ('TOPPADDING', (0, 0), (-1, -1), 3.2), ('BOTTOMPADDING', (0, 0), (-1, -1), 3.6), ('LEFTPADDING', (0, 0), (-1, -1), 4.5), ('RIGHTPADDING', (0, 0), (-1, -1), 4.5),
          ('LINEBELOW', (0, 1), (-1, -1), 0.4, RULE), ('LINEBELOW', (0, 0), (-1, 0), 1.2, GOLDLINE)]
    if zebra:
        for i in range(2, len(rows), 2): st.append(('BACKGROUND', (0, i), (-1, i), ZEBRA))
    t.setStyle(TableStyle(st)); return t

def deadlines_cell(m):
    out = []
    for e in m.get('events', []):
        c = ' color="#b3261e"' if e.get('overdue') else ''
        out.append('<font name="%s"%s>%s</font>  %s%s' % (BOLD, c, esc(long_date(e['due'])), esc(e['type']), ' <font color="#b3261e">(past)</font>' if e.get('overdue') else ''))
    if m.get('more'): out.append('<font color="#5b6472">+ %d later, through %s</font>' % (m['more'], esc(str(m.get('last', ''))[:4])))
    return P('<br/>'.join(out) if out else '<font color="#5b6472">None docketed</font>', S['cell'])

def number_cell(label_no, no, when_label, when):
    parts = []
    if no: parts.append('<font name="%s">%s</font>' % (BOLD, esc(no)))
    if when: parts.append('<font color="#5b6472">%s %s</font>' % (when_label, esc(long_date(when))))
    return P('<br/>'.join(parts) if parts else '<font color="#5b6472">-</font>', S['cell'])

def picture(path, maxw, maxh):
    """The patent figure / logo-mark drawing the firm's catalogs hold (2026-10-14), scaled to fit; None if unreadable."""
    if not path or not os.path.isfile(path): return None
    try:
        import io
        from PIL import Image as PILImage
        src = PILImage.open(path); w, h = src.size; k = min(maxw / w, maxh / h, 1.0)
        # downsampled to ~200 dpi at its printed size, else 1400-px figures make the PDF tens of megabytes
        px = (max(1, round(w * k * 200 / 72)), max(1, round(h * k * 200 / 72)))
        if src.mode in ('RGBA', 'LA', 'P'):
            src = src.convert('RGBA'); bg = PILImage.new('RGB', src.size, 'white'); bg.paste(src, mask=src.split()[-1]); src = bg
        buf = io.BytesIO(); src.convert('RGB').resize(px, PILImage.LANCZOS).save(buf, 'JPEG', quality=85); buf.seek(0)
        im = Image(buf, width=w * k, height=h * k); im.hAlign = 'LEFT'; return im
    except Exception:
        return None

def build(data, out):
    c = data['client']; today = data.get('today') or date.today().isoformat(); firm = data.get('firm', 'Your Firm')
    W = landscape(letter)[0] - 1.0 * inch
    doc = SimpleDocTemplate(out, pagesize=landscape(letter), leftMargin=0.5 * inch, rightMargin=0.5 * inch, topMargin=0.5 * inch, bottomMargin=0.62 * inch,
                            title='Client Portfolio - %s' % (c.get('name') or c['code']), author=firm)
    NumberedCanvas.footer = '%s  ·  %s  ·  %s  ·  prepared %s' % (firm, c.get('name') or c['code'], c['code'], long_date(today))
    el = []
    # ---- header: navy band, client on the left, the firm's logo on the right (2026-10-03; the letterhead's look)
    name = c.get('name') or c['code']
    left = P('<font name="%s" size="7.5" color="#c9a227">CLIENT PORTFOLIO</font><br/><font name="%s" size="17" color="#ffffff">%s</font><br/><font name="%s" size="8.5" color="#c8d0dc">%s &nbsp;·&nbsp; Client code %s</font>'
             % (BOLD, BOLD, esc(name), REG, esc(long_date(today)), esc(c['code'])), ParagraphStyle('t', leading=21))
    logo = os.path.join(TPL, 'ff-logo.png'); LW = 1.62 * inch   # 336 x 175 px; its own background is the band's navy
    right = Image(logo, width=LW, height=LW * 175 / 336) if os.path.exists(logo) else P('<para align="right"><font name="%s" size="11" color="#ffffff">%s</font></para>' % (BOLD, esc(firm)), ParagraphStyle('r', leading=14))
    hd = Table([[left, right]], colWidths=[W - LW - 0.3 * inch, LW + 0.3 * inch])
    hd.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, -1), NAVYBAND), ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('ALIGN', (1, 0), (1, 0), 'RIGHT'), ('LINEBELOW', (0, 0), (-1, 0), 2.2, GOLDLINE),
                            ('TOPPADDING', (0, 0), (-1, -1), 7), ('BOTTOMPADDING', (0, 0), (-1, -1), 8), ('LEFTPADDING', (0, 0), (0, 0), 14), ('RIGHTPADDING', (1, 0), (1, 0), 12)]))
    el += [hd, Spacer(1, 8)]
    # ---- counts (no contact details on the report: 2026-10-03)
    lab = lambda k, v: '<font name="%s" color="#0c203b">%s</font>  %s' % (BOLD, k, esc(v))
    act = [m for m in data['matters'] if not m['dead']]; dead = [m for m in data['matters'] if m['dead']]
    kinds = [('patent', 'patent', 'patents'), ('trademark', 'trademark', 'trademarks'), ('copyright', 'copyright', 'copyrights'), ('domain', 'domain', 'domains'), ('general', 'other matter', 'other matters')]
    cnt = ', '.join('%d %s' % (n, pl if n != 1 else sg) for k, sg, pl in kinds for n in [len([m for m in act if m['kind'] == k])] if n)
    counts = lab('Active properties', '%d%s' % (len(act), (' (' + cnt + ')') if cnt else '')) + ('   ' + lab('Inactive', str(len(dead))) if dead else '')
    el.append(P(counts, S['body']))
    # ---- upcoming deadlines, next 12 months
    up = sorted([(e['due'], m, e) for m in act for e in m.get('upcoming', [])], key=lambda x: (x[0], x[1]['ref']))
    el.append(P('Deadlines in the next 12 months', S['h2']))
    if up:
        rows = [[P(x, S['head']) for x in ('Date', 'File', 'Property', 'Deadline')]]
        for due, m, e in up:
            rows.append([P('<font name="%s"%s>%s</font>' % (BOLD, ' color="#b3261e"' if e.get('overdue') else '', esc(long_date(due))), S['cell']), P(esc(m['ref']), S['cellb']),
                         P(esc(m['title'] or '(untitled)') + ('  <font color="#5b6472">%s</font>' % esc(m['country_code']) if m.get('country_code') and m['country_code'] != 'US' else ''), S['cell']),
                         P(esc(e['type']) + (' <font color="#b3261e">(past)</font>' if e.get('overdue') else ''), S['cell'])])
        el.append(table(rows, [1.3 * inch, 1.35 * inch, W - 1.3 * inch - 1.35 * inch - 3.6 * inch, 3.6 * inch]))
    else:
        el.append(P('None.', S['body']))
    # ---- properties by kind
    for kind, title, numlab, datelab, reglab, regdate in (('patent', 'Patents', 'Application', 'filed', 'Patent', 'granted'), ('trademark', 'Trademarks', 'Serial / application', 'filed', 'Registration', 'registered'),
                                                         ('copyright', 'Copyrights', 'Application', 'filed', 'Registration', 'registered'), ('domain', 'Domain names', 'Number', 'filed', 'Registration', 'registered'), ('general', 'Other matters', 'Number', 'filed', 'Registration', 'dated')):
        ms = [m for m in act if m['kind'] == kind]
        if not ms: continue
        el.append(CondPageBreak(1.7 * inch)); el.append(P('%s (%d)' % (title, len(ms)), S['h2']))
        tm = kind == 'trademark'
        hdr = ['File', 'Mark' if tm else 'Title', 'Country', numlab, reglab, 'Status', 'Next deadlines']
        wd = [1.15 * inch, 1.85 * inch, 0.62 * inch, 1.22 * inch, 1.22 * inch, 1.15 * inch]; wd.append(W - sum(wd))
        rows = [[P(x, S['head']) for x in hdr]]
        for m in ms:
            sub = [x for x in (m.get('app_type'), ('Class ' + m['classes']) if tm and m.get('classes') else '', ('Expires ' + long_date(m['expires'])) if m.get('expires') else '') if x]
            tcell = P(esc(m['title'] or '(untitled)') + ('<br/><font size="7.2" color="#5b6472">%s</font>' % esc(' · '.join(sub)) if sub else ''), S['cell'])
            pic = picture(m.get('image'), wd[1] - 9, 0.85 * inch)
            rows.append([P(esc(m['ref']), S['cellb']), [tcell, Spacer(1, 3), pic] if pic else tcell,
                         P(esc(m.get('country_code') or m.get('country') or ''), S['cell']),
                         number_cell(numlab, m.get('app_no'), datelab, m.get('filing_date')), number_cell(reglab, m.get('reg_no'), regdate, m.get('grant_date')),
                         P(esc(m.get('status') or '') + ('<br/><font size="7.2" color="#5b6472">as of %s</font>' % esc(long_date(m['status_date'])) if m.get('status_date') and m.get('status') else ''), S['cell']),
                         deadlines_cell(m)])
        el.append(table(rows, wd))
    # ---- inactive
    if dead:
        el.append(CondPageBreak(1.5 * inch)); el.append(P('Inactive properties (%d)' % len(dead), S['h2']))
        rows = [[P(x, S['head']) for x in ('File', 'Title / mark', 'Kind', 'Country', 'Application', 'Patent / registration', 'Why inactive')]]
        for m in dead:
            rows.append([P(esc(m['ref']), S['cellb']), P(esc(m['title'] or '(untitled)'), S['cell']), P(esc(m['kind'].capitalize()), S['cell']), P(esc(m.get('country_code') or ''), S['cell']),
                         P(esc(m.get('app_no') or '-'), S['cell']), P(esc(m.get('reg_no') or '-'), S['cell']), P(esc(m.get('dead_reason') or m.get('status') or ''), S['cell'])])
        wd = [1.15 * inch, 2.1 * inch, 0.75 * inch, 0.55 * inch, 1.1 * inch, 1.2 * inch]; wd.append(W - sum(wd))
        el.append(table(rows, wd))
    el += [Spacer(1, 8), P('Dates are from the firm\'s docket as of %s. Trademark maintenance and patent maintenance-fee dates run from the registration or grant date; "Next deadlines" lists the nearest %d for each property.' % (long_date(today), data.get('per_matter', 3)), S['note'])]
    doc.build(el, canvasmaker=NumberedCanvas)

if __name__ == '__main__':
    build(json.load(open(sys.argv[1], encoding='utf-8')), sys.argv[2])
