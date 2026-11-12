-- =====================================================================================
-- snt_quotes — purge de TOUS les devis (DEV / recette uniquement, jamais en production)
--
-- Préfixe des tables : ps_  → remplacer partout si la boutique en utilise un autre
--                              (voir database_prefix dans app/config/parameters.php).
-- Faire une sauvegarde de la base avant.
--
-- Conservé : les commandes déjà créées depuis des devis, le transporteur technique,
-- les produits techniques, les statuts de commande et la configuration du module.
-- Les devis Opart ne sont pas touchés.
-- =====================================================================================

START TRANSACTION;

-- 1. Paniers techniques des devis : prix imposés (tous) ...
DELETE sp FROM ps_specific_price sp
INNER JOIN ps_snt_quote_cart qc ON qc.id_cart = sp.id_cart
WHERE sp.id_cart > 0;

-- ... règles panier « SNTQ-… » jamais utilisées dans une commande ...
DELETE ccr FROM ps_cart_cart_rule ccr
INNER JOIN ps_cart_rule cr ON cr.id_cart_rule = ccr.id_cart_rule
WHERE cr.code LIKE 'SNTQ-%'
  AND NOT EXISTS (SELECT 1 FROM ps_order_cart_rule ocr WHERE ocr.id_cart_rule = cr.id_cart_rule);

DELETE crl FROM ps_cart_rule_lang crl
INNER JOIN ps_cart_rule cr ON cr.id_cart_rule = crl.id_cart_rule
WHERE cr.code LIKE 'SNTQ-%'
  AND NOT EXISTS (SELECT 1 FROM ps_order_cart_rule ocr WHERE ocr.id_cart_rule = cr.id_cart_rule);

DELETE crs FROM ps_cart_rule_shop crs
INNER JOIN ps_cart_rule cr ON cr.id_cart_rule = crs.id_cart_rule
WHERE cr.code LIKE 'SNTQ-%'
  AND NOT EXISTS (SELECT 1 FROM ps_order_cart_rule ocr WHERE ocr.id_cart_rule = cr.id_cart_rule);

DELETE cr FROM ps_cart_rule cr
WHERE cr.code LIKE 'SNTQ-%'
  AND NOT EXISTS (SELECT 1 FROM ps_order_cart_rule ocr WHERE ocr.id_cart_rule = cr.id_cart_rule);

-- ... et paniers techniques sans commande (ceux avec commande restent : la commande en dépend).
DELETE cp FROM ps_cart_product cp
INNER JOIN ps_snt_quote_cart qc ON qc.id_cart = cp.id_cart
WHERE NOT EXISTS (SELECT 1 FROM ps_orders o WHERE o.id_cart = qc.id_cart);

DELETE c FROM ps_cart c
INNER JOIN ps_snt_quote_cart qc ON qc.id_cart = c.id_cart
WHERE NOT EXISTS (SELECT 1 FROM ps_orders o WHERE o.id_cart = qc.id_cart);

-- 2. Données du module
DELETE FROM ps_snt_quote_cart;
DELETE FROM ps_snt_quote_payment_link;
DELETE FROM ps_snt_quote_message;
DELETE FROM ps_snt_quote_pdf;
DELETE FROM ps_snt_quote_history;
DELETE FROM ps_snt_quote_discount;
DELETE FROM ps_snt_quote_line;
DELETE FROM ps_snt_quote;

-- 3. Numérotation : repart à 00001 (supprimer cette ligne pour garder les numéros)
DELETE FROM ps_snt_quote_sequence;

COMMIT;

ALTER TABLE ps_snt_quote AUTO_INCREMENT = 1;
ALTER TABLE ps_snt_quote_line AUTO_INCREMENT = 1;
ALTER TABLE ps_snt_quote_discount AUTO_INCREMENT = 1;
ALTER TABLE ps_snt_quote_history AUTO_INCREMENT = 1;
ALTER TABLE ps_snt_quote_message AUTO_INCREMENT = 1;
ALTER TABLE ps_snt_quote_payment_link AUTO_INCREMENT = 1;
ALTER TABLE ps_snt_quote_pdf AUTO_INCREMENT = 1;

-- Fichiers à supprimer à la main (FTP / SSH) :
--   var/modules/snt_quotes/   PDF archivés (si le stockage S3 n'est pas configuré ; sinon le dossier du site dans le bucket)
--   upload/snt_quotes/        pièces jointes des messages (garder .htaccess et index.php)
