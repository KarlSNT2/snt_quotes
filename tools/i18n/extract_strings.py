"""Extract translatable strings of snt_quotes, per translation domain."""
import json
import os
import re
import sys

root = sys.argv[1]
out = sys.argv[2]
A = 'Modules.Sntquotes.Admin'
SQ = r"'((?:[^'\\]|\\.)*)'"
strings = {}


def add(dom, s):
    strings.setdefault(dom, set()).add(s.replace("\\'", "'"))


for dp, dn, fn in os.walk(root):
    if any(x in dp for x in ('vendor', '.git', 'tests', 'docs')):
        continue
    for f in fn:
        p = os.path.join(dp, f)
        s = open(p, encoding='utf-8', errors='ignore').read()
        if f.endswith('.php'):
            for m in re.finditer(r"->t\(\s*" + SQ, s):
                add(A, m.group(1))
            for m in re.finditer(r"trans\(\s*" + SQ + r"\s*,\s*(?:\[[^\]]*\]|\$\w+)\s*,\s*'([^']+)'", s):
                add(m.group(2), m.group(1))
            for m in re.finditer(r"\$this->trans\(\s*" + SQ + r"\s*,\s*\$params\s*,\s*\$quote\)", s):
                add('Modules.Sntquotes.Emails', m.group(1))
            for m in re.finditer(r"QuoteException\(\s*" + SQ, s):
                add(A, m.group(1))
            for m in re.finditer(r"\$help\(" + SQ + r"\)", s):
                add(A, m.group(1))
        if f.endswith('.twig'):
            for m in re.finditer(SQ + r"\|trans", s):
                add(A, m.group(1))
        if f.endswith('.tpl'):
            for m in re.finditer(r"\{l s=" + SQ + r"[^}]*?d='([^']+)'", s):
                add(m.group(2), m.group(1))

for x in ['Draft', 'Awaiting internal approval', 'Sent', 'Viewed', 'Change requested', 'Accepted', 'Declined',
          'Expired', 'Ordered', 'Paid', 'Replaced',
          'This action is not possible: the quote is "%from%" and cannot become "%to%".',
          'Quotes marked as expired: %count%.', 'Reminders sent: %count%.']:
    add(A, x)

json.dump({d: sorted(v) for d, v in strings.items()}, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
for d, v in strings.items():
    print(d, len(v))
