@mod @mod_aiknowledgecheck @javascript
Feature: The quiz can be operated without a mouse
  In order to take an assessment using a keyboard or a screen reader
  As a student
  I need the answer options to behave as a proper radio group

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following config values are set as admin:
      | siteid | behat-site-id | mod_aiknowledgecheck |
      | apikey | behat-api-key | mod_aiknowledgecheck |
    And the following "activities" exist:
      | activity         | name        | course | idnumber | maxattempts |
      | aiknowledgecheck | Fire safety | C1     | kc1      | 3           |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                 | answer1  | answer2 | answer3 | answer4 | correctanswer |
      | kc1      | 1              | Where is the assembly point? | Car park | Kitchen | Roof    | Cellar  | 0             |

  Scenario: The answer options are exposed to assistive technology as a radio group
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    Then "#options-container[role='radiogroup']" "css_element" should exist
    And "#options-container[aria-labelledby='question-text']" "css_element" should exist
    And ".kc-option[role='radio'][aria-checked='false']" "css_element" should exist
    And "#feedback-container[aria-live='polite']" "css_element" should exist
    And "#question-counter[aria-live='polite']" "css_element" should exist

  Scenario: An option can be chosen with the keyboard alone
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    And I press tab
    And I press the down key
    Then ".kc-option[aria-checked='true']" "css_element" should exist
    And the "disabled" attribute of "#check-answer-btn" "css_element" should not be set

  Scenario: Only one option stays in the tab order, as a radio group should
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    Then ".kc-option[tabindex='0']" "css_element" should exist
    And ".kc-option[tabindex='-1']" "css_element" should exist

  Scenario: Answering marks the options unavailable to assistive technology too
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    And I click on "Car park" "text"
    And I click on "#check-answer-btn" "css_element"
    Then I should see "Correct"
    And ".kc-option[aria-disabled='true']" "css_element" should exist
