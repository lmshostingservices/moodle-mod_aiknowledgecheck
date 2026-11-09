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

  @javascript
  Scenario: The correct-answer reveal is on by default and can be turned off
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 1" section "1" and I fill the form with:
      | Name | Assessment mode |
    Then I am on the "Assessment mode" "aiknowledgecheck activity editing" page
    And I expand all fieldsets
    And the field "Show the correct answer after a wrong response" matches value "1"
    When I set the field "Show the correct answer after a wrong response" to "0"
    And I press "Save and return to course"
    And I am on the "Assessment mode" "aiknowledgecheck activity editing" page
    And I expand all fieldsets
    Then the field "Show the correct answer after a wrong response" matches value "0"

  @javascript
  Scenario: The survey completion rule appears only in survey mode
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 2 | C2        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    And I am on the "Course 2" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 2" section "1" and I fill the form with:
      | Name | Completion modes |
    Then I am on the "Completion modes" "aiknowledgecheck activity editing" page
    And I expand all fieldsets
    And I set the field "Add requirements" to "1"
    And I should see "All answers must be correct"
    And I should not see "Must submit survey"
    When I set the field "Enable Survey Mode" to "1"
    Then I should see "Must submit survey"
    And I should not see "All answers must be correct"

  @javascript
  Scenario: The survey completion rule saves and comes back set
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 2 | C2        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    And I am on the "Course 2" course page logged in as "teacher1"
    When I add a aiknowledgecheck activity to course "Course 2" section "1" and I fill the form with:
      | Name | Feedback completion |
    Then I am on the "Feedback completion" "aiknowledgecheck activity editing" page
    And I expand all fieldsets
    And I set the field "Enable Survey Mode" to "1"
    And I set the field "Add requirements" to "1"
    And I set the field "Must submit survey" to "1"
    And I press "Save and return to course"
    And I am on the "Feedback completion" "aiknowledgecheck activity editing" page
    And I expand all fieldsets
    Then the field "Must submit survey" matches value "1"
