<?php

namespace Drupal\webform_queued_post_handler\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Form\FormStateInterface;
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
   *
   * Alterations:
   * - Declares the retry_upon_failure settings.
   */
  public function defaultConfiguration() {

    $default_config = parent::defaultConfiguration();

    $default_config['retry_upon_failure'] = FALSE;

    return $default_config;
  }

  /**
   * {@inheritdoc}
   *
   * Alterations:
   * - Disables the error URL settings.  An async handler achieves nothing
   *   by redirecting to an error URL.
   * - Adds settings field for Retry upon failure.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $config_form = parent::buildConfigurationForm($form, $form_state);

    $config_form['additional']['error_url']['#disabled'] = TRUE;

    $config_form['additional']['retry_upon_failure'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Retry upon failure'),
      '#description'   => $this->t('What to do when a remote POST fails.'),
      '#default_value' => $this->configuration['retry_upon_failure'],
      '#return_value'  => TRUE,
    ];

    return parent::setSettingsParents($config_form);
  }

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
   *   The submission id.
   * @param string $handler_id
   *   The webform handler id.
   * @param string $state
   *   The webform state we are handling (Completed/Updated/Deleted).
   */
  public function addQueueItem($sid, $handler_id, $state) {
    if (!empty($sid)) {
      $queue_factory = \Drupal::service('queue');
      $queue = $queue_factory
        ->get('webform_queued_post_handler.queue.remote_post_handler');

      $item = [
        'webform_submission_id' => $sid,
        'handler_id' => $handler_id,
        // Will prob only ever handle "completed" states, but let's store this
        // just in case we want to queue updated as well.
        'state' => $state,
      ];

      $queue->createItem($item);
    }
  }

  /**
   * This is called by the RemotePostQueueWorker and executes the remote post.
   *
   * @param string $state
   *   The submission's state.
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
   *
   * This is overridden so that we can raise an exception in order for failed
   * queued items to be retried in the future.
   */
  protected function handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response) {

    // An async handler achieves nothing by redirecting to an error URL.
    // The error URL settings field has been already disabled in
    // self::buildConfigurationForm().  We reset it here in case the error URL
    // has been edited in the webform config.
    $this->configuration['error_url'] = '';

    parent::handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response);

    throw new \Exception();
  }

}
