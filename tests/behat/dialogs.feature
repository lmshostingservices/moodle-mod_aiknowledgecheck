@mod @mod_aiknowledgecheck @javascript
Feature: Messages and confirmations appear as Moodle modals
  In order to keep the interface accessible and themeable
  As a user
  I need notices and confirmations to use Moodle's own dialogs rather than browser popups

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
    And the following config values are set as admin:
      | siteid | behat-site-id | mod_aiknowledgecheck |
      | apikey | behat-api-key | mod_aiknowledgecheck |
    And the following "activities" exist:
      | activity         | name        | course | idnumber |
      | aiknowledgecheck | Fire safety | C1     | kc1      |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                 | answer1  | answer2 | answer3 | answer4 | correctanswer |
      | kc1      | 1              | Where is the assembly point? | Car park | Kitchen | Roof    | Cellar  | 0             |

  Scenario: A notice is shown in a Moodle dialogue using text from the language pack
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "teacher1"
    When I click on "#edit-questions-btn" "css_element"
    Then I should see "Where is the assembly point?"
    When I click on ".kc-btn-delete-question" "css_element"
    Then I should see "Cannot delete the last question. You must have at least one question." in the "AI Knowledge Check" "dialogue"

  Scenario: A confirmation can be declined and then accepted
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "teacher1"
    When I click on "#edit-questions-btn" "css_element"
    And I click on "#cancel-edits-btn" "css_element"
    Then I should see "Discard all changes?" in the "AI Knowledge Check" "dialogue"

    When I click on "Cancel" "button" in the "AI Knowledge Check" "dialogue"
    Then I should see "Where is the assembly point?"

    When I click on "#cancel-edits-btn" "css_element"
    And I click on "Yes" "button" in the "AI Knowledge Check" "dialogue"
    Then I should not see "Where is the assembly point?"
