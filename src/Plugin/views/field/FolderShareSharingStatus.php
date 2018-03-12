<?php

namespace Drupal\foldershare\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\foldershare\Settings;

/**
 * Creates a view column showing the sharing status of FolderShare entities.
 *
 * To use this plugin, it must be added as a column on a view of
 * FolderShare entities. The colmn's value is then the translated text
 * for one of the following values:
 * - Private: the entity is not shared with anyone.
 * - Public: the entity is shared with anonymous.
 * - Group: the entity is shared with users other than anonymous.
 *
 * If module settings disable sharing, everything is marked private.
 *
 * @see \Drupal\foldershare\Entity\FolderShareViewsData
 * @see \Drupal\foldershare\Entity\FolderShare
 *
 * @ViewsField("foldershare_views_field_sharing_status")
 */
class FolderShareSharingStatus extends FieldPluginBase {

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   *--------------------------------------------------------------------*/

  /**
   * Initialized to the translated text marking entities not shared.
   *
   * @var string
   */
  private $textEntityNotShared = '';

  /**
   * Initialized to the translated text marking entities shared with anonymous.
   *
   * @var string
   */
  private $textEntitySharedWithAnon = '';

  /**
   * Initialized to the translated text marking entities shared by the user.
   *
   * @var string
   */
  private $textEntitySharedByUser = '';

  /**
   * Initialized to the translated text marking entities shared with the user.
   *
   * @var string
   */
  private $textEntitySharedWithUser = '';

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance using dependency injection to
   * cache important services needed later during execution.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $pluginId
   *   The plugin ID for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    parent::__construct($configuration, $pluginId, $pluginDefinition);

    $this->textEntityNotShared      = $this->t('Private');
    $this->textEntitySharedWithAnon = $this->t('Public');
    $this->textEntitySharedByUser   = $this->t('Shared');
    $this->textEntitySharedWithUser = $this->t('Shared');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition
    );
  }

  /*--------------------------------------------------------------------
   *
   * Views rows.
   * (Overrides FieldPluginBase)
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    // If sharing is disabled site wide, then everything is private.
    if (Settings::getSharingAllowed() === FALSE) {
      return $this->textEntityNotShared;
    }

    // Get the row's entity and its root folder on which sharing is set.
    $entity = $row->_entity;
    if ($entity === NULL) {
      return '';
    }

    $root      = $entity->getRootFolder();
    $ownerId   = (int) $root->getOwnerId();
    $currentId = (int) \Drupal::currentUser()->id();

    //
    // Public
    // ------
    // If the content is owned by anonymous, it is always public.
    if ($ownerId === 0) {
      return $this->textEntitySharedWithAnon;
    }

    //
    // Private
    // -------
    // If the item is not shared with anyone except the owner, it is
    // private.
    if ($root->isAccessPrivate() === TRUE) {
      return $this->textEntityNotShared;
    }

    //
    // Public
    // ------
    // If sharing with anonymous is allowed, and the content is shared
    // with anonymous, then it is public.
    $anonAllowed = Settings::getSharingAllowedWithAnonymous();
    if ($anonAllowed === TRUE && $root->isAccessPublic() === TRUE) {
      return $this->textEntitySharedWithAnon;
    }

    // At this point, we know:
    // - The item is not owned by anonymous.
    // - The item shares with someone other than the owner.
    // - If anonymous sharing is disabled, that someone might be anonymous.
    //   Otherwise we know it is not.
    //
    // Private
    // -------
    // If the item was only shared with anonymous, but anonymous sharing is
    // currently disabled, then it reverts to private.
    if ($anonAllowed === FALSE) {
      $uids = ($root->getAccessGrantViewUserIds() +
        $root->getAccessGrantAuthorUserIds());
      $isShared = FALSE;
      foreach ($uids as $uid) {
        if ($uid !== $ownerId && $uid !== 0) {
          // The item is shared with someone that isn't anonymous or the owner.
          $isShared = TRUE;
          break;
        }
      }

      if ($isShared === FALSE) {
        return $this->textEntityNotShared;
      }
    }

    //
    // Group
    // -----
    // If the item is owned by the current user, then it is shared with others.
    // Otherwise it is shared with this user.
    if ($ownerId === $currentId) {
      return $this->textEntitySharedByUser;
    }

    return $this->textEntitySharedWithUser;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // There is nothing to query.
  }

}
