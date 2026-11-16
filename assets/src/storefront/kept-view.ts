import type { PixonautDesigner } from '../types/sdk';

/** The view a designer showed, to show again in the one mounted after it. */
export type KeptView = {
	/** Its id. A scene's view has the same id on every blank that shows the
	 *  scene, and a stored view's is its own key. */
	viewId: string;
	/** Its place among the views the designer listed with a photo. */
	index: number;
};

type Views = Pick<PixonautDesigner, 'getView' | 'getViews'>;

/** The ids of the views `designer` lists with a photo, in its order. */
function listed(designer: Views): string[] {
	return designer
		.getViews()
		.filter((view) => view.hasPhoto)
		.map((view) => view.viewId);
}

/** The view `designer` shows; undefined when it shows none. */
export function shownViewOf(designer: Views): KeptView | undefined {
	const viewId = designer.getView();
	if (viewId === undefined) {
		return undefined;
	}
	return { viewId, index: listed(designer).indexOf(viewId) };
}

/**
 * The view of `designer` that stands for `kept`, the view the designer
 * before it showed: the one with the same id, else the one at the same
 * place among its views. Undefined when it has neither, and the designer
 * stays on the view it opened on. Views are never matched by their labels,
 * which merchants rename and translate.
 */
export function viewStandingFor(
	designer: Views,
	kept: KeptView,
): string | undefined {
	const views = listed(designer);
	return views.includes(kept.viewId) ? kept.viewId : views[kept.index];
}
