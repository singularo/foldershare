<?php

namespace Drupal\foldershare\Ajax;

use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Form\FormStateInterface;

use Drupal\foldershare\Constants;

/**
 * Defines an AJAX command to open a dialog and display error messages.
 *
 * This specialized AJAX dialog command creates a modal dialog with an
 * OK button to display a list of error messages. The dialog has a title,
 * an opening message, and a body that lists error messages. For a long
 * list, the body may include scrollbars.
 *
 * @ingroup foldershare
 */
class OpenErrorDialogCommand extends OpenModalDialogCommand {

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs an OpenDialogCommand object.
   *
   * @param string $title
   *   The title of the dialog.
   * @param string|array $content
   *   The content that will be placed in the dialog, either a render array
   *   or an HTML string.
   * @param array $dialogOptions
   *   (optional) Options to be passed to the dialog implementation. Any
   *   jQuery UI option can be used. See http://api.jqueryui.com/dialog.
   * @param array|null $settings
   *   (optional) Custom settings that will be passed to the Drupal behaviors
   *   on the content of the dialog. If left empty, the settings will be
   *   populated automatically from the current request.
   */
  public function __construct(
    string $title,
    $content = '',
    array $dialogOptions = [],
    $settings = NULL) {

    // Dialog action after closure defaults to a page refresh.
    $refreshOption = Constants::MODULE . 'RefreshAfterClose';
    $refreshIdOption = Constants::MODULE . 'RefreshViewDOMID';
    if (isset($dialogOptions[$refreshOption]) === FALSE) {
      $dialogOptions[$refreshOption] = TRUE;
    }

    if (isset($dialogOptions[$refreshIdOption]) === FALSE) {
      $dialogOptions[$refreshIdOption] = '';
    }

    // Set up some default dialog options.
    if (isset($dialogOptions['modal']) === FALSE) {
      $dialogOptions['modal'] = TRUE;
    }

    if (isset($dialogOptions['draggable']) === FALSE) {
      $dialogOptions['draggable'] = TRUE;
    }

    if (isset($dialogOptions['resizable']) === FALSE) {
      $dialogOptions['resizable'] = TRUE;
    }

    parent::__construct($title, $content, $dialogOptions, $settings);
  }

  /*--------------------------------------------------------------------
   *
   * Modify.
   *
   *--------------------------------------------------------------------*/

  /**
   * Enable a content refresh when the dialog is closed.
   *
   * Upon closing the dialog, a refresh will be triggered automatically.
   * If a view DOM ID is provided, the view associated with the DOM ID
   * will be refreshed via AJAX, if available. Otherwise the whole page
   * will be refreshed.
   *
   * @param string $viewDomId
   *   (optional) The DOM ID of the view to refresh via AJAX. If the ID
   *   is empty, the DOM doesn't contain the ID, or the view does not
   *   have AJAX enabled, the entire page is refreshed.
   */
  public function enableRefreshOnClose(string $viewDomId = '') {
    $this->dialogOptions[Constants::MODULE . 'RefreshAfterClose'] = TRUE;
    if (is_string($viewDomId) === TRUE && empty($viewDomId) === FALSE) {
      $this->dialogOptions[Constants::MODULE . 'RefreshViewDOMID'] = $viewDomId;
    }
    else {
      $this->dialogOptions[Constants::MODULE . 'RefreshViewDOMID'] = '';
    }
  }

  /**
   * Disable a page or view refresh on dialog close.
   *
   * Upon closing the dialog, no refresh will be triggered.
   */
  public function disableRefreshOnClose() {
    $this->dialogOptions[Constants::MODULE . 'RefreshAfterClose'] = FALSE;
    $this->dialogOptions[Constants::MODULE . 'RefreshViewDOMID'] = '';
  }

  /**
   * Sets dialog content based upon form state errors.
   *
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state who's error messages are used to set the dialog's
   *   body.
   * @param bool $clearErrors
   *   (optional) When TRUE, clears the form errors after adding them
   *   to the dialog body. Default is TRUE.
   */
  public function setFromFormErrors(
    FormStateInterface $formState,
    bool $clearErrors = TRUE) {

    // Create a render element container for a list of error messages.
    $body = [
      '#type' => 'container',
      '#attributes' => [
        'class'     => [
          Constants::MODULE . '-error-dialog-body',
        ],
      ],
    ];

    // Loop through the errors and add them to the container.
    // Errors have already been translated.
    foreach ($formState->getErrors() as $error) {
      $body[] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $error,
      ];
    }

    // Clear errors out of the form.
    if ($clearErrors === TRUE) {
      $formState->clearErrors();
    }

    $this->content = $body;
  }

  /**
   * Sets dialog content based upon page messages.
   *
   * @param array $messageTypes
   *   (optional) An array containing one or more of 'error', 'warning',
   *   and 'status' that indicates the types of messages to include.
   *   Messages are always added in (error, warning, status) order.
   *   A NULL or empty array means to include everything. Default is NULL.
   * @param bool $clearMessages
   *   (optional) When TRUE, clears the page messages after adding them
   *   to the dialog body. Default is TRUE.
   */
  public function setFromPageMessages(
    array $messageTypes = NULL,
    bool $clearMessages = TRUE) {

    // Create a render element container for a list of error messages.
    $body['messages'] = [
      '#type'       => 'container',
      '#attributes' => [
        'class'     => [
          Constants::MODULE . '-error-dialog-body',
        ],
      ],
    ];

    // Determine which message types to include.
    if (empty($messageTypes) === TRUE) {
      // Include everything in (error, warning, status) order.
      $messageTypes = [
        'error',
        'warning',
        'status',
      ];
    }
    else {
      // Include named types in (error, warning, status) order.
      // Ignore any other type names.
      $e = [];
      foreach (['error', 'warning', 'status'] as $t) {
        if (in_array($t, $messageTypes) === TRUE) {
          $e[] = $t;
        }
      }

      $messageTypes = $e;
    }

    // Loop through the types and messages and add them to the container.
    // Messages have already been translated.
    foreach ($messageTypes as $t) {
      foreach (drupal_get_messages($t, $clearMessages) as $messages) {
        foreach ($messages as $message) {
          $body['messages'][] = [
            '#type'  => 'html_tag',
            '#tag'   => 'p',
            '#value' => $message,
          ];
        }
      }
    }

    $this->content = $body;
  }

}
