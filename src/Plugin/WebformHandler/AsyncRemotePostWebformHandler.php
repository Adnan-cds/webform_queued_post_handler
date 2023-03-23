<?php

namespace Drupal\webform_queued_post_handler\Plugin\WebformHandler;

use Drupal\Component\Utility\Html;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Queue\DelayedRequeueException;

/**
 * Webform submission async remote post handler.
 *
 * @WebformHandler(
 *   id = "async_remote_post",
 *   label = @Translation("Async Remote post"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a URL asynchronously using a queue."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class AsyncRemotePostWebformHandler extends RemotePostWebformHandler {

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();

    $sid = $webform_submission->id();

    // Only need to queue new/completed submissions.
    if ($state == 'completed' && !$update) {
      $state_url = $state . '_url';
      if (empty($this->configuration[$state_url])) {
        // Bail if no url is given for this state (Completed/Updated).
        return;
      }
      // Queue the item.
      $this->addQueueItem($sid, $this->getHandlerId(), $state);
      return;
    }
    else {
      // Pass through anything else to parent.
      parent::postSave($webform_submission, $update);
    }
  }

  /**
   * Helper function to add an item to the queue.
   *
   * @param int $sid
   *   The subimssion id
   *
   * @param string $handler_id
   *   The webform handler id
   *
   * @param string $state_id
   *   The webform state we are handling (Completed/Updated/Deleted).
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   */
  public function addQueueItem($sid, $handler_id, $state) {
    if (!empty($sid)) {
      $queue_factory = \Drupal::service('queue');
      $queue = $queue_factory
        ->get('webform_queued_post_handler.queue.remote_post_handler');

      $item = [
        'webform_submission_id' => $sid,
        'handler_id' => $handler_id,
        // Will prob only ever handle "completed" states, but let's store this just in case we want to queue updated as well.
        'state' => $state
      ];

      $queue->createItem($item);
    }
  }

  /**
   * This is called by the RemotePostQueueWorker and executes the remote post.
   *
   * @param string $state
   *   The submission's state.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   */
  public function processQueueItem($state, WebformSubmissionInterface $webform_submission) {
    try {
      $this->remotePost($state, $webform_submission);
    }
    catch (\Exception $e) {
      // This exception will ensure the item is requeued, to try again later.
      // We must override the remotePost method in order to get here though.
      throw new DelayedRequeueException();
    }
  }

  /**
   * {@inheritdoc}
   * This is overridden so that we can catch errors and rethrow in order for failed queue items to be retried in the future.
   */
  // protected function remotePost($state, WebformSubmissionInterface $webform_submission) {
  //   $state_url = $state . '_url';
  //   if (empty($this->configuration[$state_url])) {
  //     return;
  //   }

  //   $this->messageManager->setWebformSubmission($webform_submission);

  //   $request_url = $this->configuration[$state_url];
  //   $request_url = $this->replaceTokens($request_url, $webform_submission);
  //   $request_method = (!empty($this->configuration['method'])) ? $this->configuration['method'] : 'POST';
  //   $request_type = ($request_method !== 'GET') ? $this->configuration['type'] : NULL;

  //   // Get request options with tokens replaced.
  //   $request_options = (!empty($this->configuration['custom_options'])) ? Yaml::decode($this->configuration['custom_options']) : [];
  //   $request_options = $this->replaceTokens($request_options, $webform_submission);

  //   try {
  //     if ($request_method === 'GET') {
  //       // Append data as query string to the request URL.
  //       $query = $this->getRequestData($state, $webform_submission);
  //       $request_url = Url::fromUri($request_url, ['query' => $query])->toString();
  //       $response = $this->httpClient->get($request_url, $request_options);
  //     }
  //     else {
  //       $method = strtolower($request_method);
  //       $request_options[($request_type === 'json' ? 'json' : 'form_params')] = $this->getRequestData($state, $webform_submission);
  //       $response = $this->httpClient->$method($request_url, $request_options);
  //     }
  //   }
  //   catch (RequestException $request_exception) {
  //     $response = $request_exception->getResponse();

  //     // Encode HTML entities to prevent broken markup from breaking the page.
  //     $message = $request_exception->getMessage();
  //     $message = nl2br(htmlentities($message));

  //     $this->handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response);
  //     throw $request_exception;
  //   }

  //   // Display submission exception if response code is not 2xx.
  //   if ($this->responseHasError($response)) {
  //     $message = $this->t('Remote post request return @status_code status code.', ['@status_code' => $response->getStatusCode()]);
  //     $this->handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response);
  //     throw $request_exception;
  //   }
  //   else {
  //     $this->displayCustomResponseMessage($response, FALSE);
  //   }

  //   // If debugging is enabled, display the request and response.
  //   $this->debug($this->t('Remote post successful!'), $state, $request_url, $request_method, $request_type, $request_options, $response, 'warning');

  //   // Replace [webform:handler] tokens in submission data.
  //   // Data structured for [webform:handler:remote_post:completed:key] tokens.
  //   $submission_data = $webform_submission->getData();
  //   $submission_has_token = (strpos(print_r($submission_data, TRUE), '[webform:handler:' . $this->getHandlerId() . ':') !== FALSE) ? TRUE : FALSE;
  //   if ($submission_has_token) {
  //     $response_data = $this->getResponseData($response);
  //     $token_data = ['webform_handler' => [$this->getHandlerId() => [$state => $response_data]]];
  //     $submission_data = $this->replaceTokens($submission_data, $webform_submission, $token_data);
  //     $webform_submission->setData($submission_data);
  //     // Resave changes to the submission data without invoking any hooks
  //     // or handlers.
  //     if ($this->isResultsEnabled()) {
  //       $webform_submission->resave();
  //     }
  //   }
  // }
}
