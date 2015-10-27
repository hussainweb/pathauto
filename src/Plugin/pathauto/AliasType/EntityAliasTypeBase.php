<?php

/**
 * @file
 * Contains \Drupal\pathauto\Plugin\AliasType\EntityAliasTypeBase.
 */

namespace Drupal\pathauto\Plugin\pathauto\AliasType;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginBase;
use Drupal\pathauto\AliasTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A pathauto alias type plugin for entities with canonical links.
 *
 * @AliasType(
 *   id = "canonical_entities",
 *   deriver = "\Drupal\pathauto\Plugin\Deriver\EntityAliasTypeDeriver"
 * )
 */
class EntityAliasTypeBase extends ContextAwarePluginBase implements AliasTypeInterface, ContainerFactoryPluginInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The path prefix for this entity type.
   *
   * @var string
   */
  protected $prefix;

  /**
   * Constructs a NodeAliasType instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ModuleHandlerInterface $module_handler, LanguageManagerInterface $language_manager, EntityManagerInterface $entity_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleHandler = $module_handler;
    $this->languageManager = $language_manager;
    $this->entityManager = $entity_manager;
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
      $container->get('language_manager'),
      $container->get('entity.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = NestedArray::mergeDeep(
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    $definition = $this->getPluginDefinition();
    // Cast the admin label to a string since it is an object.
    // @see \Drupal\Core\StringTranslation\TranslationWrapper
    return (string) $definition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTokenTypes() {
    $definition = $this->getPluginDefinition();
    // For some reason, we didn't unify token keys with entity types...
    foreach ($definition['types'] as $key => $type) {
      if ($type == 'taxonomy_term') {
        $definition['types'][$key] = 'term';
      }
    }
    return $definition['types'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = array(
      '#type' => 'details',
      '#title' => $this->getLabel(),
    );

    $form['default'] = array(
      '#type' => 'textfield',
      '#title' => $this->getPatternDescription(),
      '#default_value' => !empty($this->configuration['default']) ? $this->configuration['default'] : '',
      '#size' => 65,
      '#maxlength' => 1280,
      '#element_validate' => array('token_element_validate'),
      '#after_build' => array('token_element_validate'),
      '#token_types' => $this->getTokenTypes(),
      '#min_tokens' => 1,
    );

    // Show the token help relevant to this pattern type.
    $form['token_help'] = array(
      '#theme' => 'token_tree',
      '#token_types' => $this->getTokenTypes(),
      '#dialog' => TRUE,
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Returns bundles.
   *
   * @return string[]
   *   An array of bundle labels, keyed by bundle.
   */
  protected function getBundles() {
    return array_map(function ($bundle_info) {
      return $bundle_info['label'];
    }, $this->entityManager->getBundleInfo($this->getPluginId()));
  }

  /**
   * Checks if a bundle is enabled for translation.
   *
   * @param string $bundle
   *   The bundle.
   *
   * @return bool
   *   TRUE if content translation is enabled for the bundle.
   */
  protected function isContentTranslationEnabled($bundle) {
    return $this->moduleHandler->moduleExists('content_translation') && \Drupal::service('content_translation.manager')->isEnabled($this->getPluginId(), $bundle);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($object) {
    $definition = $this->entityManager->getDefinition($this->getDerivativeId());
    $class = $definition->getClass();
    return ($object instanceof $class);
  }

  /**
   * {@inheritdoc}
   */
  public function getPatternDescription() {
    return $this->t('Replace this description with proper annotation effort.');
  }

  /**
   * {@inheritdoc}
   */
  public function getSourcePrefix() {
    if (empty($this->prefix)) {
      $entity_type = $this->entityManager->getDefinition($this->getDerivativeId());
      $path = $entity_type->getLinkTemplate('canonical');
      // Remove slug(s)... This could probably be done cleaner, but I'm not in the mood.
      $path_parts = explode('/', $path);
      foreach ($path_parts as $key => $value) {
        if (strpos($value, '}') === 0 && strpos($value, '{') == -1) {
          unset ($path_parts[$key]);
        }
      }
      $this->prefix = implode('/', $path_parts);
    }
    return $this->prefix;
  }

}
