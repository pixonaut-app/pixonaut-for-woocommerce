// WordPress's media library script, `wp.media`, as the admin screens use it:
// the featured-image box, and a frame to choose one image from the library.

/** WordPress's featured-image box, `wp.media.featuredImage`. */
export type FeaturedImage = { set: (id: number) => void };

/** An attachment the media library chose. */
export type ChosenAttachment = {
	id: number;
	url: string;
	sizes?: Record<string, { url: string }>;
};

/** A media library frame, opened to choose an image. */
export type MediaFrame = {
	on(event: 'select', handler: () => void): void;
	open(): void;
	state(): {
		get(name: 'selection'): { first(): { toJSON(): ChosenAttachment } };
	};
};

export type MediaFrameOptions = {
	title: string;
	button: { text: string };
	library: { type: string };
	multiple: boolean;
};

export type WpMedia = ((options: MediaFrameOptions) => MediaFrame) & {
	featuredImage?: FeaturedImage;
};

declare global {
	interface Window {
		wp?: { media?: WpMedia };
	}
}
