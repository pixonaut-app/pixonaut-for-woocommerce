---
title: Link a product
description: Link a simple or variable product, and each of its variations, to a Pixonaut blank and its sides, each with a print position, a template and print sizes, so customers can personalise it or buy it printed as the template is.
sidebar:
  order: 3
---

A personalised product is a WooCommerce product linked to what Pixonaut
prints: a **template** customers fill in, and usually a **blank** (the item
it is printed on, such as a t-shirt) and a **print position** on that blank
(such as the front). Connect the store first ([Connect your store](../connect/)).

A product has one or more **sides**, such as a front and a back, each
printed on its own print position with its own template. Each side is either
**personalised**, filled in by the customer, or a **fixed design**, printed as
the template is (see [Fixed designs](#fixed-designs)). A variable product is
linked the same way, and each variation can override it (see
[Variations](#variations)).

## Link a simple product

1. Open the product under **Products**, or add a new one, and keep its type
   *Simple product*.
2. In the **Product data** box, open the **Pixonaut** tab.
3. Tick **Personalise**. The rest of the tab shows once it is ticked,
   grouped as **Printing** (**Printed on**, the blank, with its printshop and
   variant), **Template and print sizes** (the sides), **Product images**,
   and two closed sections: **Views on the product page** and **Store
   settings for this product** (the layout, approval and more). A section
   opens by itself when something in it was changed. Each field's label sits
   on its line, with its help text under it.
   Fields marked with a red asterisk are required: each side's
   **Template**, its **Print position** with a blank, and the width and height
   of a size in mm. One left empty says what is missing under it.
4. Under **Printed on**, search for the blank by name and choose it. A
   product printed by a print partner such as Gelato is picked from the
   partner's own catalogue instead: see
   [Pick the printshop's product](#pick-the-printshops-product). Each blank
   is listed with its first photo. Only blanks with at least one print
   position are listed, and not one still being imported into Pixonaut or
   whose import failed. Its **Printshop** and **Variant** show at once, and
   its views, before you update the product.
5. Set up the first side. With one side, its fields sit inline:
   - Under **Print position**, choose where the design is printed. Each
     print position is listed with the photo of its first view. A blank with
     one print position has it chosen for you.
   - Under **Template**, search for the template by name and choose it.
     Each template is listed with its preview, and the chosen one shows its
     preview beside its name and an **Open in Pixonaut** link to its page in
     the studio. Once a blank and print
     position are chosen, only live templates whose artwork fits the print
     position are listed. A template that fits it at some of the blank's
     sizes only says which it cannot be printed at (see
     [Sizes a template cannot be printed at](#sizes-a-template-cannot-be-printed-at)).
     Without a blank, a template whose background shows through gets a
     warning (see
     [Templates whose background shows through](#templates-whose-background-shows-through)).
   - Under **Print sizes**, choose what the side is sold in (see
     [Print sizes](#print-sizes)).
   - Open **More for this side** for the rest, all optional:
     - **Name** is the name customers see, such as *Front*. Leave it empty to
       use the print position's name. The key beside it identifies the side in
       orders and in Pixonaut; it defaults to the print position.
     - Untick **Customers fill in the template** to print the template as it
       is, a fixed design (see [Fixed designs](#fixed-designs)).
     - Under **Template version**, tick **Stay on one version** to keep the
       side on one version of its template (see
       [Template versions](#template-versions)).
     - Under **When a print size has other proportions**, choose the shape
       customers design this side on (see
       [When a print size has other proportions](#when-a-print-size-has-other-proportions)).
     - Under **Groups**, arrange the designer's form for this product (see
       [Groups](#groups)).
6. To personalise another print position, click **Add side**: the sides
   become cards under a **Sides** heading, each named, and you set up the
   new one the same way. Each print position can be one side, up to ten
   sides. Pixonaut recommends at most four: each side is one more step in the
   designer and slows it down on the customer's device, so the tab says so
   past four. **Remove side** takes a side off.
7. Optionally, open **Views on the product page**, tick the views customers
   see their design in under **Views shown**, and choose the view it
   **Opens on** (see [Views](#views)).
8. Optionally, open **Store settings for this product** to set this product
   apart from the store's settings. Each field's first choice follows the
   store's setting (*Store setting: …*); on a variation, *As the product
   says* follows the product's.
   - Under **Layout**, choose how the product page shows the designer. See
     [Product page layouts](../layouts/).
   - Under **Form layout**, choose how the designer lays out its form. See
     [Form layout](../layouts/#form-layout).
   - Under **Options in the form** and **Group numbers**, choose how the
     product's options and the groups' numbers show in the form (see
     [Product page layouts](../layouts/)).
   - Under **When a print size has other proportions**, choose the shape
     customers design on (see
     [When a print size has other proportions](#when-a-print-size-has-other-proportions)).
   - Under **Approval**, choose how customers approve their design for this
     product. See [Approval modes](../approval-modes/).
9. Click **Update** (or **Publish**).

Saving asks Pixonaut to check every side, and matches the product to one of
the blank's variants (see [Variant](#variant)): check the **Variant** field
the tab then shows.

### Template versions

A side follows its template: each time a new version of the template is
published in Pixonaut, the side moves to it on its own, with nothing to
update in the product. It keeps its setup on the new version: its groups,
print sizes, starting size and whether customers fill it in. A customer who personalised the product
on the version just left can still add that design to the cart.

To keep a side on one version, tick **Stay on one version** under
**Template version**, in **More for this side**, and pick it: *Version 3 (current)* is the latest version
published, then *Version 2*, *Version 1* and so on. The label moves to the new
number after a publish; the number you chose stays the one the side is on.
Click **Update**. The
side stays on that version through later publishes until you change it
here; untick the box to follow the template again, which moves the side to
the latest version.

A variation with its own configuration follows or stays on a version in
the same way, under its own sides.

### Groups

A personalised side's **Groups** setting arranges the designer's form on
this product's page. It is closed whenever the product screen opens: click
**Groups** to open it, and again to close it. Open, it lists the template's groups in their order, each
with its fields, and the product's options used for variations (such as
Size or Colour) in **Product options** above them. **Product options** and
the groups are closed until you open them with the arrow beside their name;
the first group starts open. With nothing changed,
the product page shows the product's options first and then the template's
groups, as the template has them.
If the store or the product puts the options in
[groups of their own](../layouts/#product-options-as-their-own-groups), a side
follows that until you arrange it here.

- Drag an option from **Product options** into any group, such as Size
  next to the shirt's colour, or onto **New group** for a group of the
  product's own. Drag it back, or click **Back to Product options**, to
  undo. Options left there come first, in a group of their own. On a
  product with more than one side, the options stay in the product form.
- Drag groups by their handle, and fields and options within a group or
  between groups. With the keyboard, focus the handle and press Alt and an
  arrow key; press Enter on an option in **Product options** to put it in
  the first group. Clicking **New group** adds an empty group to name; a
  group of your own without a name is not kept.
- Rename a group, or a field's label, in place. **More** shows a field's
  help text, a text field's placeholder and the words of each choice it
  offers. A name you change here is this product's only and shows in every
  language; left empty, the template's own shows.
- **Use the template's groups** clears the arrangement and every name.

The designer's **Next** waits until the customer has chosen each option in
the step. When the template later gains a field, it shows at the end of its
group, marked **New**; a group or field it no longer has is left out, and
the setting says which. The product page keeps working either way: an
option placed in a group the template no longer has comes first again,
with the other options. A
fixed design and a variation's own sides have no Groups setting: a
variation with its own configuration shows the groups of the product's side
of the same key.

### Print sizes

Each side has a table of print sizes. Customers choose from it when they
personalise the product.

- *The print position's size* is the print position's default print area,
  fitted to the template's shape. After saving, the tab shows its size in
  millimetres.
- *A size in mm* takes a name, a width and a height in millimetres. The name
  is what customers see, such as *A4*. Without a name, customers see the
  millimetres. Under them, the tab shows the largest print the side's
  print position takes, such as *Up to 330 × 420 mm*; a width or height past
  it is marked in red with that limit, and Pixonaut refuses it when you
  update the product.

Click **Add the print position's size** or **Add a size in mm** to offer
another size, and **Remove** to take one off. A side with no sizes is sold in
the print position's size.

The designer opens on the size whose radio button is ticked in the **Start**
column, on the inline
designer and on the hosted one alike; with none marked, on the first. A side
with one size starts on it, and the choice is not shown. When Pixonaut
removes the size marked to start, the side starts on the first size it kept.
Products linked before this choice existed start on their first size.

Pixonaut checks every size of a side when you update the product. A size it
cannot print, because it is larger than the print position or does not match the
template's shape, stays in the menu as you entered it, and the product cannot
be bought until you change it and update the product again; the notice says
why.

When Pixonaut cannot be reached, the product is saved without sizes and
cannot be bought, but the tab keeps the menu as you entered it, so updating
the product again once Pixonaut answers checks those sizes. A side you have
not chosen a template for yet is kept too, and the product cannot be bought
until it has one.

### Images printed too soft

A template can hold images of your own, such as a photo or a logo from the
Pixonaut library. The larger the print size, the fewer of an image's pixels
fall on each inch: a 4800-pixel-wide photo printed 1000 mm wide has about 122
dots per inch (dpi), and may look soft or pixelated. When you update the
product, Pixonaut also checks each print size against the resolution it
recommends for the template's images. A size an image falls below it at gets a
warning under the side, such as *The image in "Photo" prints at 122 dpi at
1000 × 1000 mm, below the 300 dpi recommended, so it may look soft*. The image
is named by its layer's name in the template, else its form field's label,
else Pixonaut's word for a picture or the background, in your Pixonaut
workspace's language. When Pixonaut gives no name, the warning says *The image
prints at …* instead. On a product with more than one
side, the warning starts with the side's name. A variation's own print size,
or its attribute value's, is warned of under its **Print size** field in the
same way.

The product can still be bought. On each warning you can:

- Click **Upscale in Pixonaut**. The image opens in the Pixonaut studio's
  library, where you upscale it and use the upscale in the templates that
  hold it. Once the template uses the upscale and is published again, the
  store checks the product again on its own, and the warning goes.
- Choose *Accept at this size*. The warning goes for that image at that size
  of that side. A new image, or another size, is warned of again.
- Choose *Don't warn me again about this image*. That image is not warned of
  on any product again. **Pixonaut > Storefront > Image resolution** lists
  those images; remove one there to be warned of it again.
- Choose *Don't warn me again about any image*. This turns off **Warn about
  soft images** under **Pixonaut > Storefront > Image resolution**; tick it to
  turn the warnings back on.

Your choice is saved when you update the product.

Whether Pixonaut warns, refuses such sizes, or says nothing at all is chosen
for the whole workspace in its settings in the Pixonaut studio, and an image
can be accepted as it is in the studio's library. When the workspace refuses
them, the size is not sold: the product cannot be bought until you change
it, and the warning says why, with **Upscale in Pixonaut** and **Accept in
Pixonaut** but nothing to accept in the store. Turning the store's warnings
off does not change that.

### When a print size has other proportions

A template can be sold at print sizes whose proportions are not its own,
within the ratio tolerance set on the template in Pixonaut: a 5:7 template
sold at 70 × 100 cm, say. The template says, in Pixonaut, which shape
customers design on then. **When a print size has other proportions** lets
the product choose instead:

- *As the template says* (the default) follows the template's setting.
- *Always the template's shape* keeps the canvas in the template's
  proportions whatever size the customer chooses: a size change makes the
  canvas larger or smaller but never another shape. The print is still made
  at the chosen size, cropped (or padded) to it the way the template fits
  its artwork, and the proof shows that crop.
- *Always the chosen print size's shape* reshapes the canvas to each size
  the customer chooses.

**Store settings for this product** holds the product's choice; each side has
its own, in **More for this side**, where *As the product's store settings
say* takes the product's. A variation with its own configuration chooses in the
same way: its sides first, then its own choice, then, when it chooses
neither, the product's. The choice has no effect on a shaped print area or a
repeating design, which are always drawn at the chosen size, and Pixonaut's
hosted designer (the **Personalise** button layout) follows the template.

### Fixed designs

A side with **Customers fill in the template** unticked is a fixed design: it
is printed as its template is, with nothing for the customer to fill in, the
same picture on every item. When you update the product, Pixonaut finds or
makes one design of the template for the side, on its print position at the
start size (the one marked in the **Start** column), and approves it.
Every paid order of the product is printed from that design, so no one has
to make it in the Pixonaut studio first.

- A product whose sides are all fixed designs sells as any other product: its
  page shows no designer, it goes into the cart with **Add to cart**, and
  the customer approves nothing (no approval box, no proof by email).
- The design belongs to this store's connection. Another store connected
  to the same workspace makes its own, and designs made in the Pixonaut
  studio are never used for a fixed design.
- A product can mix the two, such as a fixed front design and a personalised
  back. Customers then design the back alone, and approve it as the
  product's approval mode says; both sides are printed as one item.
- Changing a fixed design's template, print position or start size, or a
  new version of its template, finds or makes the design for what it is
  now when the product is checked again.

#### Blanks whose print area changes with an option

On some blanks the print area changes with an option, most often the size:
the front of a small t-shirt takes a smaller print than the front of an
extra large one. You still set the side up once. When you update
the product, Pixonaut makes one design of the side for each value of that
option the blank sells, such as one for S, one for M and one for L, each
fitted to that value's print area. Every order is printed from the design
of the value it was bought in.

- Each design is printed at the start size when that
  size fits the value's print area. When it does not fit, that value's
  design is printed at its usual size instead.
- Below the template, the side shows a preview per value (**Per size**): the
  design on the blank, the value, and the size it prints at. Check how the
  design sits on each one. A blank that sells more than eight values shows
  the first eight; **Show all** opens the rest.
- Changing the side's template, print position or print sizes replaces the
  previews with a line saying they appear when you update the product.
  Updating makes every value's design again from the side, so they stay in
  step, except a value whose layout you adjusted (below).
- A value whose variants are all retired gets no design. A variant added to
  the blank later cannot be bought until you update the product again.
- A value the template cannot be printed at gets no design either, and its
  variants cannot be bought; the side names it (see
  [Sizes a template cannot be printed at](#sizes-a-template-cannot-be-printed-at)).
  When the template fits none of the values, the product cannot be bought.

##### Adjusting one value's layout by hand

When the design sits wrong on one value, say the artwork is too close to
the collar on S, click **Adjust layout** under that value's preview. Pixonaut
opens its design for its layout alone: you can move and scale each printed
part within the print area, and change nothing else. Approve it there, and
you come back to the product with a line saying the layout is saved. It is
saved at once; you do not need to update the product.

- The value is marked **Adjusted**, and its preview shows your layout.
  Orders in that value are printed from it.
- An adjusted value keeps its layout when the side moves to a new version
  of its template or another print size. The other values follow the side
  as before.
- It follows the side again when you choose another template, blank or
  print position for the side, or when the blank no longer sells that value.
- **Adjust again** opens your layout to change it once more. **Reset** asks
  first, then puts the value back on the side's own design; the layout you
  adjusted is not kept.
- Your adjustment is this product's only. Another product on the same
  template, blank and size keeps its own design.

The tab warns, without stopping the update, when **Customers fill in the
template** does not suit the template:

- Unticked on a template that offers fields to fill in: customers do not
  get to fill them in.
- Ticked on a template that offers none: customers have nothing to
  personalise. Untick it to make the side a fixed design.

The tab knows whether a template offers fields once you choose it from the
template search or update the product.

### Sizes a template cannot be printed at

On a blank whose print area changes with the size, such as a cut-and-sewn
garment printed panel to panel, a template can fit some sizes only: its
shape matches the S panel and not the wider XL one. Pixonaut checks each
size of the blank against the template.

- Under **Template**, the search lists such a template with the sizes it
  cannot be printed at under its name, such as *Cannot print at XL and
  3XL*. Choosing it shows the same warning on the side.
- Once you update the product, the side keeps saying which sizes they are
  until you choose a template that fits every size, or the template changes
  so that it does. Each check of the product, on update or when the
  template or blank changes in Pixonaut, reads them again.
- On a [fixed design](#fixed-designs), those sizes get no design and
  their variants cannot be bought; the sizes the template fits still sell.
  Stop selling the misfit sizes, for example by removing those variations,
  or choose a template that fits them.
- On a personalised side, the variations of those sizes cannot be bought;
  the sizes the template fits still sell. Choose a template that fits every
  size, or stop selling those sizes.

On such a blank, a personalised side sold at *The print position's size* prints
each variation at its own blank variant's print area: the template's shape
fitted into the area's usual size, or, where the print covers the whole area
(a poster printed edge to edge, say), exactly that area. Nothing needs to be
typed per size: a 30×40 poster prints at 300 × 400 mm and a 50×70 one at
508 × 711.2 mm. A variation's **Print size** field (see
[Variations](#variations)) shows the size it prints at.

### Views

A view is a way the blank is photographed, set up in the Pixonaut studio,
such as "Flat" or "On model" for the front of a t-shirt; each view holds a
photo of every colour it was shot in. Customers see their design drawn onto
the photos of the views of each side's print position, in the colour of the
variant they buy (see [Variant](#variant)), and switch between
those views in the designer.

**Views shown**, in the closed **Views on the product page** section, lists
the blank's views by print position, each as a tile with its first photo.
Tick the ones customers see; with none ticked, they see every view. While
some are ticked, the others are washed out, so the tiles show what customers
see. The view the designer opens on carries an **Opens first** badge, and
the section's summary names it. A view without a photo
of the colour being bought is not shown for it, and neither is a view the
studio limits to some option values, such as some colours, for a variant
without them.

After the blank's views, **Design** is the design alone, without the
product, its tile showing the template's preview, on a checked background, the same in every colour. Tick it to offer
it beside the photos, or alone; with no view ticked it is offered too.

The list shows the blank chosen when the product was last updated: after
choosing another blank, update the product to list its views. Views are the
blank's own, so a product moved to another blank is shown in every view of
it until you tick some. When Pixonaut cannot be reached, the tab says so and
updating the product keeps the views chosen before.

**Starting view** chooses the view the designer opens on, among those
ticked (any of the blank's views while none is ticked). Left at **The first
view**, each side opens on the first view of its print position with a photo. On
a product with several sides, the starting view is one side's print position's:
that side opens on it, and every other side on its own first view. Every
side opens on **Design** when it is the starting view. A
starting view that is unticked, or a product moved to another blank, goes
back to the first view.

Product pages read the photos from Pixonaut as the designer loads, never
from the page itself, so a page cache never holds a photo link that has
expired.

### Printshop

Once a blank is linked, the tab shows the **Printshop** that makes its
prints, read-only: the blank's own, its category's (marked *from the blank's
category*), or the workspace default when neither names one. When none of
them names a printshop, the tab says **None set**: Pixonaut cannot route
the blank's orders until one is chosen. The blank decides its printshop, so
the store cannot choose another. To change it, follow **Change in Pixonaut**
to the blank in the Pixonaut studio.

A product without a blank chooses its printshop: **Printshop** lists the
workspace's printshops that print without a blank, with **Workspace default**
first. Left on **Workspace default**, its orders go to the workspace's
default printshop. A workspace with none says "No printshop in your workspace
prints without a blank." A printshop chosen earlier that the workspace no
longer has stays chosen, marked *A printshop no longer connected to the
workspace*, until you choose another. A product saved on a printshop that
needs a blank cannot be bought: the tab says "The printshop prints only on a
blank. Choose a blank, or a printshop that prints without one, in the
product's Pixonaut tab."

### Pick the printshop's product

When your Pixonaut workspace has a print partner with a catalogue, such as
Gelato or Printful, **Choose from** above **Printed on** lists each one's
catalogue (*Gelato’s catalogue*) beside *Your blanks in Pixonaut*. It only
changes what **Printed on** searches; nothing is saved. It starts on the
first catalogue, or on your blanks when the product already has one.

With a catalogue chosen, search **Printed on** for the product as the
printshop sells it. Each is listed with its picture, how many variants it is
sold in, and *Imported* when Pixonaut already has it as a blank. Choosing
one links the product to that blank: the one it was imported as, else one
Pixonaut imports for you there and then. You never make the blank first.

An import takes a minute or two. Meanwhile the tab says *Importing Classic
poster from Gelato into Pixonaut…* and reads the blank again by itself; once
the import is done, its print positions, printshop and variants show. You can
update the product meanwhile, but it cannot be bought until the import is
done and its sides are set. When Pixonaut cannot import a product, such as
one with nothing to print on, the tab says why under **Printed on**.

Each variation is then paired with the printshop's variant its attributes
name (see [Variant](#variant)), and prints at that variant's print area when
its side is sold at the print position's size. A printshop whose workspace
is the store's own (a custom printshop) has no catalogue: link its products
as before.

### Variant

A blank comes in variants, such as each colour in each size. Once a product
is saved on a blank, its **Pixonaut** tab shows the **Variant** it
sells. By default it is found automatically, by option labels: the variant whose every
option is one of the product's attributes, so `Color: Red, Size: M` matches
a product with a **Color** attribute of only `Red` and a **Size** attribute
of only `M`, case and spacing aside. A size matches however it is written:
`70×100 cm`, `70x100cm`, `700 x 1000 mm` and Gelato's `28x40" / 70x100cm`
are one size (within a millimetre, either way round), as are `A4` and
`210 × 297 mm`. A size option no attribute is named after matches the one
attribute naming that size, so your **Format** finds the printshop's
**Size**. When more than one variant matches,
none is chosen. When the labels differ (your size "Medium" for the blank's
`M`), or the product has no such attributes, pick the variant from the list
and update the product. *Find automatically* finds it again, and once it has, the field reads
such as *Gelato variant: 28x40" / 70x100cm (found automatically)*: the
printshop's name for a blank from a print partner's catalogue, else *Blank
variant: Color: Red, Size: M (found automatically)*. When none is found, the
field says so, such as "No Gelato variant found: pick one." Nothing falls
back: the product, or the variation, cannot be bought until you pick its
variant; a variation does not use its product's. Picking a blank or a variant
shows its printshop, variant and views straight away, before you update the
product. A retired variant is listed with
*(retired)*.

The blank's option names, option values and view labels are shown in your
site's language (the one set under **Settings › General**) when the blank has names in it in Pixonaut, else as written
there; the variant a product stores is named the same way. Attributes match
either: a product whose attributes were named before the blank had names in
your site's language keeps its variant.

A variable product's own **Pixonaut** tab has no **Variant** field:
each variation is a variant of its own. Under **Variants** the tab lists
each variation with its variant and that variant's print area, the ones with
no variant first, each saying "No Gelato variant found: pick one." Pick a
variant there, or in the variation's own **Variant** field below its
attributes (the same choice), and update the product. Past 50 variations the
rest are under **Variations**.

When you change the attributes of a product or variation matched by
options, however you change them (the product screen, an import, or
another plugin), it cannot be bought until it is matched again on its new
attributes. That happens in the background within a few moments; it never
keeps selling the variant of its old attributes. A variant you picked is
kept whatever the attributes.

A variation has its own **Variant** field below its attributes,
whether or not it overrides the product; see [Variations](#variations).

A product without a variant, or whose variant is retired in Pixonaut
(retired by you, or no longer sold by its printshop), cannot be bought: see
[When a product cannot be bought](#when-a-product-cannot-be-bought). The
field also warns about a variant none of the product's views has a photo
of, on a side it names: customers cannot design it, and the product page
shows it as unavailable.

### Without a blank

Leave **Printed on** empty (*No blank: the design alone*) to link a template on its own, such as a sign or a
poster. The product then has one side, with only a template and its print
sizes: no print position, view or variant is checked. Its orders go
to the printshop chosen under [Printshop](#printshop), or to the
workspace's default printshop.

There is no print position to take a size from, so add each print size
yourself with **Add a size in mm**: its name and its width and height in
millimetres, in the template's shape (a 5:7 sign, for example, at 500 × 700
mm).
Pixonaut checks each one against the template's aspect ratio when you update
the product. A product without a blank and without a print size cannot be
bought. On a variable product, a print size here is needed only for the
variations that have none of their own or from their attribute value (see
[Print sizes by attribute](#print-sizes-by-attribute)): when every variation
has one, leave the table empty.

#### Templates whose background shows through

A template with no background, or a background image with transparency that
shows, has nothing of its own behind the design. In the designer, customers
see the design on white paper, cut to the template's outline, and the print
comes out on white paper too. Whatever the template leaves empty is white.

When Pixonaut says a template's background shows through, the side warns you
while the product has no blank, both when you choose the template and when
you open a product already set up that way. To fix it, set a background on
the template in Pixonaut. The warning blocks nothing: if transparent is what
you intend, leave it, and customers can still buy the product. With a blank,
or when Pixonaut does not know, there is no warning.

## Variations

A variable product is linked in its **Pixonaut** tab exactly as a simple
product is. Its variations inherit that link: customers personalise every
variation with the same sides, templates, print sizes and views.

To give one variation its own, such as larger print sizes for the larger
sizes of a hoodie:

1. In the **Product data** box, open the **Variations** tab and expand the
   variation.
2. Below its attributes, under **Pixonaut**, tick **Override for this variation**.
   The variation's own Pixonaut fields appear, filled in with the
   product's, to change from there.
3. Change what differs: the blank, its views, the sides, their templates,
   print sizes or the shape customers design on, or the layout or approval. Untick **Personalise** to sell this
   variation unpersonalised while the others are personalised.
4. Click **Save changes** (or update the product).

Each variation is one of its blank's variants. Its **Variant** field,
below its attributes, says which: the variant matched by the variation's
attribute labels (`Color: Red, Size: M` matches the variation Red, M), or
the one you pick there, for example when your sizes read "Medium" and the
blank's `M`. A variation set to "Any" for an attribute matches no variant
on its own; pick one. Click **Save changes** to keep a pick.

Saving asks Pixonaut to check the variation's sides, as for a product. A
size Pixonaut accepted for one variation is sold for that variation only:
a design made at a size another variation offers is refused when it is
added to the cart.

Price each size as its own variation. When a customer picks a size, in the
variation form or in the designer, the product page shows that variation's
price in place of the product's price range, and the range again when they
clear the choice.

A variation whose override fails a check cannot be bought, and the notice
names it; the product's other variations are still sold. A variable
product can be bought while at least one of its variations can.

Untick the override and save to make the variation follow the product
again. Its own link is kept, unused and unchecked, so ticking the override
again restores it, checked again on the next save.

### Print sizes by attribute

On a variable product, a side can take its print size from one attribute
the variations are chosen by, so you enter each size once rather than on
every variation. A poster sold in Posterstorlek 30×40 cm and 50×70 cm, for
example, prints the 30×40 cm variations at 300 × 400 mm and the 50×70 cm
ones at 500 × 700 mm:

1. In the side's **Print sizes**, under **Print size by attribute**, choose
   the attribute, such as *Posterstorlek*. Its values are listed.
2. Enter each value's width and height in millimetres. The label customers
   see is the value's name unless you enter another. A value left empty has
   no size of its own.
3. Update the product.

Every variation with that value prints at its size, checked by Pixonaut for
the variation as its own size is (below). A variation with a size of its
own prints at that instead. A variation set to "Any" for the attribute, or
whose value has no size, prints at the side's print sizes, which it then
needs: on a product without a blank, the product cannot be bought in that
variation until it has one, and the notice says so.

A value's size Pixonaut refuses, or could not check yet, stops only the
variations with that value from being bought, and their **Print size**
field says why.

### A variation's own print size

A variation that follows the product can still be printed at a size of its
own, without overriding the rest of its setup. Under its **Pixonaut**
section, each personalised side has a **Print size** field:

- *As the product* prints the side as the product's **Pixonaut** tab says,
  and names the size: on a blank whose print area changes with the size,
  the variation's blank variant's area. With a size per attribute value,
  it reads *As its Posterstorlek (30×40 cm)*: the size of the variation's
  value.
- *Custom size for this variation* takes a width and a height in
  millimetres, and the label customers see. Only that size is sold for the
  variation; its blank, sides, templates and settings follow the product.

Click **Save changes** (or update the product). Pixonaut checks the size
against the variation's blank variant: a size larger than the variant's
print area, other than the whole area where the print covers it, or off the
template's shape, stays in the field as you entered it, with why under it,
and the variation cannot be bought until you change it. Updating the
product, or picking another blank variant, checks the size again. A
variation that overrides its whole setup sets its sizes in its own fields
instead, and its own size is dropped.

## Product images

While **Use Pixonaut pictures as product images** is on (**Pixonaut >
Storefront**, on by default), a personalised product's image is Pixonaut's
picture of it. The picture becomes the product's own image, its featured
image, so everything that shows the product's image shows it: the shop and
category pages, the front page's product sections, related products, search
results, the cart, order emails, the admin, and anything else that reads the
image, such as product feeds and the picture search engines and social
networks show for the product's page. The picture is the product's first
side's template on the first view of its print position that the product is
shown in, unless you choose another view or photo (below). A variation's
picture is on the photo of its own blank variant, so each colour shows its
own, and becomes the variation's image. A product without a blank shows the
template's own picture.

The pictures are saved in the media library, named `pixonaut-<product id>-…`,
and are made a few moments after the product is personalised, then again
after its blank, print position, template or variant changes, after a new version
of its template is published in Pixonaut, after a blank is updated in
Pixonaut, or when you delete the picture from the media library. Until then,
and whenever Pixonaut cannot be reached, the product keeps the image it has.

### Regenerating a product's image

To have a product's pictures drawn again, for example after changing how
they are drawn, use one of the two buttons under **Draw again**, at the end of
**Product images** in its Pixonaut tab.
Both draw the product image, its gallery's Pixonaut pictures and its
variations' images again from the template version and settings they use
now.

- **Regenerate in background** queues the pictures. Drawing starts at once,
  several pictures at a time, and usually takes a few seconds. While it runs
  a spinner turns beside the buttons; then the line beside them says when
  every picture is drawn, which product or variation Pixonaut could not draw
  (it keeps the image it has, and it is tried again later), or when the
  product shows an image you set yourself, which is kept while its gallery's
  and variations' Pixonaut pictures, if it has any, are drawn again. Reload
  the page to see the new image in the **Product image** box.
- **Regenerate now** draws the pictures right away, in your browser tab, one
  after another, without waiting for the store's background jobs (it works
  when WP-Cron is turned off too). It first asks you to confirm: the site is
  busier while it runs, and the tab has to stay open. A dialog then shows each
  product's progress, and at the end says whether every product image was
  regenerated or which products to look at. A picture your tab could not
  reach the store for stays queued for the background. Closing the tab, or
  clicking **Stop**, leaves the pictures not drawn yet to the background
  queue, as **Regenerate in background** would. **Regenerate now** works
  while a background drawing is still running too, so pictures waiting in the
  queue can be drawn at once.

To do the same for several products, tick them on **Products**, choose
**Regenerate in background** or **Regenerate now** under **Bulk actions**,
and click **Apply**. Each product's row on **Products** has the same two
actions when you hover it. Products that show no Pixonaut picture are passed
over, and so are products that keep an image you set yourself and have no
other Pixonaut pictures to draw.

A picture is drawn once for each thing it is drawn from. The sizes of a sign
come from the same template, so their pictures are identical: the variations
share one picture in the media library, drawn once, and it stays there while
any of them, or the product, still shows it.

The buttons and the actions are there while **Use Pixonaut pictures as
product images** is on.

The **Product image** box says when the image is Pixonaut's picture, and a
personalised variation's options say the same beside its image.

- **The image you set wins.** The first picture takes the place of the image
  the product had, which the plugin remembers. After that, a new picture
  replaces only Pixonaut's earlier picture or an empty image: set an image of
  your own and it stays, however often the picture is drawn again. The box
  then offers **Use the Pixonaut picture**; click it, or remove your image,
  and update the product to have Pixonaut's picture back. For a variation,
  click **Save changes** instead.
- **Back to the image it had.** When a product is no longer personalised,
  or its Pixonaut configuration is removed, it gets back the image it had
  before its first picture, unless you have set another one since.
- **Turning the setting off** gives every product back the image it had
  before its first picture, the same way, deletes the pictures, and draws no
  more. Turning it on again draws them anew.

### Choosing the view and photo

When the product is on a blank with photos for its first side's print position,
**Product images** in the Pixonaut tab shows a card for each picture the
product can show, with a thumbnail of it. **The template's own** card is the
image drawn with the template's own text and pictures, and under **Drawn on**
lets you choose what it is drawn on, each choice a small tile:

- **Main photo**, the default: the first view the product is shown
  in, on the photo of the blank variant it sells.
- **One of the blank's photos** for that print position: every photo of every
  view, scene photos included, named after its view. Choose one to show the
  product on it, for example a lifestyle scene, without hiding other views
  from customers.
- **Design**: the design alone, without the product.

Update the product, and its pictures are drawn again a few moments later.
This choice is used while no showcase set is ticked; each ticked set has its
own (below).

A photo shows the colour it was taken in. A variation's image follows the
product's choice: on the photo chosen when that photo shows the variation's
colour, else on the same view in the variation's colour. To choose one for a
variation alone, use **Variation image drawn on** in its options: **As the
product image**, or one of the blank's photos. Save the variations to draw
it again.

When the blank no longer has the view or photo chosen, for example after the
photo is removed in Pixonaut, the choice is marked **No longer on the
blank**, and the image is drawn on the photo the product page starts on until
you choose another.

### Showcase sets

A template's placeholders, such as *Your name here* or an empty photo frame,
rarely sell a product. In Pixonaut, a template can have **showcase sets**:
named sets of example values, such as a real name and a real photo, that its
pictures are drawn with instead. The product's images can show them.

Once the product is personalised and saved with a template that has sets,
the Pixonaut tab lists the first side's template's sets under **Product
images**:

1. Tick each set the product's images show. The first set ticked, in the
   order listed, is the product image; the others are added to the product
   gallery, before the gallery's own images. Each card's badge says which:
   **Product image**, **Gallery**, or **Left out** past the most a product
   shows. With none ticked, **The template's own** card is the product
   image.
2. For each set, choose what it is drawn on under **Drawn on**, as for the
   image without a set (above): **Main photo**, one of the blank's photos,
   or **Design**, the design alone without the product. The card's
   thumbnail shows the set's preview over the photo chosen, or alone for
   the design; the drawn picture places the design in the photo's print
   area. When the product has no blank, or the blank has no photo for the
   first side's print position, there is nothing to choose: every set is
   the design alone.
3. Update the product. The images are drawn a few moments later.

A variation's image shows the product's first set, on the view and photo
chosen for it as above. With no set ticked, or a template without sets, the
images show the template's own defaults, as **The template's own** card
shows them.

Showcase sets only change the product's images. Customers still start
designing from the template's own defaults, and their designs, proofs and
prints never use a showcase set.

- **Republishing the template** draws the images again, with the sets as
  the new version has them.
- **A set removed from the template** is drawn with the template's own
  defaults instead, and the field marks it as no longer in the template.
  Untick it, or choose another set, and update the product.
- **The sets are listed for the template saved on the first side.** After
  choosing another template, update the product to list its sets.
- **Gallery pictures are Pixonaut's.** Your own gallery images stay after
  them. To drop a set's picture from the gallery, untick the set rather than
  removing the picture from the gallery: it would come back the next time
  the images are drawn.

## When a product cannot be bought

If Pixonaut finds a problem, the product is still saved, but customers
cannot buy it until it is fixed. The Pixonaut tab says why, and a notice at
the top of the admin screens names the product until you fix it. Each
message says what is wrong, where (the side, when the product has more than
one, and the print size Pixonaut refused) and what to change, in the
product's Pixonaut tab or the variation's Pixonaut fields. When Pixonaut
refused a request, the message says what its answer means; an answer this
version of the plugin does not know is quoted as Pixonaut's code. Reasons
include:

- no template chosen, or no print position for a side;
- two sides on the same print position, or with the same key;
- more than ten sides, more than 50 print sizes on a side, or a custom
  size without a usable width and height;
- no print size on a product without a blank, or, on a variable product,
  on a variation that has none of its own or from its attribute value;
- the blank is deleted in Pixonaut, or the blank, print position or template no
  longer exists there;
- a print size does not fit the print position or the template's shape;
- Pixonaut would not make the design of a fixed-design side, or the store may
  not create and approve designs;
- the template of a fixed design cannot be printed at any of the blank's
  sizes;
- Pixonaut could not be reached, for example because the store is not
  connected;
- the product was saved by a newer version of the plugin than the one
  installed (update the plugin).

Fix the choice and update the product; the notice goes away when the check
passes. Deleting the product removes its notice too.
[Troubleshooting](../troubleshooting/#a-product-cannot-be-bought) lists what
to do for each reason.

A product, or a variation, that is no variant of its blank, or whose variant
is retired, cannot be bought either. The product page shows the variation
as unavailable, and a notice names the product and each such variation
until each sells a variant again: pick another in its **Variant**
field.

## When templates and blanks change in Pixonaut

The store checks a product again on its own when its template or blank
changes in Pixonaut, and once after it connects:

- **A template is published again.** The product follows the new version,
  except a side stuck to one version (see
  [Template versions](#template-versions)).
  A customer who personalised the product just before keeps the design
  made from the version it replaced, and can still add it to the cart. A
  fixed design is printed from the new version's design from then on.
- **A template is paused or deleted.** Customers cannot buy the product
  until the template is live again (it is then checked again) or you
  choose another. Saving a product whose template is paused says so on its
  Pixonaut tab too.
- **A template's id is changed.** Every product and variation on the
  template, and the orders made from it, take the new id, on the version
  each was on. Nothing is checked again, and carts and products keep
  working.
- **A blank is updated, deleted or restored.** The product's print positions and
  print sizes are checked against the blank as it is now, and each product
  and variation is matched to its variants again. A deleted blank stops the
  product; the Pixonaut tab says so even before the check has run. When the
  blank is fixed or restored in Pixonaut, the product is checked again and
  sold again, with the print sizes and variants you had chosen. A variant retired in Pixonaut stops
  the products and variations that sell it, until you pick another or sell
  it again.

Each time, the notice names a product that can no longer be bought, and goes
away when it can. When Pixonaut cannot be reached, nothing changes: the
product stays on sale and is checked again a few minutes later.

## Stop personalising a product

Untick **Personalise** and update the product. Customers then buy it as an
ordinary product. The link is kept, so ticking **Personalise** again and
updating restores it, checked again.

## Searches

The search fields ask Pixonaut through your store: your Pixonaut keys never
reach the browser. Results are kept for five minutes, so a template you just
published in Pixonaut may take that long to show up.
