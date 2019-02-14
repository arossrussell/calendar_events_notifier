<?php

namespace Drupal\jsonapi\Controller;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\Entity\EntityValidationTrait;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\Link;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\NullEntityCollection;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi\LinkManager\LinkManager;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ForwardCompatibility\FileFieldUploader;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Handles file upload requests.
 *
 * @internal
 */
class FileUpload {

  use EntityValidationTrait;

  /**
   * The current user making the request.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * The file uploader.
   *
   * @var \Drupal\jsonapi\ForwardCompatibility\FileFieldUploader
   */
  protected $fileUploader;

  /**
   * An HTTP kernel for making subrequests.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The link manager service.
   *
   * @var \Drupal\jsonapi\LinkManager\LinkManager
   */
  protected $linkManager;

  /**
   * Creates a new FileUpload instance.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   The entity field manager.
   * @param \Drupal\jsonapi\ForwardCompatibility\FileFieldUploader $file_uploader
   *   The file uploader.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   An HTTP kernel for making subrequests.
   * @param \Drupal\jsonapi\LinkManager\LinkManager $link_manager
   *   The link manager service.
   */
  public function __construct(AccountInterface $current_user, EntityFieldManagerInterface $field_manager, FileFieldUploader $file_uploader, HttpKernelInterface $http_kernel, LinkManager $link_manager) {
    $this->currentUser = $current_user;
    $this->fieldManager = $field_manager;
    $this->fileUploader = $file_uploader;
    $this->httpKernel = $http_kernel;
    $this->linkManager = $link_manager;
  }

  /**
   * Handles JSON:API file upload requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The JSON:API resource type for the current request.
   * @param string $file_field_name
   *   The file field for which the file is to be uploaded.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity for which the file is to be uploaded.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
   *   Thrown when there are validation errors.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if the upload's target resource could not be saved.
   * @throws \Exception
   *   Thrown if an exception occurs during a subrequest to fetch the newly
   *   created file entity.
   */
  public function handleFileUploadForExistingResource(Request $request, ResourceType $resource_type, $file_field_name, FieldableEntityInterface $entity) {
    $field_definition = $this->validateAndLoadFieldDefinition($resource_type->getEntityTypeId(), $resource_type->getBundle(), $file_field_name);

    static::ensureFileUploadAccess($this->currentUser, $field_definition, $entity);

    $filename = FileFieldUploader::validateAndParseContentDispositionHeader($request);
    $stream = FileFieldUploader::getUploadStream();
    $file = $this->fileUploader->handleFileUploadForField($field_definition, $filename, $stream, $this->currentUser);
    fclose($stream);

    if ($file instanceof EntityConstraintViolationListInterface) {
      $violations = $file;
      $message = "Unprocessable Entity: file validation failed.\n";
      $message .= implode("\n", array_map(function (ConstraintViolationInterface $violation) {
        return PlainTextOutput::renderFromHtml($violation->getMessage());
      }, (array) $violations->getIterator()));
      throw new UnprocessableEntityHttpException($message);
    }

    if ($field_definition->getFieldStorageDefinition()->getCardinality() === 1) {
      $entity->{$file_field_name} = $file;
    }
    else {
      $entity->get($file_field_name)->appendItem($file);
    }
    static::validate($entity, [$file_field_name]);
    $entity->save();

    $route_parameters = ['entity' => $entity->uuid()];
    $route_name = sprintf('jsonapi.%s.%s.related', $resource_type->getTypeName(), $file_field_name);
    $related_url = Url::fromRoute($route_name, $route_parameters)->toString(TRUE);
    $request = Request::create($related_url->getGeneratedUrl(), 'GET', [], $request->cookies->all(), [], $request->server->all());
    return $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
  }

  /**
   * Handles JSON:API file upload requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The JSON:API resource type for the current request.
   * @param string $file_field_name
   *   The file field for which the file is to be uploaded.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
   *   Thrown when there are validation errors.
   */
  public function handleFileUploadForNewResource(Request $request, ResourceType $resource_type, $file_field_name) {
    $field_definition = $this->validateAndLoadFieldDefinition($resource_type->getEntityTypeId(), $resource_type->getBundle(), $file_field_name);

    static::ensureFileUploadAccess($this->currentUser, $field_definition);

    $filename = FileFieldUploader::validateAndParseContentDispositionHeader($request);
    $stream = FileFieldUploader::getUploadStream();
    $file = $this->fileUploader->handleFileUploadForField($field_definition, $filename, $stream, $this->currentUser);
    fclose($stream);

    if ($file instanceof EntityConstraintViolationListInterface) {
      $violations = $file;
      $message = "Unprocessable Entity: file validation failed.\n";
      $message .= implode("\n", array_map(function (ConstraintViolationInterface $violation) {
        return PlainTextOutput::renderFromHtml($violation->getMessage());
      }, iterator_to_array($violations)));
      throw new UnprocessableEntityHttpException($message);
    }

    // @todo Remove line below in favor of commented line in https://www.drupal.org/project/jsonapi/issues/2878463.
    $self_link = new Link(new CacheableMetadata(), Url::fromRoute('jsonapi.file--file.individual', ['entity' => $file->uuid()]), ['self']);
    /* $self_link = new Link(new CacheableMetadata(), $this->entity->toUrl('jsonapi'), ['self']); */
    $links = new LinkCollection(['self' => $self_link]);

    return new ResourceResponse(new JsonApiDocumentTopLevel($file, new NullEntityCollection(), $links), 201, []);
  }

  /**
   * Ensures that the given account is allowed to upload a file.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account for which access should be checked.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field for which the file is to be uploaded.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity, if one exists, for which the file is to be uploaded.
   */
  protected static function ensureFileUploadAccess(AccountInterface $account, FieldDefinitionInterface $field_definition, FieldableEntityInterface $entity = NULL) {
    $access_result = $entity
      ? FileFieldUploader::checkFileUploadAccess($account, $field_definition, $entity)
      : FileFieldUploader::checkFileUploadAccess($account, $field_definition);
    if (!$access_result->isAllowed()) {
      $reason = 'The current user is not permitted to upload a file for this field.';
      if ($access_result instanceof AccessResultReasonInterface) {
        $reason .= ' ' . $access_result->getReason();
      }
      throw new AccessDeniedHttpException($reason);
    }
  }

  /**
   * Validates and loads a field definition instance.
   *
   * @param string $entity_type_id
   *   The entity type ID the field is attached to.
   * @param string $bundle
   *   The bundle the field is attached to.
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The field definition.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the field does not exist.
   * @throws \Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException
   *   Thrown when the target type of the field is not a file, or the current
   *   user does not have 'edit' access for the field.
   */
  protected function validateAndLoadFieldDefinition($entity_type_id, $bundle, $field_name) {
    $field_definitions = $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle);
    if (!isset($field_definitions[$field_name])) {
      throw new NotFoundHttpException(sprintf('Field "%s" does not exist.', $field_name));
    }

    /** @var \Drupal\Core\Field\FieldDefinitionInterface $field_definition */
    $field_definition = $field_definitions[$field_name];
    if ($field_definition->getSetting('target_type') !== 'file') {
      throw new AccessDeniedException(sprintf('"%s" is not a file field', $field_name));
    }

    return $field_definition;
  }

}
