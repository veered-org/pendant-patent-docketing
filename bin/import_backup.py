#!/usr/bin/env python3
"""Import a DocketTrak *Backup* export (Trademarks / Patents / Domain Names / Copyrights / General / Events sheets).
Usage: import_backup.py DocketTrakBackup.XLSX [--reset]"""
import sys, os, re, html, zipfile, sqlite3, datetime, argparse
from collections import Counter, defaultdict
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ap = argparse.ArgumentParser(); ap.add_argument('file'); ap.add_argument('--reset', action='store_true'); a = ap.parse_args()
z = zipfile.ZipFile(a.file)
ss = [html.unescape(re.sub(r'<[^>]+>', '', m)) for m in re.findall(r'<si>(.*?)</si>', z.read('xl/sharedStrings.xml').decode(), re.S)]
wb = z.read('xl/workbook.xml').decode(); rels = z.read('xl/_rels/workbook.xml.rels').decode()
rid2file = dict(re.findall(r'<Relationship [^>]*?Id="(rId\d+)"[^>]*?Target="/?(?:xl/)?(worksheets/sheet\d+\.xml)"', rels))
if not rid2file:
    rid2file = {m[1]: m[0] for m in re.findall(r'Target="/?(?:xl/)?(worksheets/sheet\d+\.xml)"[^>]*?Id="(rId\d+)"', rels)}
sheets = {n: rid2file[r] for n, r in re.findall(r'<sheet name="([^"]+)" sheetId="\d+" r:id="(rId\d+)"', wb)}

def rows(name):
    s = z.read('xl/' + sheets[name]).decode(); out = []
    for r in re.findall(r'<row[^>]*>(.*?)</row>', s, re.S):
        cells = {}
        for m in re.finditer(r'<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', r, re.S):
            col, attrs, inner = m.groups(); v = ''
            if inner:
                vm = re.search(r'<v>(.*?)</v>', inner, re.S); im = re.search(r'<is>.*?<t[^>]*>(.*?)</t>', inner, re.S)
                if 't="s"' in attrs and vm: v = ss[int(vm.group(1))]
                elif im: v = html.unescape(im.group(1))
                elif vm: v = vm.group(1)
            cells[col] = v.replace('\r', '')
        out.append(cells)
    cols = sorted(out[0], key=lambda c: (len(c), c)); hdr = [out[0][c] for c in cols]
    return [dict(zip(hdr, [r.get(c, '') for c in cols])) for r in out[1:]]

def is_dead(status, live=''):
    if re.search(r'Cancellation (Pending|Terminated)|Petition to Revive|Revived', status or '', re.I): return False
    if (live or '').lower() == 'dead': return True
    return bool(re.search(r'^\s*(Abandoned|Cancelled|IR Cancelled|Expired|Provisional Application Expired)|Registration cancelled|has expired|Patent Expired|expired for failure|abandonment', status or '', re.I))
def num(v): return re.sub(r'[^0-9A-Za-z]', '', v or '')
def xd(v):
    v = (v or '').strip()
    if not v: return None
    if re.fullmatch(r'(19|20)\d{6}', v): return datetime.datetime.strptime(v, '%Y%m%d').date().isoformat()   # DocketTrak Done Date
    try:
        f = float(v)
        if 0 < f < 200000: return (datetime.date(1899, 12, 30) + datetime.timedelta(days=int(f))).isoformat()
        return None
    except ValueError: pass
    for f in ('%m/%d/%Y', '%m/%d/%Y %I:%M:%S %p', '%Y%m%d'):
        try: return datetime.datetime.strptime(v, f).date().isoformat()
        except ValueError: pass
    return None
def num(v): return re.sub(r'[^0-9A-Za-z]', '', v or '')

db = os.path.join(ROOT, 'data', 'docket.db')
if a.reset and os.path.exists(db): os.remove(db)
con = sqlite3.connect(db); con.executescript(open(os.path.join(ROOT, 'schema.sql')).read())
key2id = {}; owners = defaultdict(Counter); stats = {'renamed': 0, 'unresolved': []}
SERIALISH = re.compile(r'^(SN|RN)?[\d/,\s]+$'); DOCKETISH = re.compile(r'^[A-Z]{2,4}\d{3}')
# Firm file numbers by serial/application number, from the client database built off USPTO data (2026-07-16)
DOCKET_BY_SERIAL = {}
try:
    import openpyxl
    wb = openpyxl.load_workbook(os.path.expanduser(os.environ.get('CLIENT_DATABASE_XLSX', '~/Dropbox/matters/Forms/Client_Database.xlsx'))   # optional, read_only=True)
    for row in wb['Matters'].iter_rows(min_row=2, values_only=True):
        fileno, serial, regno = (str(row[1] or '').strip(), num(str(row[5] or '')), num(str(row[6] or '')))
        if DOCKETISH.match(fileno):
            if serial: DOCKET_BY_SERIAL.setdefault(serial, fileno)
            if regno: DOCKET_BY_SERIAL.setdefault(regno, fileno)
except Exception as e: print('client database not used:', e)

def put(kind, r, title, app, reg, filing, grant, extra):
    client = (r.get('Client') or '').strip(); ref = (r.get('Reference Number') or '').strip() or (r.get('Attorney Docket Number') or '').strip()
    if not ref: return
    dt_ref = ref
    if SERIALISH.match(ref):
        # DocketTrak imported this from the PTO and never got a firm file number. Use the attorney docket number if it
        # carries one, else the client database (built from USPTO data by serial), else leave it and flag it.
        adn = (r.get('Attorney Docket Number') or '').strip()
        alt = adn if DOCKETISH.match(adn) else DOCKET_BY_SERIAL.get(num(app) or num(reg), '')
        if alt: ref = alt; stats['renamed'] += 1
        else: stats['unresolved'].append((kind, ref)); r = dict(r, Comments=(r.get('Comments') or '') + ' [no firm file number: needs a docket number]')
    if client in ('', 'Unspecified', 'Unsorted'):   # DocketTrak left the client blank; the file number carries it (3 letters, or legacy P+3)
        cm = re.match(r'^([A-Z]{3})\d{3}', ref) or re.match(r'^(P[A-Z]{3})\d{3}', ref)
        if cm: client = cm.group(1)
    con.execute('INSERT OR IGNORE INTO clients(code) VALUES (?)', (client,))
    cur = con.execute('''INSERT INTO matters(kind,ref,client_code,title,country,app_no,reg_no,pto_status,pto_status_date,status,family_id,attorney,filing_date,grant_date,
        dt_id,dt_ref,active,live,owner,classes,goods,history,comments,entity,pub_no,expiration,app_type,basis,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',
        (kind, ref, client, title.strip(), (r.get('Country') or '').strip(), num(app) if (r.get('Country') or 'United States').strip() in ('United States', '') else (app or '').strip(), num(reg) if (r.get('Country') or 'United States').strip() in ('United States', '') else (reg or '').strip(), (r.get('PTO Status') or '').strip(), xd(r.get('PTO Status Date')),
         (r.get('Status') or '').strip(), extra.get('family', ''), (r.get('Attorney / Agent') or r.get('Attorney Of Record') or '').strip(), filing, grant,
         int(r['Record ID']) if r.get('Record ID', '').isdigit() else None, dt_ref, 1 if r.get('Active', 'Yes') == 'Yes' else 0, extra.get('live', ''), extra.get('owner', ''),
         extra.get('classes', ''), extra.get('goods', ''), extra.get('history', ''), (r.get('Comments') or '').strip(), extra.get('entity', ''), extra.get('pub', ''),
         extra.get('exp'), extra.get('type', ''), extra.get('basis', ''), ''))
    for k in {ref, dt_ref}: key2id.setdefault((kind, (r.get('Client') or '').strip(), k), cur.lastrowid); key2id.setdefault((kind, '', k), cur.lastrowid)
    if extra.get('owner') and client not in ('', 'Unspecified', 'Unsorted'): owners[client][extra['owner'].split(';')[0].strip()] += 1

for r in rows('Trademarks'):
    put('trademark', r, r.get('Mark', ''), r.get('Serial #'), r.get('Registration Number'), xd(r.get('Application Filing Date')), xd(r.get('Registration Date')),
        {'live': r.get('Live / Dead', ''), 'owner': r.get('Owner') or r.get('Applicant', ''), 'classes': r.get('Class', ''), 'goods': r.get('Goods & Services', ''),
         'history': r.get('PTO Prosecution History', ''), 'basis': r.get('Current Filing Basis', ''), 'type': r.get('PTO Mark Type', '')})
for r in rows('Patents'):
    put('patent', r, r.get('Title', ''), r.get('Application Number'), r.get('Patent Number'), xd(r.get('Application Filing Date')), xd(r.get('Date Granted')),
        {'owner': r.get('Assignee') or r.get('Applicant(s)') or r.get('Inventor', ''), 'history': r.get('PTO ImageFileWrapper History', ''), 'entity': r.get('Entity Status', ''),
         'pub': r.get('Publication Number', ''), 'exp': xd(r.get('Expiration Date')), 'type': r.get('Application Type', ''), 'family': r.get('Family ID', '')})
for r in rows('Copyrights'):
    put('copyright', r, r.get('Title') or r.get('Application Title', ''), r.get('Case Number'), r.get('Registration Number'), xd(r.get('Application Filing Date')), xd(r.get('Registration Date')), {'owner': r.get('Copyright Claimant', '')})
for r in rows('Domain Names'):
    put('domain', r, r.get('Domain Root Name') or r.get('Reference Number', ''), '', '', xd(r.get('Created')), None, {'exp': xd(r.get('Expires')), 'owner': r.get('Owner', '')})
for r in rows('General'):
    put('general', r, r.get('Description/Title', ''), '', '', None, None, {'type': r.get('Record Type', '')})

# Client display names: the most common owner/assignee among that client's matters
for code, c in owners.items(): con.execute('UPDATE clients SET name=? WHERE code=? AND name=""', (c.most_common(1)[0][0], code))

kindmap = {'Patent': 'patent', 'Trademark': 'trademark', 'Copyright': 'copyright', 'Domain Name': 'domain', 'General': 'general', 'Tax Payment': 'general'}
n = miss = 0; unmatched = Counter()
for r in rows('Events'):
    kind = kindmap.get(r.get('Record Type', ''), 'general'); client = (r.get('Client') or '').strip(); ref = (r.get('Reference Number') or '').strip()
    mid = key2id.get((kind, client, ref)) or key2id.get((kind, '', ref))
    if not mid:
        # DocketTrak sometimes files trademark events under the registration/serial number
        row = con.execute('SELECT id FROM matters WHERE kind=? AND (app_no=? OR reg_no=?)', (kind, num(ref), num(ref))).fetchone()
        mid = row[0] if row else None
    due = xd(r.get('Docketing Event Date'))
    if not mid or not due: miss += 1; unmatched[(kind, ref)] += 1; continue
    done = (r.get('Done?') or '').strip() == 'Yes'
    con.execute('INSERT INTO events(matter_id,type,due_date,status,source,note,done_at,dt_event_id) VALUES (?,?,?,?,?,?,?,?)',
                (mid, (r.get('Docketing Event') or '').strip(), due, 'done' if done else 'open', 'import', (r.get('Notes') or '').strip(), xd(r.get('Done Date')) if done else None,
                 int(r['Event Id']) if r.get('Event Id', '').isdigit() else None)); n += 1
# Client emails gathered from PaySimple + Gmail (data/client_emails.json, maintained by bin/apply_client_emails.py) survive re-imports
import json
try:
    em = json.load(open(os.path.join(ROOT, 'data', 'client_emails.json'))); k = 0
    for code, d in em.items():
        if not d.get('emails'): continue
        con.execute('INSERT OR IGNORE INTO clients(code) VALUES (?)', (code,))
        con.execute('UPDATE clients SET email=CASE WHEN email="" THEN ? ELSE email END, alt_emails=?, name=CASE WHEN name="" THEN ? ELSE name END WHERE code=?', (d['emails'][0], '; '.join(d['emails'][1:]), d.get('name', ''), code)); k += 1
    print('client emails applied:', k)
except FileNotFoundError: pass
# Dead properties: flag them and propose every open event for one-click confirmation
dead = 0
for mid, st, live, sd in con.execute("select id, pto_status, live, pto_status_date from matters").fetchall():
    if is_dead(st, live):
        con.execute("update matters set dead=1, dead_reason=?, dead_at=? where id=?", (st, sd, mid))
        con.execute("update events set status='proposed', proposed_by='import', proposed_at=datetime('now'), note=trim(note || ' | ' || ?) where matter_id=? and status='open'", ('DocketTrak/USPTO status: ' + st + '. Property is dead, nothing further falls due', mid)); dead += 1
print('dead properties flagged:', dead)
con.execute("INSERT OR REPLACE INTO settings(key,value) VALUES ('imported_at', ?)", (datetime.datetime.now().isoformat(timespec='seconds') + ' from ' + os.path.basename(a.file),))
con.commit()
print('matters', con.execute('select kind,count(*) from matters group by kind').fetchall())
print('file numbers replaced by firm docket numbers:', stats['renamed'], '| still serial-only:', len(stats['unresolved']), stats['unresolved'][:10])
print('events imported', n, 'unmatched', miss, 'open', con.execute("select count(*) from events where status='open'").fetchone()[0])
print('top unmatched refs:', unmatched.most_common(8))
