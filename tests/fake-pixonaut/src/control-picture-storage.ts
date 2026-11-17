// `GET /__fake/picture-storage`: the last picture storage report each
// connected store sent (`PUT /v1/connect/picture-storage`), as it sent it,
// so a test can see what the store told Pixonaut. A store that has sent none
// is left out; the next reset empties it.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { PREFIX } from './control';

export function registerPictureStorageControl(app: Hono, fake: Fake): void {
	app.get(`${PREFIX}/picture-storage`, (c) =>
		c.json({
			reports: [...fake.state.stores.values()]
				.filter((store) => store.pictureStorage !== undefined)
				.map((store) => ({
					siteUrl: store.siteUrl,
					report: store.pictureStorage,
				})),
		}),
	);
}
