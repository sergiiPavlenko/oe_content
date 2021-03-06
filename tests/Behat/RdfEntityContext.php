<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_content\Behat;

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Drupal\DrupalExtension\Context\ConfigContext;
use Drupal\rdf_entity\RdfInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Defines step definitions specifically for testing the RDF entities.
 *
 * We are extending ConfigContext to override the setConfig() method until
 * issue https://github.com/jhedstrom/drupalextension/issues/498 is fixed.
 *
 * @todo Extend DrupalRawContext and gather the config context when the above
 * issue is fixed.
 */
class RdfEntityContext extends ConfigContext {

  /**
   * An array of RDF entities we need to keep track to delete.
   *
   * @var \Drupal\rdf_entity\RdfInterface[]
   */
  protected $rdfEntities = [];

  /**
   * After scenario hook to delete the RDF entities.
   *
   * @param \Behat\Behat\Hook\Scope\AfterScenarioScope $scope
   *   The Hook scope.
   *
   * @AfterScenario @rdf-test
   */
  public function afterScenarioRdfCleanUp(AfterScenarioScope $scope): void {
    if (empty($this->rdfEntities)) {
      return;
    }

    foreach ($this->rdfEntities as $rdf) {
      $rdf->delete();
    }
  }

  /**
   * Configures the Provenance URI.
   *
   * @Given the site Provenance URI is set to :uri
   * @And the site Provenance URI is set to :uri
   */
  public function setSiteProvenanceUri(string $uri): void {
    $this->setConfig('oe_content.settings', 'provenance_uri', $uri);
  }

  /**
   * User creation and login for creating RDF entities.
   *
   * A custom step that creates a user that has the permissions to manage and
   * view RDF entities of a give type.
   *
   * @Given I am logged in with a user that can create and view :bundle RDF entities
   */
  public function assertLoggedInWithRdfEntityTypePermissions(string $bundle): void {
    /** @var \Drupal\rdf_entity\RdfEntityTypeInterface[] $types */
    $types = \Drupal::entityTypeManager()->getStorage('rdf_type')->loadMultiple();
    $permission_map = [];
    foreach ($types as $id => $type) {
      $permission_map[$type->label()] = [
        "create $id rdf entity",
        "edit $id rdf entity",
        "delete $id rdf entity",
      ];
    }

    if (!isset($permission_map[$bundle])) {
      throw new \InvalidArgumentException('The provided entity type is not correct.');
    }

    $permissions = array_merge($permission_map[$bundle], [
      'view rdf entity',
      'view rdf entity overview',
    ]);

    $role = $this->getDriver()->roleCreate($permissions);

    // Create user.
    $user = (object) [
      'name' => $this->getRandom()->name(8),
      'pass' => $this->getRandom()->name(16),
      'role' => $role,
    ];
    $user->mail = "{$user->name}@example.com";
    $this->userCreate($user);

    // Assign the temporary role with given permissions.
    $this->getDriver()->userAddRole($user, $role);
    $this->roles[] = $role;

    // Login.
    $this->login($user);
  }

  /**
   * Creates a test RDF entity.
   *
   * @Given I create an :bundle RDF entity with the name :name
   */
  public function createRdfEntityWithLabel(string $bundle, string $name): void {
    $values = [
      'bundle' => $bundle,
      'label' => $name,
    ];

    $this->createRdfEntity($values);
  }

  /**
   * Tests that the created RDF entity has the correct provenance URI.
   *
   * @Then the Provenance URI of the RDF entity with the name :name should be :provenance_uri
   */
  public function assertRdfEntityProvenanceUri(string $name, string $provenance_uri): void {
    $entity = $this->loadRdfEntityByName($name);
    if ($entity->get('provenance_uri')->value !== $provenance_uri) {
      throw new \Exception('The RDF entity did not get created with the proper Provenance URI.');
    }
  }

  /**
   * Removes a RDF entity with a given name.
   *
   * @Then I delete the RDF entity with the name :name
   */
  public function deleteRdfEntityByLabel(string $name): void {
    $entity = $this->loadRdfEntityByName($name);
    $entity->delete();
  }

  /**
   * Asserts that the current user has access to a given RDF entity.
   *
   * @Then I should have :operation access to the RDF entity with the name :name
   */
  public function assertAccessToTheRdfEntityWithTheName(string $operation, string $name): void {
    $entity = $this->loadRdfEntityByName($name);
    $current_user = $this->getCurrentUser();
    if (!$entity->access($operation, $current_user)) {
      throw new \Exception('The current user does not have access for this operation and they should.');
    }
  }

  /**
   * Asserts that the current user does not have access to a given RDF entity.
   *
   * @Then I should not have :operation access to the RDF entity with the name :name
   */
  public function assertNoAccessToTheRdfEntityWithTheName(string $operation, string $name): void {
    $entity = $this->loadRdfEntityByName($name);
    $current_user = $this->getCurrentUser();
    if ($entity->access($operation, $current_user)) {
      throw new \Exception('The current user has access for this operation and they should not.');
    }
  }

  /**
   * Creates a an announcement RDF entity and visits its canonical URL.
   *
   * @Given I visit an announcement page that links to the department :name taxonomy term
   */
  public function visitAnnouncementThatLinksToDepartment(string $name): void {
    $department = $this->getDepartmentByName($name);
    $values = [
      'bundle' => 'Announcement',
      'label' => 'My announcement',
      'oe_announcement_department' => $department->id(),
      'oe_announcement_location' => 'Brussels',
      'oe_announcement_subject' => 'http://eurovoc.europa.eu/1422',
      'oe_announcement_type' => 'http://publications.europa.eu/resource/authority/resource-type/PRESS_REL',
    ];

    $entity = $this->createRdfEntity($values);
    $this->visitPath($entity->toUrl()->toString());
  }

  /**
   * Asserts that we are on the canonical page of an RDF entity.
   *
   * @Then I should be on the :name RDF entity page
   */
  public function assertOnRdfEntityPage(string $name): void {
    $path = $this->getSession()->getCurrentUrl();

    $entities = \Drupal::entityTypeManager()->getStorage('rdf_entity')->loadByProperties(['label' => $name]);
    if (!$entities) {
      throw new \Exception('Missing RDF entity with name: ' . $name);
    }

    $entity = reset($entities);
    $url = $entity->toUrl()->toString();
    global $base_url;
    // The "absolute" option on the Url object does not take the subfolder into
    // account so we append the base URL like so.
    $url = $base_url . $url;

    if ($path !== $url) {
      throw new \Exception(sprintf('The current URL of %s does not match the URL of the entity "%s" which is %s', $path, $name, $url));
    }
  }

  /**
   * Creates keeps track of an RDF entity.
   *
   * @param array $values
   *   Values for the RDF entity.
   *
   * @return \Drupal\rdf_entity\RdfInterface
   *   The entity.
   */
  protected function createRdfEntity(array $values): RdfInterface {
    $bundle = isset($values['bundle']) ? $values['bundle'] : NULL;
    if (!$bundle) {
      throw new \InvalidArgumentException('No bundle provided.');
    }

    /** @var \Drupal\rdf_entity\RdfEntityTypeInterface[] $types */
    $types = \Drupal::entityTypeManager()->getStorage('rdf_type')->loadMultiple();
    $map = [];
    foreach ($types as $id => $type) {
      $map[$type->label()] = $type->id();
    }

    if (!isset($map[$bundle])) {
      throw new \InvalidArgumentException('The provided entity type is not correct.');
    }

    $values += [
      'rid' => $map[$bundle],
    ];

    if ($map[$bundle] === 'oe_department') {
      $department = $this->getDepartmentByName($values['label']);
      $values['oe_department_name'] = $department->id();
    }

    /** @var \Drupal\rdf_entity\RdfInterface $entity */
    $entity = \Drupal::entityTypeManager()->getStorage('rdf_entity')->create($values);
    $entity->save();
    $this->rdfEntities[] = $entity;
    return $entity;
  }

  /**
   * Loads an RDF entity by name.
   *
   * @param string $name
   *   The name of the entity.
   *
   * @return \Drupal\rdf_entity\RdfInterface
   *   The RDF entity instance.
   */
  protected function loadRdfEntityByName(string $name): RdfInterface {
    $entities = \Drupal::entityTypeManager()->getStorage('rdf_entity')->loadByProperties(['label' => $name]);
    if (!$entities) {
      throw new \Exception('RDF entity not found.');
    }
    return reset($entities);
  }

  /**
   * Returns the currently logged-in user.
   *
   * @return \Drupal\user\UserInterface
   *   The User entity.
   */
  protected function getCurrentUser(): UserInterface {
    $object = $this->userManager->getCurrentUser();
    if (!$object) {
      return NULL;
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($object->uid);
    return $user;
  }

  /**
   * Returns the Department taxonomy term by name.
   *
   * @param string $name
   *   The name of the department.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The taxonomy term.
   */
  protected function getDepartmentByName(string $name): TermInterface {
    // We need to reference the Department term.
    $departments = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $name]);
    if (!$departments) {
      throw new \Exception('There was no department found by the name: ' . $name);
    }

    return reset($departments);
  }

  /**
   * {@inheritdoc}
   *
   * @todo Remove when https://github.com/jhedstrom/drupalextension/issues/498
   * gets fixed.
   */
  public function setConfig($name, $key, $value) {
    $backup = $this->getDriver()->configGet($name, $key);
    $this->getDriver()->configSet($name, $key, $value);
    if (!array_key_exists($name, $this->config)) {
      $this->config[$name][$key] = $backup;
      return;
    }

    if (!array_key_exists($key, $this->config[$name])) {
      $this->config[$name][$key] = $backup;
    }
  }

}
