"""Generate PrestaShop XLIFF catalogues (fr-FR) for snt_quotes and report missing strings."""
import hashlib
import json
import os
import sys
from xml.sax.saxutils import escape

sys.path.insert(0, os.path.dirname(__file__))
from fr import FR, FR_EMAILS, FR_PDF, FR_SHOP, FR_SHOP_EXCEPTIONS  # noqa: E402

root, strings_file = sys.argv[1], sys.argv[2]
strings = json.load(open(strings_file, encoding='utf-8'))
extra_admin = [
    'Reference', 'Customer', 'Sales rep', 'Amount incl. tax', 'Status', 'Valid until', 'Date', 'Order',
    'Download the PDF', 'Create a new draft quote from this one?', 'Company, name, e-mail', 'e.g. >1000 or 500-1000',
    'Send a reminder', 'Mark as expired', 'View', 'Duplicate',
    'The selected quotes will be marked as expired. Customers will no longer be able to accept them.',
    # Libellés des modes de conversion (PaymentMode::label(), traduits dynamiquement)
    'Bank transfer', 'Deferred payment (B2B)', 'Awaiting payment', 'Credit card by phone (HiPay MO/TO)',
    'PayPlug payment link', 'Credit card (online)',
]
domains = {
    'Modules.Sntquotes.Admin': (sorted(set(strings.get('Modules.Sntquotes.Admin', [])) | set(extra_admin)), FR),
    'Modules.Sntquotes.Pdf': (sorted(set(strings.get('Modules.Sntquotes.Pdf', []))), FR_PDF),
    'Modules.Sntquotes.Emails': (sorted(set(strings.get('Modules.Sntquotes.Emails', []) + [
        # Domaine passé par constante (self::DOMAIN) : non détecté par l'extracteur.
        'View and pay my quote', 'View my quote online', 'The customer asks for changes to the quote.',
        'The customer has declined the quote.', 'The customer sent a message about the quote.', 'Quote %reference%: %action%',
    ])), FR_EMAILS),
    # La phrase CGV contient une variable Smarty dans sprintf : non détectée par l'extracteur.
    'Modules.Sntquotes.Shop': (sorted(set(strings.get('Modules.Sntquotes.Shop', []) + ['I have read and accept the [1]terms and conditions of sale[/1] and this quote.'] + list(FR_SHOP_EXCEPTIONS))), FR_SHOP),
}
ignore = {'cart', 'cart_product', 'orders', 'snt_quote'}
missing = []
out_dir = os.path.join(root, 'translations', 'fr-FR')
os.makedirs(out_dir, exist_ok=True)
for domain, (sources, dico) in domains.items():
    units = []
    for source in sources:
        if source in ignore:
            continue
        target = dico.get(source)
        if target is None:
            missing.append((domain, source))
            continue
        uid = hashlib.md5(source.encode('utf-8')).hexdigest()
        units.append(
            '      <trans-unit id="%s">\n        <source>%s</source>\n        <target>%s</target>\n      </trans-unit>'
            % (uid, escape(source), escape(target))
        )
    name = domain.replace('.', '')
    xml = ('<?xml version="1.0" encoding="UTF-8"?>\n'
           '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">\n'
           '  <file original="snt_quotes" source-language="en-US" target-language="fr-FR" datatype="plaintext">\n'
           '    <body>\n' + '\n'.join(units) + '\n    </body>\n  </file>\n</xliff>\n')
    open(os.path.join(out_dir, name + '.fr-FR.xlf'), 'w', encoding='utf-8', newline='\n').write(xml)
    print(domain, len(units), 'units')
for d, s in missing:
    print('MISSING', d, '|', s)
