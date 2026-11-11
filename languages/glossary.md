# Translation glossary

This glossary fixes the word for every term Pixonaut for WooCommerce uses, in
every language the plugin is translated into, and the form of address each
language uses. Read it before translating a string, and keep to it, so that a
term reads the same in the admin, the cart, the emails and Pixonaut's own
pages.

How to use it:

- **Product terms** (blank, template, side, proof and the rest) take the word
  in [Product terms](#product-terms). For Swedish these are Pixonaut's own
  words wherever Pixonaut's Swedish pages have one, so the plugin reads like
  the workspace's hosted pages.
- **WooCommerce terms** (cart, checkout, order, variation and the rest) take
  WooCommerce core's own translation for the locale, listed in
  [WooCommerce terms](#woocommerce-terms). The plugin's strings sit on the
  same pages and in the same emails as WooCommerce's, so the two must agree.
  Menu paths of WordPress itself (Tools > Erase Personal Data, Settings >
  General) take WordPress core's translation.
- **Translate from context, never word for word.** Read the string's
  translator comment (`#.` in the `.po` file) and its context (`msgctxt`):
  they say where the string shows, who reads it and what each placeholder
  holds. Inflect and reorder the glossary's words as the sentence needs; the
  glossary fixes the word, not the sentence.
- A term that is not here is translated the way a native speaker would say it
  on a shop page. Add a new product term here, in every language, when a
  string introduces one.

## Register

One form of address per locale: the one WooCommerce core's translation for
that locale uses, because the plugin's strings sit beside WooCommerce's on the
same pages and in the same emails. Where WordPress has a formal and an
informal variant of a language (`de_DE` and `de_DE_formal`, `nl_NL` and
`nl_NL_formal`, `de_CH` and `de_CH_informal`), each is its own locale with its
own register, again WooCommerce's. The plugin speaks to two readers: the
**customer** (product page, cart, My account, emails) and the **merchant**
(admin screens, order notes, store emails). Both get the locale's register. In
customer emails "we" is the store.

| Language | Locale | Address | Why |
|---|---|---|---|
| English | `en_US` | you | The source. |
| Swedish | `sv_SE` | du | Swedish shops say du, and so do WooCommerce's Swedish translation ("Din varukorg") and Pixonaut's Swedish pages ("Din design"). |
| German | `de_DE` | du | WooCommerce's German translation says du ("Dein Warenkorb", "Hast du einen Gutschein?"). |
| German, formal | `de_DE_formal` | Sie | WooCommerce's formal German translation says Sie ("Ihr Warenkorb", "Haben Sie einen Gutschein?"); a copy of `de_DE` with the address changed. |
| German (Austria) | `de_AT` | du | WordPress core's Austrian translation says du and WooCommerce has no `de_AT` translation, so the store shows WooCommerce's `de_DE` words beside it; a copy of `de_DE`. |
| German (Switzerland) | `de_CH` | Sie | WooCommerce's Swiss German translation says Sie ("Ihr Warenkorb", "Haben Sie einen Gutschein?") and writes ss for ß; a copy of `de_DE_formal` with its spelling and words. |
| French | `fr_FR` | vous | French commerce addresses its customers as vous, and WooCommerce's French translation does too ("Votre panier"). |
| French (Belgium) | `fr_BE` | vous | WooCommerce's Belgian French translation says vous like the French one ("Votre commande"); a copy of `fr_FR` with its words where they differ. |
| French (Canada) | `fr_CA` | vous | WooCommerce's Canadian French translation says vous ("Votre panier", "Avez-vous un code promo?") and writes *courriel* for email. |
| Spanish | `es_ES` | tú | WooCommerce's Spanish translation uses tú ("Tu carrito"), as most Spanish web shops do; usted would sit oddly beside it. |
| Spanish (Mexico) | `es_MX` | tú | WooCommerce's Mexican translation uses tú ("Tu carrito", "¿Tienes un cupón?"); a copy of `es_ES` in Latin American Spanish (ustedes, *celular*, *agregar*, the preterite for what just happened: *No se pudo*, *Pixonaut respondió*). |
| Spanish (Argentina) | `es_AR` | vos | WooCommerce's Argentine translation says vos ("¿Tenés un cupón de descuento?", "Ingresá el código de tu cupón"), with *tu* as the possessive ("Tu carrito"); a copy of `es_MX` with voseo imperatives and present forms (*Revisá*, *Volvé a intentarlo*, *tenés*). |
| Spanish (Colombia) | `es_CO` | tú | WooCommerce's Colombian translation uses tú ("Tu carrito", "¿Tienes un cupón?"); a copy of `es_MX` that says *añadir*, as WooCommerce's Colombian translation always does. |
| Spanish (Chile) | `es_CL` | tú | WooCommerce's Chilean translation uses tú ("Tu carrito", "¿Tienes un cupón?"); a copy of `es_MX` with its words where they differ. |
| Dutch | `nl_NL` | je | WooCommerce's Dutch translation uses je ("Je winkelwagen"), as most Dutch web shops do. |
| Dutch, formal | `nl_NL_formal` | u | WooCommerce's formal Dutch translation says u ("Uw winkelwagen", "U heeft geen toestemming"); a copy of `nl_NL` with the address changed. |
| Dutch (Belgium) | `nl_BE` | je | WooCommerce's Flemish translation says je like the Dutch one ("Je winkelwagen", "Bedankt. Je bestelling is ontvangen."); a copy of `nl_NL` with its words where they differ. |
| Danish | `da_DK` | du | Danish shops and WooCommerce's Danish translation say du ("Din kurv"); De is no longer used in commerce. |
| Norwegian Bokmål | `nb_NO` | du | Norwegian shops and WooCommerce's Bokmål translation say du ("Handlekurven din"); De is no longer used in commerce. |
| Finnish | `fi` | sinä, sparingly | WooCommerce's Finnish translation addresses the reader with the possessive suffix ("Tilauksesi") and otherwise prefers the passive ("Tilaus on otettu vastaan"); do the same and avoid a bare *sinä* or *te*. |

## Product terms

Pixonaut is in English and Swedish. A Swedish word marked † is Pixonaut's own
(its hosted pages or its designer); every other non-English word was chosen
for this glossary, as a native speaker would say it in a web shop. German,
French, Spanish and Dutch nouns carry their article.

| English | Meaning, where it shows | sv | de | fr | es | nl | da | nb | fi |
|---|---|---|---|---|---|---|---|---|---|
| workspace | The Pixonaut account the store connects to. Admin. | arbetsyta | der Arbeitsbereich | l'espace de travail (m.) | el espacio de trabajo | de werkruimte | arbejdsområde | arbeidsområde | työtila |
| Pixonaut studio | Pixonaut's own admin, where the merchant manages the workspace. Admin. | Pixonaut-studion | das Pixonaut-Studio | le studio Pixonaut | el estudio de Pixonaut | de Pixonaut-studio | Pixonaut-studiet | Pixonaut-studioet | Pixonaut-studio |
| brand | A logo and accent colour the Pixonaut workspace keeps; the store's customer pages are drawn in one. Admin. | varumärke | die Marke | la marque | la marca | het merk | brand (et) | merkevare | brändi |
| connect, connection | Linking the store to a workspace; the connection card in the settings. Admin. | ansluta, anslutning | verbinden, die Verbindung | connecter, la connexion | conectar, la conexión | koppelen, de koppeling | forbinde, forbindelse | koble til, tilkobling | yhdistää, yhteys |
| disconnect | Ending the connection and revoking the store's key. Admin. | koppla från | trennen | déconnecter | desconectar | ontkoppelen | afbryde forbindelsen | koble fra | katkaista yhteys |
| key | The store's API key that Pixonaut accepts or revokes. Admin. | nyckel | der Schlüssel | la clé | la clave | de sleutel | nøgle | nøkkel | avain |
| catalogue | The workspace's blanks and templates the admin searches. Admin. | katalog | der Katalog | le catalogue | el catálogo | de catalogus | katalog | katalog | katalogi |
| printshop | Whoever makes the item: the print business an order goes to. Admin, order notes. | tryckeri | die Druckerei | l'imprimeur (m.) | la imprenta | de drukkerij | trykkeri | trykkeri | painotalo |
| printshop order | Pixonaut's order to the printshop for one item; never the WooCommerce order. Admin, order notes. | tryckeribeställning | der Druckauftrag | la commande d'impression | el pedido de impresión | de drukopdracht | trykordre | trykkordre | painotilaus |
| blank | The undecorated item a design is printed on: a t-shirt, a mug. Admin. | grundprodukt | der Rohling | le produit vierge | el producto base | het blanco product | basisprodukt | basisprodukt | pohjatuote |
| blank variant | One buyable combination of a blank's options (the red tee in M). Kept apart from WooCommerce's *variation*. Admin. | grundproduktvariant | die Rohling-Variante | la variante du produit vierge | la variante del producto base | de variant van het blanco product | basisproduktvariant | basisproduktvariant | pohjatuotteen versio |
| Printed on | The label of the blank picker: what the product is printed on, a blank or nothing (the design alone). Admin. | Trycks på | Gedruckt auf | Imprimé sur | Impreso en | Gedrukt op | Trykkes på | Trykkes på | Painetaan |
| found automatically, find automatically | The blank variant matched to the product's own attributes, as the variant picker shows it: "Gelato variant: 28x40\" / 70x100cm (found automatically)", "No Gelato variant found: pick one". Admin. | hittades automatiskt, hitta automatiskt | automatisch gefunden, automatisch finden | trouvée automatiquement, trouver automatiquement | encontrada automáticamente, buscar automáticamente | automatisch gevonden, automatisch zoeken | fundet automatisk, find automatisk | funnet automatisk, finn automatisk | löydetty automaattisesti, etsi automaattisesti |
| store setting | A product setting that follows the Pixonaut settings page until the product changes it; its choices start "Store setting: …". Admin. | butiksinställning | die Shop-Einstellung | le réglage de la boutique | el ajuste de la tienda | de winkelinstelling | butiksindstilling | butikkinnstilling | kaupan asetus |
| template | The authored artwork a customer fills in. Admin. | mall | die Vorlage | le modèle | la plantilla | het sjabloon | skabelon | mal | mallipohja |
| showcase set | A template's named set of example values, such as a real name or photo, that product images can be drawn with instead of its placeholders. Admin. | exempelset (pl. exempelset) | das Beispielset | le jeu d'exemples | el conjunto de muestra | de voorbeeldset | eksempelsæt | eksempelsett | esimerkkisarja |
| showcase picture | A product image drawn with one of the template's showcase sets, on the blank's photo or as the flat design. Admin. | exempelbild | das Beispielbild | l'image d'exemple (f.) | la imagen de muestra | de voorbeeldafbeelding | eksempelbillede | eksempelbilde | esimerkkikuva |
| print position (placement) | One spot on a blank where a print can go: chest, back, sleeve. Pixonaut's API calls it a placement; the admin says print position. Admin. | tryckposition | die Druckposition | l'emplacement d'impression (m.) | la posición de impresión | de drukpositie | trykposition | trykkposisjon | painokohta |
| print area | The part of a placement the design may cover; drawn as a frame on the preview. Admin. | tryckyta | der Druckbereich | la zone d'impression | el área de impresión | het drukgebied | trykområde | trykkområde | painoalue |
| side | One placement a product holds a design for: front, back. Admin, product page, cart. | sida† | die Seite | la face | la cara | de zijde | side | side | puoli |
| view | One photo angle of a placement: flat, on model. Product page, admin. | vy† | die Ansicht | la vue | la vista | het aanzicht | visning | visning | näkymä |
| print size | A size a side can be printed at, chosen by the customer. Product page, admin. | tryckstorlek† | die Druckgröße | la taille d'impression | el tamaño de impresión | het drukformaat | trykstørrelse | trykkstørrelse | painokoko |
| start size | The print size the designer starts on, and the one a standing side is printed at. Admin. | startstorlek | die Startgröße | la taille de départ | el tamaño inicial | het beginformaat | startstørrelse | startstørrelse | aloituskoko |
| soft (image), recommended resolution | An image of a template that prints below the resolution (dpi) Pixonaut recommends at a print size, so it may look blurred; the warnings in the Pixonaut tab and the setting. Admin. | oskarp†, rekommenderad upplösning | unscharf, die empfohlene Auflösung | flou, la résolution recommandée | borroso, la resolución recomendada | onscherp, de aanbevolen resolutie | uskarp, anbefalet opløsning | uskarp, anbefalt oppløsning | epätarkka, suositeltu resoluutio |
| upscale | Enlarging an image's pixels in the Pixonaut studio so it prints sharper at a larger size. Admin. | skala upp | hochskalieren | augmenter la résolution | aumentar la resolución | opschalen | opskalere | oppskalere | kasvattaa resoluutiota |
| accept (a soft image) | Letting an image print below its recommended resolution, at one print size or in the Pixonaut studio. Kept apart from the customer's *approve*. Admin. | acceptera | akzeptieren | accepter | aceptar | accepteren | acceptere | godta | hyväksyä |
| canvas shape | The shape of the canvas customers design on in the designer: the template's own proportions at every print size, or each chosen print size's. The admin asks it as a question, "When a print size has other proportions": keep the template's shape, or take the print size's. Admin. | designytans form (designyta) | die Form der Gestaltungsfläche | la forme de la zone de création | la forma del lienzo | de canvasvorm (het canvas) | lærredsform (lærred) | lerretsform (lerret) | suunnittelualueen muoto (suunnittelualue) |
| design | A customer's filled-in template, one per side. Everywhere. | design† (pl. designer†) | das Design | la création | el diseño | het ontwerp | design (et) | design (et) | suunnitelma |
| personalise, personalised, personalisation | Making a product one's own; the button, the cart line, the item details. Customer. | personalisera, personaliserad, personalisering | personalisieren, personalisiert, die Personalisierung | personnaliser, personnalisé, la personnalisation | personalizar, personalizado, la personalización | personaliseren, gepersonaliseerd, de personalisatie | personalisere, personaliseret, personalisering | personalisere, personalisert, personalisering | personoida, personoitu, personointi |
| fixed design (standing side) | A side printed as the template is, with nothing to fill in, and its one design; the opposite of a side customers fill in. Admin. | fast (fast sida, fast design) | fest (feste Seite, festes Design) | fixe (face fixe, création fixe) | fijo (cara fija, diseño fijo) | vast (vaste zijde, vast ontwerp) | fast (fast side, fast design) | fast (fast side, fast design) | kiinteä (kiinteä puoli, kiinteä suunnitelma) |
| option (of a blank) | One choice of a blank's attribute, such as a size, that a standing side's print area changes with; each has its own design. Admin. | alternativ | die Option | l'option (f.) | la opción | de optie | valgmulighed | alternativ | vaihtoehto |
| designer | The tool the customer personalises with, inline or opened by the Personalise button. Customer, admin. | designverktyg | der Designer | l'outil de personnalisation (m.) | el personalizador | de ontwerptool | designværktøj | designverktøy | suunnittelutyökalu |
| designer link | The Pixonaut link that opens the designer for one product. Admin. | designlänk | der Designer-Link | le lien de personnalisation | el enlace de personalización | de ontwerplink | designlink | designlenke | suunnittelulinkki |
| field | One input of the designer's form; named in the cart line's details. Customer. | fält† | das Feld | le champ | el campo | het veld | felt | felt | kenttä |
| form arrangement | How a side's designer form is grouped and ordered on the product page; kept apart from the form layout. Admin. | formulärordning | die Formularanordnung | l'agencement du formulaire (m.) | la organización del formulario | de groepsindeling van het formulier | formularordning | skjemaoppsett | lomakkeen järjestely |
| preview | The live picture of the design on the product, before it is saved. Customer. | förhandsvisning† | die Vorschau | l'aperçu (m.) | la vista previa | het voorbeeld | forhåndsvisning | forhåndsvisning | esikatselu |
| proof | The rendered picture of a saved design that the customer approves. Customer, emails, admin. | korrektur† | der Korrekturabzug | l'épreuve (f.) | la prueba | de drukproef | korrektur | korrektur | vedos |
| approve, approval | The customer's yes to a design before it is printed. Customer, emails, admin. | godkänna†, godkännande† | freigeben, die Freigabe | valider, la validation | aprobar, la aprobación | goedkeuren, de goedkeuring | godkende, godkendelse | godkjenne, godkjenning | hyväksyä, hyväksyntä |
| approval mode | How a product asks for approval: adding to the cart, a checkbox, or proof by email. Admin. | godkännandesätt | die Freigabeart | le mode de validation | el modo de aprobación | de goedkeuringswijze | godkendelsesmåde | godkjenningsmåte | hyväksyntätapa |
| proof by email | The approval mode that emails a proof after payment. Admin, emails. | korrektur via e-post | Korrekturabzug per E-Mail | épreuve par e-mail | prueba por correo electrónico | drukproef per e-mail | korrektur på e-mail | korrektur på e-post | vedos sähköpostitse |
| reject (deny) | The customer's no to a proof, or the store's at the deadline; the item is not printed. Customer, admin. | avvisa† | ablehnen | refuser | rechazar | afwijzen | afvise | avvise | hylätä |
| ask for changes, changes requested | The customer's request to change a proof; the store email's title. Customer, emails. | be om ändringar†, ändringar efterfrågade† | um Änderungen bitten, Änderungen gewünscht | demander des modifications, modifications demandées | pedir cambios, cambios solicitados | om wijzigingen vragen, wijzigingen gevraagd | bede om ændringer, ændringer ønsket | be om endringer, endringer ønsket | pyytää muutoksia, muutoksia pyydetty |
| edit, correct (a design) | Changing a design from the cart or after asking for changes. Customer. | redigera, rätta† | bearbeiten, korrigieren | modifier, corriger | editar, corregir | bewerken, corrigeren | redigere, rette | redigere, rette | muokata, korjata |
| deadline | The last day to answer a proof by email. Emails, admin. | tidsfrist | die Frist | la date limite | el plazo | de termijn | frist | frist | määräaika |
| reminder | An email to a customer who has not answered a proof. Emails, admin. | påminnelse | die Erinnerung | le rappel | el recordatorio | de herinnering | påmindelse | påminnelse | muistutus |
| held, release | A printshop order Pixonaut takes but keeps back past the free plan's monthly orders, then sends on. Kept apart from WooCommerce's *On hold*. Admin, order notes. | kvarhållen, släppa | zurückgehalten, weiterleiten | retenue, libérer | retenido, liberar | vastgehouden, vrijgeven | tilbageholdt, frigive | holdt tilbake, frigi | pysäytetty, vapauttaa |
| needs attention | An item that cannot go on to the printshop without the merchant. Admin, store emails. | behöver åtgärdas | Handlungsbedarf | à traiter | requiere atención | vraagt aandacht | kræver handling | krever handling | vaatii toimia |
| print, print file | The file made from an approved design, which the printshop prints from. Admin. | tryck†, tryckfil† | der Druck, die Druckdatei | l'impression (f.), le fichier d'impression | la impresión, el archivo de impresión | de druk, het drukbestand | tryk, trykfil | trykk, trykkfil | paino, painotiedosto |
| printed, made, shipped | An item's progress at the printshop. Customer, admin. | tryckt†, tillverkad†, skickad† | gedruckt, hergestellt, versandt | imprimé, fabriqué, expédié | impreso, fabricado, enviado | gedrukt, gemaakt, verzonden | trykt, fremstillet, afsendt | trykket, produsert, sendt | painettu, valmistettu, lähetetty |
| tracking | The carrier's tracking of a shipped item. Customer. | spårning†, spårningsnummer† | die Sendungsverfolgung, die Sendungsnummer | le suivi, le numéro de suivi | el seguimiento, el número de seguimiento | het volgen, het volgnummer | sporing, sporingsnummer | sporing, sporingsnummer | seuranta, seurantanumero |
| automation | A workspace's own rule in Pixonaut that can place a printshop order. Order notes. | automatisering | die Automatisierung | l'automatisation (f.) | la automatización | de automatisering | automatisering | automatisering | automaatio |
| plan, free plan, upgrade | What the workspace pays for in Pixonaut. Admin, order notes. | plan, gratisplan, uppgradera | der Tarif, der kostenlose Tarif, upgraden | l'offre (f.), l'offre gratuite, passer à une offre supérieure | el plan, el plan gratuito, mejorar el plan | het abonnement, het gratis abonnement, upgraden | abonnement, gratisabonnement, opgradere | abonnement, gratisabonnement, oppgradere | paketti, ilmainen paketti, päivittää |
| watermark | The mark across a proof, set in the workspace. Admin. | vattenstämpel | das Wasserzeichen | le filigrane | la marca de agua | het watermerk | vandmærke | vannmerke | vesileima |
| ability | An action the store offers an assistant (the WordPress Abilities API), such as "Create product", which Pixel runs as a tool. Admin. | förmåga | die Fähigkeit | la capacité | la habilidad | de vaardigheid | evne | evne | kyky |
| credits | What the workspace pays Pixel's answers with. Admin. | krediter | die Credits | les crédits | los créditos | de credits | kreditter | kreditter | krediitit |
| member | A person in the workspace, such as the one who connected the store. Admin. | medlem | das Mitglied | le membre | el miembro | het lid | medlem | medlem | jäsen |

Kept as they are, in every language: **Pixonaut**, **WooCommerce**,
**Pixonaut for WooCommerce**, **Live** and **Sandbox** (the names of the
workspace's two environments, as the Pixonaut studio shows them; in running
text *sandbox* may be inflected), **API**, **SDK**, **CDN**, **webhook**,
**dpi** and **mm**.

## WooCommerce terms

The words WooCommerce core's own translation uses (WooCommerce stable on
translate.wordpress.org). Take them as they are, with the casing WooCommerce
gives them when they name a page, button or menu. Where WooCommerce gives an
order status its own context, the status word is listed.

| English | Where the plugin uses it | sv | de | fr | es | nl | da | nb | fi |
|---|---|---|---|---|---|---|---|---|---|
| Cart | Product page notices, cart line details, emails. | Varukorg | Warenkorb | Panier | Carrito | Winkelwagen | Kurv | Handlekurv | Ostoskori |
| Your cart | Customer notices. | Din varukorg | Dein Warenkorb | Votre panier | Tu carrito | Je winkelwagen | Din kurv | Din handlekurv | Ostoskorisi |
| Add to cart | The button the plugin relabels and refers to. | Lägg till i varukorg | In den Warenkorb | Ajouter au panier | Añadir al carrito | Toevoegen aan winkelwagen | Tilføj til kurv | Legg i handlekurv | Lisää ostoskoriin |
| Checkout | Where the item's details also show. | Kassa | Kasse | Validation de la commande | Finalizar compra | Afrekenen | Kasse | Kasse | Kassa |
| Order | Emails, order notes, admin. | Beställning | Bestellung | Commande | Pedido | Bestelling | Ordre | Ordre | Tilaus |
| Your order | Customer emails. | Din beställning | Deine Bestellung | Votre commande | Tu pedido | Je bestelling | Din ordre | Ordren din | Tilauksesi |
| Order #%s | Email subjects and headings (`#{order_number}`). | Beställning #%s | Bestellung Nr. %s | Commande n°%s | Pedido #%s | Bestelling #%s | Ordre nr. %s | Ordrenr. %s | Tilaus #%s |
| Order number | The privacy export. | Beställningsnummer | Bestellnummer | Numéro de commande | Número de pedido | Bestelnummer | Ordrenr. | Ordrenummer | Tilausnumero |
| Order received | The page after payment where the item's details show. | Beställning mottagen | Bestellung eingegangen | Commande reçue | Pedido recibido | Bestelling ontvangen | Ordre er modtaget | Ordre mottatt | Tilaus vastaanotettu |
| Order notes | Where the plugin's notes to the merchant appear. | Beställningsanteckningar | Bestellhinweise | Notes de commande | Notas del pedido | Bestelnotities | Bemærkninger til ordren | Ordrenotater | Tilauksen kommentit |
| Processing | Order status the plugin completes from. | Behandlas | In Bearbeitung | En cours | Procesando | In behandeling | Behandler | Under behandling | Käsittelyssä |
| Completed | Order status once every item shipped. | Färdigbehandlad | Abgeschlossen | Terminée | Completado | Afgerond | Gennemført | Fullført | Valmistunut |
| On hold | WooCommerce's status; not the plugin's *held*. | Pausad | In Wartestellung | En attente | En espera | In de wacht | På hold | På vent | Pidossa |
| Pending payment | Order status. | Inväntar betalning | Bezahlung ausstehend | Attente paiement | Pendiente de pago | Wachtend op betaling | Afventer betaling | Avventer betaling | Odottaa maksua |
| Cancelled | Order status. | Avbruten | Storniert | Annulée | Cancelado | Geannuleerd | Annulleret | Kansellert | Peruttu |
| Refunded | Order status. | Återbetald | Erstattet | Remboursée | Reembolsado | Terugbetaald | Tilbagebetalt | Refundert | Hyvitetty |
| Failed | Order status. | Misslyckad | Fehlgeschlagen | Échouée | Fallido | Mislukt | Mislykket | Mislyktes | Epäonnistui |
| Product | Admin, privacy export. | Produkt | Produkt | Produit | Producto | Product | Vare | Produkt | Tuote |
| Variable product | Admin. | Variabel produkt | Variables Produkt | Produit variable | Producto variable | Variabel product | Varevariant | Variabelt produkt | Muunnelmatuote |
| Variation | A variable product's variation; never a blank variant. Admin. | Variant | Variante | Variation | Variación | Variatie | Variant | Variant | Muunnelma |
| Attribute | A variation's attributes (Red, M). Admin. | Attribut | Attribut | Attribut | Atributo | Attribuut | Egenskab | Attributt | Ominaisuus |
| Item | One line of an order, the plugin's unit of work. | Artikel | Position | Article | Artículo | Item | Enhed | Produkt | Tuote |
| Quantity | Admin, emails. | Antal | Anzahl | Quantité | Cantidad | Aantal | Antal | Antall | Määrä |
| Refund (noun) | Order notes, admin. | Återbetalning | Erstattung | Remboursement | Reembolso | Terugbetaling | Refundering | Refundering | Hyvitys |
| Refund %s manually | The model for "refund it by hand". | Återbetala %s manuellt | %s manuell erstatten | Rembourser %s manuellement | Reembolsar %s manualmente | Betaal %s handmatig terug | Refundér %s manuelt | Refunder %s manuelt | Hyvitä %s manuaalisesti |
| Payment method | Order notes. | Betalningsmetod | Zahlungsart | Moyen de paiement | Método de pago | Betaalmethode | Betalingsmetode | Betalingsmåte | Maksutapa |
| Shipping address | The plugin's *delivery address* of an order. | Leveransadress | Lieferadresse | Adresse de livraison | Dirección de envío | Verzendadres | Leveringsadresse | Leveringsadresse | Toimitusosoite |
| Customer | Everywhere. | Kund | Kunde | Client | Cliente | Klant | Kunde | Kunde | Asiakas |
| Store, Shop | The merchant's shop, as the plugin says "the store". | Butik | Shop | Boutique | Tienda | Winkel | Webshop | Butikk | Kauppa |
| Shop manager | The role that sees the debug tools. | Butiksägare | Shop-Manager | Gestionnaire de boutique | Gestor de la tienda | Winkelmanager | Webshop leder | Butikkadministrator | Kauppias |
| My account | Where the item's details and state show. | Mitt konto | Mein Konto | Mon compte | Mi cuenta | Mijn account | Min konto | Min konto | Oma tili |
| Email | Email settings. | E-post | E-Mail | E-mail | Correo electrónico | E-mail | E-mail | E-post | Sähköposti |
| Recipient(s) | Store email settings. | Mottagare | Empfänger | Destinataire(s) | Destinatario(s) | Ontvanger(s) | Modtager(e) | Mottaker(e) | Vastaanottaja(t) |
| Settings | WooCommerce > Settings > Pixonaut. | Inställningar | Einstellungen | Réglages | Ajustes | Instellingen | Indstillinger | Innstillinger | Asetukset |
| Status | WooCommerce > Status. | Status | Status | État | Estado | Status | Status | Status | Tila |
| Logs | WooCommerce > Status > Logs. | Loggar | Protokolle | Journaux | Registros | Logs | Logs | Logger | Lokit |
| Scheduled Actions | WooCommerce > Status > Scheduled Actions. | Schemalagda åtgärder | Geplante Aktionen | Actions planifiées | Acciones programadas | Geplande acties | Skemalagte handlinger | Planlagte handlinger | Ajastetut toiminnot |
| Webhooks | Connection card. | Webhooks | Webhooks | Crochets Web | Webhooks | Webhooks | Webhooks | Webhooks | Verkkotoimintokutsut |
| Personal data retention | The setting behind "the shop's retention period". | Personuppgiftsbevaring | Aufbewahrung personenbezogener Daten | Conservation des données personnelles | Conservación de datos personales | Persoonsgegevens bewaren | Opbevaring af persondata | Oppbevaring av personopplysninger | Henkilökohtaisten tietojen säilyttäminen |
| Edit | Buttons and links. | Redigera | Bearbeiten | Modifier | Editar | Bewerken | Redigér | Rediger | Muokkaa |
| Remove | Buttons. | Ta bort | Entfernen | Supprimer | Eliminar | Verwijderen | Fjern | Fjern | Poista |
| Cancel | Buttons. | Avbryt | Abbrechen | Annuler | Cancelar | Annuleren | Annullér | Avbryt | Peruuta |

Notes:

- Where a WooCommerce word is a column label rather than prose (Danish
  *Enhed* and Norwegian *Produkt* for *Item*, Finnish *Tuote*), keep it for
  labels and use the ordinary word in sentences when the label would read
  oddly: Danish *vare*, Norwegian *vare*, Finnish *tuote*.
- `de_DE_formal`, `de_CH`: the German words with the formal possessive, as
  WooCommerce's formal and Swiss translations do: *Ihr Warenkorb*, *Ihre
  Bestellung*, *Ihr Konto* for *My account*. `de_CH` also writes ss for ß
  (*Grösse*, *Strasse*), quotes with « », and numbers orders with `#`
  (*Bestellung #{order_number}*) where `de_DE` says *Bestellung Nr.*; every
  other term as `de_DE`. `de_AT` takes every term as `de_DE`.
- The plugin's *held* and WooCommerce's *On hold* are different things; never
  translate one with the other's word.
- The plugin's *blank variant* and WooCommerce's *variation* are different
  things; in Swedish, Danish and Norwegian, where WooCommerce says *Variant*,
  always qualify the blank's (*grundproduktvariant*, *basisproduktvariant*).
- `fr_BE`: *Journal* for *Logs*; every other term as `fr_FR`. `fr_CA`:
  *Courriel* for *Email* (*par courriel*, *adresse courriel*), *Retirer* for
  *Remove*, *Gérant de boutique* for *Shop manager*, and WordPress core's
  *Effacer les données personnelles* and *Adresse web du site Web (URL)*;
  like WooCommerce's Canadian translation it keeps the non-breaking space
  before `:` and inside « », but none before `;`, `?` and `!`.
- `nl_NL_formal`, `nl_BE`: *Artikel* for *Item* (*artikelen*, *het
  artikel*), and WordPress core's *Persoonsgegevens verwijderen*. `nl_BE`
  also takes *Eigenschap* for *Attribute*, *In afwachting* for *On hold*
  and WordPress core's *Website adres (URL)*, and says *gsm* for a phone;
  `nl_NL_formal` takes *Siteadres (URL)* and the formal possessive, *Uw
  winkelwagen*, *Uw bestelling*. Every other term as `nl_NL`.
- `es_AR`, `es_CL`: *Agregar al carrito* for *Add to cart*; `es_MX`,
  `es_CO`: *Añadir al carrito*, as `es_ES`. `es_AR`: *Finalizar la compra*
  for *Checkout*, *Devuelto* for the *Refunded* status, *Cancelada* for
  *Cancelled*, *Falló* for *Failed*. `es_CL`: *Medio de pago* for *Payment method*, and WordPress
  core's *Opciones* > *General* for *Settings* > *General*. `es_MX`,
  `es_AR`, `es_CO`: WordPress core's *Borrar datos personales*. `es_MX`
  says *Indicaciones del pedido* only for the customer's checkout note; the
  admin's *Order notes* stay *Notas del pedido*. Every other term as `es_ES`.
  The Latin American locales quote with “ ” rather than « », and say
  *celular* for a phone.

WordPress core's own words, for menu paths the plugin names:

| English | sv | de | fr | es | nl | da | nb | fi |
|---|---|---|---|---|---|---|---|---|
| Tools | Verktyg | Werkzeuge | Outils | Herramientas | Tools | Værktøjer | Verktøy | Työkalut |
| Erase Personal Data | Ta bort personuppgifter | Personenbezogene Daten löschen | Effacer les données | Borrar los datos personales | Persoonsgegevens wissen | Slet Persondata | Slett personopplysninger | Poista henkilötiedot |
| General (settings screen) | Allmänt | Allgemein | Général | Generales | Algemeen | Generelt | Generelt | Yleinen |
| WordPress Address (URL) | WordPressadress (URL) | WordPress-Adresse (URL) | Adresse web de WordPress (URL) | Dirección de WordPress (URL) | WordPress adres (URL) | WordPress-adresse (URL) | WordPress-adresse (URL) | WordPressin osoite (URL) |
| Site Address (URL) | Webbplatsadress (URL) | Website-Adresse (URL) | Adresse web du site (URL) | Dirección del sitio (URL) | Site adres (URL) | Webstedsadresse (URL) | Nettstedsadresse (URL) | Sivuston osoite (URL) |

## Style

- **Capitalisation.** Every language uses sentence case for headings,
  buttons, labels and email subjects, as the English source does: only the
  first word and proper names are capitalised (German also capitalises its
  nouns). Keep WooCommerce's and WordPress's own casing when quoting one of
  their menus or buttons.
- **Order numbers** follow WooCommerce's pattern for the locale: *Bestellung
  Nr. 123* (de), *Commande n°123* (fr), *Ordre nr. 123* (da), *Ordrenr. 123*
  (nb); the others keep `#`.
- **French** puts a non-breaking space before `:`, `;`, `?` and `!`, as
  WooCommerce's French translation does.
- **Finnish** inflects *Pixonaut* like any noun (*Pixonautissa*,
  *Pixonautin*), and avoids possessive pronouns where the suffix does.
- **German** writes compounds with a hyphen when one part is a name or an
  English word (*Pixonaut-Studio*, *Designer-Link*).
