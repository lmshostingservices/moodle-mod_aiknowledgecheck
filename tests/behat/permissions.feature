@mod @mod_aiknowledgecheck
Feature: Access to an AI Knowledge Check activity is controlled by capability
  In order to keep generation and reports away from learners
  As a teacher
  I need students to see only the activity itself

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity          | name         | course | idnumber |
      | aiknowledgecheck  | Fire safety  | C1     | kc1      |

  Scenario: A teacher can open the activity
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "teacher1"
    Then I should see "Fire safety"

  Scenario: A student can open the activity
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    Then I should see "Fire safety"

  Scenario: A student cannot reach the report page
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I am on the "Fire safety" "aiknowledgecheck activity" page
    Then I should not see "Attempts report"

  Scenario: A guest cannot open the activity
    Given I am on the "Fire safety" "aiknowledgecheck activity" page
    Then I should see "You are not logged in"
