/*! Pixonaut SDK type declarations. Licensed under the MIT licence: see LICENSE-MIT. */
import { paths } from './schema.js';

/** The added-layer words `SdkMessages` carries. */
declare interface AddedLayerMessages {
    /** The step after the template's groups where a customer adds layers of their own (#2860). */
    "form.added-step": string;
    /** The same step correcting a stored design: the layers it added, which a correction neither adds to nor removes. */
    "form.added-step-correcting": string;
    /** Its buttons, one per kind the template lets a customer add. */
    "form.added-add-text": string;
    "form.added-add-image": string;
    "form.added-add-shape": string;
    /** An added layer's card heading; {n} counts that kind's added layers from 1. */
    "form.added-title-text": string;
    "form.added-title-image": string;
    "form.added-title-shape": string;
    /** The accessible name of the card's icon removing the layer; {name} is the card heading. */
    "form.added-remove-label": string;
    /** How many more may be added when the template offers one kind; {count} is at least 1. */
    "form.added-left": string;
    /** The same for each kind when it offers several; {noun} is one of the nouns below, the singular when {count} is 1. */
    "form.added-left-kind": string;
    /** Under a kind's button once its limit is reached; {noun} as above, plural. */
    "form.added-full": string;
    "form.added-full-kind": string;
    /** The nouns the two lines above name a kind by, singular and plural. */
    "form.added-noun-text": string;
    "form.added-noun-texts": string;
    "form.added-noun-image": string;
    "form.added-noun-images": string;
    "form.added-noun-shape": string;
    "form.added-noun-shapes": string;
}

/** What `P`'s `GET` answers, as JSON. */
export declare type AnswerOf<P extends ReadPath> = ReadOf<P> extends {
    responses: {
        200: {
            content: {
                "application/json": infer A;
            };
        };
    };
} ? A : never;

/** What every bundle's `PixonautApiError` marks its refusals with: a
 *  registered symbol, the same in each. */
declare const API_ERROR_BRAND: unique symbol;

/** What `approve()` resolves: the lead side's proof, and every enabled
 *  side's in `placements` order, the lead first. */
export declare type Approved = SideProof & {
    sides: [SideProof, ...SideProof[]];
};

/** What `POST /v1/proofs` answers with its 201, as the API's reference
 *  declares it. Derived rather than restated, so a field the route adds or
 *  renames reaches every caller of `approve()` without a hand edit. */
export declare type ApproveResult = paths["/v1/proofs"]["post"]["responses"][201]["content"]["application/json"];

/** A blank's views and variants, as `@pixonaut/sdk/client` reads them:
 *  `client.blanks.mockups.list(blankId)` and
 *  `client.blanks.variants.list(blankId)`. */
export declare interface BlankViewsRead {
    /** The blank's placements, views and photos. */
    mockups: AnswerOf<"/v1/blanks/{id}/mockups">;
    /** The blank's option axes, its options with how each value looks, and
     *  its variants. */
    variants: AnswerOf<"/v1/blanks/{id}/variants">;
}

/** Derive the customer form schema from the artwork's groups. A template is
 *  ONE artwork (ADR 018), so each of its groups becomes one step. */
export declare function buildFormSchema(template: FormSchemaSource, 
/** The `fieldTexts` a read serves beside a document whose texts are
 *  already written with their defaults; none for an authored one. */
fieldTexts?: FieldTexts): BuiltFormSchema;

/** The form `buildFormSchema` answers, every step a `BuiltFormStep`. */
declare interface BuiltFormSchema<C = Record<string, unknown>> extends FormSchema_2<C> {
    steps: BuiltFormStep<C>[];
}

/** A step as `buildFormSchema` makes it: it always names its deletable
 *  members, an empty list when there are none. */
declare interface BuiltFormStep<C = Record<string, unknown>> extends FormStep<C> {
    deletableLayerIds: string[];
}

/** The bundled profiles by name: each one's file name in `assets/icc/` less
 *  its `.icc`. */
declare const BUNDLED_OUTPUT_PROFILES: readonly ["ISOcoated_v2_eci", "GRACoL2013_CRPC6"];

declare type BundledOutputProfile = (typeof BUNDLED_OUTPUT_PROFILES)[number];

/** One window of the canvas document's `canvasWindows`, in the canvas's
 *  millimetres: its rect after its turn, and its placement's shape there
 *  as a path filled even-odd, absent where the placement has none. */
declare interface CanvasWindow {
    xMm: number;
    yMm: number;
    widthMm: number;
    heightMm: number;
    shape?: string;
}

export declare interface ClientOptions {
    /** The key every read is made with. A secret or integration key only on a
     *  server; in a browser, a publishable key. */
    key: string;
    /** The API's address, such as `https://api.pixonaut.app`. */
    baseUrl: string;
    /** The `fetch` reads are made with; the platform's own when omitted. */
    fetch?: typeof fetch;
    /** The designer session reads are made in, sent as `x-pixonaut-session`.
     *  A publishable key reads a shopper's upload only in the session it was
     *  uploaded in. */
    session?: string;
}

/** A generated estimate table as `gen:cmyk-estimate` writes it, one per
 *  bundled output profile (`cmyk-estimate-tables.ts`). */
declare interface CmykEstimateTableFile {
    gridPoints: number;
    profileSha256: string;
    ghostscriptVersion: string;
    /** The packed grid, one character per byte (`gridTableText`). */
    text: string;
}

/** A `cmyk-tables/<profile>-preview.json`, as the generator writes it and
 *  both `screen-color.ts` and the render crate (`cmyk_preview.rs`) read it. */
declare interface CmykPreviewTableFile {
    gridPoints: number;
    profileSha256: string;
    ghostscriptVersion: string;
    /** The packed grid, one character per byte (`gridTableText`). */
    text: string;
}

/** A template color: RGB, CMYK, or a spot color with an RGB/CMYK fallback. */
declare interface ColorValue {
    rgb?: [number, number, number];
    cmyk?: [number, number, number, number];
    spot?: {
        fallback: ColorValue;
    };
}

/** What `commit()` is told of how the customer approved. */
export declare interface CommitOptions {
    /** Your page approves the design by a checkbox: each design then records
     *  whether its preview was opened before it (`reportPreviewOpened()`).
     *  Left out, it records that this does not apply. */
    approvalCheckbox?: boolean;
}

/** What `commit()` resolves: the committed design's id. A design drawn on a
 *  panel layout is committed as one design per placement, `designs`, the
 *  lead first, and `designId` is the lead's. */
export declare type CommitResult = paths["/v1/designs"]["post"]["responses"][201]["content"]["application/json"] & {
    designs?: paths["/v1/designs/layout"]["post"]["responses"][201]["content"]["application/json"]["designs"];
};

/** What `commit()` resolves: the lead side's design, and every enabled
 *  side's in `placements` order, the lead first. */
export declare type Committed = SideCommit & {
    sides: [SideCommit, ...SideCommit[]];
};

/** A correction link's token (#1084), which a store's server hands out for
 *  one design (`POST /v1/designs/{id}/correction-links`) for a short while.
 *  It reaches that design and nothing else the tenant holds, from the
 *  store's own pages. */
declare interface CorrectionLinkCredential {
    correctionLink: string;
    publishableKey?: never;
    shareLink?: never;
    studioSession?: never;
    transport?: never;
}

/** Mounted by a store on a design its customer made, to correct it: the
 *  correction link and the design's id. It opens at the size it was bought
 *  at, with no size menu. Given the product's options it looks as the product
 *  did, on the pair the design was made on (another is a `TypeError`) and on
 *  that side alone. `approve()` proofs the correction and writes nothing: the
 *  store's server makes it with `PATCH /v1/designs/{id}`. No `commit()`. */
declare type CorrectionLinkMount = MountOptionsBase & CorrectionLinkCredential & MountPlacement & {
    /** The design's id. */
    design: string;
    templateId?: never;
    template?: never;
};

/** A client reading as `key`. In a browser, a key that must stay on a server
 *  is refused here, before it is sent anywhere. */
export declare function createClient(opts: ClientOptions): PixonautClient;

/** Create the designer in `target` (a selector or the element) from the
 *  data the client fetched, replacing the element's content. Makes no
 *  request for the template or the placement it opens on; the
 *  other sides of `placements` are read with its credential. */
export declare function createDesigner(target: MountTarget, options: DesignerOptions): Promise<PixonautDesigner>;

/** Create the designer in the host's preview, form and view strip elements,
 *  as `createDesigner(target, options)` does in one. What the host printed
 *  in them stays until the designer's preview is drawn, and stays for good
 *  if the mount fails. */
export declare function createDesigner(options: DesignerOptions & DesignerTargets): Promise<PixonautDesigner>;

/** A crop's site: a layer's picture, or the artwork's image background. */
export declare type CropTarget = {
    kind: "layer";
    layerId: string;
} | {
    kind: "background";
};

/** The kinds of layer a customer may be allowed to add, in the order the
 *  builder and the designer list them. */
declare const CUSTOMER_LAYER_KINDS: readonly ["text", "image", "shape"];

declare type CustomerLayerKind = (typeof CUSTOMER_LAYER_KINDS)[number];

export declare const DEFAULT_MESSAGES: SdkMessages;

/** The deleted-layer words `SdkMessages` carries. */
declare interface DeletedLayerMessages {
    /** The accessible name of the icon deleting a layer the template lets a customer delete; {name} is the layer's first field, or its step. */
    "form.deleted-delete-label": string;
    /** Where a deleted layer's fields were; {name} as above. */
    "form.deleted-note": string;
    /** The button putting it back, and its accessible name. */
    "form.deleted-restore": string;
    "form.deleted-restore-label": string;
}

/** The id of the Design view: the artwork alone, on a transparency checker,
 *  with no photo behind it (#1537). Offered beside a blank's photographed
 *  views, after them; `views` leaves it out when it names other views and
 *  not this one, and a view of the blank's own with this id stands in its
 *  place. */
export declare const DESIGN_VIEW_ID = "design";

/** What failed: the request the designer made for its host, or the mount
 *  itself. */
export declare type DesignerAction = "mount" | "approve" | "commit" | "upload" | "improve-resolution" | "cut-out" | "pixel";

export declare type DesignerEventHandler<E extends DesignerEventName> = (payload: DesignerEvents[E]) => void;

export declare type DesignerEventName = keyof DesignerEvents;

/** Each event and what its handler is handed. */
export declare interface DesignerEvents {
    /** The designer is shown: its first preview is drawn, and in a mount
     *  split across the host's elements, its parts have taken the place of
     *  what the host printed in them. Once per designer; `shown()` resolves
     *  then too, for a host that subscribes after the mount resolves. */
    shown: Record<string, never>;
    /** The design changed: a value, a transform, the print size, the step. */
    change: DesignerState;
    /** Another print size is chosen, in the size menu or by
     *  `setPrintSize()`: its index into `printSizes`, and the entry. */
    printSize: {
        index: number;
        size: PrintSizeChoice;
    };
    /** The selected layer, or null when none (or several) is. */
    selection: {
        layerId: string | null;
    };
    /** The step a form drawn as steps shows. */
    step: FormStepPosition;
    /** The product view shown. */
    view: {
        viewId: string;
    };
    /** The product variant shown, or chosen again by `setVariant()`. */
    variant: {
        ref: string;
    };
    /** The preview took another shape, width over height: a photo of another
     *  shape was placed, or a print size drawn at another shape landed. Heard
     *  once the designer is `shown`, a host that frames the preview follows
     *  it; `getPreviewAspect()` is the shape now. */
    previewShape: {
        aspect: number;
    };
    /** The side of the item shown, by `setSide()` or `setView()`. */
    side: {
        placementId: string;
    };
    /** The repeat changed: offered (`enableRepeat()`), chosen
     *  (`setRepeat()`), or withdrawn (`disableRepeat()`, `repeat: null`). */
    repeat: {
        repeat: RepeatState | null;
    };
    /** A placement of a linked side was taken out of its group, put back,
     *  or mirrored (`setSideLink()`). */
    link: SideLinkEvent;
    /** A print a view of several placements draws may have changed: the
     *  side shown's, its preview drawn again (`renderSidePrint()` draws it
     *  as it now is), or another placement's, by `setSidePrint()`. */
    print: {
        placementId: string;
    };
    /** The canvas switched mode (`setMode`). */
    mode: {
        mode: DesignerMode;
    };
    /** The picture a drag on the canvas now moves (`setCropMode`), or `null`
     *  once that ended: by the call, Escape, or a click away. */
    crop: {
        target: CropTarget | null;
    };
    /** An upload is under way (`uploadAsset`). */
    progress: UploadProgress;
    /** A request the designer made failed; its promise rejects with `error`
     *  too. */
    error: {
        action: DesignerAction;
        error: unknown;
    };
    /** The customer opened the preview of a side, as the host told
     *  `reportPreviewOpened()`, each time they did: its placement, null for a
     *  mount naming none. Nothing of the customer or the design, so a host may
     *  hand it to its own analytics as it is. */
    previewOpened: {
        placementId: string | null;
    };
    /** The proofs `approve()` resolves with. */
    approved: Approved;
    /** The designs `commit()` resolves with. */
    committed: Committed;
    /** Pixel's progress (`designer.pixel`): a request sent, its changes
     *  applied, its failure, its cancel, or its step taken back. */
    pixel: PixelEvent;
    /** A preload's progress: after each item settles, and once more, `done`,
     *  when it ends or is cancelled. */
    preload: PreloadProgress;
}

/** What the canvas shows: handles and guides to edit by, or the product
 *  alone, as it prints. */
export declare type DesignerMode = "edit" | "preview";

/** What `createDesigner` takes: the data `@pixonaut/sdk/client` fetched,
 *  as it resolved it (or as it was serialised into the page and parsed),
 *  the credential, or the host's `transport`, and the designer's options. */
export declare type DesignerOptions = Omit<MountOptionsBase, "templateId" | "template" | "templateRead" | "placementBox" | "cmykPreviewProfile" | "blankViews" | "initialValues" | "offeredShapes" | "printMargins" | "dieShapeId" | "layerShapeIds"> & MountCredential & {
    /** The template to open: `client.templates.get(templateId)`. */
    template: TemplateAnswer;
    /** The blank and placement the design is for, with the box it fits
     *  the design to: `client.templates.constraints.get(templateId,
     *  { blankId, placementId })`. Omitted, the template's own shape. */
    placement?: PlacementAnswer;
    /** The values the design starts from, as `getState().values` holds
     *  them. */
    values?: DesignerState["values"];
    /** The generated form and pickers, from `@pixonaut/sdk/ui`. Omitted,
     *  the designer draws its canvas alone. */
    ui?: DesignerUi;
    /** The views and variants of the placement's blank:
     *  `client.blanks.mockups.list(blankId)` and
     *  `client.blanks.variants.list(blankId)`. Omitted, the designer shows
     *  the storefront's own `mockups`, or none. */
    blank?: BlankViewsRead;
};

/** `designer.pixel`: Pixel for the customer, for a template that offers
 *  it. */
export declare interface DesignerPixel {
    /** Whether the template shown offers the customer Pixel. False with no
     *  template read (an in-memory template), in a studio member's mount, and
     *  through a `transport`, whose server credential the route refuses. */
    available(): boolean;
    /** Whether a request is under way. */
    busy(): boolean;
    /** Ask Pixel to change the design. Resolves once its changes are applied
     *  as one step; rejects with `PixelUnavailableError`, changing nothing,
     *  when Pixel did not answer, when another request is under way, or when
     *  another design (template or side) was shown before it answered. A
     *  picture Pixel cuts out is put in place later, once it is done, as an
     *  upload's is. Cancelled through `options.signal`, it rejects with the
     *  signal's reason instead, changing nothing, and `on("pixel")` hears
     *  `cancelled`, never `failed`. */
    ask(prompt: string, options?: PixelAskOptions): Promise<PixelAnswer>;
    /** Whether `undo()` has a step of Pixel's to take back on the design
     *  shown. */
    canUndo(): boolean;
    /** Put the fields Pixel's last step on the design shown changed back as
     *  they were before it, and stop its cut-outs still running. A field the
     *  customer has changed since keeps their value. A layer of the
     *  customer's own it added is removed, and one it removed comes back at
     *  its place with its values, unless the customer has added or removed
     *  one since. False when there was nothing to take back. */
    undo(): boolean;
}

/** The views, variants, sides and repeat of the product a
 *  `PixonautDesigner` shows. */
export declare class DesignerProduct {
    /** Each view the product allows, for the variant shown: a view with no
     *  photo of it (`hasPhoto: false`) is hidden, and `setView()` refuses
     *  it. The Design view (`kind: "design"`), the artwork alone, follows. */
    getViews(): ProductView[];
    /** What a view that says `estimated` is told with, in the designer's language:
     *  the picture is an estimate, with its numbers. The default views draw it; a
     *  host with `showViews: false` shows it in its own markup. Empty until the
     *  designer has opened a template. */
    getEstimateNote(): string;
    /** The view shown; undefined when the product has none with a photo. */
    getView(): string | undefined;
    /** The preview's shape now, width over height: the photo shown, else the
     *  print's. `on("previewShape")` reports it changing. */
    getPreviewAspect(): number;
    /** Tell the designer the customer opened the preview of a side, by default
     *  the side shown: call it when your page shows them the proof `approve()`
     *  made. `on("previewOpened")` reports it each time, and `wasPreviewOpened()`
     *  remembers it until the design changes. A side `approve()` would not take
     *  is a `TypeError`. */
    reportPreviewOpened(placementId?: string): void;
    /** Whether the preview of a side, by default the side shown, was opened as
     *  the design now stands: what a store with an approval checkbox records
     *  with the design, as `previewOpened` on its commit. */
    wasPreviewOpened(placementId?: string): boolean;
    /** Each variant the product offers (`variants`, else every one not
     *  retired), with its value on each option and whether a view has a photo
     *  of it; empty for storefront photos. */
    getVariants(): ProductVariant[];
    /** The options the variants offered are chosen by, in the blank's order,
     *  each with its values and how each looks (a fill, an image, or none);
     *  empty for storefront photos. */
    getOptions(): ProductOption[];
    /** The variant shown, as `setVariant()` or `initialVariant` named it;
     *  undefined before. */
    getVariant(): string | undefined;
    /** Show view `viewId` behind the design; one on another placement
     *  switches the editor to that placement, and to the template the product
     *  prints there, keeping the customer's values as `setTemplate()` does.
     *  Resolves once it is shown; `on("view")` reports it. A view the product
     *  does not allow, or one without a photo of the variant shown, rejects
     *  with a `TypeError`. */
    setView(viewId: string): Promise<void>;
    /** A PNG of view `viewId` with the design on it, for a thumbnail in the
     *  host's own markup: in `variant`'s photo (the variant shown when
     *  absent), its longest edge `maxEdgePx` (240 when absent); the Design
     *  view's is the design alone. Rejects as `setView()` does, and when the
     *  photo cannot be read (served without CORS). */
    renderView(viewId: string, options?: RenderViewOptions): Promise<Blob>;
    /** A PNG of template `templateId` for a gallery, drawn in the designer's
     *  render worker: with its own sample, or with `values` (a state's) carried
     *  by field key as `setTemplate()` carries them; in view `viewId`'s photo
     *  when named. Kept by template, size, view, values and profile. */
    renderTemplate(id: string, options?: RenderTemplateOptions): Promise<Blob>;
    /** Load `target` ahead of a switch to it — `"all"`, or the variants,
     *  views and sides named, an omitted axis meaning every one — so the
     *  switch makes no request: each photo decoded, each side's template,
     *  constraints and pictures. Resolves once it is loaded or cancelled;
     *  `on("preload")` reports it. A target the product does not allow is a
     *  `TypeError`. Shows nothing, and fails no switch. */
    preload(target: PreloadTarget, options?: PreloadOptions): Promise<void>;
    /** Stop the preload's queued and in-flight loads; what loaded stays. */
    cancelPreload(): void;
    /** What `preload: "auto"` would do and why, loading nothing. */
    preloadPlan(): PreloadPlan;
    /** Show side `placementId` to edit, with its own template and values, in
     *  its first view; `on("side")` reports it. Rejects as `setView()` does,
     *  and with a `TypeError` for a placement that is not one of
     *  `placements`. */
    setSide(placementId: string): Promise<void>;
    /** A side's print as a view of several placements draws it, at its print
     *  box (`SidePrintOptions`: the side shown's by default); null for none. */
    renderSidePrint(options?: SidePrintOptions): Promise<SidePrint | null>;
    /** The print `placementId`, no side of this designer, shows in a view of
     *  several placements (another's `renderSidePrint()`); null shows its
     *  fill colour. A `TypeError` for a placement this designer draws. */
    setSidePrint(placementId: string, print: SidePrint | null): void;
    /** Offer a repeat (#2148): the template laid as a tile across every
     *  side's print, or the placements named (#2193), in the layouts offered,
     *  starting at the layout, size and arrangement given, and saying what
     *  the shopper may change (`RepeatOptions`). Called again, it replaces
     *  the offer. `approve()` and `commit()` send it with the design of each
     *  placement it is laid on, and `on("repeat")` reports it. A `TypeError`
     *  for an offer that cannot be made, and for placements that are not
     *  sides or that leave out one linked with them. */
    enableRepeat(options?: RepeatOptions): void;
    /** Withdraw the repeat: the design is placed once. */
    disableRepeat(): void;
    /** The repeat offered and chosen; null while none is offered. */
    getRepeat(): RepeatState | null;
    /** Choose the repeat's layout (`null`: not repeated, where the shopper
     *  may turn it off), size or arrangement; `on("repeat")` reports it. A
     *  `TypeError` with none offered and for a choice outside the offer. */
    setRepeat(choice: RepeatChoice): void;
}

/** One side of the item, as `getSides()` lists it. */
export declare interface DesignerSide {
    /** The placement the side prints on; null for a mount naming none. */
    placementId: string | null;
    /** The template the side shows, `baseId@version`. */
    templateId: string;
    /** Whether `approve()` and `commit()` take it (`setSideEnabled`). */
    enabled: boolean;
    /** Whether its design passes the template's rules, judged in the
     *  browser against its own template and placement, as the form judges
     *  the side shown. False too when it could not be judged. */
    valid: boolean;
    /** The placements one design drives on this side, each with whether its
     *  print is mirrored, `placementId` among them; null for a side designed
     *  on its own (`linkedPlacements`). */
    linked: LinkedSide[] | null;
}

export declare interface DesignerState {
    templateId: string;
    /** The placement of the side shown, when the mount names placements: a
     *  remount names it as its `placementId` (#1364). */
    placementId?: string;
    /** The template's unkeyed values by layer id (and `<id>.transform`, and
     *  `$artwork`), every keyed field's value by its key under `$fields`, the
     *  values of the other templates `setTemplate` showed, by template id,
     *  under `$templates` (#1363), and the other sides of the item, by
     *  placement, under `$sides` (#1364), each `{ templateId, values }` with
     *  values of this same shape. Each entry is absent when it holds
     *  nothing. */
    values: Record<string, Record<string, unknown>>;
    /** The print size the customer chose, when the host offers a size menu
     *  (ADR 051). It is NOT in `values` — that is keyed by layer id and reaches a
     *  Rust model with `deny_unknown_fields` — so without it here a host
     *  persisting `onChange` state would lose the size and have no way to
     *  restore it. Absent when there is no menu, or when the selected entry is
     *  one the rule refuses. */
    printSize?: {
        widthMm: number;
        heightMm: number;
    };
    /** The step the form shows, when it lays out as steps (ADR 196). Absent on
     *  a page, so a page's state is what it always was. */
    step?: FormStepPosition;
}

/** The elements a designer split across the host's page is drawn into, as
 *  `mount({ previewTarget, formTarget, viewsTarget })` takes them. */
export declare type DesignerTargets = Pick<SplitMountOptions, "previewTarget" | "formTarget" | "viewsTarget" | "fill">;

/** The one-click designer's UI — the generated form, its steps, the
 *  print-size picker and the view switcher — as `@pixonaut/sdk/ui` exports it, for
 *  `createDesigner`'s `ui`. Branded by a property no value has, by name
 *  rather than by a symbol, so the core's and the UI's declarations, each
 *  rolled up on its own, name one type. */
export declare interface DesignerUi {
    readonly __pixonautDesignerUi: true;
}

/** The generated form, the default pickers and the view switcher, for
 *  `createDesigner`'s `ui`. */
export declare const designerUi: DesignerUi;

/** A design's repeat as it is chosen: the mode, the tile's width in percent
 *  of the print area's width (the tile keeps the template's shape), and the
 *  seed a scatter is arranged by. */
export declare interface DesignRepeat {
    mode: RepeatMode;
    size: number;
    seed: number;
}

/** The mount option that hears every `error` event from the start. */
export declare interface ErrorOption {
    /** Hears what `on("error")` does, and a mount that fails as well
     *  (`action: "mount"`): that rejects before there is a designer to
     *  subscribe to. */
    onError?: (event: DesignerEvents["error"]) => void;
}

/** Constraints attached to a form field / editable layer prop. */
declare interface FieldConstraints {
    maxLines?: number;
    maxLength?: number;
    min?: number;
    max?: number;
    step?: number;
    oneOf?: string[];
    /** image `src` only: may the customer upload their own file? Independent of
     *  `oneOf`, which lists the presets offered (ADR 016). */
    allowUpload?: boolean;
    /** The offered palette: its NAME in the resolved document a canvas or the
     *  SDK draws, its id only in an authored one (#525). */
    palette?: string;
    /** A colour of the customer's own is offered, alone or beside `palette`
     *  (ADR 114, ADR 180). */
    anyColor?: true;
    options?: Array<string | {
        value: string;
        label?: string;
    }>;
    [key: string]: unknown;
}

/** `field`'s heading: its authored label, or the message for its prop while
 *  the label is blank, the prop's own key, or a word a read already put there
 *  (`labelIsDefault`). A prop the form has no word for keeps the label it came
 *  with. */
export declare function fieldLabel(field: Pick<FormField, "prop" | "label" | "labelIsDefault">, msg: (key: FieldWordKey) => string): string;

/** One field of the form as text: its label, and its value as the form
 *  shows it. */
export declare interface FieldText {
    /** The field's layer, as `getFormSchema()` names it. */
    layerId: string;
    /** The field's prop, as `getFormSchema()` names it. */
    prop: string;
    /** The field's label, as the form heads it: the author's words, or the
     *  form's own for a field they left unlabelled. */
    label: string;
    /** The value in words: the text typed; the chosen option's word; a
     *  palette colour's name in the mount's language, or for any other
     *  colour the hex the form's colour field heads it with (`#RRGGBB`,
     *  upper-case, a CMYK or spot colour as the output profile previews it);
     *  a picture's file name as uploaded, or a preset's label; a
     *  number as written. Empty when the field holds nothing to say, such as
     *  no picture. */
    value: string;
}

/** A run of a text naming a field, as a customer's values are written into
 *  it: the author's own characters, dates already written, or a field by
 *  key, with the text written in its place when its value is empty. */
declare type FieldTextPiece = {
    literal: string;
    field?: undefined;
} | {
    field: string;
    fallback?: string;
    literal?: undefined;
};

/** Each fixed text naming a field, by layer id, as pieces. */
declare type FieldTexts = Record<string, FieldTextPiece[]>;

/** A message that names an unlabelled field. */
export declare type FieldWordKey = (typeof WORD_KEY_BY_PROP)[keyof typeof WORD_KEY_BY_PROP];

/** What a host styles in fill mode. The defaults leave the stage and the form
 *  unpainted and give the form the width it asks for embedded. */
export declare const FILL_PROPERTIES: {
    /** The stage behind the preview. */
    readonly stageBackground: {
        readonly name: "--pixonaut-stage-background";
        readonly fallback: "transparent";
    };
    /** Space kept clear around the preview on a desktop's stage, as CSS
     *  `padding`: a host that draws something of its own over the stage
     *  reserves its room here, so the preview is fitted beside it rather than
     *  under it. A phone's stage is a band above the form, padded by
     *  `PHONE_STAGE_PADDING`. */
    readonly stagePadding: {
        readonly name: "--pixonaut-stage-padding";
        readonly fallback: "24px";
    };
    /** The form column's width beside the stage. */
    readonly formWidth: {
        readonly name: "--pixonaut-form-width";
        readonly fallback: "260px";
    };
    /** The form column's background. */
    readonly formBackground: {
        readonly name: "--pixonaut-form-background";
        readonly fallback: "transparent";
    };
};

/** A custom property a host may set on its element, and the value used when
 *  it sets none. */
export declare interface FillProperty {
    name: `--pixonaut-${string}`;
    fallback: string;
}

/** One face of a family: the weight it draws, its style when it is italic
 *  (a face storing none is upright), and its TTF's sha256. */
declare interface FontFaceRef {
    weight: number;
    style?: "italic";
    hash: string;
}

/** Families by name, each with its faces: what a document is resolved
 *  against (the tenant's rows, or a version's snapshot) and what the core
 *  reads under `fonts`. */
declare type FontSource = Readonly<Record<string, readonly FontFaceRef[]>>;

/** Every widget hint a field can carry: the host renders one control per
 *  type. A consumer that draws each (the builder's "renders as" preview) keys
 *  a record on `FormFieldType`, so a type added here fails to compile there
 *  until it is drawn. */
declare const FORM_FIELD_TYPES: readonly ["text", "select", "number", "color", "image", "percent", "crop"];

/** How the customer form lays out its steps (ADR 196): every step on one
 *  page, or one step at a time.
 *
 *  A template's author may choose one as `form.layout`, and the form schema
 *  carries that choice as `layout` so a headless host sees it too. The SDK's
 *  mount option `layout` outranks it, and with neither the form is a page —
 *  which is what every embed showed before the choice existed.
 *
 *  A zero-dependency leaf for `artwork-step-label.ts`'s reason: the SDK reads
 *  it, and importing zod would pull it into the widget. */
declare const FORM_LAYOUTS: readonly ["page", "steps"];

/** replace {name} tokens with vars[name]; unknown tokens are left as-is */
export declare function formatMessage(template: string, vars?: Record<string, string>): string;

/** A generated form field descriptor. `layerId` is the write key the widget
 *  sets values through, and what distinguishes two member layers that expose
 *  the same prop.
 *
 *  An `image` field's `prop` also says how a chosen picture is written:
 *  `pictureValueOf(prop, id)` (`asset-ids.ts`) — the bare id for an image's
 *  `src` and the artwork's `imageAssetId`, `{imageAssetId}` for a shape's
 *  picture `fill` — and its `default` is the prop's value in that same
 *  spelling, read back with `pictureIdOf`.
 *
 *  Generic in the constraints type, which is not ceremony: this package cannot
 *  import canvas-kit's `FieldConstraints` (that is the dependency cycle), but
 *  the SDK's field renderers read `oneOf`, `maxLines`, `min`, `max`, `step`,
 *  `maxLength` and `palette` off it by name. A bare `Record<string, unknown>`
 *  would make every one of those `unknown`. So the api takes the default and
 *  the SDK re-exports this instantiated with its own constraint type — one
 *  declaration, two typings, no cycle. */
declare interface FormField<C = Record<string, unknown>> {
    layerId: string;
    prop: string;
    type: FormFieldType;
    label: string;
    /** `label` is the form's own word for what the field sets, in the language
     *  of the read that served it, and not the author's; absent otherwise. */
    labelIsDefault?: boolean;
    /** The author's help text, shown under the control as text (ADR 192);
     *  absent when they wrote none. */
    description?: string;
    /** What the control offers. A `fontWeight` field's values are numbers, and
     *  its `faces` maps each font the customer may pick to the weights it
     *  offers (see `offeredWeightConstraints`). */
    constraints: C;
    default: unknown;
    /** The fit the customer must have chosen for this field to apply — a
     *  crop's `cover`, a tile size's `tile` — on a site whose fit the customer
     *  chooses (#805). A form shows the field only while the site's fit is
     *  that one. Absent where the fit is the author's alone, so the field
     *  always applies. */
    requiresFit?: PictureFitValue;
}

declare type FormFieldType = (typeof FORM_FIELD_TYPES)[number];

/** A group the form shows: a template group's id, or a new one for a group
 *  of the host's own, which needs a `title`. */
export declare interface FormGroup {
    /** a template group's id, or a new one */
    id: string;
    /** its heading, in every language */
    title?: string;
    /** what it holds, in order */
    items: FormGroupItem[];
}

/** A field of the template's, named `"<layerId>:<prop>"` as
 *  `getFormSchema()` names it, with the words it shows on this mount. */
export declare interface FormGroupField {
    /** the field, as `"<layerId>:<prop>"` */
    field: string;
    /** its heading */
    label?: string;
    /** the help text under it */
    help?: string;
    /** a text field's placeholder */
    placeholder?: string;
    /** an offered value's words, by the value */
    options?: Record<string, string>;
}

/** An element of the host's page, moved into the group as it is. */
export declare interface FormGroupHostItem {
    /** what `setHostItemComplete` names it by */
    host: string;
    /** the element, moved in and put back on `destroy()` */
    element: HTMLElement;
}

/** An item of a group: a field of the template's, or the host's own. */
export declare type FormGroupItem = FormGroupField | FormGroupHostItem;

/** The `formGroups` mount option. */
export declare interface FormGroups {
    /** the groups shown first, in order; the rest follow */
    groups: FormGroup[];
}

declare type FormLayout = (typeof FORM_LAYOUTS)[number];

/** How a mount lays out its form. */
export declare interface FormMountOptions {
    /** How the customer form lays out (ADR 196): `"page"`, every step under a
     *  numbered heading, or `"steps"`, one step at a time with Back and Next.
     *  Omitted, the template's own `form.layout` decides, and a page when it
     *  has none — so an embed that never passes it shows what it always did. */
    layout?: FormLayout;
    /** A first step before the template's, holding the blank's variant
     *  picker and print-size picker, then `content`: the host's own fields,
     *  such as a shop's variation selects, moved into the step as they are
     *  (never copied, so their listeners keep working) and put back where they
     *  were on `destroy()`. Its heading is `title`, else the
     *  `form.step-options` message ("Options"); `getStep()` names it
     *  `"$options"`. A step with nothing to hold is left out. On a page the
     *  same block is drawn first, above the form, with no heading. Omitted,
     *  the pickers sit above the form. */
    optionsStep?: {
        title?: string;
        content?: HTMLElement;
    };
    /** The host's element for the form's last step, such as its price,
     *  quantity and Add to cart: moved into the form as it is and shown only
     *  on the last step, under Back; on a page, at the end of the form. Put
     *  back where it was on `destroy()`. */
    lastStepContent?: HTMLElement;
    /** The form's groups for this mount, over the template's: which groups
     *  are shown and in what order, which items each holds and the words they
     *  show, in every language. A group is a template group's id, or a new id
     *  for a group of the host's own, which needs a `title`. An item is a
     *  template field, named `"<layerId>:<prop>"` as `getFormSchema()` names
     *  it, with its `label`, `help`, `placeholder` and `options` (an offered
     *  value's words, by value) for this mount; or `{ host, element }`, an
     *  element of the host's page moved in as it is (never copied, so its
     *  listeners keep working) and put back on `destroy()`, as `optionsStep`'s
     *  content is. A host item holds Next on its step until
     *  `setHostItemComplete(host, true)`. Groups and fields it does not name
     *  are shown after it, in template order; a name the template does not
     *  have is passed over with a console warning. A host item takes over
     *  from `optionsStep`. Malformed, the mount is refused with a
     *  `TypeError`. Works in both layouts; omitted, the template's groups are
     *  shown as they are. */
    formGroups?: FormGroups;
    /** `false` draws no note under the views saying a scene's picture is an
     *  estimate, for a host that says so in its own words or not at all.
     *  Omitted, the note shows while a view that says `estimated` is shown;
     *  with `showViews: false` it is never drawn, whatever this says */
    showEstimateNote?: boolean;
}

/** The generated form: its steps, and its author's layout when they chose
 *  one (ADR 196). */
declare type FormSchema = FormSchema_2<FieldConstraints>;

/** The customer form: its steps, and the layout its author chose, when they
 *  chose one (ADR 196). `layout` is absent rather than defaulted, so a
 *  template that never chose serves the schema it always did. */
declare interface FormSchema_2<C = Record<string, unknown>> {
    steps: FormStep<C>[];
    layout?: FormLayout;
}

/** A group of layers forming one customer-facing form step. `layerIds` is
 *  optional for the same reason `layers` is below: the SDK derives from an
 *  integrator's in-memory template that no schema gates, and a group missing
 *  its members should yield a step with no fields rather than throw "not
 *  iterable" out of the widget's mount. */
declare interface FormSchemaGroup {
    id: string;
    label: string;
    order: number;
    layerIds?: string[];
}

/** What the derivation reads off a layer — deliberately narrower than any
 *  caller's model. The api passes its `ValidatedDoc`, the SDK a canvas-kit
 *  `LayerSpec`, and this package can see neither (canvas-kit depends on it,
 *  not the reverse). Declaring what is actually read means a change to either
 *  caller's model that breaks the derivation fails at the call site — which
 *  only holds if both call sites assign WITHOUT a cast, so the shapes here are
 *  the widest the derivation can honestly accept rather than the narrowest
 *  that would typecheck against one caller.
 *
 *  `transform` maps to `unknown` rather than to an axis type, and the body
 *  narrows before reading. That is not laziness: canvas-kit's `LayerTransform`
 *  declares `[key: string]: unknown` (it holds `xMm`/`yMm`/`rotationDeg`
 *  numbers and a `value` object beside the four axis flags), and an index
 *  signature of `unknown` is assignable to no narrower one. Any tighter
 *  declaration here would force the SDK's call site to cast — and a cast is
 *  exactly what this type exists to avoid, since it would swallow the next
 *  change to either caller's model instead of failing on it. */
declare interface FormSchemaLayer {
    id: string;
    type?: string;
    props?: Record<string, FormSchemaProp | undefined>;
    /** Beside `props` rather than in it, because it belongs to no one layer
     *  kind (#427); read through `LAYER_LEVEL_PROPS`. */
    opacity?: FormSchemaProp;
    transform?: Record<string, unknown>;
    /** `false` for a guide layer, which offers nothing (ADR 183). */
    print?: boolean;
    /** `true` for a locked layer, which offers no transform axis and no crop
     *  (ADR 184). */
    locked?: boolean;
}

/** What the derivation reads off one editable prop. Mirrors the api's
 *  `LayerProp` and canvas-kit's, both of which satisfy it structurally. */
declare interface FormSchemaProp {
    editable?: boolean;
    label?: string;
    /** Help text for the customer (ADR 192). `unknown`, because the SDK derives
     *  from an in-memory template no schema gates: only text is carried. */
    description?: unknown;
    constraints?: Record<string, unknown>;
    value?: unknown;
    [key: string]: unknown;
}

/** The minimum an artwork must expose to derive a form from it. `layers` is
 *  optional because the api's `ValidatedDoc` declares it so, and the body
 *  already guards it — encoding the optionality in the body while denying it
 *  in the signature is what would force a cast. */
declare interface FormSchemaSource {
    layers?: FormSchemaLayer[];
    groups?: FormSchemaGroup[];
    /** The artwork's own background (ADR 038): its colour, or its picture —
     *  both props a customer may be offered. Typed the same way
     *  `FormSchemaLayer.props` is, and for the same reason: both callers must be
     *  able to assign their own document WITHOUT a cast, or this type stops
     *  catching the day their model drifts from what the derivation reads. */
    background?: {
        /** The discriminant a TEMPLATE carries since ADR 046. Optional because a
         *  RESOLVED document reaches this type too and deliberately has none — it
         *  freezes into the design's revisions (`design_revisions.doc_json`) and
         *  must keep parsing as approved. The index signature below swallowed this
         *  field when it arrived, which is exactly the drift the comment above
         *  promises this type catches, so it is named explicitly rather than left
         *  to the catch-all. */
        kind?: "color" | "image";
        color?: FormSchemaProp;
        /** The image arm's picture: a prop on a template, offered as an image
         *  layer's `src` is; a bare id on a resolved document, which offers
         *  nothing. `unknown`, and narrowed in the body, because the api's
         *  `ValidatedDoc` spans both. */
        imageAssetId?: unknown;
        /** The image arm's crop (ADR 181): a prop on a template; on a resolved
         *  document a bare crop, which offers nothing. `unknown` for the reason
         *  `imageAssetId` is. */
        crop?: unknown;
        [key: string]: unknown;
    };
    /** The artwork's die-cut (#183, ADR 047), the artwork's second editable
     *  value. Its `constraints.oneOf` is the offered set, as shape ids. */
    shape?: FormSchemaProp;
    /** How the author laid the form out (ADR 196). `unknown` inside, because
     *  the SDK derives from an in-memory template no schema gates: a value that
     *  is not a layout is not carried. */
    form?: {
        layout?: unknown;
    };
    /** The families a resolved document may draw, with their faces (ADR 219
     *  §6). `unknown`, as the SDK's in-memory template is ungated: anything
     *  but a map of families offers none. */
    fonts?: unknown;
}

/** A generated form step, derived from one of the artwork's groups. No step
 *  carries a surface id: the surface it once named is gone, and a two-sided
 *  product is now two templates (ADR 018). */
declare interface FormStep<C = Record<string, unknown>> {
    index: number;
    groupId: string;
    label: string;
    layerIds: string[];
    fields: FormField<C>[];
    /** The members a customer may place: one entry per layer with at least one
     *  editable axis, in `layerIds` order. */
    transforms: FormStepTransform[];
    /** The members a customer may delete from their design (#2883), in
     *  `layerIds` order. Absent from a schema served before it, and from a
     *  step a host makes. */
    deletableLayerIds?: string[];
}

/** One step a form laid out as steps shows: its group, and the name its
 *  heading shows. */
export declare interface FormStepName {
    groupId: string;
    label: string;
}

/** Where a form laid out as steps stands: the step it shows, as an index
 *  into the steps it shows (a step that offers nothing is not one of them),
 *  how many those are, the shown step's group, whether it is the last one,
 *  and every shown step in order, so a host can draw its own stepper beside
 *  the form's. */
export declare interface FormStepPosition {
    index: number;
    count: number;
    groupId: string;
    /** `index === count - 1`: the step where a host shows its finishing
     *  button. */
    isLast: boolean;
    steps: FormStepName[];
}

/** One member layer's editable transform axes, as its step offers them.
 *
 *  Per layer rather than merged across the group, because an axis moves the
 *  layer it belongs to and a merged record cannot say which layer that is
 *  (#390).
 *
 *  The SDK reads an entry only for its existence and its `layerId`: whether a
 *  place control exists and which layer it selects. The controls themselves
 *  are drawn from the layer's own spec (`panel.ts`, the canvas gestures), and
 *  named by the `translate` axis's `label` when the author gave one. The
 *  axes in `transform`, each with the author's `label` and `description`
 *  (ADR 192), are for headless hosts that render their own controls. */
declare interface FormStepTransform {
    layerId: string;
    transform: Record<string, unknown>;
}

/** A group of layers forming one customer-facing form step. */
declare interface GroupSpec {
    id: string;
    label: string;
    order: number;
    layerIds: string[];
}

/** An improvement that came back. */
export declare interface Improved {
    /** The improved picture's asset id. */
    assetId: string;
    /** Whether it was put on the site: false when the site no longer held the
     *  upload it improves, because the customer chose another picture while
     *  it ran. The improved picture is then left unused. */
    applied: boolean;
}

/** The nine points a layer is anchored by. The name means BOTH reference
 *  points at once: the layer's own `top-right` sits against the reference's
 *  `top-right`, coinciding at offset `0,0` (ADR 024). */
declare type LayerAnchor = "top-left" | "top-center" | "top-right" | "center-left" | "center" | "center-right" | "bottom-left" | "bottom-center" | "bottom-right";

/** An editable property descriptor on a layer (label/constraints/value). */
declare interface LayerProp {
    editable?: boolean;
    label?: string;
    constraints?: FieldConstraints;
    value?: unknown;
    [key: string]: unknown;
}

/** A single layer of a template/resolved doc (ADR 018: one artwork).
 *
 *  The placement fields are DECLARED rather than left to the index signature
 *  below, and that is not tidiness. They used to be `sizeMm`, and when ADR 024
 *  replaced it this interface still typechecked clean against a percentage
 *  layer — the index signature admitted the new fields and the old ones read as
 *  `unknown` rather than as missing, so `spec.sizeMm.w` compiled and threw at
 *  runtime, and `spec?.sizeMm ?? {w:0,h:0}` silently rendered a zero-size
 *  layer. Declaring them is what makes the next such change a compile error.
 *
 *  A RESOLVED doc carries `sizeMm` and flat transform millimetres instead. That
 *  half is `ResolvedLayerSpec` below rather than more optional fields here:
 *  one interface describing both shapes is what let `spec.sizeMm.w` compile
 *  against a percentage layer in the first place, and widening this one to
 *  cover the resolved doc too would rebuild exactly that hazard. */
declare interface LayerSpec {
    id: string;
    type?: string;
    z?: number;
    transform: LayerTransform;
    /** One of the nine anchors (`ANCHORS` in `@pixonaut/schema/src/layer-box`).
     *  Typed as the union rather than `string` so a misspelling is caught here
     *  and not by a fallback at render. */
    anchor: LayerAnchor;
    /** Another layer's id, when the layer is anchored to that layer's declared
     *  box rather than to the plate. A MISSING target falls back to the plate at
     *  render rather than failing (ADR 024). */
    anchorTo?: string;
    /** Fractions of the reference, always measured toward its centre. */
    offsetFrac: {
        x: number;
        y: number;
    };
    /** The layer's box, as fractions of the reference's width and height. */
    wFrac: number;
    hFrac: number;
    props?: Record<string, LayerProp | undefined>;
    /** resolved-doc only: the image layer's asset id (ADR 016). */
    kind?: {
        src?: unknown;
    };
    /** The layer's own backdrop (ADR 033), a sibling of `props`. Declared rather
     *  than left to the index signature for the reason the header gives: nothing
     *  here reads it — `buildGestureDoc`'s `structuredClone` carries it through
     *  untouched, which is what ADR 033 relies on — but an undeclared field is
     *  exactly the state `sizeMm` was in when it read as `unknown` instead of as
     *  missing. `unknown` rather than the schema's `LayerMat`: that union lives
     *  in the zod schema module, and importing it would land zod in the SDK's
     *  browser bundle for a field this package never inspects — the same reason
     *  the imports in `doc/index.ts` reach for zod-free leaf modules by path. */
    mat?: unknown;
    /** The layer's opacity (#427), a sibling of `props` like `mat`: a prop an
     *  author may offer, which the customer form reads beside `props`. */
    opacity?: LayerProp;
    [key: string]: unknown;
}

/** A layer transform. Template layers carry the current values under `value`
 *  plus per-axis editability flags; a resolved (author) doc carries the flat
 *  numeric fields directly. Both shapes flow through here.
 *
 *  `value` no longer carries a position: a layer is placed by `anchor` +
 *  `offsetFrac` on the LAYER (ADR 024), and rotation stays here because a layer
 *  is anchored by its unrotated box. The flat `xMm`/`yMm` below survive because
 *  they are the RESOLVED doc's, which is millimetres and stays so. */
declare interface LayerTransform {
    value: {
        rotationDeg: number;
        scale: number;
        flipX?: boolean;
        flipY?: boolean;
    };
    translate?: TransformAxis;
    rotate?: TransformAxis;
    scale?: TransformAxis;
    resize?: TransformAxis;
    xMm?: number;
    yMm?: number;
    rotationDeg?: number;
    [key: string]: unknown;
}

/** The parts of a layer's place `setTransform()` changes: its place, turn,
 *  size and flip. */
declare type LayerTransformPatch = TransformPatch;

/** How a design's placements are linked, short of naming the groups:
 *  `each` designs every placement separately, `suggested` links the groups
 *  the blank suggests (its sleeves), `all` links every placement offered. */
declare const LINK_PRESETS: readonly ["each", "suggested", "all"];

/** One placement of a group as a setting names it: its mirror may be left
 *  to the blank's suggestion. */
export declare interface LinkedPlacementChoice {
    placementId: string;
    mirror?: boolean;
}

/** How a designer links its placements: a preset, or groups of its own. */
export declare interface LinkedPlacementsChoice {
    preset?: LinkPreset;
    groups?: LinkedPlacementChoice[][];
}

/** The `linkedPlacements` mount option: which of `placements` one design
 *  drives, and whether the shopper may design one of them on its own. */
export declare interface LinkedPlacementsOption extends LinkedPlacementsChoice {
    /** Whether `setSideLink(placementId, { linked: false })` may take a
     *  placement out of its group. Omitted, false. */
    designSeparately?: boolean;
}

/** One placement of a linked side, as `getSides()` lists it. */
export declare interface LinkedSide {
    placementId: string;
    /** Whether its print is mirrored left to right. */
    mirrored: boolean;
}

export declare type LinkPreset = (typeof LINK_PRESETS)[number];

/**
 * The consumer's menu with the rule's verdict attached to each entry.
 *
 * MARKS, never filters. A size the consumer put in its own menu that comes
 * back `wrong_ratio` is a misconfiguration on their side — they have paired an
 * artwork with a size it cannot print at — and silently dropping the option
 * means they never find out. This is the stance the validation endpoint
 * already takes by answering `200 {legal: false, reason}` rather than a 4xx.
 *
 * Order and labels are passed through untouched, for the reason on
 * {@link PrintSizeOption}.
 *
 * A design that `repeats` lays its tile across whatever size it prints at,
 * so no size is held to the template's shape: the api judges a repeated
 * design's size by its placement alone (`printSizeIllegalReason`).
 */
export declare function markPrintSizes(template: SizeConstrainedTemplate, options: readonly PrintSizeOption[], selectedIndex: number, repeats?: boolean): PrintSizeChoice[];

/** One of the consumer's own product photos, shown as a view.
 *
 *  The consumer owns ALL of this: which photos exist, what they are called,
 *  and what order they appear in. Pixonaut never learns that a colourway
 *  exists — a storefront that sells a green tee passes the green photo, and
 *  nothing here records that "green" was a thing (ADR 027). */
export declare interface MockupOption {
    /** Fetched by the CUSTOMER'S browser, never by Pixonaut's server. That is
     *  what keeps this field free of SSRF, and it is why the composite is
     *  client-side rather than a server-rendered PNG (ADR 027). */
    url: string;
    /** rendered verbatim — the SDK never composes or translates this */
    label: string;
    /** The label per customer-facing language, by its code (`fr`, #2226): the view
     *  strip shows the one in the designer's language, else `label`. */
    localizedLabels?: Partial<Record<string, string>>;
    /** The id `setView()` and `initialView` name this photo's view by, and
     *  `getViews()` lists it under. Absent: its place in the list, as a
     *  string (`"0"`, `"1"`), which moves when the list is reordered. */
    viewId?: string;
    /** Travels with the url rather than being inherited from a blank: the
     *  browser has no blank to read one from, and a rect measured against one
     *  photo is meaningless against another. */
    placementRectPx: RectPx;
    /** The size of the photo the rect is measured in, when that is not the size
     *  of the image the url serves — a downscaled preview of a larger original,
     *  say. Absent: the rect is in the loaded image's own pixels, as before. */
    photoSizePx?: {
        w: number;
        h: number;
    };
    /** How strongly the photo's light and shade carry onto the design shown on
     *  it, from 0 (flat) to 1. Absent means the default the stored blank uses
     *  too. */
    shadingStrength?: number;
    /** How the design wraps round the product in this photo — a mug or a
     *  bottle — as `{ kind: "cylinder", curvature, edgeFalloff? }`, curvature
     *  in (0, 1]. Absent or null draws the design flat, as an author's mockup
     *  without a wrap. */
    warp?: MockupWarp | null;
    /** How far the photo's folds move the design shown on it — a t-shirt or a
     *  bag — in (0, 1]. Absent or null leaves the design where it is, as an
     *  author's mockup without it. A value outside that range (0 included) is
     *  read as none: the entry is still drawn, undisplaced, as Pixonaut's own
     *  mockup image draws it, and the console says so once. */
    displacementStrength?: number | null;
}

/** A mockup's wrap, as stored on `BlankMockup.warp` and as a storefront
 *  passes it. */
export declare interface MockupWarp {
    kind: typeof WARP_KIND_CYLINDER;
    /** in (WARP_CURVATURE_MIN, WARP_CURVATURE_MAX] */
    curvature: number;
    /** in [EDGE_FALLOFF_MIN, EDGE_FALLOFF_MAX]; absent is DEFAULT_EDGE_FALLOFF */
    edgeFalloff?: number;
}

/** Mount the preview and the form into one element (a selector or the
 *  element), replacing its content. */
export declare function mount(target: MountTarget, opts: MountOptions): Promise<PixonautDesigner>;

/** Mount the preview into `previewTarget` and the form, with its pickers,
 *  into `formTarget`, replacing each one's content, and the view switcher
 *  into `viewsTarget` when given (else above the form). The two are
 *  required, and every target must be apart from the others. `fill` is not
 *  accepted: the host sizes the elements. */
export declare function mount(opts: SplitMountOptions): Promise<PixonautDesigner>;

/** The one credential a mount holds. Both, or neither, does not compile: the
 *  api would have to guess which of two credentials the designer acts as. */
export declare type MountCredential = {
    /** A publishable key, for a storefront that embeds the designer. */
    publishableKey: string;
    shareLink?: never;
    studioSession?: never;
    correctionLink?: never;
    transport?: never;
} | {
    /** A designer share link's token (#557), for a page hosted on the
     *  link's own address. It reaches the one template the link was made
     *  for, by `templateId`, and nothing else the tenant holds. */
    shareLink: string;
    publishableKey?: never;
    studioSession?: never;
    correctionLink?: never;
    transport?: never;
} | TransportCredential;

export declare type MountOptions = (MountOptionsBase & MountCredential & MountPlacement & {
    design?: never;
}) | StoredDesignMount | StudioTemplateMount | CorrectionLinkMount;

/** Every option but the credential. */
declare interface MountOptionsBase extends ErrorOption, FormMountOptions, SideMountOptions {
    templateId?: string;
    /** mount an in-memory template (builder/preview mode) instead of
     *  fetching by templateId; approve() requires a saved templateId */
    template?: TemplateDoc;
    /** The geometry of each shape id an in-memory `template` offers, so
     *  a customer's pick of one previews and validates (#402). The fetch path
     *  takes it from the template route instead; without it here, a pick is
     *  refused locally as `unresolved_shape`. */
    offeredShapes?: ShapeSource;
    /** The tenant's default bleed and safe area for an in-memory `template`,
     *  which it prints with when no placement is named. The fetch path takes
     *  them from the template route instead; without them here, the designer
     *  judges with the product's default margins. */
    printMargins?: PrintMargins;
    /** The shape id of the die the author chose for an in-memory `template`,
     *  which the die picker preselects (#402). Its die arrives resolved to
     *  geometry, so the host that holds the authored reference says which row
     *  it was; the fetch path takes it from the template route instead. */
    dieShapeId?: string;
    /** The shape id each offering shape layer of an in-memory `template`
     *  starts on, by layer id, which its picker preselects (ADR 187): the
     *  layer's outline arrives resolved too. The fetch path takes it from the
     *  template route instead. */
    layerShapeIds?: Readonly<Record<string, string>>;
    /** The template route's answer for the template to open, as the client
     *  fetched it: `@pixonaut/sdk/core` opens it as it is, with no request.
     *  @internal */
    templateRead?: TemplateRead;
    /** The box the named placement fits the design to, as the client fetched
     *  it: the mount's pair opens on it with no request. @internal */
    placementBox?: PlacementBox;
    /** The profile an in-memory `template` draws CMYK through while no
     *  placement is named; a named placement's own wins. @internal */
    cmykPreviewProfile?: BundledOutputProfile;
    /** The blank's views and variants, as the client fetched them: the
     *  mount shows them with no request. @internal */
    blankViews?: BlankViewsRead;
    /** The ids of the blank's views the product is shown in, on its
     *  placements, and `DESIGN_VIEW_ID` (`"design"`) for the Design view, the
     *  artwork alone after them; omitted or empty, every view and the Design
     *  view. */
    views?: readonly string[];
    /** The view shown first, one of `placementId`'s or the Design view: the
     *  editor opens on that placement. Omitted, or naming another, its first
     *  view with a photo. */
    initialView?: string;
    /** The refs of the blank's variants the product is offered in;
     *  omitted or empty, every one not retired. The default picker draws
     *  their colours and garment sizes, and `setVariant()` refuses another. */
    variants?: readonly string[];
    /** The variant shown first, one of `variants`. Omitted, or naming one
     *  that cannot be shown, the first offered with a photo on `placementId`
     *  when the product has options to choose; each view's default photo
     *  when it has none. */
    initialVariant?: string;
    /** What the designer loads ahead so a switch to another variant, view
     *  or side shows at once: `"off"` nothing; `"background"` one at a time
     *  in idle time once the first view is shown, nearest first, holding off
     *  during a gesture, typing or a hidden page; `"eager"` everything at
     *  once once the first view is shown; `"auto"` one of those, with a
     *  concurrency and a memory budget, chosen from an estimate of what it
     *  would hold and the device's signals (`preloadPlan()`). Preloading
     *  spends the shopper's memory, bandwidth and CPU, and may slow the page
     *  on a low-end device. Omitted, `"auto"`. */
    preload?: PreloadMode;
    apiBase?: string;
    /** Where a template's font files are fetched (`<fontBase>/f/<hash>.ttf`,
     *  public and keyless), when that is not `apiBase`: for a host mounting
     *  an in-memory `template` with no `apiBase` of its own. Omitted, they
     *  are fetched under `apiBase`. */
    fontBase?: string;
    /** Read asset bytes through the HOST instead of the key-bearing client.
     *
     *  Called with canvas-kit's `/v1/assets/<id>/content` path and nothing else:
     *  approve, commit, sessions and validate keep going through `apiBase` +
     *  the mount's credential regardless. It exists for a host whose credential this
     *  client cannot attach — e.g. studio, which mounts a preview with an empty
     *  key and serves asset bytes over its own cookie-authed proxy. Omitted,
     *  the built-in client answers asset reads exactly as before. */
    fetchAsset?: (path: string) => Promise<Response>;
    /** The URL of the wasm glue (`pixonaut_wasm.js`); its binary
     *  (`pixonaut_wasm_bg.wasm`) is fetched from beside it, under the same
     *  query. Omitted, the pair is looked for beside the SDK's own script,
     *  keeping the script's query (`?ver=1.4.0`). Pass it when the pair is
     *  served from somewhere else (another host, a CDN), or when a bundler
     *  re-bundles the SDK, which moves the script away from the pair: copy
     *  both files to your public assets and name the glue here. */
    wasmUrl?: string;
    /** the live preview's resolution, in dots per inch of the print. Omitted,
     *  the preview is as many pixels wide as it is drawn on screen, at the
     *  screen's pixel density, whatever the print size */
    previewDpi?: number;
    /** cap on the preview raster's longest edge; keeps big products cheap
     *  to render in the browser (default 1100, interactive gestures 75%) */
    previewMaxEdgePx?: number;
    /** click a rendered layer on the canvas to select it (default true) */
    selectOnClick?: boolean;
    /** `false` turns off the template's snap targets for this mount: a
     *  customer's drag then snaps to nothing (ADR 195) */
    snapping?: boolean;
    /** `false` offers no customer an upscale of a short upload on this mount,
     *  whatever its pictures offer; `{ belowDpi }` offers one only to an
     *  upload below that density at the chosen size. It narrows what the
     *  template offers and never widens it: an upscale still reaches the
     *  picture's own target density (its `toDpi`, else its threshold) and no
     *  further */
    upscale?: UpscaleOption;
    /** hide the generated customer form (builder canvas mode); with it go the
     *  pickers drawn above it */
    showForm?: boolean;
    /** `false` draws no print-size, colour or garment-size picker above the
     *  form, for a host drawing its own from `getPrintSizes()`,
     *  `setPrintSize()`, `getVariants()` and `setVariant()`; `{ size, variant }`
     *  leaves out each one given `false` and draws the other. */
    showPickers?: boolean | PickerVisibility;
    /** `false` draws none of the blank's options but keeps the print-size
     *  menu, for a host whose own variation form chooses the item through
     *  `getOptions()`, `getVariants()` and `setVariant()`, as
     *  `showPickers: { variant: false }` does. `showPickers: false` hides both
     *  whatever this says. */
    showVariantPicker?: boolean;
    /** `false` draws no switcher between the product's views, for a host
     *  drawing its own from `getViews()`, `setView()` and `renderView()` */
    showViews?: boolean;
    /** `true` draws a thin grey frame around the print area on the preview,
     *  as the designer did by default before 0.4.0. Omitted, no frame: the
     *  print sits on the product photo as it will be printed. A preview that
     *  fills its element (`fill`) stands on its stage and draws none either
     *  way. */
    showPrintAreaFrame?: boolean;
    /** `true` draws the frame around a picture placed on the photo at rest.
     *  Omitted, it shows only while the picture is dragged or sized, which
     *  the shopper still does directly on the preview. */
    showPlacementFrame?: boolean;
    /** The host's accent colour, `#rrggbb`: the chosen view, swatch or tile
     *  is ringed in it, a slider's track is filled in it up to the thumb, and
     *  the form's Next button is drawn in it, with black or white text,
     *  whichever reads. Any other value is refused at mount. Omitted, all of
     *  them are drawn in the designer's own ink. */
    accent?: string;
    /** What the artwork is shown on when the template has no blank and its
     *  background shows what stands behind it (`backgroundSeeThrough`): a
     *  `#rrggbb` colour, for a print on other stock, or `false` for none, for a
     *  host drawing its own photo behind a transparent canvas. Omitted, white
     *  paper, cut to the template's outline, and a console warning that a
     *  background should probably be set. Display only: it is in no print file
     *  and no stored design. Any other value is refused at mount. */
    paper?: string | false;
    /** The element "Powered by Pixonaut" is drawn into, when the workspace
     *  shows it: an element, or a CSS selector naming one, apart from the
     *  ones the designer is drawn in. A host that draws a card around the
     *  preview places it under the card, outside its border, rather than have
     *  the mark drawn inside it. Omitted, the mark sits under the preview, in
     *  the element mounted into. It cannot hide the mark: where it is drawn,
     *  it shows. */
    markTarget?: MountTarget;
    /** A link to a server-drawn picture of the default design on the first view:
     *  shown under the loading cover until its first whole frame. */
    posterUrl?: string;
    /** Fill the element mounted into, for a host that gives the designer a
     *  page or window of its own: the preview centred on a stage, as large as
     *  fits, and the form in a column beside it that scrolls by itself; on a
     *  phone the preview stays at the top and the form scrolls under it. The
     *  element needs a height. The stage and the form column are styled
     *  through the custom properties in `FILL_PROPERTIES`, set on the element.
     *  Omitted, the designer flows in the page as it always has. */
    fill?: boolean;
    /** author-mode build guides: canvas center/edges/other layers (default on) */
    authorSnap?: boolean;
    /** template-author mode: every layer is selectable and movable/resizable/
     *  rotatable regardless of customer flags; gestures call onAuthorChange
     *  instead of writing customer payload values */
    authorMode?: boolean;
    /** an author-mode gesture, committed: a layer's patch under its id, or
     *  the image background's crop under the artwork's reserved key (`$artwork`,
     *  the key its customer values travel under), whose patch holds `crop`
     *  alone — the one change the canvas makes to the background */
    onAuthorChange?: (layerId: string, patch: {
        xMm?: number;
        yMm?: number;
        rotationDeg?: number;
        wMm?: number;
        hMm?: number;
        /** the picture's crop, in fractions of its frame (ADR 181) */
        crop?: {
            xFrac: number;
            yFrac: number;
            zoom: number;
        };
    }) => void;
    onChange?: (state: DesignerState) => void;
    /** Hears what `on("printSize")` does: another print size is chosen, in
     *  the size menu or by `setPrintSize()`. */
    onPrintSizeChange?: (event: DesignerEvents["printSize"]) => void;
    /** Hears what `on("variant")` does, from the start: a variant picked in
     *  the designer's picker before `mount()` resolves is heard too, which
     *  `on()`, subscribed once it has, misses. The variant the product opens
     *  on is not reported: it is shown, not chosen. */
    onVariantChange?: (event: DesignerEvents["variant"]) => void;
    /** `true` logs to the console what the designer does: each request it
     *  makes and its answer, each event, the failures it otherwise keeps to
     *  itself, and hints for setting the link up (more sides than
     *  recommended), which a local host gets without it. For diagnosing an embed; leave it off in production, as the
     *  lines carry the customer's values. */
    debug?: boolean;
    /** The values the design starts from, as `getState().values` holds them:
     *  a remount passes back the values it kept, and they are in place, on the
     *  form and in `getState()`, once `mount()` resolves. Keyed values (under
     *  `$fields`) fill the template's fields of the same key, and other
     *  templates' values (under `$templates`) are kept for `setTemplate`. A
     *  key naming no layer of the template is ignored, with a console line.
     *  `onChange` is not called for them. */
    initialValues?: DesignerState["values"];
    onValidation?: (violations: RemoteViolation[]) => void;
    /** BCP-47 tag picking the bundled words: en sv de fr es nl da nb fi, by
     *  its language (`sv-SE` is `sv`). Any other tag, or none, is the
     *  workspace's default language (#1952), else English. */
    locale?: string;
    /** override any subset of the SDK's user-visible strings */
    messages?: Partial<SdkMessages>;
    /** The sizes this storefront sells this design at, in its OWN order and with
     *  its OWN labels (ADR 051). Pixonaut never learns the catalogue: it marks
     *  which entries its rule refuses and renders the rest verbatim. Omitted, no
     *  picker is drawn and the size is whatever the placement or the template's
     *  nominal plate says — exactly as before. */
    printSizes?: PrintSizeOption[];
    /** Which entry starts selected, as an index into `printSizes`. Defaults to
     *  the first. Preselection is merchandising — the launch storefront marks
     *  50×70 POPULAR and puts it in the middle — so it is the consumer's to say
     *  rather than "the first legal one". */
    selectedPrintSizeIndex?: number;
    /** Draw the customer's canvas and preview at the template's own ratio
     *  whatever print size is chosen, and print at the chosen size through the
     *  template's fit (ADR 051 amended). Omitted, the template's own
     *  `drawAtTemplateRatio` decides; `true` or `false` overrides it for this
     *  mount. */
    drawAtTemplateRatio?: boolean;
    /** The storefront's own product photos, in its OWN order and with its OWN
     *  labels (ADR 027), shown instead of the blank's: each is a view of its
     *  own, named by its `viewId` (else its place in the list). Pixonaut never
     *  learns what they show: it composites the chosen photo in the customer's
     *  browser and records nothing about it — no variant, no availability, and
     *  nothing on the Design, the proof or the job ticket.
     *
     *  Each entry carries its own `placementRectPx`, saying where the print sits
     *  IN THAT PHOTO. It travels with the url because the browser has no blank
     *  to inherit a rect from, and a rect measured against one photo is
     *  meaningless against another.
     *
     *  Nothing here is judged against the artwork: unlike a print size, a photo
     *  cannot affect what gets cut. Only an entry that cannot be drawn is
     *  skipped — an empty url, an unusable rect, a `shadingStrength` outside
     *  0 to 1, or a `warp` the stored blank would refuse (`isUsableMockup`). A
     *  `displacementStrength` the stored blank would refuse is read as none,
     *  with a console line, and the entry is still drawn. Omitted, the blank's
     *  views are shown, if the mount names one. */
    mockups?: MockupOption[];
}

/** The panel layout of `blankId` a design is drawn across, in place of a
 *  placement: its name, the canvas at the size sold and each window on it,
 *  as a designer link's resolve answers them (`panelLayoutCanvas`). */
export declare interface MountPanelLayout {
    /** The layout's name on its blank, which proofs and commits name. */
    name: string;
    /** The canvas at the size sold, in mm. */
    canvasWidthMm: number;
    canvasHeightMm: number;
    /** Each window, in the layout's order. */
    windows: readonly PanelLayoutWindow[];
    /** Each placement's print, which the designer cuts from the canvas to
     *  show it on the blank's photos. Absent, the canvas is shown, with each
     *  window's outline over it. */
    prints?: readonly PanelLayoutPrint[];
    /** The placement a picture placed through the layout starts centred on
     *  (#2368), as the resolve answers it. Absent, it starts on the first
     *  window's placement. */
    focusPlacementId?: string;
    /** The size the canvas is at, as the blank's option values
     *  (`{ Size: "L" }`): the proof and the designs are made at it. Absent on
     *  a blank whose print areas change with no option. */
    variantRow?: Readonly<Record<string, string>>;
}

/** The blank and placement this design is for: both, or neither (a
 *  placement id is unique only within its blank). Named, every proof and
 *  commit the designer makes is fitted to that placement, so the proof a
 *  customer approves is the print they get (ADR 022); a pair that does not
 *  resolve is refused as `unknown_placement` (ADR 231). The blank's views
 *  are shown behind the design, and `setView()` switches to another of the
 *  product's `placements` without remounting. */
declare type MountPlacement = {
    blankId: string;
    placementId: string;
    panelLayout?: never;
} | {
    blankId: string;
    placementId?: never;
    /** A panel layout of `blankId` to draw one design across, in place of
     *  a placement (#2047): the preview is the layout's canvas, with each
     *  window's outline over it, and `approve()` proofs that canvas and
     *  `commit()` makes one design per placement it is cut into. No size
     *  menu, photos or other sides: the layout is drawn at its canvas. */
    panelLayout: MountPanelLayout;
    placements?: never;
    mockups?: never;
    printSizes?: never;
    selectedPrintSizeIndex?: never;
} | {
    blankId?: never;
    placementId?: never;
    panelLayout?: never;
};

/** An element, or a CSS selector naming one. */
export declare type MountTarget = string | HTMLElement;

/** A method for every published `GET` operation, named from its
 *  operationId, over `json` (an answer read as JSON) and `raw` (an answer
 *  handed over as it came: a picture or a PDF). */
declare function operations({ json, raw }: Reads): {
    apiKeys: {
        /** List the tenant's API keys */
        list: () => Promise<AnswerOf<"/v1/api-keys">>;
    };
    assetCategories: {
        /** List asset categories */
        list: (query?: QueryOf<"/v1/asset-categories">) => Promise<AnswerOf<"/v1/asset-categories">>;
    };
    assetFilters: {
        /** List the values the asset listing can filter on */
        list: () => Promise<AnswerOf<"/v1/asset-filters">>;
    };
    assets: {
        /** Check whether the tenant already stores a file */
        checkDuplicate: (sha256hex: string) => Promise<AnswerOf<"/v1/asset-duplicate/{sha256hex}">>;
        content: {
            /** Fetch an asset's preview */
            get: (id: string, query?: QueryOf<"/v1/assets/{id}/content">) => Promise<Response>;
        };
        /** How a customer's upload's cut-out stands */
        cutOutStatus: (id: string) => Promise<AnswerOf<"/v1/assets/{id}/cut-out">>;
        /** Read an asset */
        get: (id: string) => Promise<AnswerOf<"/v1/assets/{id}">>;
        /** List assets */
        list: (query?: QueryOf<"/v1/assets">) => Promise<AnswerOf<"/v1/assets">>;
        original: {
            /** Fetch an asset's file */
            get: (id: string) => Promise<Response>;
        };
        roundShapes: {
            /** Find the round shapes in a picture */
            get: (id: string, query?: QueryOf<"/v1/assets/{id}/round-shapes">) => Promise<AnswerOf<"/v1/assets/{id}/round-shapes">>;
        };
        usage: {
            /** Where an asset is used */
            get: (id: string) => Promise<AnswerOf<"/v1/assets/{id}/usage">>;
        };
    };
    assetTags: {
        /** List asset tags */
        list: (query?: QueryOf<"/v1/asset-tags">) => Promise<AnswerOf<"/v1/asset-tags">>;
    };
    automations: {
        /** Read one of an automation's firings */
        firing: (id: string, firingId: string) => Promise<AnswerOf<"/v1/automations/{id}/firings/{firingId}">>;
        /** List an automation's firings */
        firings: (id: string, query?: QueryOf<"/v1/automations/{id}/firings">) => Promise<AnswerOf<"/v1/automations/{id}/firings">>;
        /** Read an automation */
        get: (id: string) => Promise<AnswerOf<"/v1/automations/{id}">>;
        /** List the workspace's automations */
        list: (query?: QueryOf<"/v1/automations">) => Promise<AnswerOf<"/v1/automations">>;
        /** List the placeholders an automation may write */
        placeholders: () => Promise<AnswerOf<"/v1/automations/placeholders">>;
        /** List an automation's versions */
        versions: (id: string, query?: QueryOf<"/v1/automations/{id}/versions">) => Promise<AnswerOf<"/v1/automations/{id}/versions">>;
    };
    blanks: {
        /** Read a blank */
        get: (id: string) => Promise<AnswerOf<"/v1/blanks/{id}">>;
        /** List the tenant's blanks */
        list: (query?: QueryOf<"/v1/blanks">) => Promise<AnswerOf<"/v1/blanks">>;
        mockups: {
            /** List a blank's placements, views and mockups */
            list: (id: string, query?: QueryOf<"/v1/blanks/{id}/mockups">) => Promise<AnswerOf<"/v1/blanks/{id}/mockups">>;
        };
        placements: {
            fittingTemplates: {
                /** List the templates that fit a placement */
                list: (id: string, placementId: string, query?: QueryOf<"/v1/blanks/{id}/placements/{placementId}/fitting-templates">) => Promise<AnswerOf<"/v1/blanks/{id}/placements/{placementId}/fitting-templates">>;
            };
        };
        previewTables: {
            /** Read the colour tables a placement draws with */
            get: (id: string, placementId: string) => Promise<AnswerOf<"/v1/blanks/{id}/placements/{placementId}/preview-tables">>;
        };
        variants: {
            /** List a blank's variants */
            list: (id: string, query?: QueryOf<"/v1/blanks/{id}/variants">) => Promise<AnswerOf<"/v1/blanks/{id}/variants">>;
        };
        views: {
            mockup: {
                /** Render a view of a blank with its prints */
                get: (id: string, viewId: string, query?: QueryOf<"/v1/blanks/{id}/views/{viewId}/mockup.png">) => Promise<Response>;
            };
        };
    };
    connectedStore: {
        /** Read this store's connection */
        get: () => Promise<AnswerOf<"/v1/connect">>;
    };
    customerSessions: {
        /** Inspect a customer session's uploads and proofs */
        get: (id: string) => Promise<AnswerOf<"/v1/admin/sessions/{id}">>;
    };
    designs: {
        delivery: {
            /** Read a design's first-order delivery */
            get: (id: string) => Promise<AnswerOf<"/v1/designs/{id}/delivery">>;
        };
        designer: {
            /** Open a design in the designer to correct it */
            get: (id: string) => Promise<AnswerOf<"/v1/designs/{id}/designer">>;
        };
        /** Read one design */
        get: (id: string) => Promise<AnswerOf<"/v1/designs/{id}">>;
        /** List the tenant's designs, newest first */
        list: (query?: QueryOf<"/v1/designs">) => Promise<AnswerOf<"/v1/designs">>;
        mockup: {
            /** Render a design on a product photo */
            get: (id: string, query?: QueryOf<"/v1/designs/{id}/mockup.png">) => Promise<Response>;
        };
        proof: {
            /** Fetch a design's proof image */
            get: (id: string, query?: QueryOf<"/v1/designs/{id}/proof">) => Promise<Response>;
        };
        review: {
            /** Read a design as its customer reviews it */
            get: (id: string) => Promise<AnswerOf<"/v1/designs/{id}/review">>;
        };
        revisions: {
            /** Read one revision of a design */
            get: (id: string, revision: string) => Promise<AnswerOf<"/v1/designs/{id}/revisions/{revision}">>;
            image: {
                /** Draw a revision of a design */
                get: (id: string, revision: string, query?: QueryOf<"/v1/designs/{id}/revisions/{revision}/image">) => Promise<Response>;
            };
            /** List a design's revisions */
            list: (id: string, query?: QueryOf<"/v1/designs/{id}/revisions">) => Promise<AnswerOf<"/v1/designs/{id}/revisions">>;
        };
    };
    events: {
        /** Pull the tenant's events after a given one */
        list: (query?: QueryOf<"/v1/events">) => Promise<AnswerOf<"/v1/events">>;
    };
    orders: {
        deliveries: {
            /** Read an order's step delivery log */
            list: (id: string) => Promise<AnswerOf<"/v1/orders/{id}/deliveries">>;
        };
        /** Read one order, with its history */
        get: (id: string) => Promise<AnswerOf<"/v1/orders/{id}">>;
        job: {
            files: {
                /** Fetch every file of an order's print as one zip */
                bundle: (id: string, query?: QueryOf<"/v1/orders/{id}/job/bundle">) => Promise<Response>;
                /** Fetch the print file of an order */
                get: (id: string, query?: QueryOf<"/v1/orders/{id}/job/file">) => Promise<Response>;
                /** List the files an order can be fetched as */
                list: (id: string, query?: QueryOf<"/v1/orders/{id}/job/files">) => Promise<AnswerOf<"/v1/orders/{id}/job/files">>;
            };
            /** Read an order as its printshop makes it */
            get: (id: string, query?: QueryOf<"/v1/orders/{id}/job">) => Promise<AnswerOf<"/v1/orders/{id}/job">>;
        };
        /** List the tenant's orders, newest first */
        list: (query?: QueryOf<"/v1/orders">) => Promise<AnswerOf<"/v1/orders">>;
        progress: {
            /** Read an order as its customer follows it */
            get: (id: string) => Promise<AnswerOf<"/v1/orders/{id}/progress">>;
        };
        /** List the blanks a failed order could be sent to */
        rerouteTargets: (id: string, query?: QueryOf<"/v1/orders/{id}/reroute-targets">) => Promise<AnswerOf<"/v1/orders/{id}/reroute-targets">>;
    };
    pixelConversation: {
        /** Read the author's open Pixel conversation */
        get: () => Promise<AnswerOf<"/v1/pixel/conversation">>;
    };
    prints: {
        files: {
            /** Fetch every file of a print as one zip */
            bundle: (id: string, query?: QueryOf<"/v1/prints/{id}/bundle">) => Promise<Response>;
            /** Fetch a print's file */
            get: (id: string, query?: QueryOf<"/v1/prints/{id}/file">) => Promise<Response>;
            /** List the files a print can be fetched as */
            list: (id: string, query?: QueryOf<"/v1/prints/{id}/files">) => Promise<AnswerOf<"/v1/prints/{id}/files">>;
        };
        /** Read one print */
        get: (id: string) => Promise<AnswerOf<"/v1/prints/{id}">>;
        /** List the tenant's prints */
        list: (query?: QueryOf<"/v1/prints">) => Promise<AnswerOf<"/v1/prints">>;
    };
    printshopConnections: {
        /** List the tenant's printshop connections */
        list: (query?: QueryOf<"/v1/printshop-connections">) => Promise<AnswerOf<"/v1/printshop-connections">>;
    };
    scenes: {
        /** List the library's scenes */
        list: (query?: QueryOf<"/v1/scenes">) => Promise<AnswerOf<"/v1/scenes">>;
    };
    shareLinks: {
        designer: {
            /** Read what a designer link was handed out with */
            get: (id: string) => Promise<AnswerOf<"/v1/share-links/{id}/designer">>;
        };
        /** List the tenant's share links */
        list: (query?: QueryOf<"/v1/share-links">) => Promise<AnswerOf<"/v1/share-links">>;
    };
    storage: {
        /** Read the tenant's storage use */
        get: () => Promise<AnswerOf<"/v1/storage">>;
    };
    templates: {
        constraints: {
            /** Read what a template's print size is judged by */
            get: (id: string, query?: QueryOf<"/v1/templates/{id}/constraints">) => Promise<AnswerOf<"/v1/templates/{id}/constraints">>;
        };
        fittingPlacements: {
            /** List the blank placements a template fits */
            list: (id: string, query?: QueryOf<"/v1/templates/{id}/fitting-blanks">) => Promise<AnswerOf<"/v1/templates/{id}/fitting-blanks">>;
        };
        /** Read a published template */
        get: (id: string, query?: QueryOf<"/v1/templates/{id}">) => Promise<AnswerOf<"/v1/templates/{id}">>;
        /** List the tenant's templates */
        list: (query?: QueryOf<"/v1/templates">) => Promise<AnswerOf<"/v1/templates">>;
    };
    webhookEndpoints: {
        deliveries: {
            /** List an endpoint's recent deliveries */
            list: (id: string) => Promise<AnswerOf<"/v1/webhooks/{id}/deliveries">>;
        };
        /** List the tenant's webhook endpoints */
        list: () => Promise<AnswerOf<"/v1/webhooks">>;
    };
};

/** The option words `SdkMessages` carries. */
export declare interface OptionMessages {
    /** A text layer's offered alignments, one message per wire name (#317).
     *  Named because `middle` is the renderer's vocabulary and a stable
     *  identity, not the word a customer should read. Three rungs apply --
     *  integrator, tenant, then these -- and an alignment with no
     *  word anywhere falls back to its own name. A font family has no key: its
     *  name IS the word. */
    "form.align.left": string;
    "form.align.center": string;
    "form.align.right": string;
    "form.valign.top": string;
    "form.valign.middle": string;
    "form.valign.bottom": string;
    /** A text's offered cases (#2505), under the same three rungs as an
     *  alignment: `upper` is the renderer's word. Each reads as it draws. */
    "form.textCase.asTyped": string;
    "form.textCase.upper": string;
    "form.textCase.lower": string;
    "form.textCase.title": string;
    /** A picture's offered fits (#805), one message per fit, under the same
     *  three rungs as an alignment: `cover` is the renderer's word, not the
     *  one a customer should read. */
    "form.fit.contain": string;
    "form.fit.cover": string;
    "form.fit.fill": string;
    "form.fit.tile": string;
    /** A photo's offered tones (#2559), under the same three rungs as an
     *  alignment: `none` is the renderer's word for the photo's own colours. */
    "form.tone.none": string;
    "form.tone.blackAndWhite": string;
    "form.tone.sepia": string;
    "form.tone.duotone": string;
    "form.tone.recolour": string;
    "form.tone.invert": string;
}

/** How a value of an option looks to a shopper. */
export declare type OptionValueAppearance = 
/** Drawn flat in `colours`: one `#rrggbb`, or two for a heather or a
*  two-tone, drawn half and half. */
    {
    kind: "fill";
    colours: readonly string[];
}
/** Drawn as a picture of it, such as a pattern or a material: `url`
*  opens it with no key. */
| {
    kind: "image";
    assetId: string;
    url: string;
};

/** A named color entry within a palette, as the resolved document carries
 *  it. */
declare interface PaletteEntry {
    name: string;
    color: ColorValue;
}

/** One placement's print at the size sold, which its windows' pieces are
 *  cut into. */
export declare interface PanelLayoutPrint {
    placementId: string;
    widthMm: number;
    heightMm: number;
    /** The placement's print area shape, where it has one: a scattered
     *  repeat's items are measured against it within each window. */
    shape?: PrintAreaShape;
}

/** One window of a panel layout: where one piece of a placement's print is
 *  cut from the canvas. */
export declare interface PanelLayoutWindow {
    /** The placement the piece is printed on. */
    placementId: string;
    /** The placement's name, which the outline is labelled with. */
    placementName: string;
    /** Which piece of the placement's print it is, by the merchant's name. */
    piece: string;
    /** Its left edge on the canvas, in mm. */
    xMm: number;
    /** Its top edge on the canvas, in mm. */
    yMm: number;
    /** Its width on the canvas, in mm, after its turn. */
    widthMm: number;
    /** Its height on the canvas, in mm, after its turn. */
    heightMm: number;
    /** The piece, in mm of the placement's print (`MountPanelLayout.prints`).
     *  Absent from an api from before it, whose page shows the canvas. */
    srcRectInFile?: {
        xMm: number;
        yMm: number;
        widthMm: number;
        heightMm: number;
    };
    /** Its turn on the canvas, clockwise about its centre, after it is
     *  mirrored; 0 when absent. */
    rotationDeg?: 0 | 90 | 180 | 270;
    /** Whether the piece is flipped left to right on the canvas. */
    mirrored?: boolean;
}

/** A path's `{name}` segments, by name. */
declare type PathParams = Record<string, string | number>;

/** A payload's layer-keyed values, as `validate_payload` reads them: layer
 *  id → that layer's props. */
declare type PayloadValues = Record<string, Record<string, unknown>>;

/** The pickers `showPickers` draws above the form, each on its own: `false`
 *  leaves that one out, for a host drawing its own. Omitted, it is drawn. */
export declare interface PickerVisibility {
    /** the print-size menu, which `getPrintSizes()` and `setPrintSize()`
     *  replace */
    size?: boolean;
    /** the blank's options, which `getOptions()`, `getVariants()` and
     *  `setVariant()` replace */
    variant?: boolean;
}

/** The words, spelled once in TypeScript so the type is a closed union. The
 *  fixture is the shared list; `checkedAgainstFixture` refuses to load when
 *  the two disagree, rather than a test noticing later. */
declare const PICTURE_FIT_WORDS: readonly ["contain", "cover", "fill", "tile"];

declare type PictureFitValue = (typeof PICTURE_FIT_WORDS)[number];

/** How a picture measures for print (`getResolution`): its pixels along the
 *  edge that decides, the pixels that edge needs at `floorDpi`, and whether
 *  it falls short (`low`). */
export declare interface PictureResolution extends PrintResolution {
    /** The floor it was judged against: the template's and the tenant's. */
    floorDpi: number;
}

/** What `ask()` resolves with: what Pixel says it did, for the customer,
 *  each field it changed, each picture it is cutting out, which takes its
 *  place once done, and each layer of the customer's own it added or
 *  removed. An answer with none of them changed nothing. */
export declare interface PixelAnswer {
    reply: string;
    changed: PixelField[];
    cutOuts: PixelField[];
    layers: PixelLayerChange[];
}

/** What `ask()` takes besides the prompt. */
export declare interface PixelAskOptions {
    /** Cancels the request: the api stops working on it, nothing it answered
     *  is applied, and `ask()` rejects with the signal's reason. */
    signal?: AbortSignal;
}

/** Pixel's progress, as `on("pixel")` hears it: a request sent (`asking`),
 *  its changes applied (`done`), its generic failure (`failed`), the
 *  customer's cancel (`cancelled`), or its last step taken back
 *  (`undone`). */
export declare type PixelEvent = {
    status: "asking";
} | ({
    status: "done";
} & PixelAnswer) | {
    status: "failed";
} | {
    status: "cancelled";
} | {
    status: "undone";
    changed: PixelField[];
    layers: PixelLayerChange[];
};

/** A field of the design: a layer's prop, as the form offers it. */
export declare interface PixelField {
    layerId: string;
    prop: string;
}

/** A layer of the customer's own Pixel added or removed (#2938), by the id
 *  it had when it did. */
export declare interface PixelLayerChange {
    action: "added" | "removed";
    layerId: string;
    kind: CustomerLayerKind;
}

/** The Pixel control's words `SdkMessages` carries. */
declare interface PixelMessages {
    /** The button that opens the prompt. */
    "pixel.create": string;
    /** In the empty prompt, and its accessible name. */
    "pixel.placeholder": string;
    /** The button that sends the prompt. */
    "pixel.generate": string;
    /** While Pixel works on a request. */
    "pixel.generating": string;
    /** After it, muted: how long a request may take. */
    "pixel.generating-hint": string;
    /** The button that cancels a request under way. */
    "pixel.cancel": string;
    /** The open prompt's close button's accessible name. */
    "pixel.close": string;
    /** Once Pixel changed the design. */
    "pixel.done": string;
    /** The button that takes Pixel's last change back. */
    "pixel.undo": string;
    /** While Pixel's change is taken back. */
    "pixel.undoing": string;
    /** The accessible name of the button that puts the done row away. */
    "pixel.dismiss": string;
    /** Whatever stopped a request: never why, which the customer cannot act
     *  on and the shop keeps to itself. */
    "pixel.failed": string;
    /** The button that sends a failed prompt again. */
    "pixel.try-again": string;
}

/** Why `ask()` rejected: Pixel did not answer, for any reason. It carries
 *  none on purpose: the customer is shown one generic failure. */
export declare class PixelUnavailableError extends Error {
    constructor();
}

/** `mount`, the version and the error classes, as one default export. The
 *  IIFE build's `window.Pixonaut` is the whole module namespace, which
 *  carries these and every other export. */
declare const Pixonaut: {
    mount: typeof mount;
    version: string;
    PixelUnavailableError: typeof PixelUnavailableError;
    PlacementChangedError: typeof PlacementChangedError;
    TemplateChangedError: typeof TemplateChangedError;
    TemplateRenderError: typeof TemplateRenderError;
    UnreadableDocumentError: typeof UnreadableDocumentError;
    VariantUnavailableError: typeof VariantUnavailableError;
    WasmBootError: typeof WasmBootError;
    WasmIntegrityError: typeof WasmIntegrityError;
    WasmVersionMismatchError: typeof WasmVersionMismatchError;
};
export { Pixonaut }
export default Pixonaut;

/** A refusal from Pixonaut's API. `status` is the contract a host branches
 *  on (an expired link's 401, a paused template's 404, a throttled visitor's
 *  429, an outage's 5xx); `code` is the server's `error`, as its error codes
 *  list it, when its body names one; `requestId` names the request to
 *  Pixonaut's support, when the response carried one. */
export declare class PixonautApiError extends Error {
    /** Whether `value` is a refusal from any of the SDK's bundles. Each entry
     *  (client, core, the whole SDK) carries its own copy of this class, so a
     *  refusal is told by its brand rather than by which copy made it: one
     *  imported from the client catches the designer's refusals too. */
    static [Symbol.hasInstance](value: unknown): boolean;
    readonly [API_ERROR_BRAND] = true;
    readonly status: number;
    readonly code?: string;
    readonly requestId?: string;
    /** what the design breaks, on a 422 that is a validation failure; the same
     *  list `onValidation` was handed */
    readonly violations?: RemoteViolation[];
    /** The side refused, when `approve()` or `commit()` made a design of
     *  several (#1364): its placement. */
    placementId?: string;
    /** On a refused `commit()` of several sides (#2190): the sides committed
     *  before it, which the next `commit()` answers with rather than commits
     *  again while their design is unchanged. */
    committed?: {
        designId: string;
        placementId: string | null;
    }[];
    /** On a `link_design_limit` refusal: when the share link makes designs
     *  again, in epoch milliseconds, or null for a limit in total, which never
     *  reopens. */
    readonly reopensAt?: number | null;
    /** On an `sdk_outdated` refusal: the first SDK release that draws the
     *  template, or null when no released SDK does yet. */
    readonly minimumSdkVersion?: string | null;
    /** On a `design_not_stitchable` refusal: each part of the design the
     *  embroidery machine cannot stitch, an off-list colour with the nearest
     *  thread by name. */
    readonly stitchFindings?: StitchFinding[];
    constructor(message: string, status: number, detail?: {
        code?: string;
        violations?: RemoteViolation[];
        requestId?: string;
        reopensAt?: number | null;
        minimumSdkVersion?: string | null;
        stitchFindings?: StitchFinding[];
    });
}

/** The client: a method for every published `GET` operation, named from its
 *  operationId (`client.templates.get(id)`). */
export declare type PixonautClient = ReturnType<typeof operations>;

export declare class PixonautDesigner extends DesignerProduct {
    /** holds the view switcher apart from the form; null: above the form */
    private readonly viewsRoot;
    private readonly opts;
    private root;
    /** holds the form apart from the preview (`dom/split.ts`); null: `root` */
    private readonly formRoot;
    private template;
    /** The template as the colour shown draws it (#2962). */
    private toned;
    private drawn;
    private formSchema;
    private printMargins;
    private read;
    private values;
    /** Pixel for the customer (#2744), over the form and its requests. */
    readonly pixel: DesignerPixel;
    /** the chosen print size, held BESIDE `values` — see `form/size.ts` */
    private size;
    /** the view shown and its variant's photo. Kept OUT of `DesignerState`:
     *  unlike a print size it reaches no Design, proof or job ticket, so
     *  surfacing it as persistable state would imply it is part of the
     *  artefact (ADR 027). */
    private views;
    /** The profiles the canvas draws through, and their uploaded tables. */
    private previewTables;
    /** `renderTemplate()`'s pictures, drawn by the pipeline (#1365). */
    private thumbnails;
    /** DOM/canvas layer: preview img and overlay host (owns those live nodes;
     *  exposes them via getters) */
    private dom;
    /** generated-form machinery: field renderers, upload flow, headless
     *  schema/value API (deps: schema/template/values + the place control) */
    private form;
    /** server seam: session id, authenticated fetch, clearSession, approve */
    private apiClient;
    /** The blank and placement requests carry, switched by `setView`. */
    private placement;
    /** A layer's place as the customer moved it. */
    private transforms;
    /** What the proof waits for: an upload being improved (#1092). */
    private work;
    /** the stored design a correction mount opens, and what its requests carry */
    private stored;
    /** `setTemplate`, and the values of the templates not shown (#1363). */
    private templates;
    /** Template reads, each template and pair read once. */
    private reads;
    /** The item's other sides, each with its template and values (#1364). */
    private sides;
    /** The design's repeat, when the host offers one (#2148). */
    private repeat;
    /** What is loaded ahead of a switch (#1430). */
    private preloads;
    /** `approve()` and `commit()` over every enabled side. */
    private requests;
    private previewOpened;
    /** What the last `commit()` asked; a commit after another size asks too. */
    private commitOptions;
    /** the shared render pipeline; its parts below are aliases set after it */
    private pipeline;
    private gestures;
    /** live-preview render loop: rAF coalescing, settle re-render, dpi/edge caps */
    private preview;
    /** render orchestration: worker pump, queues, asset + gesture caches, sprite */
    private render;
    /** doc-model helpers: templateJson/authorDoc/violations state, validation,
     *  transform/size resolution, gesture plate/sprite split, author patch */
    private doc;
    /** init built the designer into `root`; until then root is the embedder's */
    private built;
    /** what `on()` subscribes to (`events.ts`) */
    private readonly events;
    /** where the designer is drawn, and how its canvas takes input */
    private canvas;
    /** The generated form and pickers (`@pixonaut/sdk/ui`); null draws the
     *  canvas alone, as `showForm: false` and `showPickers: false` do. */
    private readonly ui;
    /** takes the host's accent off the elements drawn into */
    private readonly clearAccent;
    /** the mount's own listeners, its `debug` log and `shown` */
    private readonly signals;
    constructor(root: HTMLElement, opts: MountOptions, formRoot?: HTMLElement | null, ui?: DesignerUi | null, 
    /** holds the view switcher apart from the form; null: above the form */
    viewsRoot?: HTMLElement | null);
    /** Words and request language (#2091): the mount's, else the workspace's. */
    private words;
    /** `showForm`, and false for a designer with no form to draw. */
    private showsForm;
    /** tenant words for what a template offers (#238/#254) and its die offer (#402) */
    private pickerSources;
    getSessionId(): string;
    /** "clear my design": wipes this session's unordered uploads server-side */
    clearSession(): Promise<void>;
    init(): Promise<void>;
    /** Another garment tone draws the template's other values (#2962): the
     *  form and every side's print follow them. */
    private retone;
    /** Take `opened` as the template shown. */
    private open;
    /** 'worker' when preview rendering runs off the main thread */
    get previewEngine(): "worker" | "sync";
    /** Telemetry seam fired for every dispatched render job with its target
     *  and the length of its assets json: the refs a job names its pictures
     *  by, and their base64 only where a picture goes inline. The bytes a
     *  picture is registered with once per instance are not counted. A no-op
     *  by default; it lives on the prototype so a host can wrap it once and
     *  observe every mount's jobs (used by the studio progressive-asset e2e).
     *  Not part of the headless API. @internal */
    protected onRenderJob(_target: string, _assetsLen: number): void;
    private api;
    selectLayer(layerId: string | null): void;
    getSelectedLayer(): string | null;
    /** Select no layer. */
    clearSelection(): void;
    /** Subscribe to `event`; the function returned ends the subscription.
     *  `change` is what the `onChange` option hears. */
    on<E extends DesignerEventName>(event: E, handler: DesignerEventHandler<E>): () => void;
    /** `"preview"` shows the product alone, with no handles or guides and no
     *  gesture on the canvas; `"edit"` brings them back. */
    setMode(mode: DesignerMode): void;
    /** The canvas's mode now (`setMode`). */
    getMode(): DesignerMode;
    /** Turn a layer's handles off, or on again: off, the layer takes no drag,
     *  turn or scale on the canvas, and its form fields stay. */
    setInteractive(layerId: string, on: boolean): void;
    /** Put a layer back as the template has it: its values and its place. */
    resetLayer(layerId: string): void;
    /** Draw the designer into `el` instead, keeping the design, the selection
     *  and the step: for a canvas in a dialog that opens and closes. */
    attach(el: HTMLElement): void;
    /** Take the designer out of its element, keeping the design, until
     *  `attach()` draws it again. */
    detach(): void;
    /** the steps JSON a headless host renders its own UI from: groupId/label/
     *  layerIds, typed fields (an image field: feed its file to uploadAsset) and
     *  `transforms`, one per placeable layer, whose `layerId` selectLayer takes.
     *  Same schema the API serves for the template. */
    getFormSchema(): FormSchema;
    /** The mounted template document, whole — every prop's `constraints` and
     *  an image layer's `oneOf` preset ids. The contract (ADR 048): it is this
     *  widget's copy of the validation and layout rules, which `validateLocal`
     *  and the preview run on with no round-trip, and the API serves it
     *  unprojected to the `pk` alike, so an embedder sees what the renderer
     *  sees. For the customer-facing subset — editable props of grouped
     *  layers, with their labels and field types — use
     *  {@link PixonautDesigner.getFormSchema}. */
    getTemplate(): TemplateDoc;
    /** The consumer's own size menu with the rule's verdict on each entry — the
     *  headless seam a storefront drawing its own radio buttons reads instead of
     *  the default picker, the same way `getFormSchema()` serves the JSON behind
     *  `buildForm()` (ADR 051). */
    getPrintSizes(): PrintSizeChoice[];
    /** The size the customer chose, as an index into `opts.printSizes`. */
    setPrintSize(index: number): void;
    /** Show every view in variant `ref` (another colour or size of the
     *  product): the view shown stays when it has a photo of it, else the
     *  first that has one is shown. `on("variant")` reports it. A ref that is
     *  not the blank's, a retired one, and one no allowed view has a photo of
     *  (on a product photographed in some) reject with a `VariantUnavailableError` naming why, and the variant
     *  shown stays. Another size after `commit()` commits every side again,
     *  resolving as it does, on a blank whose areas change with it (#1915). */
    setVariant(ref: string): Promise<void>;
    /** Each side of the item, in `placements` order: its placement, the
     *  template it shows, whether `approve()` takes it, and whether its design
     *  passes its template's rules. A mount naming no placements has one. */
    getSides(): DesignerSide[];
    /** Have `approve()` and `commit()` take side `placementId`, or leave it
     *  out; it keeps its values either way. A `TypeError` for a placement
     *  that is not a side, and for leaving out the last side they take. */
    setSideEnabled(placementId: string, on: boolean): void;
    /** Take a placement of a linked side out of its group to design it on
     *  its own, keeping the group's design as it stands, where
     *  `linkedPlacements.designSeparately` allows; or put it back, the side
     *  shown's design then the group's when it is among them; and mirror its
     *  print, or not. `on("link")` reports it. A `TypeError` for a placement
     *  no group links, and for one the mount does not let out. */
    setSideLink(placementId: string, change: SideLinkChange): void;
    getState(): DesignerState;
    /** Where a form drawn as steps stands (ADR 196), so a host can show its
     *  own finishing button on the last step. Undefined on a page and while the
     *  generated form is not shown (`showForm: false`). */
    getStep(): FormStepPosition | undefined;
    /** Show the step at `index`, from 0 among the steps the form shows.
     *  Ignored unless the form is shown as steps, and for an index that names
     *  no shown step. */
    setStep(index: number): void;
    /** Mark a host item (`formGroups`) complete, or not: Next waits on it. */
    setHostItemComplete(id: string, complete: boolean): void;
    setValue(layerId: string, prop: string, value: unknown): void;
    /** The value a field shows: the customer's, carried by its field key
     *  too, else the template's own. */
    getValue(layerId: string, prop: string): unknown;
    /** Each field's label and its value in words (`FieldText`). */
    getFieldTexts(): FieldText[];
    setTransform(layerId: string, patch: LayerTransformPatch): void;
    /** Scale a layer as its scale handle does: held to the layer's bounds,
     *  rounded to its step, kept on the plate, and redrawn. */
    scaleLayer(layerId: string, scale: number): void;
    /** Let a drag on the canvas move a picture inside its frame — a layer's
     *  (`{ kind: "layer", layerId }`) or the image background's
     *  (`{ kind: "background" }`) — or end that (`null`); `on("crop")` reports
     *  it. False, and nothing changes, for a site with no picture to move.
     *  Entering it selects the layer (none, for the background), which
     *  `on("selection")` hears. */
    setCropMode(target: CropTarget | null): boolean;
    private notifyChange;
    private afterChange;
    private templateIdOf;
    /** Resolves once the designer is shown (`on("shown")`). */
    shown(): Promise<void>;
    /** Local WASM validation for instant feedback; the server re-validates. */
    validateLocal(): {
        ok: boolean;
        doc?: TemplateDoc;
        violations: Violation[];
    };
    /** Resolves once every upload and improvement under way has written its
     *  value, however it ended: `getState()` then holds what they landed. */
    settled(): Promise<void>;
    /** upload a customer image for an image layer and set its `src`.
     *  Returns the assetId plus any print-quality warning from the API
     *  (machine `warningKey` + `warningParams`, and the English `warning`). */
    uploadAsset(file: File, layerId: string): Promise<UploadResult>;
    /** How the picture uploaded for a layer measures at the chosen print size
     *  against the template's floor, as the upload's own check measures it: on
     *  the site as the template places it, before any crop, and under a
     *  repeat at the tile's size, as the design's own check does. Undefined
     *  without an upload there, a print size or a floor. */
    getResolution(layerId: string): PictureResolution | undefined;
    /** A refused approve or commit: its step shown, why told at each field. */
    private refused;
    /** Improve an upload whose result offered `improveResolution`; the
     *  improved picture replaces it on the layer, and `approve()` waits. */
    improveResolution(assetId: string, layerId: string): Promise<Improved>;
    /** Server-side proof of every enabled side (#1364); resolves the lead
     *  side's proof, the first enabled in `placements`, with every side's
     *  under `sides`. Rejects with a `PixonautApiError` (its `violations`, or
     *  `status` and `code`; its `placementId` names the side refused), or a
     *  `PlacementChangedError` when the side shown switched while the proofs
     *  were being made, or a `TemplateChangedError` when `setTemplate()`
     *  switched the template. */
    approve(): Promise<Approved>;
    /** Switch to another published template without remounting (#1363): a
     *  value on a keyed field follows its key into it, and the rest is kept
     *  for the way back. Resolves once it is drawn, an upload or improvement
     *  under way having landed in the template it was made on first; a
     *  template the key may not read rejects with a `PixonautApiError`, and
     *  the template shown stays. A stored design keeps its template: a
     *  `TypeError`. */
    setTemplate(templateId: string): Promise<void>;
    /** Server-side commit of every enabled side, with no proof first: one
     *  design per side, for one order. Several sides are each checked before
     *  any is committed. Resolves as `approve()` does, with designs; rejects
     *  as it does. */
    commit(options?: CommitOptions): Promise<Committed>;
    destroy(): void;
    /** move a layer's center to a named anchor (author tooling) */
    positionLayer(layerId: string, anchor: string): void;
    /** author build-guide snapping (canvas center/edges/other layers) */
    get authorSnapEnabled(): boolean;
    set authorSnapEnabled(on: boolean);
    setAuthorSnap(on: boolean): void;
    /** Lock the designer, or unlock it: locked, it takes no input — no gesture
     *  on the preview, one under way included, and no field on the form — so
     *  nothing changes the design while the host makes a proof of it. */
    setLocked(locked: boolean): void;
}

/** A template's constraints at a placement, as
 *  `client.templates.constraints.get(templateId, { blankId, placementId })`
 *  resolves them. */
export declare type PlacementAnswer = AnswerOf<"/v1/templates/{id}/constraints">;

/** The box a named pair fits the design to: the template's ratio fitted into
 *  the placement's default box, with the placement's bleed and safe area —
 *  what a proof naming the pair and no size is printed at. The live preview
 *  draws and judges against it, so it frames the design as the proof does. */
declare interface PlacementBox extends PrintMargins {
    widthMm: number;
    heightMm: number;
    /** The placement's own box, when `widthMm` and `heightMm` are a stored
     *  design's size instead (`frozenOver`): what a blank's photo marks. */
    usualMm?: {
        widthMm: number;
        heightMm: number;
    };
    /** The output profile a design on the pair draws CMYK through: read with
     *  the box, so a switch of pair switches both at once. A workspace's
     *  uploaded one carries the tables read for it. */
    cmykPreviewProfile: ScreenProfile;
    /** Where the uploaded profile's tables are read, while they are not yet
     *  (`withPreviewTables`). */
    cmykPreviewTables?: string;
    /** Where on the product a print may go, when not the whole rectangle: the
     *  preview is cut to it and draws it as a guide. */
    shape?: PrintAreaShape;
    /** The blank's sizes the template can't be printed at on the pair, by
     *  option value (`"XL"`): a size whose panel is another shape. Absent
     *  for none, and from an api that names none. */
    misfitSizes?: readonly string[];
}

/** What `approve()` rejects with when `setPlacement()` switched the pair
 *  while its proof was being made: the proof is fitted to the pair before
 *  the switch, so it is not the print the customer would now get. Also what
 *  a `setPlacement()` rejects with when a later one superseded it. */
export declare class PlacementChangedError extends Error {
    constructor(message?: string);
}

/** What a preload would load, and what it chose to. */
export declare interface PreloadChoice {
    /** The mode chosen; never `"auto"`. */
    mode: Exclude<PreloadMode, "auto">;
    /** How many loads it keeps in flight at once. */
    concurrency: number;
    /** The most estimated bytes it holds; `Infinity` for no cap. */
    budgetBytes: number;
    /** Why: each signal or fallback that decided it, as
     *  `"saveData on: off"` or `"deviceMemory unavailable: assumed low"`. */
    reasons: string[];
}

/** One thing a preload loads, as `preloadPlan()` lists it. */
export declare type PreloadItem = {
    kind: "photo";
    /** The first view, in preload order, the photo is shown in. */
    viewId: string;
    /** The variant it is the photo of; undefined for the default photo. */
    variant: string | undefined;
    url: string;
    estimatedBytes: number;
} | {
    kind: "side";
    placementId: string;
    estimatedBytes: number;
};

/** How a designer loads ahead what a switch would otherwise wait for:
 *  `"off"` nothing, `"background"` one at a time in idle time once the first
 *  view is shown, `"eager"` everything at once once it is shown, `"auto"`
 *  one of those chosen from an estimate and the device's signals. */
export declare type PreloadMode = "off" | "background" | "eager" | "auto";

/** What `designer.preload()` takes besides its target. */
export declare interface PreloadOptions {
    /** Omitted, `"background"`. */
    mode?: PreloadMode;
    /** How many loads are in flight at once. */
    concurrency?: number;
    /** The most estimated bytes preloaded photos and canvases hold; past it
     *  the least recently shown is let go. */
    budgetBytes?: number;
}

/** What `preloadPlan()` answers: what auto would do, and why. */
export declare interface PreloadPlan extends PreloadChoice {
    /** What preloading everything would hold decoded, in bytes. */
    estimatedBytes: number;
    /** Everything the product allows, nearest to what is shown first. */
    items: PreloadItem[];
    signals: PreloadSignals;
}

/** What a `preload` event reports: after each item settles, and once more,
 *  `done`, when the run ends or is cancelled. */
export declare interface PreloadProgress {
    /** Items loaded, of `total`. */
    loaded: number;
    /** Items the run set out to load. */
    total: number;
    /** The estimated bytes preloading holds now. */
    estimatedBytes: number;
    /** Items that failed to load; a switch to one loads it as it would have. */
    failed: number;
    /** Whether this is the run's last report. */
    done: boolean;
}

/** The device's signals auto reads; null where the browser has none. */
export declare interface PreloadSignals {
    /** `navigator.deviceMemory`, in GiB (Chromium only). */
    deviceMemory: number | null;
    /** `navigator.hardwareConcurrency`. */
    hardwareConcurrency: number | null;
    /** `navigator.connection.effectiveType` (Chromium only). */
    effectiveType: string | null;
    /** `navigator.connection.saveData` (Chromium only). */
    saveData: boolean | null;
}

/** What `designer.preload()` loads: `"all"`, or the variants (refs), views
 *  (ids) and sides (placement ids) named, an omitted axis meaning every
 *  one the product allows. */
export declare type PreloadTarget = "all" | {
    variants?: readonly string[];
    views?: readonly string[];
    sides?: readonly string[];
};

/** A placement's shape, as a blank stores it and a print box carries it. */
declare interface PrintAreaShape {
    /** The outline, then each cut-out, as subpaths of one path. */
    dFrac: string;
    /** The print shop cuts the outline and the cut-outs, so they go into the
     *  print file as its cut line. Absent or false: the product already has
     *  the shape, and the print only leaves it bare. */
    cutByPrintShop?: boolean;
}

/** The resolved print geometry an artwork is drawn into: the box and its
 *  margins. Mirrors the Rust `PrintBox` (`crates/pixonaut-render/src/
 *  validate.rs`), which the resolved doc carries and the template does not —
 *  ADR 018 moved the size to the order and the margins to the blank's
 *  placement. */
declare interface PrintBoxSpec {
    widthMm: number;
    heightMm: number;
    bleedMm?: number;
    safeAreaMm?: number;
    printMethod?: string;
    anchor?: unknown;
    [key: string]: unknown;
}

/** How far a print runs past its trim, and how far inside it important
 *  artwork stays, in millimetres. */
declare interface PrintMargins {
    bleedMm: number;
    safeAreaMm: number;
}

declare interface PrintResolution {
    /** Pixels per inch along the constraining axis. */
    effectiveDpi: number;
    /** The picture's pixels along the constraining axis. */
    actualPx: number;
    /** The pixels that axis needs at the floor it was judged against. */
    minPx: number;
    low: boolean;
}

/** One entry, with the rule's verdict attached. */
export declare interface PrintSizeChoice extends PrintSizeOption {
    /** False when a check that RAN refused this size. Read it together with the
     *  two `checked*` flags below: `true` means "nothing that ran said no", not
     *  "checked completely". */
    legal: boolean;
    /** The rule's own code when illegal, verbatim — the same vocabulary
     *  `POST /v1/templates/:id/print-size` answers with, so a host can branch on
     *  one set of strings whichever side told it (ADR 019). */
    reason?: "wrong_ratio" | "too_large";
    /** The shape check ran. Always true: it needs only the template. */
    checkedRatio: boolean;
    /** The ceiling check ran. Always FALSE here, and deliberately visible rather
     *  than implied: bounds live on a blank's placement, and the browser does
     *  not resolve the blank's bounds, even for a mount that names one. A host drawing its own radio buttons must be able to tell "this
     *  size is legal" from "this size has no known ratio problem and I could not
     *  check its ceiling" — different claims, and only one of them is a green
     *  tick. A host that has named a placement and wants a complete answer
     *  before letting a customer invest work calls `POST /print-size`, which
     *  exists for that and is already reachable with a publishable key. */
    checkedBounds: boolean;
    /** Whether this is the currently chosen entry. */
    selected: boolean;
}

/** One entry in the consumer's own size menu.
 *
 *  The consumer owns ALL of this: which sizes exist, what they are called, and
 *  what order they appear in. Their storefront marks 50×70 POPULAR and puts
 *  it in the middle; an SDK that sorted by area or relabelled these would have
 *  made a merchandising decision, which is the catalogue's job and never
 *  Pixonaut's (ADR 011/019). */
export declare interface PrintSizeOption {
    widthMm: number;
    heightMm: number;
    /** rendered verbatim — the SDK never composes or translates this */
    label: string;
    /** The label per customer-facing language, by its code (`fr`, #2226): the designer
     *  shows the one in its language, else `label`. */
    localizedLabels?: Partial<Record<string, string>>;
}

/** One option a product is chosen by, as `getOptions()` lists it. */
export declare interface ProductOption {
    /** The option's name, as the blank's axes and a variant's `options` key
     *  it. */
    name: string;
    /** Its values an offered variant carries, in the blank's order. */
    values: ProductOptionValue[];
}

/** One value of an option, as `getOptions()` lists it. */
export declare interface ProductOptionValue {
    /** The value, as a variant's `options` names it. */
    value: string;
    /** How it looks; null when the blank gives it no appearance. */
    appearance: OptionValueAppearance | null;
}

/** One placement a product sells, and the template it prints there. */
export declare interface ProductPlacement {
    /** One of the blank's placements. */
    placementId: string;
    /** The template printed on this placement, `baseId@version`; the
     *  mount's own when absent. */
    templateId?: string;
    /** Whether `approve()` and `commit()` take this side when the designer
     *  opens (`setSideEnabled()` changes it): false for a side the shopper
     *  has not chosen, such as the back of a print sold single-sided.
     *  Omitted, true. */
    enabled?: boolean;
}

/** One variant the product offers, as `getVariants()` lists it. */
export declare interface ProductVariant {
    /** The variant's ref, which `setVariant()` takes. */
    ref: string;
    /** Its value on the axis named as a colour; null on a blank without one.
     *  @deprecated Read `options`, by the option `getOptions()` names: an
     *  option's kind is not in its name. */
    colour: string | null;
    /** Its value on the axis named as a garment size; null on a blank
     *  without one.
     *  @deprecated Read `options`, by the option `getOptions()` names: an
     *  option's kind is not in its name. */
    size: string | null;
    /** Its value on each of the blank's axes, by axis name. */
    options: Readonly<Record<string, string>>;
    /** Whether a view the product allows has a photo of it: `setVariant()`
     *  refuses one without, unless the product has a photo of none. */
    hasPhoto: boolean;
    /** Whether the design can be printed at its size: false for a size
     *  whose panel is another shape than the template, which `setVariant()`
     *  still shows and the default picker offers disabled (#2785). */
    printable: boolean;
    /** Its photo in the first view the product allows that has one; null
     *  without. A swatch is drawn from its value's appearance instead. */
    photoUrl: string | null;
}

/** One view the product allows, as `getViews()` lists it. */
export declare interface ProductView {
    /** The view's id, which `setView()` and `renderView()` take. */
    viewId: string;
    /** The view's label, as the merchant named it (or the storefront's own
     *  label for one of its photos). */
    label: string;
    /** The placement it photographs; null for a view of several placements,
     *  and for a storefront photo on a mount naming no placement. */
    placement: string | null;
    /** The placements it shows a print of: its own, or each region's for a
     *  view of several placements, such as a sweater's front and sleeves;
     *  empty for the Design view and a storefront photo naming none. */
    placements: string[];
    /** Whether it has a photo of the variant shown: a view without one is
     *  hidden, and `setView()` refuses it. The Design view needs none, and is
     *  always true. */
    hasPhoto: boolean;
    /** The url of its photo of the variant shown; null without one, and for
     *  the Design view. A variant whose photo of a view is the same url, in
     *  the same `dye`, changes nothing that view shows. */
    photoUrl: string | null;
    /** The colour, `#rrggbb`, a view drawn from a 3D render dyes its garment
     *  in for the variant shown (CONTEXT.md → Garment dye); null for a photo
     *  shown as photographed, and for the Design view. A variant changing it
     *  changes what the view shows though its photo is the same url. */
    dye: string | null;
    /** `"photo"` for a photographed view; `"design"` for the Design view, the
     *  artwork alone on a transparency checker, whose `renderView()` picture
     *  is the artwork on a transparent ground. */
    kind: "photo" | "design";
    /** Whether it is a stock scene, whose print is an estimate: never true to exact
     *  placement or size. `getEstimateNote()` is the words for it. False for a
     *  printshop's or the merchant's own photo, and for the Design view. A host with
     *  `showViews: false` shows those words itself. */
    estimated: boolean;
}

declare interface ProfileTables {
    estimate: CmykEstimateTableFile;
    preview: CmykPreviewTableFile;
}

/** The query `P`'s `GET` takes. */
export declare type QueryOf<P extends ReadPath> = ReadOf<P> extends {
    parameters: {
        query?: infer Q;
    };
} ? NonNullable<Q> : never;

/** The subset of a declared ratio this derivation reads. Structural rather
 *  than `AspectRatioValue` so this leaf stays free of the zod module that
 *  declares it; the two shapes are identical by construction. */
declare interface RatioPair {
    w: number;
    h: number;
}

declare type ReadOf<P extends ReadPath> = paths[P] extends {
    get: infer O;
} ? O : never;

/** A path the reference serves a `GET` at. */
export declare type ReadPath = {
    [P in keyof paths]: paths[P] extends {
        get: object;
    } ? P : never;
}[keyof paths];

declare interface Reads {
    /** `GET` `path` with `params` and `query`, and read its answer as JSON. */
    json<P extends ReadPath>(path: P, params: PathParams, query?: QueryOf<P>): Promise<AnswerOf<P>>;
    /** `GET` `path` with `params` and `query`, and hand its answer over as it
     *  came: a picture or a PDF, for the caller to stream or save. */
    raw<P extends ReadPath>(path: P, params: PathParams, query?: QueryOf<P>): Promise<Response>;
}

/** Where the print sits IN one photo, in that photo's own pixels. */
export declare interface RectPx {
    x: number;
    y: number;
    w: number;
    h: number;
}

/** A side's print as it was drawn: its raster and the print box it is of. */
declare interface RegionPrint {
    png: Uint8Array;
    /** The raster's own pixels. */
    sizePx: {
        w: number;
        h: number;
    };
    printMm: {
        widthMm: number;
        heightMm: number;
    };
}

/** A constraint failure as it arrives from the API, which strips `allowed`
 *  before responding (issue #61, ADR 016 Amendment 3).
 *
 *  Anything fed by BOTH paths — `onValidation`, notably — is typed as this, so
 *  an embedder reading `allowed` off it gets a compile error rather than
 *  `undefined` at runtime from the network path.
 *
 *  Deliberately a plain `Omit` and NOT a branded type, unlike the API's
 *  `PublicViolation`: a WASM `Violation` must remain assignable here, since the
 *  local path really does hand one to the same callback. That means this type
 *  documents intent rather than enforcing absence — it stops a consumer relying
 *  on the field, not a producer including it. The enforcement that matters
 *  happens server-side, where the field is actually removed. */
export declare type RemoteViolation = Omit<Violation, "allowed">;

/** What `renderTemplate()` takes. */
export declare interface RenderTemplateOptions {
    /** The picture's longest edge, in pixels (default 240). */
    maxEdgePx?: number;
    /** The values drawn, as `getState().values` holds them: keyed values are
     *  carried into the template by their key, as `setTemplate()` carries
     *  them. Absent, the template is drawn with its own sample. */
    values?: PayloadValues;
    /** A view of the product to draw the template in, on its photo; absent,
     *  the artwork alone. */
    viewId?: string;
    /** With `viewId`: the variant whose photo is drawn, the one shown when
     *  absent. */
    variant?: string;
}

/** What `renderView()` takes. */
export declare interface RenderViewOptions {
    /** The variant whose photo is drawn; the variant shown when absent. */
    variant?: string;
    /** The picture's longest edge, in pixels (default 240). */
    maxEdgePx?: number;
}

/** How the tiles are laid: in a plain grid, every other row moved along by
 *  half a tile (`brick`), every other column moved down by half a tile
 *  (`halfDrop`), or strewn at random places, turns and sizes (`scatter`),
 *  the arrangement chosen by the seed. Mirrors Rust's `RepeatMode`. */
declare const REPEAT_MODES: readonly ["grid", "brick", "halfDrop", "scatter"];

/** What `setRepeat()` changes: any of the layout, size and arrangement. */
export declare type RepeatChoice = Partial<Pick<RepeatState, "mode" | "size" | "seed">>;

export declare type RepeatMode = (typeof REPEAT_MODES)[number];

/** What `enableRepeat()` offers. */
export declare interface RepeatOptions {
    /** The layouts offered, at least one. Omitted, every one: `grid`,
     *  `brick`, `halfDrop` and `scatter`. */
    modes?: readonly RepeatMode[];
    /** The layout the design starts in, one of `modes`; `null` starts it
     *  not repeated, which needs `shopperMayTurnOff`. Omitted, the first of
     *  `modes`. */
    mode?: RepeatMode | null;
    /** The tile's width in percent of the print area's width, above 0 and at
     *  most 100; the tile keeps the template's shape. Omitted, 25. */
    size?: number;
    /** Which arrangement a `scatter` draws, a whole number from 0. Omitted,
     *  0. */
    seed?: number;
    /** Whether the shopper may change the layout, size and arrangement.
     *  Omitted, true. */
    shopperMayChange?: boolean;
    /** Whether the shopper may turn the repeat off and place the design once.
     *  Omitted, false. */
    shopperMayTurnOff?: boolean;
    /** The placements the repeat is laid on, each once: a linked group's, so
     *  a sweater's back and sleeves tile while its front is placed once. A
     *  placement linked with one named is named too. Omitted, every side. */
    placements?: readonly string[];
}

/** The repeat as `getRepeat()` answers it: the choice, and what is offered. */
export declare interface RepeatState {
    /** The layout chosen; `null` while the design is not repeated. */
    mode: RepeatMode | null;
    /** The tile's width in percent of the print area's width. */
    size: number;
    /** Which arrangement a `scatter` draws. */
    seed: number;
    /** The layouts offered. */
    modes: RepeatMode[];
    shopperMayChange: boolean;
    shopperMayTurnOff: boolean;
    /** The placements it is laid on; absent, every side. */
    placements?: string[];
    /** The smallest size a tile is drawn at on the side shown, at the
     *  product's size: any smaller is drawn at the smallest a tile prints,
     *  the same as this. A slider starts here. */
    minSize: number;
}

/** merge order: the locale's bundle (English unless given) <- overrides */
export declare function resolveMessages(overrides?: Partial<SdkMessages>, bundle?: SdkMessages): SdkMessages;

declare type ScreenProfile = {
    kind: "bundled";
    name: BundledOutputProfile;
} | {
    kind: "uploaded";
    id: string;
    tables: ProfileTables;
};

export declare interface SdkMessages extends OptionMessages, ViewMessages, PixelMessages, AddedLayerMessages, DeletedLayerMessages {
    /** An integrator's word for ONE offered shape on this embed — a die or a
     *  shape layer's outline (ADR 187); the family keeps its `die` name so
     *  existing keys still apply — keyed by its shape id, as
     *  `form.die.<shape id>` (#402). It outranks the tenant's row name. We
     *  ship none: the words for a shape are the tenant's, which is why
     *  this is an index signature rather than a list of keys, and why a key
     *  may be absent. */
    [dieOption: `form.die.${string}`]: string | undefined;
    /** What a field says of a violation the local check found at it, keyed by
     *  the violation's rule (`form/field-violations.ts`). A rule with no
     *  sentence is not shown at the field. */
    [violation: `form.violation.${string}`]: string | undefined;
    "upload.button": string;
    "upload.uploading": string;
    "upload.error": string;
    /** A file of a format the template does not take (ADR 189); interpolates
     *  {formats}, the ones it does, as a person names a choice ("PNG or JPEG"). */
    "upload.unsupported-format": string;
    /** A file over the template's size limit; interpolates {maxMb}, the limit
     *  in megabytes, rounded down so a file read as under it is under it. */
    "upload.too-large": string;
    /** A moving picture (animated WebP, HEIC sequence): it cannot print. */
    "upload.animated": string;
    /** Offered under an upload too small for the chosen size, where the store
     *  allows it (#1092): makes a larger copy for the proof. Says what it
     *  does for the customer, never how. */
    "upload.improve": string;
    "upload.improving": string;
    "upload.improved": string;
    "upload.improve-error": string;
    "upload.cutting-out": string;
    "upload.cut-out": string;
    "upload.cut-out-uncut": string;
    "upload.cut-out-retry": string;
    "upload.cut-out-error": string;
    "upload.cut-out-kept": string;
    "upload.cut-out-unavailable": string;
    /** low-res print-quality warning; interpolates {actualPx}, {minPx}.
     *  `actualPx` is the image's CONSTRAINING edge — width or height, whichever
     *  falls further short of its own floor — and `minPx` is that same edge's
     *  floor. The wording must therefore stay axis-neutral: naming an axis would
     *  misreport a wide short image, whose reported number is its height. */
    "warning.asset.low-resolution": string;
    /** shown when the stored file is larger than the one the customer chose,
     *  because it was re-encoded into a format the print path can embed (ADR
     *  023). Interpolates {uploadBytes} and {storedBytes}. The wording must not
     *  name the format: they chose a picture, not a codec. Unlike the studio's
     *  version this says nothing about storage cost — an end customer of a
     *  tenant has no quota of their own to spend. */
    "warning.asset.stored-larger": string;
    /** shown when an upload for a tiled picture is smaller than the renderer
     *  tiles (ADR 200): its proof would fail. Interpolates {actualPx}, the
     *  picture's short side, and {minPx}, what it needs. */
    "warning.asset.tile-too-small": string;
    /** The callout ending a step with one layer the customer places on the
     *  preview (#726), unless its author named the move: then the author's
     *  words, verbatim. */
    "form.drag-hint-one": string;
    /** The same callout for a step with several such layers. */
    "form.drag-hint": string;
    /** The callout's button: the step's layers back where the author put them. */
    "form.drag-reset": string;
    "form.centre-on": string;
    /** The section placing a picture on a panel layout (#2378). */
    "form.place-picture": string;
    /** Its pad of nudges, each a centimetre on the print, and each button. */
    "form.nudge": string;
    "form.nudge-up": string;
    "form.nudge-down": string;
    "form.nudge-left": string;
    "form.nudge-right": string;
    "form.place-hint": string;
    /** A layer's size slider, when the author gave its scale no label. */
    "form.scale": string;
    /** A layer's flip buttons, when the author gave its flip no label. */
    "form.flip": string;
    "form.flip-horizontal": string;
    "form.flip-vertical": string;
    "form.image-none": string;
    "form.image-current": string;
    /** An offered shape's empty choice: no shape, the plain rectangle. */
    "form.shape-none": string;
    /** Label for one picture in an image picker when the tenant has not named
     *  it; interpolates {index}, 1-based.
     *
     *  Positional because an asset id is meaningless to a customer. Since #254
     *  a named asset reads as the tenant's own word instead, so this is the
     *  FALLBACK rather than the answer — a picker reading "Rose, Picture 2" is
     *  honest about which pictures have a word. */
    "form.image-option": string;
    /** Label for one offered die the tenant has not named — a shape row,
     *  offered by id (#237, #402). Interpolates {index}, 1-based. The ONLY
     *  word we carry for a die: the last rung, for a shape row whose name did
     *  not cross the wire; `form.die.<shape id>` in `mount({ messages })`
     *  still outranks the row's name. Positional as `form.image-option` is (a
     *  raw `shp_…` is internal plumbing), but its OWN key: "Picture 1" is
     *  wrong for a die, and a host must be able to translate the two apart. */
    "form.die-option": string;
    /** Under a tile size at which the uploaded picture repeats smaller than
     *  the renderer prints a tile (ADR 200): the remedy is a larger size. */
    "form.tile-too-small": string;
    /** A text's weight, by the face it draws (#769). */
    "form.weight.regular": string;
    "form.weight.bold": string;
    "form.weight.one-face": string;
    /** A face of a family the template names in its fonts, other than its
     *  regular (400) or bold (700) one, by its CSS weight (ADR 219 §6). */
    "form.weight.css": string;
    /** The heading above the artwork's own values. The derivation supplies
     *  `ARTWORK_STEP_LABEL` as the default so a non-SDK consumer still gets a
     *  label; this is what lets an integrator say it in their own words, the way
     *  they can for every other string here. */
    "form.artwork-step": string;
    /** A percent field's reading, such as an offered opacity (#427);
     *  interpolates {value}, the whole or fractional percent, never the stored
     *  fraction. */
    "form.percent": string;
    /** An image's crop field (ADR 181): the zoom slider's accessible name. */
    "form.crop-zoom": string;
    /** The zoom's reading; interpolates {value}, the factor over the picture
     *  as the frame first covers it. */
    "form.crop-zoom-value": string;
    /** The control that starts dragging the picture inside its frame on the
     *  preview, and what it says while the drag is on. */
    "form.crop-move": string;
    "form.crop-done": string;
    /** The picture's frame on the preview while it is being moved: its
     *  accessible name, which also says how to move it from the keyboard. */
    "form.crop-surface": string;
    /** The control that puts the picture back where the author placed it. */
    "form.crop-reset": string;
    /** In place of the background's crop while the design's repeat is a
     *  scatter, whose copies lie at random places, turns and sizes. */
    "form.crop-scattered": string;
    /** The label of a text layer's offered size when the template leaves it
     *  unlabelled; an authored label is shown instead. */
    "form.font-size": string;
    /** The heading over an offered field its author left unlabelled, one per
     *  attribute it sets (`form/field-label.ts`): the form's word in place of
     *  the attribute's internal name. Each key is `form.label.` and the field's
     *  `prop` as `getFormSchema()` carries it, except a text size's, which is
     *  `form.font-size` above. An authored label is shown instead. */
    "form.label.content": string;
    "form.label.fill": string;
    "form.label.color": string;
    "form.label.imageAssetId": string;
    "form.label.shape": string;
    "form.label.src": string;
    "form.label.crop": string;
    "form.label.fit": string;
    "form.label.tileScale": string;
    "form.label.fontFamily": string;
    "form.label.fontWeight": string;
    "form.label.align": string;
    "form.label.valign": string;
    "form.label.opacity": string;
    "form.label.outlineColor": string;
    "form.label.outlineWidthEm": string;
    "form.label.shadowColor": string;
    "form.label.shadowOffsetXEm": string;
    "form.label.shadowOffsetYEm": string;
    "form.label.shadowBlurEm": string;
    "form.label.cornerRadius": string;
    "form.label.letterSpacingEm": string;
    "form.label.lineHeight": string;
    "form.label.textCase": string;
    "form.label.tone": string;
    "form.label.brightness": string;
    "form.label.contrast": string;
    "form.label.saturation": string;
    /** The headings a text's outline fields and its shadow fields are
     *  gathered under. */
    "form.group.outline": string;
    "form.group.shadow": string;
    /** At the end of a text field's heading when it has a maximum length;
     *  interpolates {count}, the characters typed, and {max}. */
    "form.char-count": string;
    /** Above the step a form laid out as steps shows (ADR 196); interpolates
     *  {index}, counted from 1, and {count}, the steps the form shows. */
    "form.step-counter": string;
    /** The step counter where the form is too narrow for the long one, and
     *  the bars drop their step names (#726); the same variables. */
    "form.step-counter-short": string;
    /** Under a text field with a maximum length whose author gave it no help
     *  text (#726); interpolates {max}. */
    "form.char-limit": string;
    /** The button back to the previous step. */
    "form.step-back": string;
    /** The heading of the first step a host adds with `optionsStep`, holding
     *  the product's pickers and the host's own fields, when it names none. */
    "form.step-options": string;
    /** The button on to the next step; interpolates {label}, that step's
     *  heading. The last step has none: finishing is the host's button. */
    "form.step-next": string;
    /** The button after a palette's swatches that opens a free colour picker,
     *  when the author offers any colour beside the palette (ADR 180). */
    "form.palette-custom": string;
    /** What a palette field's heading shows while the colour is the
     *  customer's own rather than one of the swatches. */
    "form.palette-custom-chosen": string;
    /** Above each colour's control in a gradient's field, which the customer
     *  picks one by one; interpolates {n}, the colour's place along it, from
     *  1. */
    "form.gradient-colour": string;
    /** Under a colour field while the customer's pick cannot be printed
     *  exactly, beside the pick and what it prints as; interpolates {hex}, the
     *  pick's six hex digits without the `#`, and {cmyk}, what it prints as,
     *  `C0 M71 Y59 K30`. The studio picker's sentence. */
    "form.color-cannot-print": string;
    /** heading above the print-size picker: the print's size, which on a
     *  garment must read apart from the garment's own. The SIZES themselves are the
     *  consumer's and are rendered verbatim (ADR 051) — only this heading and
     *  the two refusal suffixes below are the SDK's words. */
    "size.legend": string;
    /** appended to an option the rule refuses for its shape */
    "size.illegal.wrong-ratio": string;
    /** appended to an option that exceeds the print area. Unreachable from the
     *  widget's own local check, which has no blank and so no ceiling to test;
     *  it exists because a headless host may attach a server verdict. */
    "size.illegal.too-large": string;
}

/** Written out, not inferred, so the SDK's published types carry no zod. */
declare type ShapeGeometryValue = {
    type: "path";
    dFrac: string;
};

/** What an id resolves against: the live rows (a draft) or the frozen
 *  snapshot (a published version), as one argument type. */
declare type ShapeSource = Readonly<Record<string, ShapeGeometryValue>>;

/** One side's committed design: its placement, null for a mount naming
 *  none. */
export declare type SideCommit = CommitResult & {
    placementId: string | null;
};

/** What `setSideLink()` changes of a placement. */
export declare interface SideLinkChange {
    /** False designs the placement on its own, keeping the group's design
     *  as it stands; true puts it back in its group. */
    linked?: boolean;
    /** Whether its print is mirrored left to right while it is linked. */
    mirrored?: boolean;
}

/** What `on("link")` reports: a placement's link as it now stands. */
export declare interface SideLinkEvent {
    placementId: string;
    linked: boolean;
    mirrored: boolean;
}

/** The sides a mount sells and how they are designed. */
export declare interface SideMountOptions {
    /** The placements of `blankId` the product sells, each a side of the
     *  item with the template printed there (the mount's `templateId` when it
     *  names none): `placementId` is the one the editor opens on, and must be
     *  one of them. Each side holds its own template and values; `setSide()`,
     *  or showing a view of another (`setView()`), switches the editor to it,
     *  and `approve()` and `commit()` take every enabled side. Omitted, the
     *  product sells `placementId` alone. */
    placements?: readonly ProductPlacement[];
    /** Which of `placements` one design drives (#2148): a preset — `each`
     *  designs every placement on its own, `suggested` links the groups the
     *  blank suggests (its sleeves, each right one mirrored), `all` links
     *  every placement — or `groups` of its own, each placement with whether
     *  its print is mirrored (the blank's suggestion where it says nothing);
     *  `{}` is `suggested`. A group is one side: `getSides()` lists it once,
     *  showing any of its placements edits them all, and `approve()` and
     *  `commit()` make one design per placement, each at its own print area
     *  and naming its group. `designSeparately` lets `setSideLink()` take a
     *  placement out of its group. Its placements print one template.
     *  Omitted, every placement is designed on its own. */
    linkedPlacements?: LinkedPlacementsOption;
    /** Whether a side keeps its own values on keyed fields: `"shared"`
     *  shows a value typed on one side's keyed field in every side's field of
     *  the same key, as one item carries one name; `"own"` keeps each side's
     *  apart. A value on a field without a key stays on its side either way.
     *  Omitted, `"shared"`. */
    sideValues?: "shared" | "own";
    /** A repeat offered from the start (#2482), as `enableRepeat()` offers
     *  it: the first draw lays the design with it, so a host that offers one
     *  never shows the design placed once first. Omitted, none until
     *  `enableRepeat()`. */
    repeat?: RepeatOptions;
}

/** A side's print as it is drawn into a region: unshaded, at its print box. */
export declare type SidePrint = RegionPrint;

/** How `renderSidePrint()` draws a side's print. */
export declare interface SidePrintOptions {
    /** Its longest edge, in pixels; 1024 when absent. */
    maxEdgePx?: number;
    /** The placement whose print is drawn; the side shown's when absent. */
    placementId?: string;
    /** For a placement this designer holds no side of, the template its
     *  print is drawn from, as that placement's own designer would open it:
     *  with its sample, and `values` carried in by field key. */
    templateId?: string;
    /** With `templateId`: a state's values (`getState().values`), whose
     *  keyed values the print carries, as `renderTemplate()` carries them. */
    values?: PayloadValues;
    /** With `templateId`: the linked group the placement is designed as part
     *  of, as its own designer links it (`getSides()`'s `linked`), it among
     *  them. A repeat is then sized against the group's widest area, and the
     *  print mirrored where its entry says so. */
    linked?: readonly LinkedSide[];
    /** With `templateId`: the repeat the print is laid with, as the
     *  placement's own designer lays it, or `null` for placed once. Absent,
     *  this designer's repeat, where it is laid on the placement. */
    repeat?: DesignRepeat | null;
}

/** One side's proof: its placement, null for a mount naming none. */
export declare type SideProof = ApproveResult & {
    placementId: string | null;
};

/** The template fields the ratio check reads. Structural, so a mounted
 *  `TemplateDoc`, a parsed template and a test fixture all satisfy it. */
declare interface SizeConstrainedTemplate {
    ratio?: RatioPair;
    ratioTolerance?: number;
}

/** Every target a template or an offered move may name, in the order the
 *  builder lists them:
 *
 *  - `centre` — the plate's vertical and horizontal centre lines;
 *  - `thirds` — the lines at one and two thirds of the plate, each way;
 *  - `safeEdges` — the safe area's four edges, or the plate's when there is
 *    no safe area;
 *  - `layers` — every other layer's sides and centre lines. */
declare const SNAP_TARGETS: readonly ["centre", "thirds", "safeEdges", "layers"];

/** What the artwork's drags snap to: named targets and the author's grid
 *  (ADR 195). */
declare type SnapConfig = SnapSettings;

/** The author's plate grid: a line every `stepFrac` of the plate, each way,
 *  stored as a fraction (`0.05` is a line every 5 %). A customer never snaps
 *  to it. */
declare interface SnapGrid {
    stepFrac: number;
}

/** A template's `snap`: the targets every offered move snaps to, unless the
 *  move names its own, and the author's grid. Absent targets mean a customer's
 *  move does not snap. */
declare interface SnapSettings {
    targets?: SnapTarget[];
    grid?: SnapGrid;
}

declare type SnapTarget = (typeof SNAP_TARGETS)[number];

/** A mount whose preview and form go into two elements the host places.
 *  The preview takes its element's width and follows it when the host
 *  resizes it; the form takes its element's. Fill mode is for one element
 *  given a page of its own, so it is not offered here. */
export declare type SplitMountOptions = MountOptions & {
    /** The element the preview is drawn into. */
    previewTarget: MountTarget;
    /** The element the form, and the size picker, are drawn into. */
    formTarget: MountTarget;
    /** The element the switcher between the product's views is drawn into;
     *  absent, it is drawn above the form. */
    viewsTarget?: MountTarget;
    fill?: never;
};

/** Every rule the stitch check refuses by, as the render core names it. */
declare const STITCH_RULES: readonly ["stitch_colour_off_list", "stitch_too_many_threads", "stitch_gradient", "stitch_picture", "stitch_opacity", "stitch_blend", "stitch_text_too_small", "stitch_line_too_thin"];

/** One part of a design a machine cannot stitch, as the rule finds it and a
 *  `design_not_stitchable` refusal lists it: the layer and prop, the rule,
 *  what the design gives and what the machine allows, and for an off-list
 *  colour the nearest thread. */
export declare interface StitchFinding {
    layerId: string;
    prop: string;
    rule: StitchRule;
    given: unknown;
    allowed: unknown;
    thread?: StitchThreadRef;
}

export declare type StitchRule = (typeof STITCH_RULES)[number];

/** The thread a finding points at: the nearest to an off-list colour. */
export declare interface StitchThreadRef {
    id: string;
    name: string;
    hex: string;
}

/** A stored design opened to correct it (#583). Every value is applied over
 *  its resolved document with the core's revise, as the api applies a
 *  correction, so the designer's values are exactly the correction to save.
 *  @internal */
declare interface StoredDesign {
    /** The design's current resolved document, and the box it was resolved
     *  into, whose size and margins are frozen with it. */
    doc: StoredDoc & {
        printBox?: Partial<PrintMargins & {
            widthMm: number;
            heightMm: number;
        }>;
    };
    /** Its template version, as the template route answers it: the document,
     *  its form, and the tenant's words for what it offers. */
    read: TemplateRead;
    /** Whether the member may upload a picture of their own. False hides the
     *  upload button; the template's presets are still offered. */
    uploads: boolean;
}

/** Mounted on a stored design: the studio's session, the design, and no
 *  size menu, since a correction keeps the size the design was bought at.
 *  @internal */
declare type StoredDesignMount = MountOptionsBase & StudioSessionCredential & {
    design: StoredDesign;
    printSizes?: never;
    selectedPrintSizeIndex?: never;
    blankId?: never;
    placementId?: never;
};

/** The parts of a stored resolved document this model reads itself; the
 *  rest is handed to the core as it came. */
declare interface StoredDoc {
    layers: Array<{
        id: string;
        xMm: number;
        yMm: number;
        sizeMm: {
            w: number;
            h: number;
        };
        transform: Pick<TransformValues, "rotationDeg" | "scale" | "flipX" | "flipY">;
        /** an image layer's picture and crop, as the core resolved them */
        kind?: Record<string, unknown>;
    }>;
    /** the resolved background: its picture and crop are read */
    background?: unknown;
}

/** A studio member's own session, for the studio's designer opened on a
 *  stored design to correct it (#583), or on a template to make a design
 *  from (#1515). Requests carry the session's cookie and the tenant it acts
 *  in, which the api checks against the member's memberships. Only with a
 *  `StoredDesign` or an in-memory `template`: the studio saves what the
 *  member made, so `approve()` and `commit()` are refused. @internal */
declare interface StudioSessionCredential {
    studioSession: {
        tenantId: string;
    };
    publishableKey?: never;
    shareLink?: never;
    correctionLink?: never;
    transport?: never;
}

/** Mounted by the studio on a template, in memory, under the member's
 *  session; the studio saves what the designer holds. @internal */
declare type StudioTemplateMount = MountOptionsBase & StudioSessionCredential & MountPlacement & StudioTemplateUploads & {
    template: TemplateDoc;
    design?: never;
};

/** A studio template mount's uploads. @internal */
declare interface StudioTemplateUploads {
    /** What a picture the member uploads becomes: a design upload, kept
     *  while a design names it (the default), or a picture in the tenant's
     *  library, kept until it is deleted, as the Draft preview's are, so a
     *  showcase that names one keeps it (#2595). `false` offers no upload,
     *  for a member who may not add one; the template's presets still are. */
    uploads?: StudioUploads;
}

/** What a studio member's upload on a template mount becomes, or `false`
 *  for none. @internal */
declare type StudioUploads = UploadKind | false;

/** A template, as `client.templates.get(templateId)` resolves it. */
export declare type TemplateAnswer = AnswerOf<"/v1/templates/{id}">;

/** What `approve()` rejects with when `setTemplate()` switched the template
 *  while its proof was being made: the proof is of the template before the
 *  switch. Also what a `setTemplate()` rejects with when a later one
 *  superseded it. */
export declare class TemplateChangedError extends Error {
    constructor(message?: string);
}

/** A resolved/authorable template document: the shape mounted or validated.
 *
 *  `surfaces[]` is retired (ADR 018) — a template is ONE artwork, so its
 *  layers and groups sit here directly. `printBox` is present on a RESOLVED
 *  doc (the validator fills it from the geometry the caller supplied) and
 *  absent on a raw authoring template, which stores no print size at all. */
declare interface TemplateDoc {
    templateId?: string;
    /** A resolved document's on a panel layout's canvas (`DocRepeat`). */
    canvasWindows?: CanvasWindow[];
    /** Each family the text may be drawn in, with its faces' hashes, as the
     *  api writes them out (ADR 219 §6). Absent on a document from before
     *  fonts were tenant data, which draws the compiled-in families. */
    fonts?: FontSource;
    name?: string;
    dpi?: number;
    printBox?: PrintBoxSpec;
    /** Each fixed text naming a field (#2070), as the template route serves
     *  it beside the template, riding on it as `printBox` does: a customer's
     *  field values are written into these texts before the core sees the
     *  template, which never reads this itself. Absent when none names one. */
    fieldTexts?: FieldTexts;
    /** The shape the template DECLARES (ADR 020) — the plate every surface
     *  falls back to when no `printBox` is supplied.
     *
     *  `RatioPair` comes from the same zod-free schema leaf as `nominalPlate`,
     *  which reads this field: the type and the function that consumes it stay
     *  one definition rather than a structural restatement free to drift. No
     *  bundle cost — the leaf has no imports of its own.
     *
     *  Optional because this interface describes JSON that arrives from a host,
     *  including docs written before templates declared a shape;
     *  `TemplateSchema.ratio` is required on the parsed template. */
    ratio?: RatioPair;
    /** How far a requested print size's shape may stray from `ratio`, as a
     *  fraction of it; `0` means that shape only.
     *
     *  Already served — `GET /v1/templates/:id` returns the whole author
     *  document — but untyped here until the SDK needed to run the ratio half of
     *  the print-size rule in the browser (ADR 051). Optional for the same
     *  reason `ratio` is: this interface describes JSON arriving from a host,
     *  including documents written before the field existed. */
    ratioTolerance?: number;
    /** Whether the customer's canvas keeps `ratio` at every print size
     *  (#2813); absent is not. A designer mount may override it. */
    drawAtTemplateRatio?: boolean;
    /** `color` was `unknown`, which is assignable to no narrower type — so once
     *  the form derivation started reading it as a prop (ADR 038), passing this
     *  document to `buildFormSchema` would have needed a cast, and a cast is
     *  what that structural type exists to avoid: it would swallow the next
     *  change to either model rather than failing at the call site.
     *
     *  Declared as what the derivation reads. On a RESOLVED doc the same key
     *  holds a plain colour, which this canvas passes through without reading —
     *  the die-cut and the background are drawn by the Rust core, not here.
     *
     *  `kind` arrived with #166, which made the TEMPLATE's background a tagged
     *  union. It is optional here, and both payload fields stay optional,
     *  because this one interface describes both documents this canvas is handed:
     *  an author document, which now carries the discriminant and exactly one
     *  payload, and a resolved document, which carries neither the discriminant
     *  nor the prop wrapper and never will — it freezes into the design's
     *  revisions (`design_revisions.doc_json`) and must keep parsing as it was
     *  approved. Narrowing this to the union would make the resolved case need
     *  the cast this type exists to avoid. */
    background?: {
        kind?: "color" | "image";
        imageAssetId?: unknown;
        /** The image arm's fit: a bare word, absent for cover (ADR 181 relies on
         *  it — a crop applies after cover only). */
        fit?: unknown;
        /** The image arm's crop: a prop on a template, a bare crop on a resolved
         *  document — `unknown` for the reason `imageAssetId` is, and read
         *  through `isRecord` where it is written. */
        crop?: unknown;
        color?: {
            editable?: boolean;
            label?: string;
            constraints?: Record<string, unknown>;
            value?: unknown;
        };
    };
    /** The die-cut silhouette — the shape the product is cut to, and the cutline
     *  the printshop cuts.
     *
     *  Structural rather than validated, like the rest of this interface: it
     *  describes JSON arriving from a host, including a document written before
     *  a field existed, and `@pixonaut/schema` is what refuses a bad one. Named
     *  here rather than left `unknown` because a field no consumer can read
     *  without a cast is one that fails silently when it stops arriving —
     *  the same story `ratioTolerance` tells about itself above.
     *
     *  A geometry is a path and nothing else (ADR 122). Its `dFrac` is in
     *  fractions of the plate (ADR 028), which is what lets one authored document
     *  keep its silhouette at every size it sells at; the millimetres a printshop
     *  cuts to appear once, at resolve. */
    shape?: {
        value: {
            type: "path";
            dFrac: string;
        } | {
            type: "shape";
            shapeId: string;
        };
        editable: boolean;
        label?: string;
        /** The offered set, as wire names. `oneOf` stays a list of strings, which
         *  is what let this ship without widening a field the publish scan, the
         *  asset scan and the SDK's renderers all read as strings. */
        constraints?: {
            oneOf?: string[];
        };
    };
    cutShape?: unknown;
    snap?: SnapConfig;
    layers: LayerSpec[];
    groups?: GroupSpec[];
    /** The palettes the document offers, by name. Only the RESOLVED document
     *  carries them — what the SDK is served and the studio's canvas draws,
     *  once `resolveReferences` has written each offered palette out. An
     *  authored template holds none: it offers the tenant's palette rows by id
     *  (#525). */
    palettes?: Record<string, PaletteEntry[]>;
    [key: string]: unknown;
}

/** What the template route answers, as far as the mount reads it. */
declare interface TemplateRead {
    template: TemplateDoc;
    formSchema: FormSchema;
    /** The tenant's default bleed and safe area, printed with no placement. */
    printMargins: PrintMargins;
    /** The minimum print resolution the design's pictures are judged against:
     *  the template's own, else the tenant's, as the server reads it. */
    printFloorDpi: number;
    /** Whether the designer shows "Powered by Pixonaut" for the tenant, as
     *  the api decides it (#1825); absent, it shows. */
    poweredBy?: boolean;
    /** Whether the template offers the customer Pixel (#2744); absent, it
     *  does not. */
    pixel?: boolean;
    /** Whether what stands behind the template shows through its background;
     *  null when that is not known (#3046). */
    backgroundSeeThrough?: boolean | null;
    workspaceLanguage?: string;
}

/** Why a picture of a template could not be drawn. */
export declare class TemplateRenderError extends Error {
    constructor(message: string);
}

/** An editable/animatable axis flag on a layer transform, with its bounds. */
declare interface TransformAxis {
    editable?: boolean;
    bounds?: string;
    min?: number;
    max?: number;
    snapDeg?: number;
    /** a move's own snap targets, in place of the template's (ADR 195) */
    snapTo?: SnapTarget[];
    /** A scale axis's grain (see `scaleBounds`). */
    step?: number;
    minWMm?: number;
    maxWMm?: number;
    minHMm?: number;
    maxHMm?: number;
    [key: string]: unknown;
}

/** A customer's change to a layer's transform, in millimetres: what
 *  `setTransform` writes over the layer, axis by axis. */
export declare type TransformPatch = Partial<TransformValues & {
    wMm: number;
    hMm: number;
}>;

/** The concrete transform values a gesture reads/writes. */
export declare interface TransformValues {
    xMm: number;
    yMm: number;
    rotationDeg: number;
    scale: number;
    /** Mirrored left to right, about the centre and inside the turn. */
    flipX?: boolean;
    /** Mirrored top to bottom, as `flipX` is left to right. */
    flipY?: boolean;
}

/** How a request the designer makes reaches Pixonaut through the host's own
 *  server: `path` is the API path (`/v1/proofs`, an upload's `/u/…`, a font's
 *  `/f/…`), and the host's server sends it on with its own credential and
 *  answers what Pixonaut answered. */
export declare type Transport = (path: string, init: RequestInit) => Promise<Response>;

/** No credential in the page: every request goes through `transport`, and
 *  the host's server adds the credential. */
export declare interface TransportCredential {
    transport: Transport;
    publishableKey?: never;
    shareLink?: never;
    studioSession?: never;
    correctionLink?: never;
}

/** What the mount was handed and this release's renderer cannot read. A
 *  store on this release updates the SDK to one that draws it. The core's
 *  own failure is the `cause`. */
export declare class UnreadableDocumentError extends Error {
    /** As a host branches on it, alike for every bundle's copy. */
    readonly code = "document_unreadable";
    constructor(options?: ErrorOptions);
}

/** The asset kind an upload is filed as (`POST /v1/assets`'s `kind`).
 *  @internal */
declare type UploadKind = "design-upload" | "library";

/** What a picture's upload has sent so far. */
export declare interface UploadProgress {
    /** The site the picture is for, as `uploadAsset` was given it. */
    layerId: string;
    /** The image field's own prop the picture lands on. */
    prop: string;
    /** The file's name, as the customer's device gave it. */
    fileName: string;
    /** Bytes sent so far, from 0 up to `totalBytes`. */
    sentBytes: number;
    /** The file's size. */
    totalBytes: number;
}

/** Result of an asset upload. `warningKey` + `warningParams` are the machine
 *  contract the host localizes via SdkMessages; `warning` is the API's English
 *  rendering, kept one release for back-compat and used as a fallback. */
export declare interface UploadResult {
    assetId: string;
    /** The file's name, as the customer's device gave it. */
    fileName: string;
    warning?: string;
    warningKey?: string;
    warningParams?: UploadWarningParams;
    /** Set when the picture is short of the floor at the chosen size and may
     *  be improved before the proof, to this size (#1092): the template allows
     *  it and the store can pay for it. `improveResolution(assetId, layerId)`
     *  asks for it. */
    improveResolution?: {
        widthPx: number;
        heightPx: number;
    };
}

/** Params interpolated into a localized asset-upload warning (see SdkMessages).
 *
 *  A union keyed by `warningKey`: `asset.low-resolution` carries the
 *  constraining edge in pixels, `asset.stored-larger` the uploaded and stored
 *  byte counts. `renderWarning` is shape-agnostic — it stringifies whatever
 *  `Object.entries` yields — so a new arm needs no code change there, only a
 *  message in `SdkMessages`. */
export declare type UploadWarningParams = {
    actualPx: number;
    minPx?: number;
} | {
    uploadBytes: number;
    storedBytes: number;
};

/** The `upscale` mount option: no upscale offered on this mount, or one only
 *  below a density. */
declare type UpscaleOption = false | {
    belowDpi: number;
};

/** What `setVariant()` rejects with for a variant the designer cannot show;
 *  the variant shown stays. */
export declare class VariantUnavailableError extends Error {
    readonly ref: string;
    readonly reason: VariantUnavailableReason;
    constructor(ref: string, reason: VariantUnavailableReason);
}

/** Why a variant cannot be shown. */
export declare type VariantUnavailableReason = 
/** The ref is not one of the blank's variants. */
"unknown_variant"
/** The variant is retired: no new design may name it. */
| "variant_retired"
/** The mount's `variants` do not name it. */
| "not_offered"
/** No view the product allows has a photo of it. */
| "no_photo";

/** This SDK's release. The wasm it boots must report the same one. */
export declare const version: string;

/** The view, variant and preview words `SdkMessages` carries. */
export declare interface ViewMessages {
    /** The views' heading. One key, where the size picker needs three: the
     *  photos are the consumer's (ADR 027), and no rule refuses a photo. */
    "views.legend": string;
    /** The views gallery's heading; interpolates {count}. */
    "views.count": string;
    /** The Design view's label (#1537): the artwork alone, with no photo. */
    "views.design": string;
    /** Under the views while a scene is shown: the picture is an estimate;
     *  interpolates {size} and {position}, written from `SCENE_ESTIMATE`. */
    "views.estimate-note": string;
    /** Once above the colour swatches (#1522).
     *  @deprecated The picker names each row by its option (#1548), as the
     *  blank names it; this key is no longer drawn. */
    "variants.colour-legend": string;
    /** Once above the garment size menu.
     *  @deprecated The picker names each row by its option (#1548), as the
     *  blank names it; this key is no longer drawn. */
    "variants.size-legend": string;
    /** The button after an option's first values that shows the rest;
     *  interpolates {count}, how many more there are. */
    "variants.more": string;
    /** An option's value no view has a photo of, which cannot be chosen;
     *  interpolates {value} (also as {colour}, its earlier name). */
    "variants.unavailable": string;
    /** A size the design can't be printed at, which cannot be chosen: its
     *  panel is another shape than the template (#2785); interpolates
     *  {value}. */
    "variants.misfit": string;
    "preview.alt": string;
    /** Over the preview's space until its first view is drawn (#2482). */
    "preview.loading": string;
    /** The preview's zoom on a scene: the control's name, its buttons, and what
     *  its level says at the limit. */
    "preview.zoom": string;
    "preview.zoom-in": string;
    "preview.zoom-out": string;
    "preview.zoom-limit": string;
    /** Under the preview when the designer fills its element (`fill`), on a
     *  desktop. */
    "preview.caption": string;
    /** Under the views when the print area has a shape (#2300): the print is
     *  cut to the product's outline and nothing is printed in its cut-outs. */
    "preview.shape-note": string;
}

/** A constraint failure as the WASM core emits it, `allowed` included. Only
 *  the local path produces these: the browser already holds the template, so
 *  restating one of its rules discloses nothing it did not send. */
export declare interface Violation {
    layerId: string;
    prop: string;
    rule: string;
    given: unknown;
    allowed: unknown;
    /** `error` refuses a publish; `warning` never does (ADR 193). */
    severity: "error" | "warning";
    /** The code of the language whose overrides break the rule, when only
     *  the template as it reads in that language does (#2123); a publish's
     *  alone, so the designer never sees one. */
    language?: string;
    /** The characters a glyph warning is about, each once in text order
     *  (`text_draws_fallback_glyphs`, `text_draws_missing_glyphs`, #2214);
     *  absent on every other rule. */
    characters?: string[];
}

/** The one kind of wrap today: an upright product photographed square-on. */
declare const WARP_KIND_CYLINDER = "cylinder";

/** The wasm glue at `url` could not be loaded, or could not instantiate its
 *  binary: not served there, or a glue and a binary from two releases (a
 *  LinkError or CompileError, before any version can be asked). The
 *  original failure is the `cause`. */
export declare class WasmBootError extends Error {
    readonly url: string;
    constructor(url: string, message: string, options?: ErrorOptions);
}

/** The Subresource Integrity values (`sha384-<base64>`) of the glue and the
 *  binary a released SDK ships with, which it checks both against before
 *  booting either. */
declare interface WasmIntegrity {
    glue: string;
    binary: string;
}

/** A file of the wasm pair whose bytes are not the ones the SDK was released
 *  with: served from a mirror that changed it, from another release, or
 *  tampered with. Nothing of it was run. */
export declare class WasmIntegrityError extends Error {
    readonly url: string;
    readonly file: keyof WasmIntegrity;
    readonly expected: string;
    readonly found: string;
    constructor(url: string, file: keyof WasmIntegrity, expected: string, found: string);
}

/** A booted wasm built for another release than the code booting it.
 *
 *  The wasm is served by whoever embeds the designer, so a script and a wasm
 *  from two releases is an ordinary deployment mistake, not a build error,
 *  and the two then disagree about exports and their arguments. The mount
 *  refuses at boot, naming both versions and where the wasm came from, rather
 *  than drawing with a renderer whose checks quietly fail call by call.
 *  `found` is null for a wasm from before the binding reported its version. */
export declare class WasmVersionMismatchError extends Error {
    readonly url: string;
    readonly expected: string;
    readonly found: string | null;
    constructor(url: string, expected: string, found: string | null, options?: ErrorOptions);
}

/** The message naming each offered prop when its author gave it no label,
 *  named after the prop as `getFormSchema()` carries it. Typed over every
 *  offered prop, so a prop added to a layer kind fails to compile here until
 *  it has a word, rather than reaching a customer as its key. */
declare const WORD_KEY_BY_PROP: {
    readonly content: "form.label.content";
    readonly fill: "form.label.fill";
    readonly color: "form.label.color";
    readonly imageAssetId: "form.label.imageAssetId";
    readonly shape: "form.label.shape";
    readonly src: "form.label.src";
    readonly crop: "form.label.crop";
    readonly fit: "form.label.fit";
    readonly tileScale: "form.label.tileScale";
    readonly fontFamily: "form.label.fontFamily";
    readonly fontWeight: "form.label.fontWeight";
    readonly fontSizeFrac: "form.font-size";
    readonly align: "form.label.align";
    readonly valign: "form.label.valign";
    readonly opacity: "form.label.opacity";
    readonly outlineColor: "form.label.outlineColor";
    readonly outlineWidthEm: "form.label.outlineWidthEm";
    readonly outlineWidthShare: "form.label.outlineWidthEm";
    readonly shadowColor: "form.label.shadowColor";
    readonly shadowOffsetXEm: "form.label.shadowOffsetXEm";
    readonly shadowOffsetYEm: "form.label.shadowOffsetYEm";
    readonly shadowBlurEm: "form.label.shadowBlurEm";
    readonly outlineWidthShortSide: "form.label.outlineWidthEm";
    readonly shadowOffsetXShortSide: "form.label.shadowOffsetXEm";
    readonly shadowOffsetYShortSide: "form.label.shadowOffsetYEm";
    readonly shadowBlurShortSide: "form.label.shadowBlurEm";
    readonly cornerRadiusShortSide: "form.label.cornerRadius";
    readonly letterSpacingEm: "form.label.letterSpacingEm";
    readonly lineHeight: "form.label.lineHeight";
    readonly textCase: "form.label.textCase";
    readonly tone: "form.label.tone";
    readonly brightness: "form.label.brightness";
    readonly contrast: "form.label.contrast";
    readonly saturation: "form.label.saturation";
};

export { }
