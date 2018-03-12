/**
 * @file
 * Implements the enhanced FolderShare user interface.
 *
 * This user interface is invoked when the FolderShareEnhancedUI views area
 * plugin is installed on a file/folder view.
 *
 * This script requires HTML elements added by FolderShareEnhancedUI *AND*
 * by FolderShareBaseUI, which must exist as a views field plugin. The latter
 * adds checkboxes to select rows, while the enhanced UI plugin adds
 * Javascript and AJAX support.
 *
 * Required page elements:
 * - A view:
 *   - A selection checkbox on every row.
 * - An enhanced UI form:
 *   - A hierarchical list to use to create a menu.
 *   - A button to trigger showing the menu.
 *   - A file field to select files to upload.
 *   - A field to hold the current menu choice.
 *   - A field to hold the current row selection.
 *
 * @ingroup foldershare
 * @see \Drupal\foldershare\Plugin\views\field\FolderShareBaseUI
 * @see \Drupal\foldershare\Form\ViewEnhancedUI
 */
(function($, Drupal, drupalSettings) {

  'use strict';

  /*--------------------------------------------------------------------
   *
   * Module items.
   *
   *--------------------------------------------------------------------*/
  Drupal.foldershare = Drupal.foldershare || {
    /*--------------------------------------------------------------------
     *
     * Constants.
     *
     *--------------------------------------------------------------------*/

    /**
     * The name of the module.
     *
     * This name is used as the base name for numerous classes and IDs.
     */
    moduleName: 'foldershare',

    /**
     * The name of the general-purpose modal dialog <div>.
     */
    modalName: 'foldershare-modal',

    /*--------------------------------------------------------------------
     *
     * Initialize.
     *
     *--------------------------------------------------------------------*/

    /**
     * Prepares for future form dialogs.
     *
     * @param pageContext
     *   The page context for this call. Initially, this is the full document.
     *   Later, this is only portions of the document added via AJAX.
     * @param settings
     *   The top-level Drupal settings object.
     *
     * @return
     *   Always returns true.
     */
    attachFormDialogs: function(pageContext, settings) {
      //
      // Create default modal div
      // -----------------------
      // FormDialog's require an existing page <div> into which to insert
      // dialog content. Rather than create it explicitly on every page
      // during page assembly, we add a general-purpose <div> here.
      var mn = Drupal.foldershare.modalName;
      if ($('#' + mn).length === 0) {
        // The dialog is marked with 
        var html = '<div id="' + mn + '" class="ui-front ' +
          Drupal.foldershare.moduleName + '-foldershare-dialog"/>';
        $(html).hide().appendTo('body');
      }

      //
      // Flag dialog button movement
      // ---------------------------
      // Add an option to automatically move form actions buttons to
      // the bottom of the dialog into a button area.
      var jcontent = $(pageContext).closest('.ui-dialog-content');
      if (jcontent.length !== 0) {
        jcontent.dialog('option', 'drupalAutoButtons');
        jcontent.trigger('dialogButtonsChange');

        // Force focus onto the dialog when the behavior is run.
        jcontent.dialog('widget').trigger('focus');
      }

      //
      // Set close behavior
      // ------------------
      // TODO

      return true;
    }
  };

  /**
   * Opens a form dialog.
   */
  Drupal.AjaxCommands.prototype.foldershareFormDialog = function(ajax, response, status) {
    // Get the general-purpose dialog <div>.
    var jcontent = $('#' + Drupal.foldershare.modalName);

    // Set up the AJAX wrapper.
    ajax.wrapper = jcontent.attr('id');

    // Add the response's contents to the dialog.
    response.command = 'insert';
    response.method  = 'html';
    ajax.commands.insert(ajax, response, status);

    response.dialogOptions = response.dialogOptions || {};

    // Move form buttons to the jQuery dialog button area at the bottom,
    // unless the dialog has specific button options.
    if (typeof response.dialogOptions.buttons === 'undefined') {
      response.dialogOptions.drupalAutoButtons = true;
      response.dialogOptions.buttons =
        Drupal.behaviors.dialog.prepareDialogButtons(jcontent);
    }

    // Add a behavior to note changes to the buttons.
    jcontent.on('dialogButtonsChange', function() {
      jcontent.dialog('option', 'buttons',
        Drupal.behaviors.dialog.prepareDialogButtons(jcontent));
    });

    // Add a default class to style the dialog.
    response.dialogOptions.dialogClass = 'foldershare-dialog';

    // Open the dialog. Always modal.
    var dialog = Drupal.dialog(jcontent[0], response.dialogOptions);
    dialog.close = function(event) {
    };
    dialog.showModal();

    jcontent.parent().find('.ui-dialog-buttonset').addClass('form-actions');

  };


  /*--------------------------------------------------------------------
   *
   * Enhanced UI plugin items.
   *
   *--------------------------------------------------------------------*/
  Drupal.foldershare.enhancedui = Drupal.foldershare.enhancedui || {

    /*--------------------------------------------------------------------
     *
     * Constants.
     *
     *--------------------------------------------------------------------*/

    /**
     * The ID of the enhanced UI.
     *
     * The ID is used in related class and attribute names.
     *
     * @see \Drupal\foldershare\Form\ViewEnhancedUI.
     */
    enhancedID: 'drupal_foldershare_form_viewenhancedui',

    /**
     * The ID of the base UI.
     *
     * The ID is used in related class and attribute names.
     *
     * @see \Drupal\foldershare\Plugin\views\field\FolderShareBaseUI
     */
    baseID: 'foldershare_views_field_baseui',

    checkboxColumn: 'views-field-foldershare-views-field-baseui',

    /*--------------------------------------------------------------------
     *
     * Initialize.
     *
     *--------------------------------------------------------------------*/

    /**
     * Attaches the module's behaviors.
     *
     * All Enhanced UI <div>s and related elements are found, validated,
     * and behaviors attached.
     *
     * @param pageContext
     *   The page context for this call. Initially, this is the full document.
     *   Later, this is only portions of the document added via AJAX.
     * @param settings
     *   The top-level Drupal settings object.
     *
     * @return
     *   Always returns true.
     */
    attachEnhancedUI: function (pageContext, settings) {
      // Insure the base UI is hidden quickly so that the page doesn't
      // flicker showing it, then hiding it.
      Drupal.foldershare.enhancedui.quickHideBaseUI(pageContext);

      var sel = '.' + Drupal.foldershare.enhancedui.enhancedID;

      // Loop through all Enhanced UI <form>s. For each one, find
      // relevant elements, validate, and build the UI. If any step
      // fails, skip that <div>.
// TODO
// TODO This won't work when a view has AJAX enabled. On a page advance,
// TODO the view's portion of a page advances and this code is called with
// TODO pageContext === that portion of the page. The problem is that that
// TODO portion of the page is *AFTER/INSIDE* the enhanced UI div, and yet
// TODO the behaviors for the enhanced UI need to have an updated environment
// TODO to point to that new replaced view and its row checkboxes.
// TODO
// TODO So, when we are called with new pageContext, we really need to
// TODO backup and rebuild the environment for the whole UI for that view.
// TODO That requires that we unattach our previous attached behaviors,
// TODO then regather everything and attach new behaviors.
// TODO
      $(sel, pageContext).each( function () {
        var jform = $(this);
        var newenv;

        // Find base UI elements.
        var env = Drupal.foldershare.enhancedui.gatherBaseUI(jform, {});
        if (typeof env === 'undefined') {
          return true;
        }

        // Find enhanced UI elements.
        newenv = Drupal.foldershare.enhancedui.gatherEnhancedUI(jform, env);
        if (typeof newenv === 'undefined') {
          Drupal.foldershare.enhancedui.showBaseUI(env);
          return true;
        }
        env = newenv;

        // Find parent attributes.
        newenv = Drupal.foldershare.enhancedui.gatherParentAttributes(env);
        if (typeof newenv === 'undefined') {
          Drupal.foldershare.enhancedui.showBaseUI(env);
          return true;
        }
        env = newenv;

        // Find menu item attributes.
        newenv = Drupal.foldershare.enhancedui.gatherMenuAttributes(env);
        if (typeof newenv === 'undefined') {
          Drupal.foldershare.enhancedui.showBaseUI(env);
          return true;
        }
        env = newenv;

        // Build enhanced UI.
        if (Drupal.foldershare.enhancedui.buildEnhancedUI(env) === false) {
          Drupal.foldershare.enhancedui.showBaseUI(env);
          return true;
        }

        // Hide base UI and show enhanced UI.
        Drupal.foldershare.enhancedui.showEnhancedUI(env);
      });

      return true;
    },

    /*--------------------------------------------------------------------
     *
     * Gather environment.
     *
     * The "environment" array created and updated by these functions
     * contains jQuery objects and assorted attributes gather from the
     * plugin's elements. Once gathered, these are passed among behavior
     * functions so they can operate upon them.
     *
     *--------------------------------------------------------------------*/

    /**
     * Gathers base UI elements from a view field form.
     *
     * Base UI elements are generated by the "baseui" plugin and are
     * intended for the non-scripted basic user interface. The "enhancedui"
     * plugin scripts (here) bypasses the basic menu and submit button
     * to route to an AJAX-friendly form instead. The "baseui" view table,
     * however, is a key part of the enhanced UI.
     *
     * The base UI is in a separate <form> and <div> that wraps a view.
     * The <div> must contain form fields for a simple <select> menu and
     * a submit button.
     *
     * If the base UI's form, div, or form fields are missing, an error
     * message is issued and undefined returned.
     *
     * If the base UI's fields are all found, the environment is updated
     * with a 'baseui' property and returned.
     *
     * @param jform
     *   The jQuery object for a <form> for the enhanced UI form.
     * @param env
     *   The environment object.
     *
     * @return
     *   Returns an updated environment object, or undefined on an error.
     */
    gatherBaseUI: function(jform, env) {
      // Selectors.
      var id = Drupal.foldershare.enhancedui.baseID;

      var selBaseUI = '.' + id + '-ui';
      var selMenu   = 'select[name=\"' + id + '-command-select\"]';
      var selInputSubmit = 'input[type=\"submit\"]';
      var selButtonSubmit = 'button[type=\"submit\"]';

      // The enhanced UI <form> must be in a parent <div> along side
      // a view.
      var jparent = jform.parent();

      // Find the base UI <div> relative to the parent <div>. The base
      // UI <div> is inside a <form> that wraps the view.
      var jbase = jparent.find(selBaseUI);
      if (jbase.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The base UI <div> is missing.');
        return undefined;
      }

      // Find base form, immediately above base <div>.
      var jbaseform = jbase.parent();
      if (jbaseform.is('form') === false) {
        Drupal.foldershare.enhancedui.malformedError(
          'The base UI <form> that should wrap the view is missing.');
        return undefined;
      }

      // Find the view table. 
      //
      // A problem here is how to identify the table. Created by Views,
      // and labeled using Views' "views-view-table.html.twig" template,
      // the table is given no distinct class. Core themes, like "Classy"
      // and "Stable" override this template, but only "Classy" adds a
      // "views-table" class name to the table. Non-core themes may or may
      // not provide their own templates, and may or may not add a class
      // to the table. The result is that there is no well-known
      // theme-independent class we can use to identify the table inside
      // the view.
      //
      // For most themes, the nesting order is this:
      //  <div class="views-form">
      //    <form>
      //      <table>
      //
      // But for some themes, and particularly those using Bootstrap,
      // the <table> gets wrapped in a new <div> to mark the table as
      // responsive. This changes the nesting to be:
      //  <div class="views-form">
      //    <form>
      //      <div>
      //        <table>
      //
      // Naturally, it would be great if we could mark the table with
      // our own class, but this is not possible, or at least not
      // simple. We are therefore forced to make guesses and search
      // for the outermost <table> we can find, nested under <form>.
      var jtable = jbaseform.children('table');
      if (jtable.length === 0) {
        var jtable = jbaseform.find('table');
        if (jtable.length === 0) {
          Drupal.foldershare.enhancedui.malformedError(
            'The <table> that should contain view rows is missing.');
          return undefined;
        }
      }

      if (jtable.length > 0) {
        // If there are multiple tables, then we want the first one.
        jtable = jtable.eq(0);
      }

      // Find the base command menu <select>.
      var jselect = jbaseform.find(selMenu);
      if (jselect.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The base UI <select> command menu in the view form is missing.');
        return undefined;
      }

      // Find the submit <input> or <button>.
      //
      // A problem here is that Drupal normally generates <input>
      // for submit buttons. But some themes (such as Bootstrap)
      // change these into <button>. Functionally they're pretty
      // similar, but <input> can only show text, while <button>
      // can wrap anything.
      //
      // Since we don't know if a theme has made this change, we
      // need to look for either <input> or <button>.
      var jsubmit = jbaseform.find(selInputSubmit);
      if (jsubmit.length === 0) {
        jsubmit = jbaseform.find(selButtonSubmit);
        if (jsubmit.length === 0) {
          Drupal.foldershare.enhancedui.malformedError(
            'The base UI submit button is missing.');
          return undefined;
        }
      }

      // Update the environment.
      env['baseui'] = {
        'div':    jbase,
        'form':   jbaseform,
        'table':  jtable,
        'select': jselect,
        'submit': jsubmit
      };

      return env;
    },

    /**
     * Gathers enhanced UI elements from the form.
     *
     * The UI's <form> contains a set of fields that all must be
     * present in order for the UI to work. These include a command
     * menu, a menu button, a field to hold the chosen command,
     * a field to hold the selection of entities for the command,
     * a file field for file uploads, and a form submit button.
     *
     * If any of the fields are missing, an error message is issued
     * and undefined returned.
     *
     * If all fields are present, an 'enhancedui' property is added to
     * the environment and the updated environment returned.
     *
     * @param jform
     *   The jQuery object for a <form> for the enhanced UI form.
     * @param env
     *   The environment object.
     *
     * @return
     *   Returns an updated environment object, or undefined on an error.
     */
    gatherEnhancedUI: function(jform, env) {
      //
      // Setup
      // -----
      // Get a few preliminary values.
      var id = Drupal.foldershare.enhancedui.enhancedID;

      var uploadClass     = id + '-command-upload';
      var selInputSubmit  = 'input[type=\"submit\"]';
      var selButtonSubmit = 'button[type=\"submit\"]';
      var selUpload       = 'input[name="files[' + uploadClass + '][]"]';
      var selCommand      = 'input[name="' + id + '-command"]';
      var selSelection    = 'input[name="' + id + '-command-selection"]';
      var selUI           = '.' + id + '-ui';
      var selMenu         = '.' + id + '-command-menu';
      var selButton       = '.' + id + '-command-menu-button';

      //
      // Find elements
      // -------------
      // Find each of the required elements within the form.
      //
      // Find the container <div> within the form.
      var jdiv = jform.find(selUI);
      if (jdiv.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The enhanced UI <div> is missing.');
        return undefined;
      }

      // Find the submit <input> or <button>.
      //
      // A problem here is that Drupal normally generates <input>
      // for submit buttons. But some themes (such as Bootstrap)
      // change these into <button>. Functionally they're pretty
      // similar, but <input> can only show text, while <button>
      // can wrap anything.
      //
      // Since we don't know if a theme has made this change, we
      // need to look for either <input> or <button>.
      var jsubmit = jform.find(selInputSubmit);
      if (jsubmit.length === 0) {
        jsubmit = jform.find(selButtonSubmit);
        if (jsubmit.length === 0) {
          Drupal.foldershare.enhancedui.malformedError(
            'The enhanced UI submit button is missing.');
          return undefined;
        }
      }

      // Find the upload files <input>. The field's name uses array syntax
      // imposed by the Drupal file module.
      var jupload = jform.find(selUpload);
      if (jupload.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The enhanced UI file input field is missing.');
        return undefined;
      }

      // Find the command ID <input>.
      var jcommand = jform.find(selCommand);
      if (jcommand.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The enhanced UI command ID field is missing.');
        return undefined;
      }

      // Find the selection IDs <input>.
      var jselection = jform.find(selSelection);
      if (jselection.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The enhanced UI selection IDs field is missing.');
        return undefined;
      }

      // Find the hierarchical menu <ul>.
      var jmenu = jform.find(selMenu);
      if (jmenu.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The enhanced UI command menu is missing.');
        return undefined;
      }

      // Find the menu button <button>.
      var jbutton = jform.find(selButton);
      if (jbutton.length === 0) {
        Drupal.foldershare.enhancedui.malformedError(
          'The enhanced UI command menu button is missing.');
        return undefined;
      }

      //
      // Return updated environment
      // --------------------------
      // Add the enhanced UI attributes and return.
      env['enhancedui'] = {
        'div':       jdiv,
        'form':      jform,
        'submit':    jsubmit,
        'upload':    jupload,
        'command':   jcommand,
        'selection': jselection,
        'menu':      jmenu,
        'button':    jbutton,
      };

      return env;
    },

    /**
     * Gathers parent attributes from a view area form.
     *
     * The <div> for the UI must be marked with attributes of the parent
     * entity (usually a folder), including its entity ID, kind, and
     * the user's access permissions for it.
     *
     * If the gathered attributes are missing or malformed, an error
     * message is output to the console and undefined is returned.
     *
     * If the gathered attributes are found and are well-formed, the
     * environment is updated to include a 'parent' property. The
     * updated environment is returned.
     *
     * @param env
     *   The environment object.
     *
     * @return
     *   Returns an updated environment object, or undefined on an error.
     */
    gatherParentAttributes: function(env) {
      //
      // Setup
      // -----
      // Get a few preliminary values.
      var id   = Drupal.foldershare.enhancedui.enhancedID;
      var data = 'data-' + id + '-';
      var jdiv = env['enhancedui']['div'];

      //
      // Gather attributes
      // -----------------
      // Find the parent entity ID, kind, and access.  Issue an error
      // message and return undefined if any of these are missing.
      var parentId     = jdiv.attr(data + 'parentid');
      var parentKind   = jdiv.attr(data + 'kind');
      var parentAccess = jdiv.attr(data + 'access');

      if (typeof parentId === 'undefined') {
        Drupal.foldershare.enhancedui.malformedError(
          'The parent folder ID attribute is missing.');
        return undefined;
      }

      if (typeof parentKind === 'undefined') {
        Drupal.foldershare.enhancedui.malformedError(
          'The parent kind attribute is missing.');
        return undefined;
      }

      if (typeof parentAccess === 'undefined') {
        Drupal.foldershare.enhancedui.malformedError(
          'The parent access attribute is missing.');
        return undefined;
      }

      //
      // Decode attributes
      // -----------------
      // Some attributes are JSON arrays, URI encoded. Decode them. Issue
      // an error message and return null if any of these are malformed.
      // Decode parent kind.
      parentKind = decodeURIComponent(parentKind);

      try {
        parentAccess = JSON.parse(decodeURIComponent(parentAccess));
      }
      catch (err) {
        Drupal.foldershare.enhancedui.malformedError(
          'The parent access attribute is malformed.');
        return undefined;
      }

      //
      // Return updated environment
      // --------------------------
      // Add the parent attributes and return.
      env['parent'] = {
        'id':     parentId,
        'kind':   parentKind,
        'access': parentAccess
      };

      return env;
    },

    /**
     * Gathers menu attributes.
     *
     * The menu is made up of a hierarchy of menu items. For each menu
     * item, the item's associated command and command attributes are
     * retreived and validated in the current page environment (particularly
     * the parent entity).
     *
     * Menu items that are malformed or invalid for the current page
     * are marked as disabled and not included in the environment's list
     * of available commands.
     *
     * Menu items that are well-formed and valid are added to the environment's
     * list of available commands.
     *
     * The updated environment containing a 'command' property is returned.
     *
     * @param env
     *   The environment object.
     *
     * @return
     *   Returns an updated environment object.
     */
    gatherMenuAttributes: function(env) {
      //
      // Setup
      // -----
      // Get a few preliminary values.
      var id          = Drupal.foldershare.enhancedui.enhancedID;
      var selMenuItem = '.' + id + '-command-menu-item';
      var jmenu       = env['enhancedui']['menu'];

      env['command'] = {};

      //
      // Gather menu items
      // -----------------
      // Loop through all menu items. For each one, gather its attributes,
      // validate them, and add them to the environment's command list.
      jmenu.find(selMenuItem).each( function() {
        var jitem = $(this);

        // Gather attributes for the menu item.
        var cmd = Drupal.foldershare.enhancedui.gatherMenuItemAttributes(jitem);
        if (cmd === null) {
          Drupal.foldershare.enhancedui.markBrokenMenuItem(jitem);
          return true;
        }

        if (Drupal.foldershare.enhancedui.validateMenuItem(env, cmd) === false) {
          Drupal.foldershare.enhancedui.markUnavailableMenuItem(jitem);
          return true;
        }

        // Update the environment. This command is complete and usable
        // with this parent.
        env['command'][cmd['id']] = cmd;
      });

      //
      // Return updated environment
      // --------------------------
      // Return the environment with valid commands added.
      return env;
    },

    /**
     * Gathers menu item attributes.
     *
     * Each menu item must have special data attributes the give the
     * name (id) of the command and constraints on where that command
     * may be used. All of these attributes must exist and must be
     * well-formed. If any of them do not exist, a null is returned.
     * A console error message is also shown.
     *
     * If everything for a command exists, an array is returned that
     * contains the command's attributes.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     *
     * @return
     *   Returns an array describing the command, or a null on an error.
     */
    gatherMenuItemAttributes: function(jitem) {
      //
      // Setup
      // -----
      // Get menu item text in the innermost child of the item.
      var j = jitem;
      var child;
      while ((child = j.find(':first-child')).length !== 0) {
        j = child;
      }
      var label = j.text();

      // If the text is a '-', this is a separator menu item and ignored.
      if (label === '-') {
        // Never enabled.
        return null;
      }

      //
      // Get attributes
      // --------------
      // Find the item's command ID and attributes.  Issue an error
      // message and return null if any of these are missing.
      var id   = Drupal.foldershare.enhancedui.enhancedID;
      var data = 'data-' + id + '-';

      var commandId            = jitem.attr(data + 'command');
      var parentConstraints    = jitem.attr(data + 'parent-constraints');
      var selectionConstraints = jitem.attr(data + 'selection-constraints');
      var specialHandling      = jitem.attr(data + 'special-handling');

      if (typeof commandId === 'undefined') {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" is missing its command ID.');
        return null;
      }

      if (typeof parentConstraints === 'undefined') {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" is missing its parent constraints.');
        return null;
      }

      if (typeof selectionConstraints === 'undefined') {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" is missing its selection constraints.');
        return null;
      }

      if (typeof specialHandling === 'undefined') {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" is missing its special handling flags.');
        return null;
      }

      //
      // Decode attributes
      // -----------------
      // Some attributes are JSON arrays, URI encoded. Decode them. Issue
      // an error message and return null if any of these are malformed.
      var testing = '';
      try {
        testing = 'parent constraints'
        parentConstraints = JSON.parse(decodeURIComponent(parentConstraints));
        testing = 'selection constraints'
        selectionConstraints = JSON.parse(decodeURIComponent(selectionConstraints));
        testing = 'special handling flags'
        specialHandling = JSON.parse(decodeURIComponent(specialHandling));
      }
      catch (err) {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" has malformed ' + testing + '.');
        return null;
      }

      //
      // Validate attributes
      // -------------------
      // Check that the attributes are complete. Issue an error message and
      // return null if any of these are malformed.
      if (parentConstraints['kinds'] === 'undefined' ) {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" has malformed parent constraints kind.');
        return null;
      }

      if (parentConstraints['access'] === 'undefined' ) {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" has malformed parent constraints access.');
        return null;
      }

      if (selectionConstraints['kinds'] === 'undefined' ) {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" has malformed selection constraints kinds.');
        return null;
      }

      if (selectionConstraints['access'] === 'undefined' ) {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" has malformed selection constraints access.');
        return null;
      }

      if (selectionConstraints['types'] === 'undefined' ) {
        Drupal.foldershare.enhancedui.malformedMenuError(
          'Menu item "' + label + '" has malformed selection constraints types.');
        return null;
      }

      //
      // Return command description
      // --------------------------
      // Return the validated command.
      return {
        'id':                   commandId,
        'item':                 jitem,
        'label':                jitem.text(),
        'parentConstraints':    parentConstraints,
        'selectionConstraints': selectionConstraints,
        'specialHandling':      specialHandling
      };
    },

    /**
     * Validates a menu item in the current environment.
     *
     * The parent is a fixed part of the environment for this page.
     * Validate that the menu item is suitable for use with this parent,
     * given the parent's kind and access permissions.
     *
     * If the command is not valid for this parent, return false.
     * Otherwise return true.
     *
     * @param env
     *   The environment object.
     *
     * @return
     *   Returns true if valid, and false otherwise.
     */
    validateMenuItem: function(env, cmd) {
      // Compare the parent kind against the list of supported kinds
      // for the command. These cases:
      // - parentKind == 'none'. Be sure the command accepts 'none'.
      // - parentKind != 'none'. Be sure the command accepts the parent's
      //   kind or 'any'.
      var parentKind = env['parent']['kind'];
      var kinds      = cmd['parentConstraints']['kinds'];

      if (parentKind === 'none' && ($.inArray('none', kinds) === (-1))) {
        // This page has no parent (i.e. it is a list of root folders)
        // AND this command does not support having no parent.
        // Never enabled on this page.
        return false;
      }

      if ($.inArray(parentKind, kinds) === (-1) &&
           $.inArray('any', kinds) === (-1)) {
        // This page's parent kind is not in the command's supported list
        // AND this command doesn't include the generic 'any' kind.
        // Never enabled on this page.
        return false;
      }

      // Determine if the menu item is ever valid with the current access.
      var parentAccesses = env['parent']['access'];
      var access         = cmd['parentConstraints']['access'];

      if (access !== 'none' && parentKind !== 'none' &&
          $.inArray(access, parentAccesses) === (-1)) {
        // The command requires specific access permissions on the parent
        // (because access is not 'none'), AND there is a parent, AND
        // that parent does not provide the necessary access.
        // Never enabled on this page.
        return false;
      }

      // Determine if parent allows creation, if needed.
      var createRequired =
        $.inArray('create', cmd['specialHandling']) !== (-1) ||
        $.inArray('createroot', cmd['specialHandling']) !== (-1);

      var createAllowed =
        $.inArray('create', env['parent']['access']) !== (-1) ||
        $.inArray('createroot', env['parent']['access']) !== (-1);

      if (createRequired === true && createAllowed === false) {
        // The command requires create or createroot access
        // AND the parent does not allow that.
        // Never enabled on this page.
        return false;
      }

      return true;
    },

    /*--------------------------------------------------------------------
     *
     * Build.
     *
     *--------------------------------------------------------------------*/

    /**
     * Builds the enhanced UI.
     *
     * @param env
     *   The environment object.
     *
     * @return
     *   Returns an updated environment object, or undefined on an error.
     */
    buildEnhancedUI: function(env) {
      // Selectors.
      var id = Drupal.foldershare.enhancedui.enhancedID;

      var data    = 'data-' + id + '-command';
      var jmenu   = env['enhancedui']['menu'];
      var jbutton = env['enhancedui']['button'];
      var jtable  = env['baseui']['table'];

      // Create menu.
      jmenu.menu();

      // Create button.
      jbutton.button();

      // Attach row selection behaviors.
      Drupal.foldershare.enhancedui.attachSelection(jtable);

      // Attach button behavior to show menu.
      jbutton.once('enhancedui').click( function (event) {
        // If menu visible, hide it and we're done.
        if (jmenu.menu().is(":visible")) {
            jmenu.menu().hide();
            return false;
        }

        // Enable/disable menu items based on selection, then show menu.
        Drupal.foldershare.enhancedui.updateMenu(env);
        jmenu.show().position({
          my:        "left top",
          at:        "left bottom",
          of:        event.target,
          collision: "fit"
        });

        // Register a one-time handler to catch a off-menu click to hide it.
        $(document).on("click", function (event) {
          jmenu.menu().hide();
        } );

        return false;
      });

      // Attach menu item behavior to trigger command.
      jmenu.once('enhancedui').on('menuselect', function (event, ui) {
        // Insure the menu is hidden.
        jmenu.menu().hide();

        // Get the ID of the selected command.
        var commandId = $(ui.item).attr(data);

        // Set the command ID.
        env['enhancedui']['command'].val(commandId);

        // Set the selection. The selection maintained in this script is
        // an associative array with one key for each kind of item found
        // (e.g. 'folder', 'file'). The value for each key is an array of
        // objects that each contain an entity ID and access grants.
        //
        // HOWEVER, the selection we need to return to the server is
        // a simple array of entity IDs.
        var selection = Drupal.foldershare.enhancedui.getSelection(jtable);
        var selectionIds = [];
        for (var kind in selection) {
          for (var item in selection[kind]) {
            selectionIds.push(Number(selection[kind][item]['id']));
          }
        }
        env['enhancedui']['selection'].val(JSON.stringify(selectionIds));

        // Get the command's special handling.
        var specialHandling = env['command'][commandId]['specialHandling'];

        // If it does a file upload, prompt for files.
        if ($.inArray('upload', specialHandling) !== (-1)) {
          // Invoke the file upload. This returns immediately,
          // so command triggering occurs when the dialog is closed.
          env['enhancedui']['upload'].click();
          return true;
        }

        // Submit form.
        env['enhancedui']['upload'].val('');
        if (drupalSettings[id]['formAjaxEnabled'] === true) {
          env['enhancedui']['submit'].submit();
        }
        else {
          env['enhancedui']['form'].submit();
        }
        env['enhancedui']['form'][0].reset();
        return true;
      });

      // Attach file upload behavior to trigger upload command.
      env['enhancedui']['upload'].change( function (event, ui) {
        if (drupalSettings[id]['formAjaxEnabled'] === true) {
          env['enhancedui']['submit'].submit();
        }
        else {
          env['enhancedui']['form'].submit();
        }
        env['enhancedui']['form'][0].reset();
      });

      return env;
    },

    /*--------------------------------------------------------------------
     *
     * Show/hide UI.
     *
     *--------------------------------------------------------------------*/

    /**
     * Quickly finds and hides the base UI.
     *
     * This is used primarily at script load (before page ready) to quickly
     * hide base UI items this script intends to replace. If the script
     * later has problems with the page, it will un-hide these items so
     * that the base UI is functional.
     *
     * @param pageContext
     *   The page context for this call. Initially, this is the full document.
     *   Later, this is only portions of the document added via AJAX.
     */
    quickHideBaseUI: function(pageContext) {
      var id = Drupal.foldershare.enhancedui.baseID;
      var selBaseUI = '.' + id + '-ui';
      var selCheckboxCol = '.' + Drupal.foldershare.enhancedui.checkboxColumn;

      $(selBaseUI, pageContext).addClass('hidden');
      $(selCheckboxCol, pageContext).addClass('hidden');
    },

    /**
     * Hides the base UI.
     *
     * @param env
     *   The environment object.
     */
    hideBaseUI: function(env) {
      env['baseui']['div'].addClass('hidden');
      env['baseui']['select'].addClass('hidden');
      env['baseui']['submit'].addClass('hidden');
      var selCheckboxCol = '.' + Drupal.foldershare.enhancedui.checkboxColumn;
      env['baseui']['table'].find(selCheckboxCol).addClass('hidden');
    },

    /**
     * Shows the base UI.
     *
     * @param env
     *   The environment object.
     */
    showBaseUI: function(env) {
      env['baseui']['div'].removeClass('hidden');
      env['baseui']['select'].removeClass('hidden');
      env['baseui']['submit'].removeClass('hidden');
      var selCheckboxCol = '.' + Drupal.foldershare.enhancedui.checkboxColumn;
      env['baseui']['table'].find(selCheckboxCol).removeClass('hidden');
    },

    /**
     * Shows the enhanced UI.
     *
     * @param env
     *   The environment object.
     */
    showEnhancedUI: function(env) {
      env['enhancedui']['div'].removeClass('hidden');

      env['enhancedui']['menu'].menu().hide();
      env['enhancedui']['menu'].removeClass('hidden');

      env['enhancedui']['button'].button().show();
      env['enhancedui']['button'].removeClass('hidden');
    },

    /*--------------------------------------------------------------------
     *
     * Selection.
     *
     * These functions collect the list of selected items within the
     * view's table. The selection is queried repeatedly to note what
     * is currently selected and how that affects what user interface
     * features are enabled.
     *
     *--------------------------------------------------------------------*/

    /**
     * Attaches a table row selection behavior and hides checkboxes.
     *
     * @param jtable
     *   The table to which to attach row select behaviors.
     */
    attachSelection: function(jtable) {
      // Selectors.
      var selCheckbox    = 'td input[type="checkbox"]:enabled';
      var selCheckboxCol = '.' + Drupal.foldershare.enhancedui.checkboxColumn;

      // For each table row with an enabled checkbox, support selection
      // of the entire row. Selection checks the checkbox.
      jtable.find(selCheckbox).closest('tr').once('table-row-select').on('click',
        function (event) {
          var row        = $(this);
          var checkbox   = row.find('td input[type="checkbox"]');
          var checkState = checkbox.prop('checked');
          checkbox.prop('checked', !checkState);
          row.toggleClass('selected', !checkState);
          return true;
        });

      // For each table row that is already selected, highlight the row.
      jtable.find(selCheckbox).each(
        function (index, element) {
          var checkbox   = $(this);
          var row        = checkbox.closest('tr');
          var checkState = checkbox.prop('checked');
          row.toggleClass('selected', checkState);
        });
    },

    /**
     * Returns an array containing the current selection.
     *
     * The view table is scanned for selected rows. The entity ID, kind, and
     * access information for each selected row are extracted and used to
     * bin entities into an associative array indexed by entity kind.
     * For a kind (e.g. 'folder'), the array contains an array of objects
     * with one object per row of that kind. The object contains the
     * entity ID and the entity's array of access grants (e.g. 'view',
     * 'update').
     *
     * @param jtable
     *   The Jquery object for the table from which to get the selection.
     *
     * @return
     *   The returned associative array contains one key for each kind
     *   found. The value for each key is an array of objects that each
     *   contain an entity ID and access grants for that entity.
     */
    getSelection: function(jtable) {
      // Selectors.
      //
      // Implementation note:  The <table> containing the view has checkboxes
      // and row attributes added by the Base UI plugin, not the Enhanced UI
      // plugin. The plugin ID and attribute names for table row data are
      // therefore for the Base UI.
      var id = Drupal.foldershare.enhancedui.baseID;
      var data       = 'data-' + id + '-';
      var selChecked = 'input[type="checkbox"]:checked';

      // Loop through the table, finding all selected rows. For each row,
      // get the entity ID, kind, and access grants and add them to the result.
      var result = { };
      jtable.find(selChecked).each(
        function (index, element) {
          // Get the entity ID, kind, and access for the entity on the row.
          // If any of these is missing, the row is malformed and ignored.
          var entityId = $(this).attr('value');
          var kind     = $(this).attr(data + 'kind');
          var access   = $(this).attr(data + 'access');

          if (typeof entityId === 'undefined' ||
            typeof kind       === 'undefined' ||
            typeof access     === 'undefined' ) {
            return true;
          }

          // Decode the kind and access, and parse the access into JSON.
          // If this fails, the row is malformed and ignored.
          kind = decodeURIComponent(kind);

          try {
            access = JSON.parse(decodeURIComponent(access));
          }
          catch (err) {
            return true;
          }

          // Add this row into the selection. Use the row kind to group
          // rows, and save the entity ID and access array.
          if (typeof result[kind] === 'undefined') {
            result[kind] = [];
          }

          result[kind].push({
            "id":     entityId,
            "access": access
          });

          return true;
        });

      return result;
    },

    /*--------------------------------------------------------------------
     *
     * Menu.
     *
     * These functions manage the command menu. Individual items in the
     * menu may be enabled or disabled based upon the current selection.
     * Some menu items may be permanently disabled if they are not available
     * for the current view's parent (usually a folder) or the user's
     * permissions on that parent. Menu items also may be permanently
     * disabled if something is wrong on the page.
     *
     *--------------------------------------------------------------------*/

    /**
     * Returns true if the menu item is a separator.
     *
     * Menu items are marked as separators by jQuery on construction of
     * the menu.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     */
    isSeparatorMenuItem: function(jitem) {
      return jitem.hasClass('ui-menu-divider');
    },

    /**
     * Returns true if the menu item is broken (permanently disabled).
     *
     * @param jitem
     *   The jQuery object for the menu item.
     *
     * @see markBrokenMenuItem()
     */
    isBrokenMenuItem: function(jitem) {
      return jitem.hasClass('ui-state-broken');
    },

    /**
     * Returns true if the menu item is unavailable (permanently disabled).
     *
     * @param jitem
     *   The jQuery object for the menu item.
     *
     * @see markUnavailableMenuItem()
     */
    isUnavailableMenuItem: function(jitem) {
      return jitem.hasClass('ui-state-unavailable');
    },

    /**
     * Returns true if the menu item is enabled.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     *
     * @see enableMenuItem()
     */
    isEnabledMenuItem: function(jitem) {
      return jitem.hasClass('ui-state-enabled');
    },

    /**
     * Returns true if the menu item is disabled.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     *
     * @see disableMenuItem()
     */
    isDisabledMenuItem: function(jitem) {
      return jitem.hasClass('ui-state-disabled');
    },

    /**
     * Enables the given menu item.
     *
     * An enabled menu item can be chosen at this time. It may be
     * disabled later on as the selection changes.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     */
    enableMenuItem: function(jitem) {
      jitem.removeClass('ui-state-disabled');
      jitem.addClass('ui-state-enabled');
    },

    /**
     * Disables the given menu item.
     *
     * A disabled menu item cannot be chosen at this time. It may be
     * enabled later on as the selection changes.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     */
    disableMenuItem: function(jitem) {
      jitem.removeClass('ui-state-enabled');
      jitem.addClass('ui-state-disabled');
    },

    /**
     * Marks the menu item as permanently broken.
     *
     * A broken command is one that was not properly described on the
     * page. It was missing one or more attributes that are needed to
     * validate its use with the current parent or selection.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     */
    markBrokenMenuItem: function(jitem) {
      jitem.removeClass('ui-state-enabled');
      jitem.addClass('ui-state-disabled');
      jitem.addClass('ui-state-broken');
    },

    /**
     * Marks the menu item as permanently unavailable.
     *
     * An unavailable command is properly formed, but invalid for the
     * current parent (usually a folder) or for this user and their
     * permissions on that parent. For example, if there is no parent,
     * then any command that requires a parent is unavailable. Or if
     * the user has no author permission on the parent, then any
     * command that requires changing the parent (e.g. editing or
     * renaming it) will be marked as unavailable.
     *
     * @param jitem
     *   The jQuery object for the menu item.
     */
    markUnavailableMenuItem: function(jitem) {
      jitem.removeClass('ui-state-enabled');
      jitem.addClass('ui-state-disabled');
      jitem.addClass('ui-state-unavailable');
    },

    /**
     * Updates the command menu to enable/disable items based on context.
     *
     * For each menu item, the current selection context is checked to see
     * if the menu command is usable. If not, the menu item is disabled.
     *
     * @param env
     *   The environment object.
     */
    updateMenu: function(env) {
      // Selectors.
      var id = Drupal.foldershare.enhancedui.enhancedID;
      var data     = 'data-' + id + '-command';
      var jmenu    = env['enhancedui']['menu'];
      var jtable   = env['baseui']['table'];

      // Get the selection, which may be empty.
      var selection = Drupal.foldershare.enhancedui.getSelection(jtable);

      // Count the total number of selected items. Also track the number
      // of different kinds of items in the selection. If there is just
      // one kind of items, note what kind that is.
      var nSelected = 0;
      var nKinds = 0;
      var kind = '';
      for (var k in selection) {
        var len = selection[k].length;
        nSelected += len;
        if (len > 0) {
          nKinds++;
          kind = k;
        }
      }

      // Prepare text to substitute into enabled menu commands in place
      // of the '@operand' marker. This text describes the current
      // selection's items
      var operand = '';

// TODO
// TODO This needs use server-side translated, title case, and pluralized terms.
// TODO
      if (nKinds > 1) {
        // The selection has a mix of items of different kinds.  Use a
        // generic plural term.
        operand = 'Items';
      }
      else if (nSelected > 1) {
        // The selection has just one kind of item, but there is more
        // than one of them. Simplify the kind name and make it plural.
        if (kind === 'rootfolder') {
          kind = 'folder';
        }

        var kind = kind.replace(/\w\S*/g, function(txt) {
          return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
        });

        operand = kind + 's';
      }
      else if (nSelected === 1) {
        // The selection has just one kind of item and there is just one
        // of them. Use the kind name.
        if (kind === 'rootfolder') {
          kind = 'folder';
        }

        operand = kind.replace(/\w\S*/g, function(txt) {
          return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
        });
      }
      else {
        // There is no selection, so there is no kind. Instead, use the
        // parent's kind (if there is a parent).
        parentKind = env['parent']['kind'];
        if (parentKind === 'rootfolder') {
          parentKind = 'folder';
        }

        var parentKind = parentKind.replace(/\w\S*/g, function(txt) {
          return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
        });

        operand = 'This ' + parentKind;
      }

      // Loop through the menu and enable items that are suitable for the
      // current selection, and disable those that are not.
      var selMenuItem = '.' + id + '-command-menu-item';
      jmenu.find(selMenuItem).each( function() {
        var jitem = $(this);

        // Ignore menu items that are separators, broken, or unavailable.
        // These were marked as disabled when the menu was first built.
        if (Drupal.foldershare.enhancedui.isSeparatorMenuItem(jitem) === true ||
          Drupal.foldershare.enhancedui.isBrokenMenuItem(jitem)      === true ||
          Drupal.foldershare.enhancedui.isUnavailableMenuItem(jitem) === true) {
          return true;
        }

        // Get the command ID of the menu item.
        var commandId = jitem.attr(data);
        if (typeof commandId === 'undefined') {
          // Odd. Ignore it.
          return true;
        }

        // Earlier, when the menu was built, all commands in the menu were
        // reviewed. Any command that could never be used on this page
        // because of failure to satisfy its parent or access requirements,
        // would have been disabled AND removed from the environment. Above
        // we already checked for disabled, but let's be redundant. If the
        // command is not in the environment, then it is not enabled and
        // need not be considered now.
        if (typeof env['command'][commandId] === 'undefined') {
          // Missing from environment. Make sure it is disabled.
          Drupal.foldershare.enhancedui.disableMenuItem(jitem);
          return true;
        }

        // Validate the selection against those constraints.
        if (Drupal.foldershare.enhancedui.checkSelectionConstraints(
          env,
          nSelected,
          selection,
          commandId) === false) {
          // The command is not enabled in this context. Perhaps the
          // selection doesn't match what the command needs, or the
          // access permissions aren't right.
          //
          // In any case, we need to:
          // - Mark the menu item as disabled.
          // - Make its menu text generic.
          Drupal.foldershare.enhancedui.disableMenuItem(jitem);

          // Generic text is encoded as an attribute on the menu item.
          // Get it and replace the user-visible text with that generic text.
          var genericText = decodeURIComponent(
            jitem.attr('data-' + id + '-menu-base'));

          jitem.empty();
          jitem.append('<div>' + genericText + '</div>');
        }
        else {
          // The command is enabled in this context. The selection must
          // have satisfied the command's constraints, or perhaps it doesn't
          // need a selection.
          //
          // In any case, we need to:
          // - Mark the command as enabled.
          // - Make its menu text specific to the selection.
          Drupal.foldershare.enhancedui.enableMenuItem(jitem);

          // Specific menu text, with a '@operand' placeholder, is encoded
          // as an attribte on the men item. Get it, substitute '@operand'
          // with a suitable comment on the selection, then replace the
          // user-visible text of the menu item with the new text.
          var specificText = decodeURIComponent(
            jitem.attr('data-' + id + '-menu-operand'));

          specificText = specificText.replace('@operand', operand);

          jitem.empty();
          jitem.append('<div>' + specificText + '</div>');
        }
      });
    },

    /*--------------------------------------------------------------------
     *
     * Validate.
     *
     *--------------------------------------------------------------------*/

    /**
     * Validates that the selection meets a command's constraints.
     *
     * Each command has selection constraints that limit the command to
     * apply only to files, folders, or root folders, or some combination
     * of these.
     *
     * This mimics similar checking on the server and is used to disable
     * command menu items that cannot be chosen in the current context.
     *
     * @param env
     *   The environment object.
     * @param nSelected
     *   The number of items selected.
     * @param selection
     *   An array of selected entity kinds, each with an array of entity Ids.
     * @param commandId
     *   A ID of the command to check for use with the selection.
     *
     * @return
     *   Returns true if the command's selection constraints are met in this
     *   context, and false otherwise.
     */
    checkSelectionConstraints: function(env, nSelected, selection, commandId) {
      //
      // Setup
      // -----
      // Get the command's selection constraints.
      var constraints = env['command'][commandId]['selectionConstraints'];

      //
      // Check selection size
      // --------------------
      // Insure the selection size is compatible with the command.
      //
      // If the command does not use a selection, then we can skip all of this.
      var types = constraints['types'];

      if ($.inArray('none', types) !== (-1)) {
        // When the command does not use a selection, it should only be
        // enabled when there is no selection.
        if (nSelected === 0) {
          return true;
        }
        return false;
      }
      var selectionIsParent = false;

      // The command uses a selection, so check it.
      if (nSelected === 0) {
        // There is no selection.
        //
        // Does the command support defaulting to operating on the parent,
        // if there is one?
        var parentId = Number(env['parent']['id']);
        if (parentId !== (-1) && $.inArray('parent', types) !== (-1)) {
          // There is a parent, and this command accepts defaulting the
          // selection to the parent. So create a fake selection for the
          // remainder of this function's checking.
          var parentKind = env['parent']['kind'];
          selection[parentKind] = {
            "id":     parentId,
            "access": env['parent']['access'],
          };
          nSelected = 1;
          selectionIsParent = true;
        }
        else {
          // The command does not default to the parent when there is no
          // selection, and we already checked that the command does not
          // work when there is no selection.
          return false;
        }
      }
      else if (nSelected === 1) {
        // There is a single item selected
        if ($.inArray('one',  types) === (-1)) {
          // But the command does not support having just one item.
          return false;
        }
      }
      else {
        // There are multiple items selected.
        if ($.inArray('many', types) === (-1)) {
          // But the command does not support having multiple items.
          return false;
        }
      }

      //
      // Check selection kinds
      // ---------------------
      // Insure the kinds of items in the selection are compatible with
      // the command.
      var kinds = constraints['kinds'];

      if ($.inArray('any', kinds) === (-1)) {
        // The command has specific kind requirements since it did not use
        // the catch-all 'any'. Loop through the selection and make sure
        // every kind is supported.
        for (var kind in selection) {
          if ($.inArray(kind, kinds) === (-1)) {
            // Kind not supported by this command.
            if (selectionIsParent === true) {
              selection = {};
            }
            return false;
          }
        }
      }

      //
      // Check selection access
      // ----------------------
      // Insure each selected item allows the command's access.
      var access = constraints['access'];

      if (access !== 'none') {
        // The command has specific access requirements. Loop through
        // the selection and make sure each selected item grants the
        // required access.
        for (var kind in selection) {
          var items = selection[kind];
          for (var i = 0, len = items.length; i < len; ++i) {
            if ($.inArray(access, items[i]['access']) === (-1)) {
              if (selectionIsParent === true) {
                selection = {};
              }
              return false;
            }
          }
        }
      }

      if (selectionIsParent === true) {
        selection = {};
      }

      return true;
    },

    /*--------------------------------------------------------------------
     *
     * Debug utilities
     *
     * These functions print messages to the Javascript console that are
     * intended for use in debugging the script.
     *
     *--------------------------------------------------------------------*/

    /**
     * Prints a malformed page error message to the console.
     *
     * @param body
     *   The body of the message.
     */
    malformedError: function(body = '') {
      Drupal.foldershare.enhancedui.printMessage(
        'Malformed page',
        body + ' The user interface cannot be enabled.');
    },

    /**
     * Prints a malformed menu item error message to the console.
     *
     * @param body
     *   The body of the message.
     */
    malformedMenuError: function(body = '') {
      Drupal.foldershare.enhancedui.printMessage(
        'Malformed menu item',
        body + ' The menu item cannot be enabled.');
    },

    /**
     * Prints a message to the console.
     *
     * The title is printed in bold, followed by optional body text
     * in a normal weight font, indented below the title.
     *
     * @param title
     *   The short title of the message.
     * @param body
     *   The body of the message.
     */
    printMessage: function(title, body = '') {
      console.log('%cFolderShare: ' +
        title + ':%c' + "\n%c" + body + '%c',
        'font-weight: bold',
        'font-weight: normal',
        'padding-left: 2em',
        'padding-left: 0');
    },

    /**
     * Prints a summary of the environment.
     *
     * @param env
     *   The environment object.
     */
    printEnv: function(env) {
      console.log('%cEnvironment:%c',
        'font-weight: bold',
        'font-weight: normal');

      for (var group in env) {
        console.log('%c' + group + ':%c',
          'padding-left: 2em',
          'padding-left: 0');

        for (var subgroup in env[group]) {
          var value = env[group][subgroup];
          if (typeof(value) === 'object') {
            value = 'object';
          }
          console.log('%c' + subgroup + ': ' + value + '%c',
            'padding-left: 4em',
            'padding-left: 0');
        }
      }
    },

    /**
     * Prints a message to the console, followed by the menu state.
     *
     * The title is printed in bold, followed by optional body text
     * in a normal weight font, indented below the title. Below this,
     * each of the menu items is listed along with their current state.
     *
     * @param env
     *   The current environment.
     * @param title
     *   The short title of the message.
     * @param body
     *   The body of the message.
     */
    printMenu: function(env, title, body = '') {
      // Selectors.
      var id = Drupal.foldershare.enhancedui.enhancedID;
      var data     = 'data-' + id + '-command';
      var jmenu    = env['enhancedui']['menu'];

      // Output the message.
      Drupal.foldershare.enhancedui.printMessage(title, body);

      // Output the menu item state.
      console.log('%cMenu:%c',
        'padding-left: 2em',
        'padding-left: 0');

      jmenu.find( jmenu.menu('option', 'items') ).each( function () {
        var commandId = $(this).attr(data);

        var state = '';
        if (Drupal.foldershare.enhancedui.isSeparatorMenuItem($(this)) === true) {
          return true;
        }

        if (Drupal.foldershare.enhancedui.isBrokenMenuItem($(this)) === true) {
          state = 'broken';
        }
        else if (Drupal.foldershare.enhancedui.isUnavailableMenuItem($(this)) === true) {
          state = 'unavailable';
        }
        else if (Drupal.foldershare.enhancedui.isEnabledMenuItem($(this)) === true) {
          state = 'enabled';
        }
        else {
          state = 'disabled';
        }

        console.log('%c' + commandId + ' = %c' + state + '%c%c',
          'padding-left: 4em',
          'font-style: italic',
          'font-style: normal',
          'padding-left: 0');
      });
    },

    /**
     * Prints a message to the console, followed by the selection.
     *
     * The title is printed in bold, followed by optional body text
     * in a normal weight font, indented below the title. Below this,
     * the current selection's IDs are listed.
     *
     * @param env
     *   The current environment.
     * @param string title
     *   The short title of the message.
     * @param string body
     *   The body of the message.
     */
    printSelection: function(env, title, body = '') {
      // Output the message.
      Drupal.foldershare.enhancedui.printMessage(title, body);

      // Output the current selection (if any).
      var jtable = env['baseui']['table'];
      var selection = Drupal.foldershare.enhancedui.getSelection(jtable);
      if (selection.length === 0) {
        console.log('%cSelection: none%c',
          'padding-left: 2em',
          'padding-left: 0');
      }
      else {
        for (var kind in selection) {
          var n = selection[kind].length;
          var ids = [];
          for (var i = 0; i < n; ++i) {
            var entry = selection[kind][i];
            ids.push(Number(entry['id']));
          }
          console.log('%cSelection of ' + kind + ': ' + ids.toString() + '%c',
            'padding-left: 2em',
            'padding-left: 0');
        }
      }
    }
  };

  /*--------------------------------------------------------------------
   *
   * AJAX behaviors.
   *
   *--------------------------------------------------------------------*/




















  /**
   * Optionally refresh content on a dialog close.
   *
   * @param event
   *   The event object.
   * @param dialogSetup
   *   The original dialog setup object.
   * @param jdialog
   *   The jQuery dialog object.
   */
  $(window).on('dialog:afterclose', function (event, dialogSetup, jdialog) {
    // If the dialog's options do not request a refresh-on-close,
    // just silently return.
    var refresh = jdialog.dialog('option',
      Drupal.foldershare.moduleName + 'RefreshAfterClose');

    if (typeof refresh === 'undefined' || refresh !== true) {
      return;
    }

    // If the dialog's options provide a valid view DOM ID, and that view
    // has a refresh handler, use it to refresh the view instead of the
    // entire page.
    var refreshId = jdialog.dialog('option',
      Drupal.foldershare.moduleName + 'RefreshViewDOMID');

    if (typeof refreshId !== 'undefined' && refreshId !== null &&
      refreshId != '') {
      // Use the view DOM ID in a selector and make sure it finds something.
      var jview = $('.js-view-dom-id-' + refreshId);
      if (jview.length > 0) {
        // Make sure the view has an AJAX refresh handler. If AJAX has been
        // disabled on the view, it will not have a handler.
        var handlers = $._data(jview.get(0), 'events');
        if (typeof handlers !== 'undefined' && handlers !== null &&
          typeof handlers['RefreshView'] !== undefined) {
console.log('Refresh view');
          jview.trigger('RefreshView');
          return;
        }
      }
    }

// TODO
// TODO Need to reset form before reload or it tries to resubmit form!
// form.reset();
// TODO
    // Otherwise fall back to reloading the entire page.
    window.location.reload(true);
  });

  /*--------------------------------------------------------------------
   *
   * On script load behaviors.
   *
   *--------------------------------------------------------------------*/

  // Execute immediately on script load. This hides the base UI as soon
  // as possible so that it doesn't flicker onto the page, then off again
  // while we wait for the document "ready" state and run through all pending
  // Drupal behaviors.
  Drupal.foldershare.enhancedui.quickHideBaseUI(document);

  /*--------------------------------------------------------------------
   *
   * On Drupal ready behaviors.
   *
   *--------------------------------------------------------------------*/

  Drupal.behaviors.foldershare = {
    attach: function (pageContext, settings) {
      Drupal.foldershare.attachFormDialogs(pageContext, settings);
      Drupal.foldershare.enhancedui.attachEnhancedUI(pageContext, settings);
    }
  };

})(jQuery, Drupal, drupalSettings);
