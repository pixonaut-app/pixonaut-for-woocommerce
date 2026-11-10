// The ids and keys the default fixture set gives, so tests name them instead
// of repeating literals. A module of its own, so the e2e suite can import it
// without loading the fake.

export const FIXTURE = {
	tenant: { live: 'tnt_live', sandbox: 'tnt_sandbox' },
	/** Each tenant's slug, under which the studio's pages sit. */
	tenantSlug: { live: 'fixture-prints', sandbox: 'fixture-prints-sandbox' },
	store: {
		main: {
			id: 'conn_main',
			integrationKey: 'ik_live_fixture_main',
			publishableKey: 'pk_live_fixture_main',
			webhook: { id: 'wh_fixture_main', secret: 'whsec_fixture_main' },
		},
		/** A second store on the live tenant, for store isolation. */
		other: {
			id: 'conn_other',
			integrationKey: 'ik_live_fixture_other',
			publishableKey: 'pk_live_fixture_other',
			webhook: { id: 'wh_fixture_other', secret: 'whsec_fixture_other' },
		},
		sandbox: {
			id: 'conn_sandbox',
			integrationKey: 'ik_test_fixture_sandbox',
			publishableKey: 'pk_test_fixture_sandbox',
			webhook: { id: 'wh_fixture_sandbox', secret: 'whsec_fixture_sandbox' },
		},
	},
	/** The member who approves a connect unless a test names another. */
	member: {
		owner: {
			memberId: 'mem_owner',
			memberName: 'Store Owner',
			memberEmail: 'owner@example.test',
		},
	},
	shareLink: 'link_fixture_template',
	template: {
		/** The live version of a live template. */
		live: 'mini-tee@2',
		/** An older version of the same template. */
		previous: 'mini-tee@1',
		/** The only version of a paused template. */
		paused: 'mini-tee-paused@1',
		sandbox: 'sandbox-tee@1',
		/** A live landscape template beside the portrait tee. */
		landscape: 'mini-poster@1',
		/** The tee whose slogan must be changed from its default. */
		requireChange: 'mini-tee-change@1',
	},
	blank: {
		/** Front and back; made by the main printshop. */
		tee: 'blank_tee',
		/** Front and back; made by the other printshop. */
		hoodie: 'blank_hoodie',
		/** No placements yet: nothing to print on. */
		bare: 'blank_tote',
	},
	printshop: { main: 'ps_main', other: 'ps_other', integrated: 'ps_gelato' },
	placement: { front: 'front', back: 'back' },
	mockup: {
		/** The front placement's photo, on every blank with placements. */
		front: 'mockup_front',
		/** A second front photo, of a dark colourway. */
		frontDark: 'mockup_front_dark',
		/** The back placement's photo. */
		back: 'mockup_back',
		/** A back photo whose upload has not finished: no `mount`. */
		uploading: 'mockup_back_uploading',
	},
	/** The blanks' variants, by ref. The tee and the hoodie have a Color and
	 *  a Size axis; the tote has none, so one variant. */
	variant: {
		/** Shown by the front view's photo and the back's. */
		teeWhiteS: 'v_tee_white_s',
		teeWhiteM: 'v_tee_white_m',
		/** Shown by the dark front view's photo and the back's. */
		teeBlackS: 'v_tee_black_s',
		teeBlackM: 'v_tee_black_m',
		/** Retired because its printshop stopped making it. */
		teeRedS: 'v_tee_red_s',
		/** Retired by the merchant. */
		teeRedM: 'v_tee_red_m',
		/** Sold, but no photo shows it. */
		teeHeatherS: 'v_tee_heather_s',
		hoodieWhiteS: 'v_hoodie_white_s',
		hoodieBlackS: 'v_hoodie_black_s',
		/** The tote's only variant. */
		tote: 'v_tote',
	},
} as const;
