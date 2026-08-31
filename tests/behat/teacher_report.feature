@mod @mod_aiknowledgecheck
Feature: A teacher can review attempts and grant extra attempts
  In order to support learners who have run out of attempts
  As a teacher
  I need the attempts report and the override screen

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
    And the following config values are set as admin:
      | siteid | behat-site-id | mod_aiknowledgecheck |
      | apikey | behat-api-key | mod_aiknowledgecheck |
    And the following "activities" exist:
      | activity         | name        | course | idnumber | maxattempts |
      | aiknowledgecheck | Fire safety | C1     | kc1      | 1           |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                 |
      | kc1      | 1              | Where is the assembly point? |
    And the following "mod_aiknowledgecheck > attempts" exist:
      | activity | user     | status | correctcount | totalcount |
      | kc1      | student1 | 1      | 1            | 1          |

  Scenario: The attempts report lists the student who attempted
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "teacher1"
    When I click on "Attempts Report" "link"
    Then I should see "Student One"

  Scenario: A student cannot open the attempts report directly
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    Then I should not see "Attempts Report"

  Scenario: A teacher can grant an extra attempt
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "teacher1"
    When I click on "More Attempts" "link"
    Then I should see "Student One"
