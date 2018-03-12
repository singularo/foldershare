<?php

namespace Drupal\foldershare\Plugin\Search;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessibleInterface;

use Drupal\Core\Cache\CacheableMetadata;

use Drupal\Core\Config\Config;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Query\Condition;

use Drupal\Core\Extension\ModuleHandlerInterface;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;

use Drupal\search\Plugin\ConfigurableSearchPluginBase;
use Drupal\search\Plugin\SearchIndexingInterface;
use Drupal\search\SearchQuery;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\foldershare\Constants;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Handles searching for files and folders using the Search module index.
 *
 * @ingroup foldershare
 *
 * @SearchPlugin(
 *   id    = "foldershare_search",
 *   title = @Translation("FolderShare files and folders")
 * )
 */
class FolderShareSearch extends ConfigurableSearchPluginBase implements AccessibleInterface, SearchIndexingInterface {

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   * These fields cache values collected during dependency injection.
   *
   *------------------------------------------------------------------*/

  /**
   * A database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * An entity type manager object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A module manager object.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * A config object for 'search.settings'.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $searchSettings;

  /**
   * The Drupal account to use for checking for access to advanced search.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The Renderer service to format the file or folder.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance of the search plugin.
   *
   *------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    // Construct a static plugin with the given parameters.
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('config.factory')->get('search.settings'),
      $container->get('renderer'),
      $container->get('current_user')
    );
  }

  /**
   * Constructs an instance of the plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $pluginId
   *   The plugin_id for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $database
   *   A database connection object.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   An entity type manager object.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   A module manager object.
   * @param \Drupal\Core\Config\Config $searchSettings
   *   A config object for 'search.settings'.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The $account object to use for checking for access to advanced search.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ModuleHandlerInterface $moduleHandler,
    Config $searchSettings,
    RendererInterface $renderer,
    AccountInterface $account = NULL) {

    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->searchSettings = $searchSettings;
    $this->renderer = $renderer;
    $this->account = $account;

    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /*--------------------------------------------------------------------
   *
   * Configuration form.
   *
   * These functions build the search configuration and record the
   * site administrator's configuration choices.
   *
   *------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    //
    // Return a default configuration that enables all search items.
    //
    return [
      'search_items' => [
        'file_content' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $formState) {
    //
    // Create a configuration form to enable site administrators to select
    // what can be searched.
    //
    // Create a form group for the searchable items.
    $form['search_items'] = [
      '#type'  => 'details',
      '#title' => $this->t('What to search'),
      '#open'  => TRUE,
    ];

    $form['search_items']['description'] = [
      '#type'   => 'item',
      '#markup' => '<p>' . $this->t(
        'Search indexing always includes the names of files and folders and the text in folder fields, such as folder descriptions. Optionally, search indexing may include the content of some types of files.') . '</p>',
    ];

    // Search file content?
    $form['search_items']['file_content'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('File content'),
      '#default_value' => TRUE,
      '#return_value'  => 'enabled',
      '#required'      => FALSE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $formState) {
    //
    // Update the configuration with the selected search items.
    //
    $this->configuration['search_items']['file_content'] =
      ($formState->getValue('file_content') === 'enabled');
  }

  /*--------------------------------------------------------------------
   *
   * Accessibility.
   *
   * These functions check if the user has permission to perform
   * the search.
   *
   *------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function access(
    $operation = 'view',
    AccountInterface $account = NULL,
    $returnAsObject = FALSE) {
    //
    // Generically check if the user has enough permissions to issue
    // a search and view results. This DOES NOT check per-folder
    // access grants because this method is called only for the entire
    // search operation, not per folder.
    //
    $entityType = $this->entityTypeManager->getDefinition(
      FolderShare::ENTITY_TYPE_ID);

    // Allow administrators and users with view or author permissions.
    $perm = $entityType->getAdminPermission();
    if (empty($perm) === TRUE) {
      $perm = Constants::ADMINISTER_PERMISSION;
    }

    // Administrator?
    $ac = AccessResult::allowedIfHasPermission($account, $perm);
    if ($ac->isAllowed() === TRUE) {
      return ($returnAsObject === TRUE) ? $ac : $ac->isAllowed();
    }

    // Author?
    $ac = AccessResult::allowedIfHasPermission(
      $account,
      Constants::AUTHOR_PERMISSION);
    if ($ac->isAllowed() === TRUE) {
      return ($returnAsObject === TRUE) ? $ac : $ac->isAllowed();
    }

    // Viewer?
    $ac = AccessResult::allowedIfHasPermission(
      $account,
      Constants::VIEW_PERMISSION);
    if ($ac->isAllowed() === TRUE) {
      return ($returnAsObject === TRUE) ? $ac : $ac->isAllowed();
    }

    // Otherwise the user does not have permission to access
    // the content.
    return ($returnAsObject === TRUE) ? AccessResult::forbidden() : FALSE;
  }

  /*--------------------------------------------------------------------
   *
   * Search form.
   *
   * The basic search page supports a single keyword field for a list
   * of space-separated words to search for. These are added to the
   * search page URL.
   *
   * This plugin extends the search page to support "advanced" search
   * abilities similar to those for nodes, including keywords to
   * exclude, alternate keywords, and an exact phrase. These additional
   * items are also encoded into the search page URL using an expression-like
   * syntax: <keyword> <keyword> ... "<phrase>" ... OR <keyword> <keyword>.
   *
   *------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function buildSearchUrlQuery(FormStateInterface $formState) {
    //
    // Use the current basic form keywords, and the advanced search
    // 'or', 'negative', and 'phrase' fields and encode them all as a
    // parameter on the URL. These same values are parsed back out
    // of the URL to initialize the search form.
    //
    // Get the form values.
    $keywords         = trim($formState->getValue('keys'));
    $orKeywords       = $formState->getValue('or');
    $negativeKeywords = $formState->getValue('negative');
    $phrase           = $formState->getValue('phrase');

    // Build the URL parameter, starting with the basic form keywords
    // and appending the other values.
    if (empty($orKeywords) === FALSE) {
      // Add <keyword> OR <keyword> OR ...
      if (preg_match_all(
        '/ ("[^"]+"|[^" ]+)/i',
        ' ' . $orKeywords,
        $matches) === 1) {
        $keywords .= ' OR ' . implode(' OR ', $matches[1]);
      }
    }

    if (empty($negativeKeywords) === FALSE) {
      // Add -<keyword> -<keyword> ...
      if (preg_match_all(
        '/ ("[^"]+"|[^" ]+)/i',
        ' ' . $negativeKeywords,
        $matches) === 1) {
        $keywords .= ' -' . implode(' -', $matches[1]);
      }
    }

    if (empty($phrase) === FALSE) {
      // Add "<phrase>".
      $keywords .= ' "' . str_replace('"', ' ', $phrase) . '"';
    }

    $keywords = trim($keywords);

    // Make the keywords a GET parameter.
    //
    // Even if the keywords are empty, add them as a parameter because the
    // search page controller uses the parameter's existence to decide if
    // it should check for search results.
    return ['keys' => $keywords];
  }

  /**
   * {@inheritdoc}
   */
  public function searchFormAlter(array &$form, FormStateInterface $formState) {
    //
    // Alter the basic search form to add a few more fields for
    // more advanced searches.
    //
    // The standard search form prompts for keywords only.  This search
    // supports some advanced settings similar to those found on node search:
    // - Containing any of the words.
    // - Containing the phrase.
    // - Containing none of the words.
    //
    // Parse keywords
    // --------------
    // The incoming keyword string can have special syntax to indicate:
    // - primary keywords.
    // - OR keywords.
    // - negated keywords (exclude these).
    // - a search phrase.
    //
    // For example 'this -that "lotsa stuff" OR thing junk' gets parsed as:
    // - keywords:  this.
    // - negated keywords: that.
    // - phrase: lotsa stuff.
    // - OR keywords: thing junk.
    $rawKeywords = ' ' . $this->getKeywords() . ' ';
    $matches = [];

    $phraseDefault = '';
    $orDefault = '';
    $negativeDefault = '';

    // Look for a quoted phrase in the keywords. The advanced search
    // only supports a single phrase, so take the first one.
    if (preg_match('/ "([^"]+)" /', $rawKeywords, $matches) === 1) {
      // Phrase found. Save.
      $phraseDefault = $matches[1];

      // Remove it from the keywords.
      $rawKeywords = str_replace($matches[0], ' ', $rawKeywords);
    }

    // Look for words with a '-' prefix.
    if (preg_match_all('/ -([^ ]+)/', $rawKeywords, $matches) === 1) {
      // Negative words found. Save.
      $negativeDefault = implode(' ', $matches[1]);

      // Remove them from the keywords.
      $rawKeywords = str_replace($matches[0], ' ', $rawKeywords);
    }

    // Look for words separated by 'OR'. The advanced search only supports
    // one set of OR words, so take the first one.
    if (preg_match('/ [^ ]+( OR [^ ]+)+ /', $rawKeywords, $matches) === 1) {
      // OR words found. Split the list on 'OR' and save.
      $words = explode(' OR ', trim($matches[0]));
      $orDefault = implode(' ', $words);

      // Remove them from the keywords.
      $rawKeywords = str_replace($matches[0], ' ', $rawKeywords);
    }

    // Use whatever remains as the generic set of keywords for the
    // basic form.
    $keywords = trim($rawKeywords);

    //
    // Initialize basic form
    // ---------------------
    // Set the keywords provided, if any.
    $form['basic']['keys']['#default_value'] = $keywords;

    //
    // Build and initialized advanced settings
    // ---------------------------------------
    // See if the user has permissions.
    $hasAccess = ($this->account !== NULL) &&
      ($this->account->hasPermission('use advanced search') == TRUE);

    // See if any of the advanced keyword features were used.
    $hasAdvanced = (empty($phraseDefault) === FALSE) ||
      (empty($orDefault) === FALSE) ||
      (empty($negativeDefault) === FALSE);

    // Create a group for advanced search settings.
    $form['advanced'] = [
      '#type'       => 'details',
      '#title'      => $this->t('Advanced search'),
      '#attributes' => [
        'class'     => ['search-advanced'],
      ],
      '#access'     => $hasAccess,
      '#open'       => $hasAdvanced,
    ];

    // Containing any of the words?
    $form['advanced']['or'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Containing any of these words:'),
      '#size'          => 30,
      '#maxlength'     => 255,
      '#default_value' => $orDefault,
    ];

    // Containing the phrase?
    $form['advanced']['phrase'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Containing this phrase:'),
      '#size'          => 30,
      '#maxlength'     => 255,
      '#default_value' => $phraseDefault,
    ];

    // Containing none of the words?
    $form['advanced']['negative'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Containing none of these words:'),
      '#size'          => 30,
      '#maxlength'     => 255,
      '#default_value' => $negativeDefault,
    ];
  }

  /*--------------------------------------------------------------------
   *
   * Search indexing.
   *
   * These functions control the creation of a search index that
   * records information about files and folders.
   *
   * The search module only allows a plugin to have a single search
   * index (the name is returned by getType()). This is awkward here
   * because we need to support searching for both folders and files
   * in the folders.
   *
   * Further, a search index has a single "search ID" which is intended
   * to hold the entity ID of the item in the index. For user search,
   * this is the UID. For node search, this is the node ID. But for
   * this search plugin, we need this to be EITHER a folder ID or a
   * file ID. But given a simple numeric ID, it is impossible to determine
   * if the ID is for a folder or file. We therefore need to indicate
   * folder vs. file with something else in the index.
   *
   * It'd be nice to say that a negative ID is a file, and a positive ID
   * is a folder. Except that the search index forces IDs to be unsigned
   * integers.
   *
   * The only other database field available to us is the 'langcode'
   * field, which is intended to indicate the language used by the entity.
   * For this search plugin, we introduce a new 'language' of 'file'
   * to mean a file entry. Any other value is a folder entry.
   *
   *------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getType() {
    //
    // Return the name of the search index used.
    //
    // While it is common for search plugins to name their search index
    // after the plugin's ID, we need to use a well-known name so that
    // other parts of the module can refer to the search index by name,
    // without knowing the name of the search plugin.
    //
    return Constants::SEARCH_INDEX;
  }

  /**
   * {@inheritdoc}
   */
  public function indexClear() {
    //
    // Clear all search index.
    //
    search_index_clear($this->getType());
  }

  /**
   * {@inheritdoc}
   */
  public function markForReindex() {
    //
    // Mark the search index as in need of re-indexing. This flags every
    // entry in the index as out of date. Later, during indexing, these
    // flags are gradually flipped.
    //
    search_mark_for_reindex($this->getType());
  }

  /**
   * {@inheritdoc}
   */
  public function indexStatus() {
    //
    // Indicate the total number of items to index, and the number
    // remaining to index.
    //
    // Get total indexable
    // -------------------
    // Get the number of files and folders.
    $totalIndexable = FolderShare::getNumberOfItems();

    //
    // Get remaining
    // -------------
    // The number of items remaining to index equals the number of
    // items marked as in need of reindexing the search index.
    $totalRemaining = $this->database->query(
      'SELECT COUNT(DISTINCT fs.id) FROM {' . FolderShare::BASE_TABLE . '} fs LEFT JOIN {search_dataset} sd ON sd.sid = fs.id AND sd.type = :searchIndex WHERE sd.sid IS NULL OR sd.reindex <> 0',
      [
        ':searchIndex' => $this->getType(),
      ])->fetchField();

    return [
      'remaining' => $totalRemaining,
      'total'     => $totalIndexable,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex() {
    //
    // Update the search index by adding a few more entries. This function
    // may be invoked via CRON, so it needs to limit its work to only a
    // few items or risk a CRON job that runs out of time and fails.
    $storage = $this->entityTypeManager->getStorage(
      FolderShare::ENTITY_TYPE_ID);

    // The search module supports a setting for the "CRON limit" to
    // specify the number of items to index on each CRON run.  We use
    // this to limit the number of folders or files indexed.
    $cronLimit = (int) $this->searchSettings->get('index.cron_limit');

    //
    // Index pending items
    // -------------------
    // Get pending items to index. This searches the index table and joins
    // it with the FolderShare table. The result are entries that are items
    // that have not been indexed yet. This also pulls in new items
    // that have not yet had their IDs added to the index table.
    $query = $this->database->select(FolderShare::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->leftJoin(
      'search_dataset',
      'sd',
      'sd.sid = fs.id AND sd.type = :searchIndex',
      [
        ':searchIndex' => $this->getType(),
      ]);
    $query->addExpression(
      'CASE MAX(sd.reindex) WHEN NULL THEN 0 ELSE 1 END',
      'ex');
    $query->addExpression('MAX(sd.reindex)', 'ex2');
    $query->condition(
      $query->orConditionGroup()
        ->where('sd.sid IS NULL')
        ->condition('sd.reindex', 0, '<>'));
    $query->orderBy('ex', 'DESC');
    $query->orderBy('ex2');
    $query->orderBy('fs.id');
    $query->groupBy('fs.id');
    $query->range(0, $cronLimit);

    // Execute the query. The only value returned for each record
    // is the ID.
    $ids = $query->execute()
      ->fetchCol();

    // Load each folder and index it.
    foreach ($storage->loadMultiple($ids) as $item) {
      $this->indexItem($item);
    }

    $cronLimit -= count($ids);
    if ($cronLimit <= 0) {
      // Hit limit.
      return;
    }
  }

  /**
   * Indexes a single file or foldder item.
   *
   * The item's name and field data is added to the index.
   *
   * @param \Drupal\foldershare\FolderShareInterface $item
   *   The item to index.
   */
  private function indexItem(FolderShareInterface $item) {
    //
    // Update the index.
    //
    // Build a view to get all of its fields, including those
    // defined by the module and those added by other modules or the
    // site administrator. Exclude pseudo-fields that list a folder's
    // contents.
    $langcode = $item->langcode->value;
    $builder = $this->entityTypeManager->getViewBuilder(
      FolderShare::ENTITY_TYPE_ID);

    $build = $builder->view($item, 'search_index', $langcode);
    unset($build['#theme']);

    // The view doesn't include the item's name, since that is usually
    // provided by a page. Add it here so that the rendered text will
    // include the name.  Give the name a strong weight to pull
    // it to the front of everything else.
    $build['search_title'] = [
      '#prefix'     => '<h1>',
      '#plain_text' => $item->getName(),
      '#suffix'     => '</h1>',
      '#weight'     => -1000,
    ];

    // Render the view to plain text.
    $text = $this->renderer->renderPlain($build);

    // Invoke hooks.
    $extra = $this->moduleHandler->invokeAll(
      FolderShare::ENTITY_TYPE_ID . '_update_index',
      [$item]);
    foreach ($extra as $e) {
      $text .= $e;
    }

    // Add the text to the search index.
    search_index($this->getType(), $item->id(), $langcode, $text);

    // TODO
    // Index file content. Always do this, regardless of the search
    // page configuration. The configuration only indicates if the
    // file content is included in search results.
    //
    // Add the file content as another pseudo-langcode to mark it
    // as skippable content if the configuration says to skip it.
  }

  /*--------------------------------------------------------------------
   *
   * Search.
   *
   * These functions execute a search, retreiving a structured list of
   * search results.
   *
   *------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function execute() {
    //
    // Executes a search, if possible, and returns a structured
    // list of search results.
    //
    // The base class provides isSearchExecutable(), which is TRUE
    // if any keywords have been provided by the user.
    if ($this->isSearchExecutable() === FALSE) {
      // The search is not executable. The user has not provided any
      // keywords to guide the search, so return nothing.
      return [];
    }

    // Search!
    $results = $this->search();
    if (empty($results) === TRUE) {
      // The search produced nothing, so return nothing.
      return [];
    }

    // Format the search results and return them.
    return $this->formatResults($results);
  }

  /**
   * Searches the search index and returns results.
   *
   * On success, an array of search results is returned. On failure,
   * the returned array may be truncated or empty and an error message
   * may have been presented to the user.
   *
   * @return \Drupal\Core\Database\StatementInterface|null
   *   Returns results from a search query, or NULL if the search failed.
   */
  private function search() {
    //
    // Get the search keyword string. This string has embedded syntax
    // that uses a '-' in front of keywords to exclude, 'OR' between
    // keyword alternatives, and a double-quoted phrase.
    $keywords = $this->keywords;

    // Search
    // ------
    // Build and execute the search index query, including special
    // search handling and a default pager. Add the search keywords
    // and the name of the search index to use.
    $query = $this->database
      ->select('search_index', 'i')
      ->extend('Drupal\search\SearchQuery')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->searchExpression($keywords, $this->getType());
    $query->addField('i', 'langcode');

    // Execute the query and get the results.
    $results = $query->execute();

    // Check for problems
    // ------------------
    // Report problems to the user.
    $status = $query->getStatus();

    if (($status & SearchQuery::EXPRESSIONS_IGNORED) !== 0) {
      // The user's search keywords were too complex and were
      // partly ignored.
      $count = $this->searchSettings->get('and_or_limit');
      drupal_set_message(
        $this->t(
          'Your search used too many AND/OR expressions. Only the first @count terms were included in this search.',
          [
            '@count' => $count,
          ]),
        'warning');
    }

    if (($status & SearchQuery::LOWER_CASE_OR) !== 0) {
      // The user entered a lower-case 'or' when they should have
      // used an uppercase 'OR'.
      drupal_set_message(
        $this->t('Search for either of the two terms with an uppercase <strong>OR</strong>. For example, <strong>cats OR dogs</strong>.'),
        'warning');
    }

    if (($status & SearchQuery::NO_POSITIVE_KEYWORDS) !== 0) {
      // The user didn't enter any keywords to find, just keywords
      // to ignore.
      $count        = $this->searchSettings->get('index.minimum_word_size');
      $singularText = 'You must include at least one keyword to match in the content, and punctuation is ignored';
      $pluralText   = 'You must include at least one keyword to match in the content. Keywords must be at least @count characters, and punctuation is ignored.';

      drupal_set_message(
        $this->formatPlural($count, $singularText, $pluralText),
        'warning');
    }

    return $results;
  }

  /**
   * Formats search results for presentation on a search page.
   *
   * @param \Drupal\Core\Database\StatementInterface $results
   *   Results found from a successful search.
   *
   * @return array
   *   Returns a renderable array containing presentable search results.
   */
  private function formatResults(StatementInterface $results) {
    //
    // Setup
    // -----
    // Get the storage manager.
    $storage = $this->entityTypeManager->getStorage(
      FolderShare::ENTITY_TYPE_ID);

    // Get the builder.
    $builder = $this->entityTypeManager->getViewBuilder(
      FolderShare::ENTITY_TYPE_ID);

    // Get the search keywords.
    $keywords = $this->keywords;

    //
    // Build a renderable
    // ------------------
    // Loop through the search results and create an abbreviated
    // version of each item.
    $rows = [];
    foreach ($results as $result) {
      $id = $result->sid;

      // Load the item.
      $item = $storage->load($id);

      // Build a presentation of the item.
      $build = $builder->view($item, 'search_result', $result->langcode);
      unset($build['#theme']);

      $text = $this->renderer->renderPlain($build);
      $this->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));

      // Invoke comment hooks.
      $text .= ' ' . $this->moduleHandler->invoke(
        'comment',
        FolderShare::ENTITY_TYPE_ID . '_update_index',
        [$item]);

      // Invoke search result hooks.
      $extra = $this->moduleHandler->invokeAll(
        FolderShare::ENTITY_TYPE_ID . '_search_result',
        [$item]);

      // Add a search result row with a link to the item.
      $row = [
        'type'     => FolderShare::ENTITY_TYPE_ID,
        'link'     => $item->url('canonical', ['absolute' => TRUE]),
        'title'    => $item->getName(),
        FolderShare::ENTITY_TYPE_ID => $item,
        'extra'    => $extra,
        'score'    => $result->calculated_score,
        'snippet'  => search_excerpt($keywords, $text, $result->langcode),
        'langcode' => $result->langcode,
      ];

      $this->addCacheableDependency($item);

      $rows[] = $row;
    }

    return $rows;
  }

}
