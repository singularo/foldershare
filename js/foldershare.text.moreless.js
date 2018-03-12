/**
 * @file
 * More/Less Behavior
 *
 * The DOM contains a <div> that wraps additional <div>s for a field of
 * arbitrary content, a "More..." button, and a "Less..." button.
 *
 * On page load:
 *
 * * If the content field has no specific attribute to set an initial height
 *   limit, then leave the field at full height and hide the buttons.
 *
 * * If the content field has a height limit, set the field's maximum height
 *   to that limit. If the field's size is less than this, then leave the
 *   field alone and hide the buttons.
 *
 * * Otherwise when the field is limited, show the "More..." button and hide
 *   the "Less..." button.
 *
 *   * When the "More..." button is clicked, remove the height limit, hide
 *     the button, and show the "Less..." button.
 *
 *   * When the "Less..." button is clicked, put back the height limit, hide
 *     the button, and show the "More..." button.
 */
(function($, Drupal, drupalSettings) {

  'use strict';

  /*--------------------------------------------------------------------
   *
   * Module items.
   *
   *--------------------------------------------------------------------*/
  Drupal.foldershareTextMoreLess = Drupal.foldershareTextMoreLess || {

    attachTextMoreLess: function(pageContext, settings) {
      // Search for all text fields marked as needing a more/less behavior.
      $('.foldershare-text-moreless').each(function() {
        var $outer = $(this);
        var $field = $(this).children('.foldershare-text');

        // Get the full height of the field. This is the amount of page space
        // the field would like to take up, including whatever styling is
        // involved in the content.
        var fullHeightPx = $field.height();

        // Get the recommended minimum height of the field. This value may
        // not have been provided. If provided, it may be in any units
        // (e.g. "3em", "10px"). The returned value is a string with units.
        var lessHeight = $field.attr('data-foldershare-text-crop-height');

        if (typeof(lessHeight) === 'undefined') {
          // A minimum height was not set. We cannot do a more/less behavior.
          // Hide more and less buttons and return.
          $outer.children('.foldershare-text-more').hide();
          $outer.children('.foldershare-text-less').hide();
          return;
        }

        // Set the maximum height of the field area. If the content is smaller,
        // it will use less than this.
        $field.css('max-height', lessHeight);

        // After setting the maximum height, get the resulting limited height.
        // This can be no larger than the "max-height" we just set, but the
        // return value is in pixels, while the "max-height" was set using
        // whatever units were on the element's attribute.
        var limitedHeightPx = $field.height();

        // If the full height of the unlimited field is no bigger than the limit,
        // then the height wasn't limited at all and there is no need for the
        // more/less behavior. Hide more and less buttons.
        if (fullHeightPx <= limitedHeightPx) {
          $outer.children('.foldershare-text-more').hide();
          $outer.children('.foldershare-text-less').hide();
        }
        else {
          // Insure the more button is shown and the less button is not.
          // We cannot assume that CSS has been properly initialized.
          $outer.children('.foldershare-text-more').show();
          $outer.children('.foldershare-text-less').hide();
        }
      });

      /**
       * Respond to a click event on "More..." and update the field size.
       */
      $('.foldershare-text-more').click(function(event) {
        var $outer = $(this).parent();
        var $field = $outer.children('.foldershare-text');

        // To expand the field to its full needed size, just remove the
        // CSS max-height limit by setting it to "none".
        $field.css('max-height', 'none');

        // Hide the more button.
        $(this).hide();

        // Show the less button.
        $outer.children('.foldershare-text-less').show();
      });

      /**
       * Respond to a click event on "Less..." and update the field size.
       */
      $(".foldershare-text-less").click(function(event) {
        var $outer = $(this).parent();
        var $field = $outer.children('.foldershare-text');

        // To limit the field size, set the CSS max-height. At this point
        // we know there is a value set or the "Less..." button would have
        // been hidden on page load and this behavior would not be triggerable.
        // Get the height, which may be in any units.
        var lessHeight = $field.attr('data-foldershare-text-crop-height');
        $field.css('max-height', lessHeight);

        // Hide the less button.
        $(this).hide();

        // Show the more button.
        $outer.children('.foldershare-text-more').show();
      });
    }
  };

  /*--------------------------------------------------------------------
   *
   * On Drupal ready behaviors.
   *
   *--------------------------------------------------------------------*/

  Drupal.behaviors.foldershareTextMoreLess = {
    attach: function (pageContext, settings) {
      Drupal.foldershareTextMoreLess.attachTextMoreLess(pageContext, settings);
    }
  };

})(jQuery, Drupal, drupalSettings);
