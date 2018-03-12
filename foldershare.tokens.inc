<?php

/**
 * @file
 * Implements token hooks for the module.
 */

use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Implements hook_token_info().
 *
 * This hook defines tokens in the 'foldershare' group that provide access
 * to useful public fields of the FolderShare entity. These include:
 * - 'id': the entity ID.
 * - 'kind': the entity kind, such as 'file' or 'folder'.
 * - 'owner': the owner's user ID.
 * - 'name': the name of the entity.
 * - 'description': the description of the entity.
 * - 'created': the creation date of the entity.
 * - 'changed': the last-modified date of the entity.
 * - 'size': the size, in bytes, of the entity.
 * - 'url': the URL to the entity's view page.
 */
function foldershare_token_info() {
  //
  // Define the 'foldershare' token type that replaces tokens
  // with values from a FolderShare entity.
  $folderShareType = [
    'name'        => t('Shared folders and files'),
    'description' => t('Tokens related to a shared folder or file.'),
    'needs-data'  => FolderShare::ENTITY_TYPE_ID,
  ];

  //
  // Entity ID as 'id'.
  $folderShareTokens['id'] = [
    'name'        => t('ID'),
    'description' => t('The unique ID of a folder or file.'),
  ];

  // Entity kind as 'kind'.
  $folderShareTokens['kind'] = [
    'name'        => t('Kind'),
    'description' => t('The kind of item, such as a folder or file.'),
  ];

  // User ID as 'owner'.
  $folderShareTokens['owner'] = [
    'name'        => t('Owner'),
    'description' => t('The user who owns the folder or file.'),
    'type'        => 'user',
  ];

  // Entity name as 'name'.
  $folderShareTokens['name'] = [
    'name'        => t('Name'),
    'description' => t('The name of the folder or file.'),
  ];

  // Entity description as 'description'.
  $folderShareTokens['description'] = [
    'name'        => t('Description'),
    'description' => t('The description of the folder or file.'),
  ];

  // Entity creation date as 'created'.
  $folderShareTokens['created'] = [
    'name'        => t('Created'),
    'description' => t('The date the folder or file was created.'),
    'type'        => 'date',
  ];

  // Entity modified date as 'changed'.
  $folderShareTokens['changed'] = [
    'name'        => t('Changed'),
    'description' => t('The date the folder or file was most recently changed.'),
    'type'        => 'date',
  ];

  // Entity size as 'size'.
  $folderShareTokens['size'] = [
    'name'        => t('Size'),
    'description' => t('The storage space used by the file or folder, including all folder content.'),
  ];

  // Entity URL as 'url'.
  $folderShareTokens['url'] = [
    'name'        => t('URL'),
    'description' => t('The URL of the folder or file.'),
  ];

  return [
    'types' => [
      Constants::FOLDERSHARE_TOKENS => $folderShareType,
    ],
    'tokens' => [
      Constants::FOLDERSHARE_TOKENS => $folderShareTokens,
    ],
  ];
}

/**
 * Implements hook_tokens().
 */
function foldershare_tokens(
  string $group,
  array $tokens,
  array $data,
  array $options,
  BubbleableMetadata $metadata) {
  //
  // Given token group (a.k.a. type), and the list of tokens in use,
  // find replacements for the tokens based upon values extracted
  // from the given data.
  //
  // Ignore anything except for the token groups we define.
  $item = NULL;
  switch ($group) {
    case Constants::FOLDERSHARE_TOKENS:
      // For these tokens we need a FolderShare object.
      if (isset($data[FolderShare::ENTITY_TYPE_ID]) === FALSE) {
        // No object, so no token replacement.
        return [];
      }

      $item = $data[FolderShare::ENTITY_TYPE_ID];
      break;

    case Constants::MODULE_TERM_TOKENS:
      // For terminology tokens, no data is required.
      break;

    default:
      // Unrecognized token group.
      return [];
  }

  //
  // Replace tokens
  // --------------
  // For each, add the replacement value.
  $replacements = [];
  if ($group === Constants::FOLDERSHARE_TOKENS) {
    // These tokens use values from the FolderShare object.
    foreach ($tokens as $name => $original) {
      switch ($name) {
        // ID.
        case 'id':
          $replacements[$original] = $item->id();
          break;

        // Kind.
        case 'kind':
          $replacements[$original] = $item->getKind();
          break;

        // Owner ID.
        case 'owner':
          $owner = $item->getOwner();
          $metadata->addCacheableDependency($owner);
          $replacements[$original] = $owner->getDisplayName();
          break;

        // Creation date.
        case 'created':
          // Date formatting defaults to the time zone and language
          // of the page being displayed.
          $metadata->addCacheableDependency(DateFormat::load('medium'));
          $replacements[$original] = format_date(
            $item->getCreatedTime(),
            'medium',
            '',
            NULL,
            NULL);
          break;

        // Changed date.
        case 'changed':
          // Date formatting defaults to the time zone and language
          // of the page being displayed.
          $metadata->addCacheableDependency(DateFormat::load('medium'));
          $replacements[$original] = format_date(
            $item->getChangedTime(),
            'medium',
            '',
            NULL,
            NULL);
          break;

        // Size.
        case 'size':
          // Could be empty.
          $sz = $item->getSize();
          $replacements[$original] = ($sz === -1) ? '' : $sz;
          break;

        // Name.
        case 'name':
          $replacements[$original] = $item->getName();
          break;

        // Description.
        case 'description':
          $replacements[$original] = $item->getDescription();
          break;

        // URL.
        case 'url':
          $replacements[$original] = $item->url('canonical', ['absolute' => TRUE]);
          break;

        default:
          // Ignore all other unrecognized tokens.
          break;
      }
    }
  }

  //
  // Replace chained tokens
  // ----------------------
  // A chained token is something like [foldershare:created:format].
  $tokenService = \Drupal::token();

  $chained = $tokenService->findWithPrefix($tokens, 'created');
  if (empty($chained) === FALSE) {
    $replacements += $tokenService->generate(
      'date',
      $chained,
      ['date' => $item->getCreatedTime()],
      $options,
      $metadata);
  }

  $chained = $tokenService->findWithPrefix($tokens, 'changed');
  if (empty($chained) === FALSE) {
    $replacements += $tokenService->generate(
      'date',
      $chained,
      ['date' => $item->getChangedTime()],
      $options,
      $metadata);
  }

  $chained = $tokenService->findWithPrefix($tokens, 'owner');
  if (empty($chained) === FALSE) {
    $replacements += $tokenService->generate(
      'user',
      $chained,
      ['user' => $item->getOwner()],
      $options,
      $metadata);
  }

  return $replacements;
}
