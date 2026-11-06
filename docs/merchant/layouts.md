---
title: Product page layouts
description: How customers personalise a product on its page, and what they see when they cannot.
sidebar:
  order: 4
---

A personalised product shows the Pixonaut designer on its product page. The
**Default layout** setting (**Pixonaut > Storefront**) picks how;
a product can override it with **Layout** on its Pixonaut tab, where **Store
default** follows the setting.

## Inline

The live preview of the customer's design takes the place of the product
images, and the form they personalise it with sits at the top of the
add-to-cart form, above the quantity and the **Add to cart** button.

- Clicking **Add to cart** makes a proof of the design and adds the product
  with it. The designer is locked while that happens, so the design added is
  the one shown, and the button shows a spinner and takes no second click
  until the next page opens or an error is shown.
- On a product whose approval mode is the checkbox, the **I approve the
  preview** box sits under the form; its word **preview** opens the proof. See
  [Approval modes](../approval-modes/).
- On a product printed on more than one side (a front and a back), a tab
  per side sits at the top of the form, named as you named the side. The
  customer designs one side at a time; picking a tab shows that side's
  preview and form. See [Sides](#sides).
- On a phone the preview spans the screen's width, and a very tall design is
  drawn narrower. A preview short enough to leave most of the screen to the
  form stays at the top of the screen while the customer scrolls through the
  form under it; a taller one scrolls away with the page, so the customer can
  always reach every field. On a larger screen the whole picture fits in the
  window under the theme's header: a tall design on a short window is drawn
  narrower rather than running below the fold.
- The page opens at the layout the designer ends up with: the preview at the
  shape of the product's photo with a spinner over it, the views under it
  and the form's steps and fields in place, so the page does not move when
  the designer has loaded. The store reads what it needs for that from
  Pixonaut as it makes the page.
- The designer loads when the customer scrolls to it or touches the form, so
  a product page loads as fast as before until it is used. Products that are
  not personalised load nothing of the plugin.
- On a product linked to a blank, the preview draws the design onto the
  blank's photos, in the views ticked on the product and in the colour of
  the blank variant being bought; the customer switches between the views
  in the strip of pictures under the preview, as in a product gallery. See [Views](../linking-products/#views). A product linked
  to a template alone shows the design on its own.

It works with classic themes and with block themes, whether the product
template uses the Product Image Gallery or Product Gallery block, and the Add
to Cart Form or Add to Cart with Options block. On a personalised product the
Add to Cart with Options block sends the page's form, as it does whenever an
extension adds a field to it.

### Form layout

The **Form layout** setting picks how the designer lays out its form, inline
and in Pixonaut's own designer the Personalise button opens:

- **Template's choice** (the default): as the template's author set it in
  Pixonaut, and every step on one page when they set nothing;
- **One page**: every step on the page, each under a numbered heading;
- **Steps**: one step at a time, with **Back** and **Next**.

A product can override it with **Form layout** on its Pixonaut tab, where
**Store default** follows the setting.

### Product options as their own groups

Off by default, a product's options, such as size or colour, stand on top of
the designer's form, unnumbered. To give them groups of their own across the
store, tick **As its own group** under **Pixonaut > Storefront**. Each option
then gets a group named after it, in the product's attribute order, and
**Place of the option groups** says where they stand:

- **First** (the default): 1 Storlek, 2 Färg, 3 Foto, 4 Text.
- **Last**: 1 Foto, 2 Text, 3 Storlek, 4 Färg.

### Group numbers

**Group numbers**, on by default, numbers the groups of the designer's form
on the product page. Off, a form on one page shows the group names with no
numbers, and a form in steps leaves out its **Step 1 of 4** line. Pixonaut's
own designer the Personalise button opens keeps its numbers.

### A product's own choice

Changing a setting changes every product following it, with nothing to update
on each, and a new product follows it. To make a product differ, choose
**Options in the form** and **Group numbers** on its Pixonaut tab, where
**Store default** follows the settings. A variation follows its product, on
another template too, and a customer editing a design from the cart or a proof
sees the same form.

A side whose **Groups** you arranged by hand keeps them, whatever **Options in
the form** says. **Group numbers** still applies to it: choose **On** on the
product to keep its numbers while the store shows none.

On a product printed on one side, the inline designer takes the product's
options and the **Add to cart** row into its form, so the customer goes
through one flow:

- With **Steps**, the first step, **Options**, holds the variation fields of
  a variable product (size, say) and the print size menu, then come the
  template's steps. The price, the quantity and **Add to cart**, with the
  **I approve the preview** box in the checkbox approval mode, show on the
  last step only, under **Back**.
- On **One page**, the options come first, above the form's numbered steps,
  and the **Add to cart** row last.

A product with nothing to choose (no variations and one print size) has no
**Options** step. A product printed on more than one side keeps the
variation fields and **Add to cart** where the theme puts them.

### Sides

Every side the product is linked with (see
[Link a product](../linking-products/)) gets its own design. Clicking
**Add to cart** makes a proof of each side and adds the product as one cart
line holding every design; nothing is added until every side has its proof.
If one side's design cannot be prepared, the form says so and shows that
side, so the customer can check it and try again.

When the order is paid, the line goes to the printshop as one Pixonaut order
with a print of each side. The first side in the product's Pixonaut tab
leads the order, so set up the front first.

In the checkbox approval mode, the word **preview** in the **I approve the
preview** box opens the proof of the side shown, and the one box covers every
side.

### Variations

On a variable product the designer follows the variation the customer
chooses in the variation fields, keeping their design. The designer offers
no colour and size picker of its own: the variation fields already offer
every colour and size the product sells. When a variation has its own Pixonaut
configuration (see [Link a product](../linking-products/#variations)):

- the designer shows the product in the blank variant the variation sells
  (its colour, say) at once;
- another print size is picked in the designer at once;
- another blank, placement, set of views or template opens the designer
  again on it, with the customer's text and images carried over where it has
  the same fields.

The size menu lists the sizes of every variation; choosing a variation
keeps the size the customer is on when the variation sells it, and otherwise
selects the size the variation starts on. When each variation sells one
print size, the variation fields choose it and the designer shows no size
menu of its own. Before the designer has opened, it
opens on the chosen variation's starting size and view. A variation with fewer sides
shows only its own tabs. A variation that is not personalised hides the
designer and is added to the cart as it is. Each change of variation asks
for the preview to be approved again, so the design added is made for the
variation chosen.

### Accent colour, print area frame and product options

Three settings under **Pixonaut > Storefront** fit the designer
to your store:

- **Designer accent colour** marks the chosen view, colour and option,
  fills sliders and draws the form's **Next** button in one colour.
  **None** (the default) takes the accent colour of the store's brand in
  Pixonaut, shown under **Workspace settings** on the connection card, and
  keeps the designer's own colours when the brand has none; **The theme's
  primary colour** takes your theme's accent colour when it names one (as
  the Pixonaut Shop theme does, following the look chosen under
  **Appearance > Editor > Styles**), else the colour of its buttons, such as
  **Add to cart**; **A custom colour** takes the one in **Custom accent colour**.
  It applies to the designer on the product page and on the pages that
  change a design from the cart or after a proof.
- **Show the print area's frame** draws a thin grey frame on the preview
  around where the design can go. It is off by default: the design sits on
  the product photo as it will be printed. It applies to the designer on the
  product page and to Pixonaut's designer the **Personalise** button opens.
- **Product options in the designer** decides how the designer shows a
  variable product's options, such as its sizes, when it draws them in its
  form (on a product with one side). **As the theme shows them** (the
  default) keeps your theme's own field: a dropdown, or the buttons a block
  theme or another plugin draws. **As swatches, like the designer's own
  choices** shows each option as a button ringed in the accent colour when
  chosen, as the designer draws its own choices; an option that cannot be
  bought with the other choices made is greyed out; clicking the chosen
  swatch again clears it. Customers pick a swatch
  with the mouse or with the arrow keys, and the product's price, picture
  and design follow it as they follow the theme's field.

With a block theme that names its accent, border and error colours, fonts
and corner radius, such as the Pixonaut Shop theme, the side tabs, the
approval box and the error messages around the designer take them, and
follow the look chosen in the theme's styles. With any other theme they
look as your theme draws buttons and checkboxes.

### Pixel and cut-outs

What a template turns on in Pixonaut shows in the designer, with nothing to
set in the store:

- **Pixel for customers**: on the product page, a **Create with AI** button
  above the form. It opens a prompt where customers ask Pixel, in their own
  words, to change their design ("write my name in gold"). While Pixel works
  they can **Cancel**; afterwards they get an **Undo** for its last change, or
  **Try again** if it failed. Pixel changes only what the template lets
  customers change. The pages that change a design from the cart or after a
  proof do not show it.
- **Cut-out**: on a picture that offers it, customers can remove the
  background of a photo they upload, on the product page and on the pages
  that change a design.

Both are paid from your workspace's Pixonaut credits.

### Loading other colours and views

Once the designer shows, it loads the product's other colours, views and
sides ahead, so switching to one shows it at once. The **Load other colours
and views** setting (**Pixonaut > Storefront**) picks how much:

- **Auto** (the default): as much as the customer's device and connection
  allow. Nothing is loaded ahead on a slow connection or with data saving
  on, and more on a strong device.
- **In the background**: one at a time while the page is idle. Choose it
  for products with many large photos.
- **Off**: each colour, view or side loads when the customer picks it.
  Choose it if most of your customers shop on their phones over mobile
  data.

On a product with several sides, a side whose tab is not selected loads
nothing ahead until the customer opens its tab, so the side they see loads
first.

### When customers cannot personalise

The product page keeps its images, and the form says *Personalisation is
unavailable right now*, when:

- the store is not connected to Pixonaut, or its connection is paused;
- the product, or the chosen variation, is flagged as not sellable (a notice
  at the top of the admin screens says why);
- the designer could not start in the customer's browser;
- the product's template needs a newer designer than this version of
  Pixonaut for WooCommerce includes;
- the product, or the chosen variation, sells no variant of its blank, or
  Pixonaut cannot show it in its variant: the variant is retired, or none
  of the product's views has a photo of it (the **Blank variant** field
  warns about this; see [Blank variant](../linking-products/#blank-variant)).

When Pixonaut no longer has the placement a product prints on, the store
checks with Pixonaut, flags the product and shows a notice asking you to link
it again. When the designer could not start in a customer's browser, a notice
says so: the site's Content Security Policy, a browser extension, or a
network that cannot reach Pixonaut may block it. The developer guide lists
what the policy has to allow ([Content Security Policy](../../developer/csp/)).
When a template needs a newer designer, a notice names the product: update
Pixonaut for WooCommerce, whose new version brings it.

## Personalise button

The product page keeps its images, and a **Personalise** button takes the
place of the **Add to cart** button: the same place, next to the quantity, and
the same look, as it carries WooCommerce's own button classes. It needs nothing
from the theme, so the row looks as your theme draws **Add to cart**, on any
classic or block theme and at any screen width. Clicking it opens Pixonaut's designer on
Pixonaut's own site, in the designer language (see
[Designer language](#designer-language)), with its form laid out as the
**Form layout** setting or the product's override says (see
[Form layout](#form-layout)).

- A product with a front and a back (up to ten sides) opens one designer
  with a step per side; the customer approves every side before coming back.
- On a product linked to a blank, the designer shows the design on the
  blank's photos in the colour of the blank variant being bought, and offers
  no other colour: the cart gets the variation the customer chose on the
  product page. It shows the views ticked on the product; with none ticked,
  or every view of that colour ticked, it shows every view and Pixonaut's
  own view of the design alone. See [Views](../linking-products/#views).
- Approving sends the customer back to the product page. Nothing joins the
  cart yet, and the designer's button reads **Approve and go back**. The design
  takes the place of the product image, one picture per side, the button reads
  **Add to cart**, and a soft note above it says *This is your design*, with a
  **Change** button that reopens the design on Pixonaut's correction page (see
  [Corrections](#corrections)). A product printed on several sides has one
  button per side, named after the side.
- A design on a template with no blank, whose background is see-through, shows on
  white as the product image. The white is only on the page: it is never in a
  print file or the stored design.
- **Add to cart** adds the design with the quantity on the page, which opens on
  the one the customer chose before leaving. The usual *added to your cart*
  message shows, and the customer lands on the item's page, or on the cart when
  the store sends customers there after adding a product. The item's page says it
  is in the cart, with its size and quantity, and offers **Change design**,
  **View cart** and a link to make another with a new design; **Add to cart**
  never shows there ([Editing a design from the cart](../cart-editing/)).
- A plain visit to the product page is always fresh, with the **Personalise**
  button, even right after a design was made or added, so a second design is
  one click away. Only the page the customer came back to shows the design. Its
  address opens for nobody else: in another browser it shows the plain product.
- In the cart, the item's link opens the product page with that item's design,
  for editing. See [Cart editing](../cart-editing/).
- Cancelling sends the customer back to the product page with nothing added.
- When the design cannot be kept for a reason that may pass (Pixonaut could
  not be reached), the product page shows the notice and a **Use my design**
  button that tries the same design again, until it works or the 24 hours are
  over. It only shows beside **Personalise**, never beside **Add to cart**.
- A design that never reaches an order is deleted from Pixonaut once the
  customer's session ends.
- Approving in the designer is the customer's approval of the preview, so on
  a product whose approval mode is the checkbox there is no box to tick. Proof
  by email still asks for approval after payment.
- Coming back to the store in another browser, or after the customer's
  session ended, changes nothing yet: the page says the design is ready and a
  **Use my design** button keeps it, with a quantity of one. Coming back a second
  time with the same design adds nothing and asks the customer to personalise the
  product again.

With **Add to cart after approve in hosted page** on (**Pixonaut > Storefront**), the designer's button reads **Approve and add to cart**. Approving
adds the product to the cart with the quantity chosen before leaving, and the
customer lands on that cart item's page. Only the customer's own trip adds: each
trip to the designer carries a code of its own, which comes back on approving.
A return in another browser or session, or the same return opened again, never
adds by itself: the customer adds the design with **Add to cart**, or presses
**Use my design** first.

If you had this setting on in an earlier version, turn it on again: an update
switched it off when it was briefly removed.

Each product (each variation, on a variable product) has one designer link
per language, made when the product is saved and kept until the product
changes in a way the designer shows. In the Pixonaut studio the link is
named after the product, works until it is withdrawn and has no design
limit. When Pixonaut refuses to make it, for example because a print size
does not fit the template, the product's screen says why once it is saved.
Turning personalisation off for the product, or deleting it, withdraws its
link; when Pixonaut cannot be reached then, the store asks again later until
it is withdrawn. Disconnecting the store withdraws every link; the store makes new ones
once it is connected again. A link withdrawn in the studio is forgotten as
soon as Pixonaut tells the store, and the next customer gets a new one; a
link regenerated in the studio keeps working at its new address.

When customers cannot personalise (the same cases as above), the product page
says *Personalisation is unavailable right now* where the button would be.

On a variable product the button follows the variation the customer chooses.
A variation that is not personalised (see
[Link a product](../linking-products/#variations)) hides the button and
shows **Add to cart**, and is added to the cart as it is. A design belongs to
the variation it was made for: choosing another variation brings back the
product's image and the **Personalise** button, and choosing the first again
shows its design. A product that is
not personalised itself shows the button once a variation that is
personalised on the button layout is chosen.

A variation with its own Pixonaut configuration has a **Layout** field too.
**As the product says**, the choice it starts on, follows the product's
layout, and the product's **Store default** follows the setting. A variation
set to **Inline** on a product on the button says personalisation is
unavailable, as the button's page cannot show the inline designer.

The product's price shows once: the page opens on the price of the variation
the form opens on, or the product's range when it opens on none, and choosing
a variation puts that variation's price in its place. Pressing the button
before choosing a variation stays on the product page and asks for the options
still to choose.

### Corrections

A design made in Pixonaut's designer is changed there too, on Pixonaut's
correction page, which is in English.

- **From the product page**: **Change**, or **Change design** on the page of an
  item in the cart, opens the side's design on the correction page. Approving there saves the change as the next revision of
  the same design, on the cart line too when the design is in the cart, and
  sends the customer back to the product page, which says *Your design has been
  updated*. Cancelling sends them back with nothing changed. A cart item's name
  links to this page. See [Editing a design from the cart](../cart-editing/).
- **After asking for changes to a proof** (proof by email): the proof page
  sends the customer to the correction page instead of the product page.
  Approving there saves and approves the changed design, and the customer
  lands on their order. See [Approval modes](../approval-modes/#asking-for-changes).

Approving on the correction page is the customer's approval, so there is no
box to tick in the checkbox mode. The variation and print size stay as they
were made, and the price does not change. A trip from the product page works once, for 2 hours,
in the browser it was started from; coming back with a used or expired link
asks the customer to change the design again from its product page.

The texts customers see can be changed; see the
[label keys](../../developer/label-keys/) in the developer guide.

## Designer language

The **Designer language** setting (**Pixonaut > Storefront**)
picks the language of the inline designer, also when it opens a design to
edit it, of Pixonaut's designer the Personalise button opens, and of the
proof page a customer approves their design on. Pixonaut speaks nine
languages: English, Swedish, German, French, Spanish, Dutch, Danish,
Norwegian (Bokmål) and Finnish. The setting offers:

- **Pixonaut workspace default**: the store names no language, and Pixonaut
  shows its pages in your workspace's default language. You set it in the
  Pixonaut studio; the option names the language the store read last, and
  the connection card shows it under **Workspace settings**
  ([Connecting](../connect/)).
- **Follow the site** (the default): each visitor sees the language they
  browse the site in, when Pixonaut has it; a regional language counts as
  its language (Swiss German is German, Canadian French is French). On a
  site in another language, such as Polish, it is the workspace default.
  With a multilingual plugin (WPML, Polylang, TranslatePress), each visitor
  gets their own language.
- Each of the nine languages, named in its own language (**Deutsch**,
  **Français**, **Suomi**, …): that language, whatever the site's.

A proof page sent later, such as with the approval email or a reminder, is
in the language the customer bought in, not yours. Pixonaut's correction
page is in English.
