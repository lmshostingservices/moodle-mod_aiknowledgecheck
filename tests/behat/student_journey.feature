@mod @mod_aiknowledgecheck @javascript
Feature: A student works through an AI Knowledge Check
  In order to demonstrate what they know
  As a student
  I need to answer each question, see feedback, and get a score

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
      | aiknowledgecheck | Fire safety | C1     | kc1      | 2           |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                  | answer1 | answer2 | answer3 | answer4 | correctanswer |
      | kc1      | 1              | Where is the assembly point?  | Car park| Kitchen | Roof    | Cellar  | 0             |
      | kc1      | 2              | Who calls the fire brigade?   | Nobody  | Warden  | Anyone  | Cleaner | 1             |

  Scenario: A student answers every question and reaches the results screen
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    Then I should see "Where is the assembly point?"

    When I click on "Car park" "text"
    And I click on "#check-answer-btn" "css_element"
    Then I should see "Correct"

    When I click on "#next-question-btn" "css_element"
    Then I should see "Who calls the fire brigade?"

    When I click on "Warden" "text"
    And I click on "#check-answer-btn" "css_element"
    Then I should see "Correct"

    When I click on "#next-question-btn" "css_element"
    Then I should see "100%"

  Scenario: A wrong answer is marked incorrect and the score reflects it
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    And I click on "Kitchen" "text"
    And I click on "#check-answer-btn" "css_element"
    Then I should see "Incorrect"

    When I click on "#next-question-btn" "css_element"
    And I click on "Warden" "text"
    And I click on "#check-answer-btn" "css_element"
    And I click on "#next-question-btn" "css_element"
    Then I should see "50%"

  Scenario: An in-progress attempt is resumed rather than restarted
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    And I click on "Car park" "text"
    And I click on "#check-answer-btn" "css_element"
    And I click on "#next-question-btn" "css_element"
    Then I should see "Who calls the fire brigade?"

    When I reload the page
    Then I should see "Continue" in the "#continue-attempt-btn" "css_element"
    And I click on "#continue-attempt-btn" "css_element"
    Then I should see "Who calls the fire brigade?"
    And I should not see "Where is the assembly point?"

  Scenario: A student who has used every attempt cannot start another
    Given the following "mod_aiknowledgecheck > attempts" exist:
      | activity | user     | status |
      | kc1      | student1 | 1      |
      | kc1      | student1 | 1      |
    When I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    Then I should see "You have reached the attempt limit (2)."
    And "#start-attempt-btn" "css_element" should not exist

  Scenario: An override lets a student who was blocked start again
    Given the following "mod_aiknowledgecheck > attempts" exist:
      | activity | user     | status |
      | kc1      | student1 | 1      |
      | kc1      | student1 | 1      |
    And the following "mod_aiknowledgecheck > overrides" exist:
      | activity | user     | extraattempts |
      | kc1      | student1 | 1             |
    And I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    Then I should see "Where is the assembly point?"
