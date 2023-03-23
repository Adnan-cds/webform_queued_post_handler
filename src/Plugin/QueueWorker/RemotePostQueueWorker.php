<?php

namespace Drupal\webform_queued_post_handler\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Does remote post for queued webform submission.
 *
 * Queued items are added by this module's custom webform handler (AsyncRemotePostWebformHandler).
 *
 * @QueueWorker(
 *   id = "webform_queued_post_handler.queue.remote_post_handler",
 *   title = @Translation("Cyberwoven Webform Queued Remote Post Worker "),
 *   cron = {"time" = 30}
 * )
 *
 * @see \Drupal\Core\Annotation\QueueWorker
 * @see \Drupal\Core\Annotation\Translation
 */
class RemotePostQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  use StringTranslationTrait;

  /**
   * This module's logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.channel.webform_queued_post_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (empty($data['webform_submission_id']) || empty($data['handler_id']) || empty($data['state'])) {
      return;
    }

    $sid = $data['webform_submission_id'];
    $handler_id = $data['handler_id'];
    $state = $data['state'];

    $webform_submission = WebformSubmission::load($sid);
    // Bail if submission has been deleted.
    if (empty($webform_submission)) {
      $this->logger->debug($this->t('Failed to retrieve webform submission. Assuming it was deleted: sid:@sid, handler_id:@handler_id, state:@state', [
        '@sid' => $sid,
        '@handler_id' => $handler_id,
        '@state' => $state,
      ]));

      // Not going to worry about re-queing the item.
      return;
    }

    $webform = $webform_submission->getWebform();
    $handler = $webform->getHandler($handler_id);

    // Bail if handler has been deleted.
    if (empty($handler)) {
      $this->logger->debug($this->t('Failed to retrieve handler. Assuming it was deleted: sid:@sid, handler_id:@handler_id, state:@state', [
        '@sid' => $sid,
        '@handler_id' => $handler_id,
        '@state' => $state,
      ]));

      // Not going to worry about re-queing the item.
      return;
    }

    // Bail if handler has been disabled.
    if (!$handler->isEnabled()) {
      // Not going to worry about re-queing the item.
      return;
    }

    // Call the public method wrapper around remotePost().
    $handler->processQueueItem($state, $webform_submission);

    $this->logger->debug($this->t('Queued item processed: sid:@sid, handler_id:@handler_id, state:@state', [
      '@sid' => $sid,
      '@handler_id' => $handler_id,
      '@state' => $state,
    ]));
  }
}
