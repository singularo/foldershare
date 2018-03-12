<?php

namespace Drupal\foldershare\Entity;

use Drupal\views\EntityViewsData;

use Drupal\foldershare\Entity\FolderShare;

/**
 * Provides descriptive information to views for listing files and folders.
 *
 * The Drupal core "views" module uses views data to guide it in directly
 * querying the entity type's table and interpreting the fields of data
 * contained in the table.
 *
 * This class defines a single getViewsData() method that returns an array
 * describing the entity's fields, along with multiple virtual fields and
 * view field plugins.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 */
class FolderShareViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    //
    // Views data for fields includes a large amount of information
    // built and used by the Views module. Most of it internal information
    // that should rarely be adjusted here.
    //
    // Principal values that may be adjusted here:
    //
    // - $data[TABLE][FIELD]['title'] - a short name shown in the 'Title'
    //   column of fields that may be added to a view.
    //
    // - $data[TABLE][FIELD]['label'] - a short name used as the suggested
    //   column title if the field is added to a view.
    //
    // - $data[TABLE][FIELD]['help'] - a description shown in the
    //   'Description' column to the right of fields that may be added
    //   to a view.
    //
    //
    //
    // Define entity fields
    // --------------------
    // The parent class automatically provides definitions for all fields
    // in the FolderShare entity type.
    //
    // 'title' values are initialized to the field's label.
    //
    // 'label' values are not set and default to 'title' unless set below.
    //
    // 'help' values are initialized to the field's description.
    //
    // Also note that the parent class invokes other modules, such as the
    // Comment module, to add their own entity-related fields. The Comment
    // module, for instance, adds a field for 'Add comment link'.
    $data = parent::getViewsData();

    //
    // Get tables
    // ----------
    // Get the table names. This includes the main folder entity table,
    // and the sub-tables used for variable-length lists of file IDs
    // and user IDs. This also includes the special folder usage table.
    $baseTable        = FolderShare::BASE_TABLE;
    $viewIdsTable     = $baseTable . '__grantviewuids';
    $authorIdsTable   = $baseTable . '__grantauthoruids';
    $disabledIdsTable = $baseTable . '__grantdisableduids';

    //
    // Remove fields
    // -------------
    // The parent class automatically lists some fields that are internal
    // and are not intended to be shown.
    //
    // See also FolderShareAccessControlHandler, which disables these same
    // fields from all view access.
    unset($data[$baseTable]['uuid']);
    unset($data[$baseTable]['langcode']);
    unset($data[$disabledIdsTable]);

    // The underlying File object, if any, has its own description field,
    // which we do not use. Remove it too.
    unset($data[$baseTable]['file__description']);

    // The underlying File object can be shown as a link to the file.
    // Unfortunately, this link bypasses our access controls and shows
    // the file's raw file path. That file path doesn't have the proper
    // file name or file name extension. Remove these.
    unset($data[$baseTable]['file__target_id']);
    unset($data[$baseTable]['file__display']);

    //
    // Remove extras
    // -------------
    // The parent class adds several helper fields:
    // - 'Link to ...' - a link to the entity's view page.
    // - 'Rendered entity' - the entire rendered entity.
    // - 'Operations links' - a pull-down menu of actions per row.
    //
    // The rendered entity does not make sense here, since it includes a
    // view of child entities. And that view could include rendered entities
    // which add more embedded views, and so on to create an enormous
    // nested mess. So remove this.
    unset($data[$baseTable]['rendered_entity']);

    // The operations links extras do not make sense here.  They trigger
    // actions, but FolderShare does not define any actions.  Further, they
    // create an alternate (and poor) user interface that
    // collides with FolderShare's own interface. So remove this.
    unset($data[$baseTable]['operations']);

    //
    // Clean up fields
    // ---------------
    // The entity definition's field names and descriptions are brief and
    // aimed at describing the field for an edit form, rather than how the
    // field's values can be used in a view. Below, we tweak some fields to
    // use more descriptive text.
    //
    // We also add 'label' values that are often shorter versions of the
    // 'title'.
    //
    // Not all fields require clean up, so this is not an exhaustive list.
    //
    // ID.
    $data[$baseTable]['id']['help'] =
      $this->t('The unique entity ID of the item.');

    // UID (owner) field.
    $data[$baseTable]['uid']['help'] =
      $this->t("The item's owner.<br><em>To get more user fields, add the 'File or folder owner' relationship to the view using the 'Advanced' section.</em>");
    $data[$baseTable]['uid']['filter']['id'] = 'user_name';

    // Name field.
    $data[$baseTable]['name']['help'] =
      $this->t('The name of the item. For files, this is the full name of the file, including the filename extension.');

    // Description field.
    $data[$baseTable]['description__value']['title'] =
      $this->t('Description (unformatted)');
    $data[$baseTable]['description__value']['help'] =
      $this->t('The description of the item, without formatting.');

    $data[$baseTable]['description__format']['title'] =
      $this->t('Description (formatted)');
    $data[$baseTable]['description__format']['help'] =
      $this->t('The description of the item, with formatting.');

    // Size field.
    $data[$baseTable]['size']['help'] =
      $this->t('The size of the item, in bytes. For folders, this is the sum of the sizes of all files within the folder and any subfolders.');

    // Created date.
    $data[$baseTable]['created']['title'] =
      $this->t('Creation date');
    $data[$baseTable]['created']['label'] =
      $this->t('Created');
    $data[$baseTable]['created']['help'] =
      $this->t('The date and time when the item was created.');

    // Modified date.
    $data[$baseTable]['changed']['title'] =
      $this->t('Modification date');
    $data[$baseTable]['changed']['label'] =
      $this->t('Modified');
    $data[$baseTable]['changed']['help'] =
      $this->t('The date and time when the item was last modified.');

    // Author and viewer grants.
    $data[$authorIdsTable]['grantauthoruids']['title'] =
      $this->t('Authors');
    $data[$authorIdsTable]['grantauthoruids']['label'] =
      $this->t('Authors');
    $data[$authorIdsTable]['grantauthoruids']['help'] =
      $this->t("The users that have been granted author access to the item. The item's owner is always on this list.");

    $data[$viewIdsTable]['grantviewuids']['title'] =
      $this->t('Viewers');
    $data[$viewIdsTable]['grantviewuids']['label'] =
      $this->t('Viewers');
    $data[$viewIdsTable]['grantviewuids']['help'] =
      $this->t("The users that have been granted view access to the item. The item's owner is always on this list.");

    //
    // Clean up extras
    // ---------------
    // A few of the extras added automatically by the parent class need
    // some cleanup.
    //
    // For some reason, the link item added by the parent class sets the
    // title and help in the underlying 'field' array, and ignores it when
    // set normally. So we have to override that to get the text right.
    $data[$baseTable]['view_foldershare']['title'] =
      $data[$baseTable]['view_foldershare']['field']['title'] =
      $this->t('Link to item');
    $data[$baseTable]['view_foldershare']['help'] =
      $data[$baseTable]['view_foldershare']['field']['help'] =
      $this->t("A link to the item's page. For files, the page describes and presents the page. For folders, the page lists the folder's contents.");

    //
    // Add relationships
    // -----------------
    // For several fields, add available relationships for entity references
    // in order to expose those entity's fields for use in views.
    //
    // UID (owner).
    $data[$baseTable]['uid']['relationship']['title'] =
      $this->t('Owner');
    $data[$baseTable]['uid']['relationship']['label'] =
      $this->t('Owner');
    $data[$baseTable]['uid']['relationship']['help'] =
      $this->t('Relate an item to the user that owns it.');

    // Parent folder ID.
    $data[$baseTable]['parentid']['relationship']['title'] =
      $this->t('Parent folder');
    $data[$baseTable]['parentid']['relationship']['label'] =
      $this->t('Parent folder');
    $data[$baseTable]['parentid']['relationship']['help'] =
      $this->t('Relate an item to the parent folder that contains it.');

    // Root folder ID.
    $data[$baseTable]['rootid']['relationship']['title'] =
      $this->t('Top-level folder');
    $data[$baseTable]['rootid']['relationship']['label'] =
      $this->t('Top-level folder');
    $data[$baseTable]['rootid']['relationship']['help'] =
      $this->t('Relate an item to the highest folder ancestor that contains it.');

    // Granted author IDs. Also provide the 'real field' so that queries
    // using this relationship go to the correct UIDs table field.
    $data[$authorIdsTable]['grantauthoruids']['relationship']['title'] =
      $this->t('Author user');
    $data[$authorIdsTable]['grantauthoruids']['relationship']['label'] =
      $this->t('Author');
    $data[$authorIdsTable]['grantauthoruids']['relationship']['help'] =
      $this->t("Relate an item to the users that have been granted author access to the item.");
    $data[$authorIdsTable]['grantauthoruids']['real field'] =
      'grantauthoruids_target_id';

    // Granted viewer IDs. Also provide the 'real field' so that queries
    // using this relationship go to the correct UIDs table field.
    $data[$viewIdsTable]['grantviewuids']['relationship']['title'] =
      $this->t('View user');
    $data[$viewIdsTable]['grantviewuids']['relationship']['label'] =
      $this->t('Viewer');
    $data[$viewIdsTable]['grantviewuids']['relationship']['help'] =
      $this->t("Relate an item to the users that have been granted view access to the item.");
    $data[$viewIdsTable]['grantviewuids']['real field'] =
      'grantviewuids_target_id';

    //
    // Add special data fields
    // -----------------------
    // To make it easier to show a date broken into multiple columns,
    // define multiple date values with different formatting.
    //
    // Created date.
    $data[$baseTable]['created_fulldate'] = [
      'title'    => $this->t('Created date'),
      'help'     => $this->t('The item creation date in the form of CCYYMMDD.'),
      'argument' => [
        'field'  => 'created',
        'id'     => 'date_fulldate',
      ],
    ];

    $data[$baseTable]['created_year_month'] = [
      'title'    => $this->t('Created year + month'),
      'help'     => $this->t('The item creation date in the form of YYYYMM.'),
      'argument' => [
        'field'  => 'created',
        'id'     => 'date_year_month',
      ],
    ];

    $data[$baseTable]['created_year'] = [
      'title'    => $this->t('Created year'),
      'help'     => $this->t('The item creation date in the form of YYYY.'),
      'argument' => [
        'field'  => 'created',
        'id'     => 'date_year',
      ],
    ];

    $data[$baseTable]['created_month'] = [
      'title'    => $this->t('Created month'),
      'help'     => $this->t('The item creation date in the form of MM (01 - 12).'),
      'argument' => [
        'field'  => 'created',
        'id'     => 'date_month',
      ],
    ];

    $data[$baseTable]['created_day'] = [
      'title'    => $this->t('Created day'),
      'help'     => $this->t('The item creation date in the form of DD (01 - 31).'),
      'argument' => [
        'field'  => 'created',
        'id'     => 'date_day',
      ],
    ];

    $data[$baseTable]['created_week'] = [
      'title'    => $this->t('Created week'),
      'help'     => $this->t('The item creation date in the form of WW (01 - 53).'),
      'argument' => [
        'field'  => 'created',
        'id'     => 'date_week',
      ],
    ];

    // Changed date.
    $data[$baseTable]['changed_fulldate'] = [
      'title'    => $this->t('Modified date'),
      'help'     => $this->t('The item modified date in the form of CCYYMMDD.'),
      'argument' => [
        'field'  => 'changed',
        'id'     => 'date_fulldate',
      ],
    ];

    $data[$baseTable]['changed_year_month'] = [
      'title'    => $this->t('Modified year + month'),
      'help'     => $this->t('The item modified date in the form of YYYYMM.'),
      'argument' => [
        'field'  => 'changed',
        'id'     => 'date_year_month',
      ],
    ];

    $data[$baseTable]['changed_year'] = [
      'title'    => $this->t('Modified year'),
      'help'     => $this->t('The item modified date in the form of YYYY.'),
      'argument' => [
        'field'  => 'changed',
        'id'     => 'date_year',
      ],
    ];

    $data[$baseTable]['changed_month'] = [
      'title'    => $this->t('Modified month'),
      'help'     => $this->t('The item modified date in the form of MM (01 - 12).'),
      'argument' => [
        'field'  => 'changed',
        'id'     => 'date_month',
      ],
    ];

    $data[$baseTable]['changed_day'] = [
      'title'    => $this->t('Modified day'),
      'help'     => $this->t('The item modified date in the form of DD (01 - 31).'),
      'argument' => [
        'field'  => 'changed',
        'id'     => 'date_day',
      ],
    ];

    $data[$baseTable]['changed_week'] = [
      'title'    => $this->t('Modified week'),
      'help'     => $this->t('The item modified date in the form of WW (01 - 53).'),
      'argument' => [
        'field'  => 'changed',
        'id'     => 'date_week',
      ],
    ];

    //
    // Add view fields
    // ---------------
    // Describe view field plugins.
    //
    $data[$baseTable]['foldershare_views_field_baseui'] = [
      'title' => $this->t('Basic user interface'),
      'label' => '',
      'help'  => $this->t("A basic user interface for selecting rows and applying commands to create, delete, and edit items. This is a required column for the module's own views that add the full menu-driven user interface."),
      'field' => [
        'id' => 'foldershare_views_field_baseui',
      ],
    ];

    $data[$baseTable]['foldershare_views_field_sharing_status'] = [
      'title' => $this->t('Sharing status'),
      'label' => $this->t('Status'),
      'help'  => $this->t('A value indicating if the item is private, shared with others, or shared with the public.'),
      'field' => [
        'id' => 'foldershare_views_field_sharing_status',
      ],
    ];

    return $data;
  }

}
