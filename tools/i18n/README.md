# Traductions

Les chaînes sources sont en anglais (convention PrestaShop) ; le catalogue français est dans
`translations/fr-FR/*.xlf`, généré à partir de `fr.py`.

Après ajout de chaînes :

```bash
python tools/i18n/extract_strings.py . /tmp/strings.json
python tools/i18n/build_xlf.py . /tmp/strings.json   # liste les chaînes « MISSING »
```

Complétez `fr.py`, relancez `build_xlf.py`, puis videz le cache PrestaShop.
Les traductions peuvent aussi être ajustées dans le BO : International > Traductions > Modules installés.
