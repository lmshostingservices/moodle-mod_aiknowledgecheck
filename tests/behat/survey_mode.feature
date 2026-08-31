@mod @mod_aiknowledgecheck @javascript
Feature: An AI Knowledge Check in survey mode collects responses without scoring
  In order to gather feedback rather than assess knowledge
  As a teacher
  I need survey mode to record responses and show no score

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
      | activity         | name            | course | idnumber | surveymode | surveyscale |
      | aiknowledgecheck | Course feedback | C1     | kc1      | 1          | likert5sat  |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                  | answer1        | answer2   | answer3 | answer4      | answer5           | correctanswer | questiontype |
      | kc1      | 1              | The training met my needs     | Very Satisfied | Satisfied | Neutral | Dissatisfied | Very Dissatisfied | 0             | scale        |
    And the following "mod_aiknowledgecheck > questions" exist:
      | activity | questionnumber | questiontext                     | questiontype |
      | kc1      | 2              | What could we improve?           | freetext     |

  Scenario: A student completes a survey and sees a completion message rather than a score
    Given I am on the "Course feedback" "aiknowledgecheck activity" page logged in as "student1"
    When I click on "#start-attempt-btn" "css_element"
    Then I should see "The training met my needs"

    And "#check-answer-btn" "css_element" should not be visible
    When I click on "Satisfied" "text"
    And I click on "#next-question-btn" "css_element"
    Then I should see "What could we improve?"

    When I set the field with xpath "//textarea[@id='kc-freetext-answer']" to "More practice please, and a < b in my answer"
    And I click on "#next-question-btn" "css_element"
    Then I should see "Survey Complete"
    And I should not see "100%"
    And I should not see "Correct"

  Scenario: The teacher report shows the collected responses
    Given the following "mod_aiknowledgecheck > attempts" exist:
      | activity | user     | status |
      | kc1      | student1 | 1      |
    When I am on the "Course feedback" "aiknowledgecheck activity" page logged in as "teacher1"
    And I click on "Attempts Report" "link"
    Then I should see "Student One"
