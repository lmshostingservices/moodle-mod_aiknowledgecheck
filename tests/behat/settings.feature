@mod @mod_aiknowledgecheck
Feature: An AI Knowledge Check can be configured from the activity settings form
  In order to control how a knowledge check behaves
  As a teacher
  I need the activity settings to save and come back

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: A teacher creates an activity and the attempt limit is stored
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 1" section "1" and I fill the form with:
      | Name         | Manual handling |
      | Maximum attempts | 2           |
    Then I should see "Manual handling"
    And I am on the "Manual handling" "aiknowledgecheck activity editing" page
    And the field "Maximum attempts" matches value "2"

  Scenario: A negative attempt limit is rejected by validation
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Name             | Bad limit |
      | Maximum attempts | -3        |
    And I press "Save and return to course"
    Then I should see "Maximum attempts cannot be negative."

  Scenario: An unlimited attempt limit is accepted
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 1" section "1" and I fill the form with:
      | Name             | No limit |
      | Maximum attempts | 0        |
    Then I should see "No limit"
    And I am on the "No limit" "aiknowledgecheck activity editing" page
    And the field "Maximum attempts" matches value "0"

  Scenario: Survey mode can be turned on and comes back set
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 1" section "1" and I fill the form with:
      | Name               | Feedback survey |
      | Enable Survey Mode | 1        |
    Then I should see "Feedback survey"
    And I am on the "Feedback survey" "aiknowledgecheck activity editing" page
    And the field "Enable Survey Mode" matches value "1"
