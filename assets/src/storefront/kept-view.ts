import type { PixonautDesigner } from '../types/sdk';

/** The view a designer showed, to show again in the one mounted after it. */
export type KeptView = {
	/** Its id. A scene's view has the same id on every blank that shows the
	 *  scene, and a stored view's is its own key. */
	viewId: string;
	/** Its place among the views the designer listed with a photo; -1 when
	 *  it is not one of them, as the artwork alone is not. */
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

/** Whether `designer` lists the very view `kept` names, with a photo. */
export function listsView(designer: Views, kept: KeptView): boolean {
	return listed(designer).includes(kept.viewId);
}
