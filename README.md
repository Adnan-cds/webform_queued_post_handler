# Webform Email Priority
This module extends the Webform module's Remote Post Webform Handler plugin to make remote posts asynchronous using Drupal Queue API.
It only queues "Completed" urls. "Updated and "Deleted" urls are run like normal.

## Setup
Just enabled the module and a new "Async Remote Post" handler should become available in the webform "Email / Handlers" section.
After submitting a webform, the queued items should appear in the queue table, and should be processed on next cron run.

## Notes

### Testing / Debugging
For testing the Completed/Updated/Deleted urls, if you enable the "Webform Remote Post Example" module, the following urls will be available:

 * <site>/webform_example_remote_post/completed
 * <site>/webform_example_remote_post/updated
 * <site>/webform_example_remote_post/deleted

You can use hidden fields as "confirmation tokens" to store a confirmation token from the remote post response data.
The format of the token is `[webform:handler:<handler_id>:<state>:<key>]`

	* `handler_id`: The handler id (eg: async_remote_post, async_remote_post_2...)
	* `state`: completed/updated/deleted
	* `key`: The key of whatever value you want in the response. I think it assumes a JSON response, but not sure.

For example `[webform:handler:async_remote_post:completed:confirmation_number]` will get replaced with a "confirmation_number" value from the "Completed URL" url response.

### Retries
The `Retry upon failure` handler setting decides if a queued item should be re-queued if it fails.  This setting defaults to no-retry.
