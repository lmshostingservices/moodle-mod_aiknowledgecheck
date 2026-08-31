@mod @mod_aiknowledgecheck @javascript
Feature: Media gates hold the quiz closed until the student has engaged with the content
  In order to make sure learners see the material first
  As a teacher
  I need the image gate to block the quiz until it is acknowledged

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
      | activity         | name        | course | idnumber | imageurl                          |
      | aiknowledgecheck | Fire safety | C1     | kc1      | https://example.com/gate.png      |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                 | answer1  | answer2 | answer3 | answer4 | correctanswer |
      | kc1      | 1              | Where is the assembly point? | Car park | Kitchen | Roof    | Cellar  | 0             |

  Scenario: The acknowledge prompt renders its apostrophe as text, not as an HTML entity
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    Then I should see "I've seen this image — continue to quiz"
    And I should not see "&#039;"

  Scenario: The quiz is gated until the image is acknowledged
    Given I am on the "Fire safety" "aiknowledgecheck activity" page logged in as "student1"
    Then "#kc-image-acknowledge-btn" "css_element" should be visible
    When I click on "#kc-image-acknowledge-btn" "css_element"
    And I click on "#start-attempt-btn" "css_element"
    Then I should see "Where is the assembly point?"
