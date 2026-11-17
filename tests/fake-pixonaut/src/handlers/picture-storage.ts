// The picture storage a store reports: how much room the pictures it keeps
// for Pixonaut products take, in total and per product. Each report replaces
// the last; the fake keeps the latest per connection so a test can read what
// the store sent.
import { callerStore } from './connect';
import type { Handler } from './types';

/** `PUT /v1/connect/picture-storage`: holds the report as sent (the contract
 *  has already checked its shape) and answers 204. */
export const reportPictureStorage: Handler = (context) => {
	callerStore(context).pictureStorage = context.body;
	return { status: 204 };
};
