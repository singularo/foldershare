<?php

namespace Drupal\foldershare\Ajax;

use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsTrait;

use Drupal\Component\Render\PlainTextOutput;

use Drupal\foldershare\Constants;

/**
 * Defines an AJAX command to open a modal dialog for a form.
 *
 * @ingroup foldershare
 */
class FormDialog implements CommandInterface, CommandWithAttachedAssetsInterface {

  use CommandWithAttachedAssetsTrait;

  /*--------------------------------------------------------------------
   *
   * Constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * The general-purpose module modal dialog selector.
   */
  const SELECTOR = '#foldershare-modal';

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   *--------------------------------------------------------------------*/

  /**
   * The render array for the body of the dialog.
   *
   * This field must be named 'content' so that it can be picked up
   * by the CommandWithAttachedAssetsTrait.
   *
   * @var array
   */
  protected $content;

  /**
   * The initial jQuery dialog options.
   *
   * @var array
   */
  protected $dialogOptions;

  /**
   * The settings, if any, passed to the DRupal behaviors on the dialog.
   *
   * @var array
   */
  protected $settings;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a form dialog with the given title, form, etc.
   *
   * @param string $title
   *   The title of the dialog.
   * @param array $content
   *   The form render array body that will be placed in the dialog.
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
    array $content,
    array $dialogOptions = [],
    $settings = NULL) {

    $this->dialogOptions = $dialogOptions;
    $this->settings      = $settings;
    $this->content       = $content;

    // Add the rendered title to the dialog's options.
    $this->dialogOptions['title'] = PlainTextOutput::renderFromHtml($title);

    // Add default refresh-after-close options.
    $this->dialogOptions['refreshAfterClose'] = TRUE;
    $this->dialogOptions['refreshViewDOMID'] = '';

    // Insure the dialog is modal.
    $this->dialogOptions['modal'] = TRUE;
  }

  /*--------------------------------------------------------------------
   *
   * Get/Set.
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
    $this->dialogOptions['refreshAfterClose'] = TRUE;
    if (is_string($viewDomId) === TRUE && empty($viewDomId) === FALSE) {
      $this->dialogOptions['refreshViewDOMID'] = $viewDomId;
    }
    else {
      $this->dialogOptions['refreshViewDOMID'] = '';
    }
  }

  /**
   * Disable a page or view refresh on dialog close.
   *
   * Upon closing the dialog, no refresh will be triggered.
   */
  public function disableRefreshOnClose() {
    $this->dialogOptions['refreshAfterClose'] = FALSE;
    $this->dialogOptions['refreshViewDOMID'] = '';
  }

  /**
   * Returns the current dialog options.
   *
   * @return array
   *   Returns the dialog's options as an associative array with keys
   *   naming options, and values giving the values for those options.
   *   Keys are strings and values are of mixed type that depends upon
   *   the option.
   */
  public function getDialogOptions() {
    return $this->dialogOptions;
  }

  /**
   * Returns the current dialog title.
   *
   * @return string
   *   Returns the dialog's title from it's dialog options. Returns an
   *   empty string if there is no title.
   */
  public function getDialogTitle() {
    if (isset($this->dialogOptions['title']) === TRUE) {
      return $this->dialogOptions['title'];
    }

    return '';
  }

  /**
   * Sets a single dialog option value.
   *
   * @param string $key
   *   The name of the dialog option.
   * @param mixed $value
   *   The new value for the dialog option.
   */
  public function setDialogOption(string $key, $value) {
    $this->dialogOptions[$key] = $value;
  }

  /**
   * Sets a the dialog title.
   *
   * @param string $title
   *   The dialog title as a raw string or HTML.
   */
  public function setDialogTitle(string $title) {
    $this->dialogOptions['title'] = PlainTextOutput::renderFromHtml($title);
  }

  /*--------------------------------------------------------------------
   *
   * Render.
   *
   *--------------------------------------------------------------------*/

  /**
   * Implements \Drupal\Core\Ajax\CommandInterface::render()
   */
  public function render() {
    // Insure the title, if any, is a string.
    if (isset($this->dialogOptions['title']) === TRUE &&
        is_string($this->dialogOptions['title']) === FALSE) {
      $this->dialogOptions['title'] = (string) $this->dialogOptions['title'];
    }

    // Insure the modal flag is set and a boolean TRUE.
    $this->dialogOptions['modal'] = TRUE;

    // Return the command.
    return [
      'command'       => Constants::MODULE . 'FormDialog',
      'selector'      => self::SELECTOR,
      'settings'      => $this->settings,
      'dialogOptions' => $this->dialogOptions,
      'data'          => $this->getRenderedContent(),
    ];
  }

}
